<?php

namespace Tests\Feature;

use App\Models\OrderFulfillment;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_buyer_can_review_completed_order_item_once(): void
    {
        $seller = $this->user();
        $buyer = $this->user();
        $intruder = $this->user();
        $product = Product::factory()->for($seller, 'owner')->create();
        $created = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [['id' => $product->id, 'quantity' => 1]],
        ]))->assertCreated();
        $orderId = $created->json('order_id');
        $itemId = $created->json('order.items.0.id');
        $fulfillmentId = $created->json('order.fulfillments.0.id');
        $path = "/api/orders/{$orderId}/items/{$itemId}/review";

        $this->actingAs($buyer)->postJson($path, ['rating' => 5])->assertConflict();
        $this->actingAs($intruder)->postJson($path, ['rating' => 5])->assertForbidden();
        $this->actingAs($buyer)->postJson($path, ['rating' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rating');

        $this->completeFulfillment($seller, $fulfillmentId);

        $this->actingAs($buyer)->postJson($path, ['rating' => 4])
            ->assertCreated()
            ->assertJsonPath('review.rating', 4)
            ->assertJsonPath('product.rating', 4)
            ->assertJsonPath('product.review_count', 1);
        $this->actingAs($buyer)->postJson($path, ['rating' => 2])->assertConflict();

        $this->assertDatabaseHas('product_reviews', [
            'order_item_id' => $itemId,
            'product_id' => $product->id,
            'user_id' => $buyer->id,
            'rating' => 4,
        ]);
        $this->assertDatabaseCount('product_reviews', 1);
    }

    public function test_catalog_and_order_payload_expose_verified_review_state(): void
    {
        $seller = $this->user();
        $product = Product::factory()->for($seller, 'owner')->create(['name' => 'Verified Costume']);
        $firstBuyer = $this->user();
        $secondBuyer = $this->user();

        $first = $this->completedOrder($firstBuyer, $seller, $product);
        $second = $this->completedOrder($secondBuyer, $seller, $product);

        $this->actingAs($firstBuyer)->getJson("/api/orders/{$first['order_id']}")
            ->assertOk()
            ->assertJsonPath('items.0.can_review', true)
            ->assertJsonPath('items.0.review', null);

        $this->actingAs($firstBuyer)->postJson($first['review_path'], ['rating' => 5])->assertCreated();
        $this->actingAs($secondBuyer)->postJson($second['review_path'], ['rating' => 3])->assertCreated();

        $this->getJson('/api/products?q=Verified%20Costume')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.rating', 4)
            ->assertJsonPath('data.0.review_count', 2);
        $this->actingAs($firstBuyer)->getJson("/api/orders/{$first['order_id']}")
            ->assertOk()
            ->assertJsonPath('items.0.can_review', false)
            ->assertJsonPath('items.0.review.rating', 5);
    }

    public function test_unassigned_cancelled_and_deleted_products_cannot_receive_new_reviews(): void
    {
        $seller = $this->user();
        $buyer = $this->user();

        $cancelledProduct = Product::factory()->for($seller, 'owner')->create();
        $cancelled = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [['id' => $cancelledProduct->id, 'quantity' => 1]],
        ]))->assertCreated();
        $this->actingAs($seller)->patchJson('/api/seller/fulfillments/'.$cancelled->json('order.fulfillments.0.id').'/status', [
            'status' => OrderFulfillment::STATUS_CANCELLED,
        ])->assertOk();
        $this->actingAs($buyer)->postJson(
            '/api/orders/'.$cancelled->json('order_id').'/items/'.$cancelled->json('order.items.0.id').'/review',
            ['rating' => 5],
        )->assertConflict();

        $deletedProduct = Product::factory()->for($seller, 'owner')->create();
        $completed = $this->completedOrder($buyer, $seller, $deletedProduct);
        $deletedProduct->delete();
        $this->actingAs($buyer)->postJson($completed['review_path'], ['rating' => 5])->assertConflict();

        $legacy = Product::factory()->create(['seller_id' => null]);
        $unassigned = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [['id' => $legacy->id, 'quantity' => 1]],
        ]))->assertCreated();
        $this->actingAs($buyer)->postJson(
            '/api/orders/'.$unassigned->json('order_id').'/items/'.$unassigned->json('order.items.0.id').'/review',
            ['rating' => 5],
        )->assertConflict();

        $this->assertDatabaseCount('product_reviews', 0);
    }

    public function test_cancelled_rental_stays_unreviewable_when_mixed_fulfillment_completes(): void
    {
        $seller = $this->user();
        $buyer = $this->user();
        $sale = Product::factory()->for($seller, 'owner')->create(['type' => Product::TYPE_SALE]);
        $rental = Product::factory()->for($seller, 'owner')->create(['type' => Product::TYPE_RENTAL]);
        $start = Carbon::today(config('app.timezone'))->addDays(2);

        $created = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [
                ['id' => $sale->id, 'quantity' => 1],
                [
                    'id' => $rental->id,
                    'quantity' => 1,
                    'start_date' => $start->toDateString(),
                    'end_date' => $start->copy()->addDay()->toDateString(),
                ],
            ],
        ]))->assertCreated();
        $items = collect($created->json('order.items'));
        $saleItem = $items->firstWhere('product_id', $sale->id);
        $rentalItem = $items->firstWhere('product_id', $rental->id);
        $orderId = $created->json('order_id');

        $this->actingAs($buyer)
            ->deleteJson("/api/orders/{$orderId}/items/{$rentalItem['id']}/rental")
            ->assertOk();
        $this->completeFulfillment($seller, $created->json('order.fulfillments.0.id'));

        $this->actingAs($buyer)
            ->postJson("/api/orders/{$orderId}/items/{$rentalItem['id']}/review", ['rating' => 5])
            ->assertConflict();
        $detail = $this->actingAs($buyer)->getJson("/api/orders/{$orderId}")->assertOk();
        $detailItems = collect($detail->json('items'));
        $this->assertTrue($detailItems->firstWhere('id', $saleItem['id'])['can_review']);
        $this->assertFalse($detailItems->firstWhere('id', $rentalItem['id'])['can_review']);
        $this->actingAs($buyer)
            ->postJson("/api/orders/{$orderId}/items/{$saleItem['id']}/review", ['rating' => 5])
            ->assertCreated();

        $this->assertDatabaseCount('product_reviews', 1);
    }

    public function test_completed_review_survives_product_deletion_on_order_snapshot(): void
    {
        $seller = $this->user();
        $buyer = $this->user();
        $product = Product::factory()->for($seller, 'owner')->create();
        $completed = $this->completedOrder($buyer, $seller, $product);

        $this->actingAs($buyer)->postJson($completed['review_path'], ['rating' => 5])->assertCreated();
        $product->delete();

        $this->assertDatabaseHas('product_reviews', [
            'order_item_id' => $completed['item_id'],
            'product_id' => null,
            'rating' => 5,
        ]);
        $this->actingAs($buyer)->getJson("/api/orders/{$completed['order_id']}")
            ->assertOk()
            ->assertJsonPath('items.0.can_review', false)
            ->assertJsonPath('items.0.review.rating', 5);
    }

    private function completedOrder(User $buyer, User $seller, Product $product): array
    {
        $created = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [['id' => $product->id, 'quantity' => 1]],
        ]))->assertCreated();
        $orderId = $created->json('order_id');
        $itemId = $created->json('order.items.0.id');
        $this->completeFulfillment($seller, $created->json('order.fulfillments.0.id'));

        return [
            'order_id' => $orderId,
            'item_id' => $itemId,
            'review_path' => "/api/orders/{$orderId}/items/{$itemId}/review",
        ];
    }

    private function completeFulfillment(User $seller, int $fulfillmentId): void
    {
        $path = "/api/seller/fulfillments/{$fulfillmentId}/status";
        $this->actingAs($seller)->patchJson($path, ['status' => OrderFulfillment::STATUS_ACCEPTED])->assertOk();
        $this->actingAs($seller)->patchJson($path, ['status' => OrderFulfillment::STATUS_READY])->assertOk();
        $this->actingAs($seller)->patchJson($path, ['status' => OrderFulfillment::STATUS_COMPLETED])->assertOk();
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }
}
