<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\User;
use App\Services\CheckoutService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_uses_database_price_and_creates_order_history(): void
    {
        $user = $this->user();
        $product = Product::factory()->create(['name' => 'Furina Set', 'price' => 145000, 'stock' => 3]);

        $this->actingAs($user)->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [['id' => $product->id, 'quantity' => 2, 'price' => 1]],
        ]))->assertCreated()->assertJsonPath('order.total_amount', 290000);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 1]);
        $this->assertDatabaseHas('order_items', ['product_id' => $product->id, 'unit_price' => 145000, 'quantity' => 2]);
        $this->actingAs($user)->getJson('/api/orders')
            ->assertOk()
            ->assertJsonPath('data.0.items.0.name', 'Furina Set')
            ->assertJsonPath('data.0.items.0.price', 145000);
    }

    public function test_duplicate_products_are_rejected(): void
    {
        $product = Product::factory()->create(['stock' => 5]);

        $this->actingAs($this->user())->postJson('/api/checkout', $this->checkoutPayload(['items' => [
            ['id' => $product->id, 'quantity' => 1],
            ['id' => $product->id, 'quantity' => 1],
        ]]))->assertUnprocessable()->assertJsonValidationErrors('items.0.id');

        $this->assertSame(5, $product->fresh()->stock);
    }

    public function test_insufficient_stock_rolls_back_the_entire_checkout(): void
    {
        $available = Product::factory()->create(['stock' => 2]);
        $unavailable = Product::factory()->create(['name' => 'Stok Tipis', 'stock' => 0]);

        $this->actingAs($this->user())->postJson('/api/checkout', $this->checkoutPayload(['items' => [
            ['id' => $available->id, 'quantity' => 1],
            ['id' => $unavailable->id, 'quantity' => 1],
        ]]))->assertConflict()->assertJsonPath('message', 'Stok Stok Tipis tidak mencukupi.');

        $this->assertSame(2, $available->fresh()->stock);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_checkout_rejects_owned_products_and_rolls_back_mixed_basket(): void
    {
        $buyer = $this->user();
        $external = Product::factory()->create(['stock' => 3]);
        $owned = Product::factory()->for($buyer, 'owner')->create([
            'name' => 'Listing Sendiri',
            'stock' => 2,
        ]);

        $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload(['items' => [
            ['id' => $external->id, 'quantity' => 1],
            ['id' => $owned->id, 'quantity' => 1],
        ]]))->assertConflict()->assertJsonPath('message', 'Produk milik sendiri tidak dapat dibeli atau disewa.');

        $this->assertSame(3, $external->fresh()->stock);
        $this->assertSame(2, $owned->fresh()->stock);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('order_fulfillments', 0);
    }

    public function test_orders_are_isolated_per_user(): void
    {
        $buyer = $this->user();
        $other = $this->user();
        $product = Product::factory()->create(['stock' => 1]);
        $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload(['items' => [['id' => $product->id, 'quantity' => 1]]]));

        $this->actingAs($other)->getJson('/api/orders')->assertOk()->assertJsonPath('data', [])->assertJsonMissingPath('pagination.total');
    }

    public function test_order_history_cursor_is_stable_bounded_and_keeps_detail_contract(): void
    {
        $buyer = $this->user();
        $other = $this->user();
        $timestamp = CarbonImmutable::parse('2026-08-28 09:00:00');

        $orders = collect(range(1, 7))->map(function (int $index) use ($buyer, $timestamp) {
            $order = $buyer->orders()->create([
                'total_amount' => $index * 1000,
                'status' => 'demo_confirmed',
            ]);
            $order->forceFill(['created_at' => $timestamp, 'updated_at' => $timestamp])->save();

            return $order;
        });
        $other->orders()->create(['total_amount' => 999000, 'status' => 'demo_confirmed']);

        $first = $this->actingAs($buyer)->getJson('/api/orders?per_page=3')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('pagination.per_page', 3)
            ->assertJsonPath('pagination.has_more', true)
            ->assertJsonMissingPath('pagination.total');
        $firstIds = collect($first->json('data'))->pluck('id');

        $second = $this->actingAs($buyer)->getJson('/api/orders?per_page=3&cursor='.urlencode($first->json('pagination.next_cursor')))
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('pagination.has_more', true);
        $secondIds = collect($second->json('data'))->pluck('id');

        $this->assertCount(0, $firstIds->intersect($secondIds));
        $this->assertSame($orders->sortByDesc('id')->pluck('id')->take(6)->values()->all(), $firstIds->merge($secondIds)->all());
        $this->actingAs($buyer)->getJson('/api/orders?per_page=21')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->actingAs($buyer)->getJson('/api/orders/'.$orders->last()->id)
            ->assertOk()
            ->assertJsonPath('id', $orders->last()->id)
            ->assertJsonMissingPath('data');
    }

    public function test_inactive_products_cannot_be_checked_out(): void
    {
        $product = Product::factory()->create(['is_active' => false, 'stock' => 2]);

        $this->actingAs($this->user())->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [['id' => $product->id, 'quantity' => 1]],
        ]))->assertUnprocessable()->assertJsonValidationErrors('items.0.id');

        $this->assertSame(2, $product->fresh()->stock);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_transactional_checkout_invariant_rejects_a_product_deactivated_after_validation(): void
    {
        $user = $this->user();
        $product = Product::factory()->create(['is_active' => false, 'stock' => 2]);

        try {
            app(CheckoutService::class)->create($user, [['id' => $product->id, 'quantity' => 1]]);
            $this->fail('Inactive product was accepted by the checkout service.');
        } catch (InsufficientStockException) {
            $this->assertSame(2, $product->fresh()->stock);
            $this->assertDatabaseCount('orders', 0);
        }
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }
}
