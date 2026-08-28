<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderFulfillment;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class PaginationQueryBoundTest extends TestCase
{
    use RefreshDatabase;

    public function test_paginated_collection_query_counts_do_not_grow_with_page_size(): void
    {
        /** @var User $buyer */
        $buyer = User::factory()->create();
        /** @var User $seller */
        $seller = User::factory()->create();
        $products = Product::factory()->count(5)->for($seller, 'owner')->create();
        $buyer->favoriteProducts()->attach($products->pluck('id'));

        $products->each(function (Product $product) use ($buyer, $seller): void {
            $order = $buyer->orders()->create([
                'total_amount' => $product->price,
                'status' => Order::STATUS_FULFILLED,
            ]);
            $fulfillment = $order->fulfillments()->create([
                'seller_id' => $seller->id,
                'seller_name' => $seller->name,
                'status' => OrderFulfillment::STATUS_COMPLETED,
                'status_changed_at' => now(),
                'completed_at' => now(),
            ]);
            $item = OrderItem::create([
                'order_id' => $order->id,
                'fulfillment_id' => $fulfillment->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_type' => Product::TYPE_SALE,
                'unit_price' => $product->price,
                'quantity' => 1,
            ]);
            ProductReview::create([
                'order_item_id' => $item->id,
                'product_id' => $product->id,
                'user_id' => $buyer->id,
                'rating' => 5,
            ]);
        });

        $this->assertQueryCountIsPageSizeInvariant($buyer, '/api/products');
        $this->assertQueryCountIsPageSizeInvariant($seller, '/api/my-products');
        $this->assertQueryCountIsPageSizeInvariant($buyer, '/api/orders');
        $this->assertQueryCountIsPageSizeInvariant($seller, '/api/seller/fulfillments');
    }

    private function assertQueryCountIsPageSizeInvariant(User $user, string $path): void
    {
        $single = $this->selectQueryCount($user, $path.'?per_page=1');
        $full = $this->selectQueryCount($user, $path.'?per_page=5');

        $this->assertSame($single, $full, "SELECT query count grew with page size for {$path}.");
    }

    private function selectQueryCount(User $user, string $path): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($user)->getJson($path)->assertStatus(Response::HTTP_OK);

        $count = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_starts_with(strtolower(ltrim($query['query'])), 'select'))
            ->count();
        $aggregateCounts = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_starts_with(
                strtolower(ltrim($query['query'])),
                'select count(*) as aggregate from',
            ));
        DB::disableQueryLog();

        $this->assertCount(0, $aggregateCounts, "Exact total count query executed for {$path}.");

        return $count;
    }
}
