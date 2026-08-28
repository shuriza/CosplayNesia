<?php

namespace Tests\Feature;

use App\Models\OrderFulfillment;
use App\Models\Product;
use App\Models\RentalReservation;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FulfillmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_creates_one_fulfillment_per_actionable_seller_and_leaves_null_owner_unassigned(): void
    {
        $sellerA = $this->user(['name' => 'Seller A']);
        $sellerB = $this->user(['name' => 'Seller B']);
        $buyer = $this->user();
        $productA = Product::factory()->for($sellerA, 'owner')->create(['seller' => 'Old A']);
        $productB = Product::factory()->for($sellerB, 'owner')->create(['seller' => 'Old B']);
        $legacy = Product::factory()->create(['seller_id' => null, 'seller' => 'Demo Rental']);

        $response = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [
                ['id' => $productA->id, 'quantity' => 1],
                ['id' => $productB->id, 'quantity' => 1],
                ['id' => $legacy->id, 'quantity' => 1],
            ],
        ]))->assertCreated()->assertJsonPath('order.status', 'processing');

        $orderId = $response->json('order_id');
        $this->assertDatabaseCount('order_fulfillments', 2);
        $this->assertDatabaseHas('order_fulfillments', ['order_id' => $orderId, 'seller_id' => $sellerA->id, 'seller_name' => 'Old A', 'status' => OrderFulfillment::STATUS_RECEIVED]);
        $this->assertDatabaseHas('order_fulfillments', ['order_id' => $orderId, 'seller_id' => $sellerB->id, 'seller_name' => 'Old B']);
        $this->assertDatabaseHas('order_items', ['order_id' => $orderId, 'product_id' => $legacy->id, 'fulfillment_id' => null]);
        $this->actingAs($buyer)->getJson('/api/orders')->assertJsonPath('data.0.status', 'processing')->assertJsonPath('data.0.items.0.fulfillment_status', 'received');
    }

    public function test_seller_isolation_and_allowed_invalid_and_idempotent_transitions(): void
    {
        $seller = $this->user();
        $other = $this->user();
        $buyer = $this->user();
        $product = Product::factory()->for($seller, 'owner')->create();
        $created = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload(['items' => [['id' => $product->id, 'quantity' => 1]]]))->assertCreated();
        $fulfillmentId = $created->json('order.fulfillments.0.id');

        $this->actingAs($other)->getJson('/api/seller/fulfillments')->assertOk()->assertJsonPath('data', [])->assertJsonMissingPath('pagination.total');
        $this->actingAs($other)->patchJson("/api/seller/fulfillments/{$fulfillmentId}/status", ['status' => 'accepted'])->assertForbidden();
        $this->actingAs($seller)->patchJson("/api/seller/fulfillments/{$fulfillmentId}/status", ['status' => 'ready'])->assertConflict();
        $this->actingAs($seller)->patchJson("/api/seller/fulfillments/{$fulfillmentId}/status", ['status' => 'unknown'])->assertConflict();
        $this->actingAs($seller)->patchJson("/api/seller/fulfillments/{$fulfillmentId}/status", ['status' => 'accepted'])->assertOk()->assertJsonPath('fulfillment.status', 'accepted');
        $this->actingAs($seller)->patchJson("/api/seller/fulfillments/{$fulfillmentId}/status", ['status' => 'accepted'])->assertOk()->assertJsonPath('fulfillment.status', 'accepted');
        $this->actingAs($seller)->patchJson("/api/seller/fulfillments/{$fulfillmentId}/status", ['status' => 'ready'])->assertOk()->assertJsonPath('fulfillment.status', 'ready');
        $this->actingAs($seller)->patchJson("/api/seller/fulfillments/{$fulfillmentId}/status", ['status' => 'completed'])->assertOk()->assertJsonPath('fulfillment.status', 'completed');
        $this->actingAs($seller)->patchJson("/api/seller/fulfillments/{$fulfillmentId}/status", ['status' => 'accepted'])->assertConflict();
        $this->assertDatabaseHas('orders', ['id' => $created->json('order_id'), 'status' => 'fulfilled']);
    }

    public function test_fulfillment_history_cursor_preserves_seller_and_status_scope(): void
    {
        $seller = $this->user();
        $otherSeller = $this->user();
        $buyer = $this->user();
        $timestamp = CarbonImmutable::parse('2026-08-28 09:00:00');

        $fulfillments = collect(range(1, 7))->map(function (int $index) use ($seller, $buyer, $timestamp) {
            $order = $buyer->orders()->create(['total_amount' => $index * 1000, 'status' => 'processing']);
            $fulfillment = $order->fulfillments()->create([
                'seller_id' => $seller->id,
                'seller_name' => $seller->name,
                'status' => $index <= 5 ? OrderFulfillment::STATUS_RECEIVED : OrderFulfillment::STATUS_COMPLETED,
                'status_changed_at' => $timestamp,
            ]);
            $fulfillment->forceFill(['created_at' => $timestamp, 'updated_at' => $timestamp])->save();

            return $fulfillment;
        });
        $otherOrder = $buyer->orders()->create(['total_amount' => 999000, 'status' => 'processing']);
        $otherOrder->fulfillments()->create([
            'seller_id' => $otherSeller->id,
            'seller_name' => $otherSeller->name,
            'status' => OrderFulfillment::STATUS_RECEIVED,
            'status_changed_at' => $timestamp,
        ]);

        $first = $this->actingAs($seller)->getJson('/api/seller/fulfillments?status=received&per_page=3')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('pagination.per_page', 3)
            ->assertJsonPath('pagination.has_more', true)
            ->assertJsonMissingPath('pagination.total');
        $firstIds = collect($first->json('data'))->pluck('id');

        $second = $this->actingAs($seller)->getJson('/api/seller/fulfillments?status=received&per_page=3&cursor='.urlencode($first->json('pagination.next_cursor')))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('pagination.has_more', false)
            ->assertJsonPath('pagination.next_cursor', null);
        $secondIds = collect($second->json('data'))->pluck('id');

        $expected = $fulfillments->where('status', OrderFulfillment::STATUS_RECEIVED)->sortByDesc('id')->pluck('id')->values()->all();
        $this->assertCount(0, $firstIds->intersect($secondIds));
        $this->assertSame($expected, $firstIds->merge($secondIds)->all());
        $this->actingAs($seller)->getJson('/api/seller/fulfillments?per_page=21')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->actingAs($seller)->getJson('/api/seller/fulfillments/'.$fulfillments->last()->id)
            ->assertOk()
            ->assertJsonPath('id', $fulfillments->last()->id)
            ->assertJsonMissingPath('data');
    }

    public function test_mixed_seller_fulfillments_transition_independently(): void
    {
        $sellerA = $this->user();
        $sellerB = $this->user();
        $buyer = $this->user();
        $first = Product::factory()->for($sellerA, 'owner')->create();
        $second = Product::factory()->for($sellerB, 'owner')->create();
        $created = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload(['items' => [['id' => $first->id, 'quantity' => 1], ['id' => $second->id, 'quantity' => 1]]]))->assertCreated();
        $fulfillments = $created->json('order.fulfillments');
        $firstFulfillment = collect($fulfillments)->firstWhere('seller_id', $sellerA->id);
        $secondFulfillment = collect($fulfillments)->firstWhere('seller_id', $sellerB->id);

        $this->actingAs($sellerA)->patchJson("/api/seller/fulfillments/{$firstFulfillment['id']}/status", ['status' => 'accepted'])->assertOk();
        $this->assertDatabaseHas('order_fulfillments', ['id' => $secondFulfillment['id'], 'status' => 'received']);
        $this->assertDatabaseHas('orders', ['id' => $created->json('order_id'), 'status' => 'processing']);
    }

    public function test_unassigned_lines_prevent_fully_completed_or_cancelled_parent_status(): void
    {
        $seller = $this->user();
        $buyer = $this->user();
        $completedProduct = Product::factory()->for($seller, 'owner')->create();
        $legacyProduct = Product::factory()->create(['seller_id' => null]);
        $completedOrder = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload(['items' => [['id' => $completedProduct->id, 'quantity' => 1], ['id' => $legacyProduct->id, 'quantity' => 1]]]))->assertCreated();
        $completedFulfillment = $completedOrder->json('order.fulfillments.0.id');
        $this->advanceToReady($seller, $completedFulfillment);
        $this->actingAs($seller)->patchJson("/api/seller/fulfillments/{$completedFulfillment}/status", ['status' => 'completed'])->assertOk();
        $this->assertDatabaseHas('orders', ['id' => $completedOrder->json('order_id'), 'status' => 'partially_fulfilled']);

        $cancelledProduct = Product::factory()->for($seller, 'owner')->create();
        $secondLegacy = Product::factory()->create(['seller_id' => null]);
        $cancelledOrder = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload(['items' => [['id' => $cancelledProduct->id, 'quantity' => 1], ['id' => $secondLegacy->id, 'quantity' => 1]]]))->assertCreated();
        $cancelledFulfillment = $cancelledOrder->json('order.fulfillments.0.id');
        $this->actingAs($seller)->patchJson("/api/seller/fulfillments/{$cancelledFulfillment}/status", ['status' => 'cancelled'])->assertOk();
        $this->assertDatabaseHas('orders', ['id' => $cancelledOrder->json('order_id'), 'status' => 'partially_cancelled']);
    }

    public function test_seller_cancellation_restores_sale_stock_once_and_deleted_product_is_not_restored(): void
    {
        $seller = $this->user();
        $buyer = $this->user();
        $product = Product::factory()->for($seller, 'owner')->create(['stock' => 3]);
        $created = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload(['items' => [['id' => $product->id, 'quantity' => 2]]]))->assertCreated();
        $fulfillmentId = $created->json('order.fulfillments.0.id');

        $this->actingAs($seller)->patchJson("/api/seller/fulfillments/{$fulfillmentId}/status", ['status' => 'cancelled'])->assertOk()->assertJsonPath('fulfillment.status', 'cancelled');
        $this->assertSame(3, $product->fresh()->stock);
        $this->assertNotNull($product->orderItems()->first()->fresh()->stock_released_at);
        $this->actingAs($seller)->patchJson("/api/seller/fulfillments/{$fulfillmentId}/status", ['status' => 'cancelled'])->assertOk();
        $this->assertSame(3, $product->fresh()->stock);

        $deleted = Product::factory()->for($seller, 'owner')->create(['stock' => 1]);
        $deletedOrder = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload(['items' => [['id' => $deleted->id, 'quantity' => 1]]]))->assertCreated();
        $deletedFulfillment = $deletedOrder->json('order.fulfillments.0.id');
        $deleted->delete();
        $this->actingAs($seller)->patchJson("/api/seller/fulfillments/{$deletedFulfillment}/status", ['status' => 'cancelled'])->assertOk();
        $this->assertDatabaseMissing('products', ['id' => $deleted->id]);
        $this->assertDatabaseHas('order_items', ['product_id' => null, 'stock_released_at' => null]);
    }

    public function test_rental_completion_requires_end_date_and_completes_reservation(): void
    {
        $seller = $this->user();
        $buyer = $this->user();
        $rental = Product::factory()->for($seller, 'owner')->create(['type' => Product::TYPE_RENTAL]);
        $start = Carbon::today(config('app.timezone'))->addDay();
        $end = $start->copy()->addDay();
        $created = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload(['items' => [['id' => $rental->id, 'quantity' => 1, 'start_date' => $start->toDateString(), 'end_date' => $end->toDateString()]]]))->assertCreated();
        $fulfillmentId = $created->json('order.fulfillments.0.id');
        $this->advanceToReady($seller, $fulfillmentId);
        $this->actingAs($seller)->getJson('/api/seller/fulfillments?status=ready')
            ->assertOk()
            ->assertJsonPath('data.0.available_transitions', []);
        $this->actingAs($seller)->patchJson("/api/seller/fulfillments/{$fulfillmentId}/status", ['status' => 'completed'])->assertConflict();
        $reservation = RentalReservation::query()->where('order_id', $created->json('order_id'))->firstOrFail();
        $reservation->update(['end_date' => Carbon::today(config('app.timezone'))]);
        $this->actingAs($seller)->getJson('/api/seller/fulfillments?status=ready')
            ->assertOk()
            ->assertJsonPath('data.0.available_transitions', [OrderFulfillment::STATUS_COMPLETED]);
        $this->actingAs($seller)->patchJson("/api/seller/fulfillments/{$fulfillmentId}/status", ['status' => 'completed'])->assertOk()->assertJsonPath('fulfillment.status', 'completed');
        $this->assertDatabaseHas('rental_reservations', ['id' => $reservation->id, 'status' => RentalReservation::STATUS_COMPLETED]);
        $this->assertDatabaseHas('orders', ['id' => $created->json('order_id'), 'status' => 'fulfilled']);
    }

    public function test_buyer_rental_cancellation_aggregates_fulfillment_and_parent_status(): void
    {
        $seller = $this->user();
        $buyer = $this->user();
        $first = Product::factory()->for($seller, 'owner')->create(['type' => Product::TYPE_RENTAL]);
        $second = Product::factory()->for($seller, 'owner')->create(['type' => Product::TYPE_RENTAL]);
        $dates = ['start_date' => Carbon::today(config('app.timezone'))->addDays(3)->toDateString(), 'end_date' => Carbon::today(config('app.timezone'))->addDays(4)->toDateString()];
        $created = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload(['items' => [['id' => $first->id, 'quantity' => 1, ...$dates], ['id' => $second->id, 'quantity' => 1, ...$dates]]]))->assertCreated();
        $items = $created->json('order.items');
        $fulfillmentId = $created->json('order.fulfillments.0.id');

        $this->actingAs($buyer)->deleteJson("/api/orders/{$created->json('order_id')}/items/{$items[0]['id']}/rental")->assertOk();
        $this->assertDatabaseHas('order_fulfillments', ['id' => $fulfillmentId, 'status' => OrderFulfillment::STATUS_RECEIVED]);
        $this->assertDatabaseHas('orders', ['id' => $created->json('order_id'), 'status' => 'processing']);
        $this->actingAs($buyer)->deleteJson("/api/orders/{$created->json('order_id')}/items/{$items[1]['id']}/rental")->assertOk();
        $this->assertDatabaseHas('order_fulfillments', ['id' => $fulfillmentId, 'status' => OrderFulfillment::STATUS_CANCELLED]);
        $this->assertDatabaseHas('orders', ['id' => $created->json('order_id'), 'status' => 'cancelled']);
    }

    private function advanceToReady(User $seller, int $fulfillmentId): void
    {
        $path = "/api/seller/fulfillments/{$fulfillmentId}/status";
        $this->actingAs($seller)->patchJson($path, ['status' => 'accepted'])->assertOk();
        $this->actingAs($seller)->patchJson($path, ['status' => 'ready'])->assertOk();
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }
}
