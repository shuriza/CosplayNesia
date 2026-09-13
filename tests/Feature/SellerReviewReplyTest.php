<?php

namespace Tests\Feature;

use App\Models\OrderFulfillment;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellerReviewReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_owning_seller_can_reply_and_reply_reaches_buyer_and_public_feed(): void
    {
        $seller = $this->user();
        $intruder = $this->user();
        $buyer = $this->user(['name' => 'Umi Kartika']);
        $product = Product::factory()->for($seller, 'owner')->create();
        $review = $this->review($buyer, $seller, $product, 4, 'Nyaman dipakai seharian.');

        $path = "/api/seller/reviews/{$review['id']}/reply";
        $this->actingAs($intruder)->patchJson($path, ['reply' => 'Bukan produk saya.'])->assertForbidden();
        $this->actingAs($seller)->patchJson($path, ['reply' => '   '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reply');
        $this->actingAs($seller)->patchJson($path, ['reply' => str_repeat('a', ProductReview::MAX_REPLY_LENGTH + 1)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reply');

        $this->actingAs($seller)->patchJson($path, ['reply' => '  Terima kasih, sampai jumpa di event berikutnya.  '])
            ->assertOk()
            ->assertJsonPath('review.seller_reply', 'Terima kasih, sampai jumpa di event berikutnya.');

        $stored = ProductReview::query()->findOrFail($review['id']);
        $this->assertNotNull($stored->seller_replied_at);

        $this->getJson("/api/products/{$product->id}/reviews")
            ->assertOk()
            ->assertJsonPath('data.0.seller_reply', 'Terima kasih, sampai jumpa di event berikutnya.');

        $this->actingAs($buyer)->getJson("/api/orders/{$review['order_id']}")
            ->assertOk()
            ->assertJsonPath('items.0.review.seller_reply', 'Terima kasih, sampai jumpa di event berikutnya.');
    }

    public function test_reply_can_be_updated_and_removed_without_touching_the_rating(): void
    {
        $seller = $this->user();
        $buyer = $this->user();
        $product = Product::factory()->for($seller, 'owner')->create();
        $review = $this->review($buyer, $seller, $product, 3, 'Warna agak berbeda.');
        $path = "/api/seller/reviews/{$review['id']}/reply";

        $this->actingAs($seller)->patchJson($path, ['reply' => 'Kami cek pencahayaan foto.'])->assertOk();
        $this->actingAs($seller)->patchJson($path, ['reply' => 'Foto katalog sudah diperbarui.'])
            ->assertOk()
            ->assertJsonPath('review.seller_reply', 'Foto katalog sudah diperbarui.')
            ->assertJsonPath('review.rating', 3);

        $this->actingAs($this->user())->deleteJson($path)->assertForbidden();
        $this->actingAs($seller)->deleteJson($path)
            ->assertOk()
            ->assertJsonPath('review.seller_reply', null)
            ->assertJsonPath('review.seller_replied_at', null);

        $this->assertDatabaseHas('product_reviews', [
            'id' => $review['id'],
            'rating' => 3,
            'body' => 'Warna agak berbeda.',
            'seller_reply' => null,
            'seller_replied_at' => null,
        ]);
        $this->getJson("/api/products/{$product->id}/reviews")
            ->assertOk()
            ->assertJsonPath('data.0.seller_reply', null);
    }

    public function test_inbox_is_scoped_to_owner_filterable_and_survives_product_deletion(): void
    {
        $seller = $this->user();
        $rival = $this->user();
        $product = Product::factory()->for($seller, 'owner')->create(['stock' => 10, 'name' => 'Kimono Seller']);
        $rivalProduct = Product::factory()->for($rival, 'owner')->create(['name' => 'Kimono Rival']);

        $answered = $this->review($this->user(), $seller, $product, 5, 'Mantap.');
        $this->review($this->user(), $seller, $product, 2, 'Kurang sesuai.');
        $this->review($this->user(), $rival, $rivalProduct, 4, 'Bagus juga.');

        $this->actingAs($seller)->patchJson("/api/seller/reviews/{$answered['id']}/reply", ['reply' => 'Terima kasih!'])->assertOk();

        $inbox = $this->actingAs($seller)->getJson('/api/seller/reviews')->assertOk();
        $this->assertCount(2, $inbox->json('data'));
        $this->assertSame(['Kimono Seller', 'Kimono Seller'], collect($inbox->json('data'))->pluck('product_name')->all());

        $unanswered = $this->actingAs($seller)->getJson('/api/seller/reviews?unanswered=1')->assertOk();
        $this->assertCount(1, $unanswered->json('data'));
        $this->assertSame(2, $unanswered->json('data.0.rating'));

        $this->actingAs($rival)->getJson('/api/seller/reviews')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product_name', 'Kimono Rival');

        // The seller snapshot keeps inbox access after the listing is gone.
        $product->delete();
        $after = $this->actingAs($seller)->getJson('/api/seller/reviews')->assertOk();
        $this->assertCount(2, $after->json('data'));
        $this->assertSame([null, null], collect($after->json('data'))->pluck('product_id')->all());
        $this->assertSame('Kimono Seller', $after->json('data.0.product_name'));
    }

    public function test_inbox_is_cursor_paginated_and_never_exposes_buyer_identity(): void
    {
        $seller = $this->user();
        $product = Product::factory()->for($seller, 'owner')->create(['stock' => 10]);
        foreach (['Dewi Ayu Lestari', 'Bagus', 'Rina Melati'] as $name) {
            $this->review($this->user(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@example.test']), $seller, $product, 5, 'Oke.');
        }

        $first = $this->actingAs($seller)->getJson('/api/seller/reviews?per_page=2')->assertOk();
        $this->assertCount(2, $first->json('data'));
        $this->assertTrue($first->json('pagination.has_more'));

        $second = $this->actingAs($seller)
            ->getJson('/api/seller/reviews?per_page=2&cursor='.$first->json('pagination.next_cursor'))
            ->assertOk();
        $this->assertCount(1, $second->json('data'));
        $this->assertFalse($second->json('pagination.has_more'));

        $labels = collect($first->json('data'))->concat($second->json('data'))->pluck('reviewer_label');
        $this->assertSame(['Rina M.', 'Bagus', 'Dewi L.'], $labels->all());

        $payload = $first->json('data.0');
        $this->assertSame(
            ['id', 'product_id', 'product_name', 'rating', 'body', 'reviewer_label', 'seller_reply', 'seller_replied_at', 'created_at'],
            array_keys($payload),
        );
        $this->assertStringNotContainsString('@example.test', json_encode($first->json()));

        $this->actingAs($seller)->getJson('/api/seller/reviews?per_page=99')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_reply_endpoints_reject_guests(): void
    {
        $seller = $this->user();
        $product = Product::factory()->for($seller, 'owner')->create();
        $review = $this->review($this->user(), $seller, $product, 5, 'Bagus.');

        $this->flushSession()->actingAsGuest();
        $this->getJson('/api/seller/reviews')->assertUnauthorized();
        $this->patchJson("/api/seller/reviews/{$review['id']}/reply", ['reply' => 'Hai'])->assertUnauthorized();
        $this->deleteJson("/api/seller/reviews/{$review['id']}/reply")->assertUnauthorized();
    }

    private function review(User $buyer, User $seller, Product $product, int $rating, ?string $body): array
    {
        $created = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [['id' => $product->id, 'quantity' => 1]],
        ]))->assertCreated();
        $orderId = $created->json('order_id');
        $itemId = $created->json('order.items.0.id');
        $statusPath = '/api/seller/fulfillments/'.$created->json('order.fulfillments.0.id').'/status';

        foreach ([OrderFulfillment::STATUS_ACCEPTED, OrderFulfillment::STATUS_READY, OrderFulfillment::STATUS_COMPLETED] as $status) {
            $this->actingAs($seller)->patchJson($statusPath, ['status' => $status])->assertOk();
        }

        $review = $this->actingAs($buyer)
            ->postJson("/api/orders/{$orderId}/items/{$itemId}/review", ['rating' => $rating, 'body' => $body])
            ->assertCreated();

        return ['id' => $review->json('review.id'), 'order_id' => $orderId, 'item_id' => $itemId];
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }
}
