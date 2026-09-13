<?php

namespace Tests\Feature;

use App\Models\OrderFulfillment;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductReviewFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_feed_exposes_bodies_masked_reviewers_and_full_distribution(): void
    {
        $seller = $this->user();
        $product = Product::factory()->for($seller, 'owner')->create(['stock' => 10]);

        $this->review($this->user(['name' => 'Dewi Ayu Lestari']), $seller, $product, 5, '  Kualitas jahitan rapi dan wig tidak kusut.  ');
        $this->review($this->user(['name' => 'Bagus']), $seller, $product, 4, null);

        $response = $this->getJson("/api/products/{$product->id}/reviews")->assertOk();

        $this->assertSame(2, $response->json('summary.review_count'));
        $this->assertSame(4.5, $response->json('summary.rating'));
        $this->assertSame(
            ['1' => 0, '2' => 0, '3' => 0, '4' => 1, '5' => 1],
            $response->json('summary.distribution'),
        );

        $entries = collect($response->json('data'));
        $this->assertSame([4, 5], $entries->pluck('rating')->all());
        $this->assertSame('Bagus', $entries[0]['reviewer_label']);
        $this->assertSame('Dewi L.', $entries[1]['reviewer_label']);
        $this->assertSame('Kualitas jahitan rapi dan wig tidak kusut.', $entries[1]['body']);
        $this->assertNull($entries[0]['body']);
        $this->assertSame(
            ['id', 'rating', 'body', 'reviewer_label', 'seller_reply', 'seller_replied_at', 'created_at'],
            array_keys($entries[0]),
        );
    }

    public function test_feed_is_cursor_paginated_and_bounded_by_page_size(): void
    {
        $seller = $this->user();
        $product = Product::factory()->for($seller, 'owner')->create(['stock' => 10]);
        foreach (range(1, 4) as $index) {
            $this->review($this->user(), $seller, $product, 5, "Ulasan {$index}");
        }

        $first = $this->getJson("/api/products/{$product->id}/reviews?per_page=2")->assertOk();
        $this->assertCount(2, $first->json('data'));
        $this->assertTrue($first->json('pagination.has_more'));

        $second = $this->getJson("/api/products/{$product->id}/reviews?per_page=2&cursor=".$first->json('pagination.next_cursor'))
            ->assertOk();
        $this->assertCount(2, $second->json('data'));
        $this->assertFalse($second->json('pagination.has_more'));

        $ids = array_merge(
            collect($first->json('data'))->pluck('id')->all(),
            collect($second->json('data'))->pluck('id')->all(),
        );
        $this->assertCount(4, array_unique($ids));
        $this->assertSame(4, $second->json('summary.review_count'));

        $this->getJson("/api/products/{$product->id}/reviews?per_page=0")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_feed_hides_inactive_listings_and_survives_deleted_products(): void
    {
        $seller = $this->user();
        $buyer = $this->user();
        $inactive = Product::factory()->for($seller, 'owner')->create(['is_active' => false]);
        $this->getJson("/api/products/{$inactive->id}/reviews")->assertNotFound();

        $deleted = Product::factory()->for($seller, 'owner')->create();
        $this->review($buyer, $seller, $deleted, 4, 'Aman untuk event outdoor.');
        $deleted->delete();

        $this->getJson("/api/products/{$deleted->id}/reviews")->assertNotFound();
        $this->assertDatabaseHas('product_reviews', [
            'product_id' => null,
            'body' => 'Aman untuk event outdoor.',
        ]);
    }

    public function test_review_body_is_optional_persisted_and_length_bounded(): void
    {
        $seller = $this->user();
        $buyer = $this->user();
        $product = Product::factory()->for($seller, 'owner')->create();
        $completed = $this->completedOrder($buyer, $seller, $product);

        $this->actingAs($buyer)->postJson($completed['review_path'], [
            'rating' => 5,
            'body' => str_repeat('a', ProductReview::MAX_BODY_LENGTH + 1),
        ])->assertUnprocessable()->assertJsonValidationErrors('body');

        $this->actingAs($buyer)->postJson($completed['review_path'], [
            'rating' => 5,
            'body' => '   Sesuai deskripsi.   ',
        ])->assertCreated()->assertJsonPath('review.body', 'Sesuai deskripsi.');

        $this->actingAs($buyer)->getJson("/api/orders/{$completed['order_id']}")
            ->assertOk()
            ->assertJsonPath('items.0.review.body', 'Sesuai deskripsi.');
    }

    public function test_blank_body_is_stored_as_null(): void
    {
        $seller = $this->user();
        $buyer = $this->user();
        $product = Product::factory()->for($seller, 'owner')->create();
        $completed = $this->completedOrder($buyer, $seller, $product);

        $this->actingAs($buyer)->postJson($completed['review_path'], ['rating' => 4, 'body' => '   '])
            ->assertCreated()
            ->assertJsonPath('review.body', null);

        $this->assertDatabaseHas('product_reviews', [
            'order_item_id' => $completed['item_id'],
            'body' => null,
        ]);
    }

    private function review(User $buyer, User $seller, Product $product, int $rating, ?string $body): void
    {
        $completed = $this->completedOrder($buyer, $seller, $product);
        $this->actingAs($buyer)
            ->postJson($completed['review_path'], ['rating' => $rating, 'body' => $body])
            ->assertCreated();
    }

    private function completedOrder(User $buyer, User $seller, Product $product): array
    {
        $created = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [['id' => $product->id, 'quantity' => 1]],
        ]))->assertCreated();
        $orderId = $created->json('order_id');
        $itemId = $created->json('order.items.0.id');
        $path = '/api/seller/fulfillments/'.$created->json('order.fulfillments.0.id').'/status';

        foreach ([OrderFulfillment::STATUS_ACCEPTED, OrderFulfillment::STATUS_READY, OrderFulfillment::STATUS_COMPLETED] as $status) {
            $this->actingAs($seller)->patchJson($path, ['status' => $status])->assertOk();
        }

        return [
            'order_id' => $orderId,
            'item_id' => $itemId,
            'review_path' => "/api/orders/{$orderId}/items/{$itemId}/review",
        ];
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }
}
