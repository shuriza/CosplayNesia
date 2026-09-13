<?php

namespace Tests\Feature;

use App\Models\FulfillmentMessage;
use App\Models\OrderFulfillment;
use App\Models\Product;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FulfillmentMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_parties_converse_and_outsiders_are_refused(): void
    {
        ['buyer' => $buyer, 'seller' => $seller, 'fulfillment' => $fulfillmentId] = $this->order();
        $outsider = $this->user();
        $path = "/api/fulfillments/{$fulfillmentId}/messages";

        $this->actingAs($buyer)->postJson($path, ['body' => '  Bisa diambil hari Sabtu?  '])
            ->assertCreated()
            ->assertJsonPath('data.body', 'Bisa diambil hari Sabtu?')
            ->assertJsonPath('data.sender_role', FulfillmentMessage::ROLE_BUYER)
            ->assertJsonPath('data.is_mine', true);

        $this->actingAs($seller)->postJson($path, ['body' => 'Bisa, jam 10 siang.'])
            ->assertCreated()
            ->assertJsonPath('data.sender_role', FulfillmentMessage::ROLE_SELLER);

        // Each side sees the same two messages but with is_mine flipped.
        $buyerView = $this->actingAs($buyer)->getJson($path)->assertOk();
        $this->assertSame([false, true], collect($buyerView->json('data'))->pluck('is_mine')->all());
        $this->assertSame(FulfillmentMessage::ROLE_BUYER, $buyerView->json('viewer_role'));

        $sellerView = $this->actingAs($seller)->getJson($path)->assertOk();
        $this->assertSame([true, false], collect($sellerView->json('data'))->pluck('is_mine')->all());
        $this->assertSame(FulfillmentMessage::ROLE_SELLER, $sellerView->json('viewer_role'));

        $this->actingAs($outsider)->getJson($path)->assertForbidden();
        $this->actingAs($outsider)->postJson($path, ['body' => 'Halo'])->assertForbidden();
        $this->actingAs($outsider)->patchJson($path.'/read')->assertForbidden();
        $this->assertDatabaseCount('fulfillment_messages', 2);
    }

    public function test_a_rival_seller_on_the_same_order_cannot_read_the_thread(): void
    {
        $buyer = $this->user();
        $seller = $this->user();
        $rival = $this->user();
        $mine = Product::factory()->for($seller, 'owner')->create();
        $theirs = Product::factory()->for($rival, 'owner')->create();

        $created = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [['id' => $mine->id, 'quantity' => 1], ['id' => $theirs->id, 'quantity' => 1]],
        ]))->assertCreated();
        $fulfillments = collect($created->json('order.fulfillments'));
        $this->assertCount(2, $fulfillments);
        // seller_name is a product snapshot, not the account name, so match on the bound seller.
        $sellerThread = OrderFulfillment::query()
            ->where('order_id', $created->json('order_id'))
            ->where('seller_id', $seller->id)
            ->value('id');

        $this->actingAs($buyer)->postJson("/api/fulfillments/{$sellerThread}/messages", ['body' => 'Khusus penjual ini.'])->assertCreated();

        // Sharing an order is not participation: only the bound seller may read this thread.
        $this->actingAs($rival)->getJson("/api/fulfillments/{$sellerThread}/messages")->assertForbidden();
        $this->actingAs($seller)->getJson("/api/fulfillments/{$sellerThread}/messages")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_unread_count_tracks_counterpart_messages_and_clears_on_read(): void
    {
        ['buyer' => $buyer, 'seller' => $seller, 'fulfillment' => $fulfillmentId] = $this->order();
        $path = "/api/fulfillments/{$fulfillmentId}/messages";

        $this->actingAs($buyer)->postJson($path, ['body' => 'Pertanyaan satu.'])->assertCreated();
        $this->actingAs($buyer)->postJson($path, ['body' => 'Pertanyaan dua.'])->assertCreated();

        // A sender never accrues unread from their own messages.
        $this->actingAs($buyer)->getJson($path)->assertOk()->assertJsonPath('unread_count', 0);
        $this->actingAs($seller)->getJson($path)->assertOk()->assertJsonPath('unread_count', 2);

        $this->actingAs($seller)->patchJson($path.'/read')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);
        $this->actingAs($seller)->getJson($path)->assertOk()->assertJsonPath('unread_count', 0);

        // Replying marks the replier read and makes the buyer's side unread instead.
        $this->actingAs($seller)->postJson($path, ['body' => 'Jawaban.'])->assertCreated();
        $this->actingAs($seller)->getJson($path)->assertOk()->assertJsonPath('unread_count', 0);
        $this->actingAs($buyer)->getJson($path)->assertOk()->assertJsonPath('unread_count', 1);
    }

    public function test_unread_badges_surface_on_order_and_fulfillment_lists(): void
    {
        ['buyer' => $buyer, 'seller' => $seller, 'fulfillment' => $fulfillmentId, 'order' => $orderId] = $this->order();

        $this->actingAs($seller)->postJson("/api/fulfillments/{$fulfillmentId}/messages", ['body' => 'Kami siapkan.'])->assertCreated();

        $this->actingAs($buyer)->getJson('/api/orders')
            ->assertOk()
            ->assertJsonPath('data.0.fulfillments.0.unread_messages', 1);
        $this->actingAs($buyer)->getJson("/api/orders/{$orderId}")
            ->assertOk()
            ->assertJsonPath('fulfillments.0.unread_messages', 1);
        $this->actingAs($seller)->getJson('/api/seller/fulfillments')
            ->assertOk()
            ->assertJsonPath('data.0.unread_messages', 0);

        $this->actingAs($buyer)->postJson("/api/fulfillments/{$fulfillmentId}/messages", ['body' => 'Baik.'])->assertCreated();
        $this->actingAs($seller)->getJson('/api/seller/fulfillments')
            ->assertOk()
            ->assertJsonPath('data.0.unread_messages', 1);
        $this->actingAs($buyer)->getJson('/api/orders')
            ->assertOk()
            ->assertJsonPath('data.0.fulfillments.0.unread_messages', 0);
    }

    public function test_each_message_notifies_only_the_counterpart(): void
    {
        ['buyer' => $buyer, 'seller' => $seller, 'fulfillment' => $fulfillmentId] = $this->order();
        $path = "/api/fulfillments/{$fulfillmentId}/messages";
        UserNotification::query()->delete();

        $this->actingAs($buyer)->postJson($path, ['body' => 'Apakah warna sesuai foto?'])->assertCreated();
        $this->assertDatabaseHas('user_notifications', [
            'recipient_id' => $seller->id,
            'actor_id' => $buyer->id,
            'fulfillment_id' => $fulfillmentId,
            'type' => UserNotification::TYPE_MESSAGE_RECEIVED,
        ]);
        $this->assertSame(0, UserNotification::query()->where('recipient_id', $buyer->id)->count());

        $notification = UserNotification::query()->where('recipient_id', $seller->id)->firstOrFail();
        $this->assertSame(FulfillmentMessage::ROLE_BUYER, $notification->payload['sender_role']);
        $this->assertSame('Apakah warna sesuai foto?', $notification->payload['preview']);

        $this->actingAs($seller)->postJson($path, ['body' => 'Sesuai foto.'])->assertCreated();
        $this->assertSame(1, UserNotification::query()->where('recipient_id', $buyer->id)->count());
        $this->assertSame(1, UserNotification::query()->where('recipient_id', $seller->id)->count());

        $this->actingAs($seller)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Pesan baru pada pesanan');
    }

    public function test_cancelled_fulfillment_closes_the_thread_but_keeps_history(): void
    {
        ['buyer' => $buyer, 'seller' => $seller, 'fulfillment' => $fulfillmentId] = $this->order();
        $path = "/api/fulfillments/{$fulfillmentId}/messages";

        $this->actingAs($buyer)->postJson($path, ['body' => 'Sebelum dibatalkan.'])->assertCreated();
        $this->actingAs($seller)->patchJson("/api/seller/fulfillments/{$fulfillmentId}/status", [
            'status' => OrderFulfillment::STATUS_CANCELLED,
        ])->assertOk();

        $this->actingAs($buyer)->postJson($path, ['body' => 'Masih bisa?'])->assertConflict();
        $this->actingAs($seller)->postJson($path, ['body' => 'Masih bisa?'])->assertConflict();

        $this->actingAs($buyer)->getJson($path)
            ->assertOk()
            ->assertJsonPath('can_send', false)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'Sebelum dibatalkan.');
        $this->assertDatabaseCount('fulfillment_messages', 1);
    }

    public function test_message_body_is_required_bounded_and_messages_are_immutable(): void
    {
        ['buyer' => $buyer, 'fulfillment' => $fulfillmentId] = $this->order();
        $path = "/api/fulfillments/{$fulfillmentId}/messages";

        $this->actingAs($buyer)->postJson($path, ['body' => '   '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');
        $this->actingAs($buyer)->postJson($path, ['body' => str_repeat('a', FulfillmentMessage::MAX_BODY_LENGTH + 1)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');

        $this->actingAs($buyer)->postJson($path, ['body' => 'Asli.'])->assertCreated();
        $message = FulfillmentMessage::query()->firstOrFail();

        // A conversation is dispute evidence, so neither side may rewrite it.
        $this->expectException(\LogicException::class);
        $message->update(['body' => 'Diubah.']);
    }

    public function test_message_cannot_be_deleted(): void
    {
        ['buyer' => $buyer, 'fulfillment' => $fulfillmentId] = $this->order();
        $this->actingAs($buyer)->postJson("/api/fulfillments/{$fulfillmentId}/messages", ['body' => 'Tetap ada.'])->assertCreated();

        $this->expectException(\LogicException::class);
        FulfillmentMessage::query()->firstOrFail()->delete();
    }

    public function test_thread_is_cursor_paginated_newest_first(): void
    {
        ['buyer' => $buyer, 'fulfillment' => $fulfillmentId] = $this->order();
        $path = "/api/fulfillments/{$fulfillmentId}/messages";
        foreach (range(1, 4) as $index) {
            $this->actingAs($buyer)->postJson($path, ['body' => "Pesan {$index}"])->assertCreated();
        }

        $first = $this->actingAs($buyer)->getJson($path.'?per_page=2')->assertOk();
        $this->assertSame(['Pesan 4', 'Pesan 3'], collect($first->json('data'))->pluck('body')->all());
        $this->assertTrue($first->json('pagination.has_more'));

        $second = $this->actingAs($buyer)
            ->getJson($path.'?per_page=2&cursor='.$first->json('pagination.next_cursor'))
            ->assertOk();
        $this->assertSame(['Pesan 2', 'Pesan 1'], collect($second->json('data'))->pluck('body')->all());
        $this->assertFalse($second->json('pagination.has_more'));

        $this->actingAs($buyer)->getJson($path.'?per_page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_thread_endpoints_reject_guests(): void
    {
        ['fulfillment' => $fulfillmentId] = $this->order();

        $this->flushSession()->actingAsGuest();
        $this->getJson("/api/fulfillments/{$fulfillmentId}/messages")->assertUnauthorized();
        $this->postJson("/api/fulfillments/{$fulfillmentId}/messages", ['body' => 'Hai'])->assertUnauthorized();
        $this->patchJson("/api/fulfillments/{$fulfillmentId}/messages/read")->assertUnauthorized();
    }

    /**
     * @return array{buyer: User, seller: User, order: int, fulfillment: int}
     */
    private function order(): array
    {
        $seller = $this->user();
        $buyer = $this->user();
        $product = Product::factory()->for($seller, 'owner')->create(['stock' => 10]);
        $created = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [['id' => $product->id, 'quantity' => 1]],
        ]))->assertCreated();

        return [
            'buyer' => $buyer,
            'seller' => $seller,
            'order' => $created->json('order_id'),
            'fulfillment' => $created->json('order.fulfillments.0.id'),
        ];
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }
}
