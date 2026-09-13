<?php

namespace Tests\Feature;

use App\Models\OrderFulfillment;
use App\Models\Product;
use App\Models\User;
use App\Models\UserNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_and_fulfillment_transitions_notify_the_counterparty_only(): void
    {
        $seller = $this->user();
        $buyer = $this->user();
        $product = Product::factory()->for($seller, 'owner')->create(['price' => 150000, 'stock' => 5]);

        $created = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [['id' => $product->id, 'quantity' => 2]],
        ]))->assertCreated();
        $orderId = $created->json('order_id');
        $fulfillmentId = $created->json('order.fulfillments.0.id');

        // Checkout notifies the seller; the acting buyer is never told about their own action.
        $this->assertDatabaseHas('user_notifications', [
            'recipient_id' => $seller->id,
            'actor_id' => $buyer->id,
            'order_id' => $orderId,
            'type' => UserNotification::TYPE_ORDER_PLACED,
            'event_key' => "notify:fulfillment:{$fulfillmentId}:placed",
        ]);
        $this->assertSame(0, UserNotification::query()->where('recipient_id', $buyer->id)->count());

        $placed = UserNotification::query()->where('recipient_id', $seller->id)->firstOrFail();
        $this->assertSame(300000, $placed->payload['subtotal']);
        $this->assertSame(1, $placed->payload['item_count']);

        $path = "/api/seller/fulfillments/{$fulfillmentId}/status";
        foreach ([OrderFulfillment::STATUS_ACCEPTED, OrderFulfillment::STATUS_READY, OrderFulfillment::STATUS_COMPLETED] as $status) {
            $this->actingAs($seller)->patchJson($path, ['status' => $status])->assertOk();
        }

        // Each transition notifies the buyer, and the seller gains nothing new.
        $this->assertSame([
            'fulfillment.completed',
            'fulfillment.ready',
            'fulfillment.accepted',
        ], UserNotification::query()
            ->where('recipient_id', $buyer->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->pluck('type')
            ->all());
        $this->assertSame(1, UserNotification::query()->where('recipient_id', $seller->id)->count());
    }

    public function test_same_state_transition_replay_does_not_duplicate_notifications(): void
    {
        $seller = $this->user();
        $buyer = $this->user();
        $product = Product::factory()->for($seller, 'owner')->create();
        $created = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [['id' => $product->id, 'quantity' => 1]],
        ]))->assertCreated();
        $path = '/api/seller/fulfillments/'.$created->json('order.fulfillments.0.id').'/status';

        $this->actingAs($seller)->patchJson($path, ['status' => OrderFulfillment::STATUS_ACCEPTED])->assertOk();
        $this->actingAs($seller)->patchJson($path, ['status' => OrderFulfillment::STATUS_ACCEPTED])->assertOk();

        $this->assertSame(1, UserNotification::query()
            ->where('recipient_id', $buyer->id)
            ->where('type', UserNotification::TYPE_FULFILLMENT_ACCEPTED)
            ->count());
    }

    public function test_rental_cancellation_and_review_lifecycle_notify_the_other_party(): void
    {
        $seller = $this->user();
        $buyer = $this->user();
        $rental = Product::factory()->for($seller, 'owner')->create(['type' => Product::TYPE_RENTAL, 'stock' => 5]);
        $start = Carbon::today(config('app.timezone'))->addDays(3);

        $created = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [[
                'id' => $rental->id,
                'quantity' => 1,
                'start_date' => $start->toDateString(),
                'end_date' => $start->copy()->addDay()->toDateString(),
            ]],
        ]))->assertCreated();
        $orderId = $created->json('order_id');
        $itemId = $created->json('order.items.0.id');

        $this->actingAs($buyer)->deleteJson("/api/orders/{$orderId}/items/{$itemId}/rental")->assertOk();
        $this->assertDatabaseHas('user_notifications', [
            'recipient_id' => $seller->id,
            'actor_id' => $buyer->id,
            'type' => UserNotification::TYPE_RENTAL_CANCELLED,
        ]);

        // Sale item completes so a review becomes possible.
        $sale = Product::factory()->for($seller, 'owner')->create();
        $saleOrder = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [['id' => $sale->id, 'quantity' => 1]],
        ]))->assertCreated();
        $saleFulfillment = $saleOrder->json('order.fulfillments.0.id');
        foreach ([OrderFulfillment::STATUS_ACCEPTED, OrderFulfillment::STATUS_READY, OrderFulfillment::STATUS_COMPLETED] as $status) {
            $this->actingAs($seller)->patchJson("/api/seller/fulfillments/{$saleFulfillment}/status", ['status' => $status])->assertOk();
        }

        $review = $this->actingAs($buyer)->postJson(
            '/api/orders/'.$saleOrder->json('order_id').'/items/'.$saleOrder->json('order.items.0.id').'/review',
            ['rating' => 5, 'body' => 'Sangat rapi.'],
        )->assertCreated();
        $reviewId = $review->json('review.id');

        $this->assertDatabaseHas('user_notifications', [
            'recipient_id' => $seller->id,
            'product_review_id' => $reviewId,
            'type' => UserNotification::TYPE_REVIEW_RECEIVED,
        ]);

        $this->actingAs($seller)->patchJson("/api/seller/reviews/{$reviewId}/reply", ['reply' => 'Terima kasih!'])->assertOk();
        $this->assertDatabaseHas('user_notifications', [
            'recipient_id' => $buyer->id,
            'product_review_id' => $reviewId,
            'type' => UserNotification::TYPE_REVIEW_REPLIED,
        ]);

        // Editing an existing reply stays silent so the buyer is not pinged repeatedly.
        $this->actingAs($seller)->patchJson("/api/seller/reviews/{$reviewId}/reply", ['reply' => 'Terima kasih banyak!'])->assertOk();
        $this->assertSame(1, UserNotification::query()
            ->where('recipient_id', $buyer->id)
            ->where('type', UserNotification::TYPE_REVIEW_REPLIED)
            ->count());
    }

    public function test_feed_is_recipient_scoped_cursor_paginated_and_filterable(): void
    {
        $seller = $this->user();
        $rival = $this->user();
        $buyer = $this->user();
        $product = Product::factory()->for($seller, 'owner')->create(['stock' => 20]);

        foreach (range(1, 3) as $ignored) {
            $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload([
                'items' => [['id' => $product->id, 'quantity' => 1]],
            ]))->assertCreated();
        }

        $first = $this->actingAs($seller)->getJson('/api/notifications?per_page=2')->assertOk();
        $this->assertCount(2, $first->json('data'));
        $this->assertTrue($first->json('pagination.has_more'));
        $this->assertSame(3, $first->json('unread_count'));
        $this->assertSame('Pesanan baru masuk', $first->json('data.0.title'));
        $this->assertTrue($first->json('data.0.is_unread'));
        $this->assertSame(
            ['id', 'type', 'title', 'order_id', 'fulfillment_id', 'payload', 'is_unread', 'read_at', 'created_at'],
            array_keys($first->json('data.0')),
        );

        $second = $this->actingAs($seller)
            ->getJson('/api/notifications?per_page=2&cursor='.$first->json('pagination.next_cursor'))
            ->assertOk();
        $this->assertCount(1, $second->json('data'));
        $this->assertFalse($second->json('pagination.has_more'));

        $this->actingAs($rival)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('unread_count', 0);

        $this->actingAs($seller)->getJson('/api/notifications?per_page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_read_state_is_owner_scoped_idempotent_and_bulk_clearable(): void
    {
        $seller = $this->user();
        $intruder = $this->user();
        $buyer = $this->user();
        $product = Product::factory()->for($seller, 'owner')->create(['stock' => 20]);

        foreach (range(1, 3) as $ignored) {
            $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload([
                'items' => [['id' => $product->id, 'quantity' => 1]],
            ]))->assertCreated();
        }
        $target = UserNotification::query()->where('recipient_id', $seller->id)->firstOrFail();

        $this->actingAs($intruder)->patchJson("/api/notifications/{$target->id}/read")->assertForbidden();
        $this->assertNull($target->fresh()->read_at);

        $this->actingAs($seller)->patchJson("/api/notifications/{$target->id}/read")
            ->assertOk()
            ->assertJsonPath('notification.is_unread', false)
            ->assertJsonPath('unread_count', 2);
        $firstReadAt = $target->fresh()->read_at;

        // Marking an already-read notification keeps the original timestamp.
        $this->actingAs($seller)->patchJson("/api/notifications/{$target->id}/read")
            ->assertOk()
            ->assertJsonPath('unread_count', 2);
        $this->assertEquals($firstReadAt, $target->fresh()->read_at);

        $this->actingAs($seller)->getJson('/api/notifications?unread=1')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($seller)->patchJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('marked', 2)
            ->assertJsonPath('unread_count', 0);
        $this->assertSame(0, UserNotification::query()->where('recipient_id', $seller->id)->unread()->count());

        $this->actingAs($seller)->patchJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('marked', 0)
            ->assertJsonPath('message', 'Tidak ada notifikasi baru.');
    }

    public function test_notification_endpoints_reject_guests(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();
        $this->patchJson('/api/notifications/1/read')->assertUnauthorized();
        $this->patchJson('/api/notifications/read-all')->assertUnauthorized();
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }
}
