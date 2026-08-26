<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\User;
use App\Services\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_uses_database_price_and_creates_order_history(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['name' => 'Furina Set', 'price' => 145000, 'stock' => 3]);

        $this->actingAs($user)->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [['id' => $product->id, 'quantity' => 2, 'price' => 1]],
        ]))->assertCreated()->assertJsonPath('order.total_amount', 290000);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 1]);
        $this->assertDatabaseHas('order_items', ['product_id' => $product->id, 'unit_price' => 145000, 'quantity' => 2]);
        $this->actingAs($user)->getJson('/api/orders')
            ->assertOk()
            ->assertJsonPath('0.items.0.name', 'Furina Set')
            ->assertJsonPath('0.items.0.price', 145000);
    }

    public function test_duplicate_products_are_rejected(): void
    {
        $product = Product::factory()->create(['stock' => 5]);

        $this->actingAs(User::factory()->create())->postJson('/api/checkout', $this->checkoutPayload(['items' => [
            ['id' => $product->id, 'quantity' => 1],
            ['id' => $product->id, 'quantity' => 1],
        ]]))->assertUnprocessable()->assertJsonValidationErrors('items.0.id');

        $this->assertSame(5, $product->fresh()->stock);
    }

    public function test_insufficient_stock_rolls_back_the_entire_checkout(): void
    {
        $available = Product::factory()->create(['stock' => 2]);
        $unavailable = Product::factory()->create(['name' => 'Stok Tipis', 'stock' => 0]);

        $this->actingAs(User::factory()->create())->postJson('/api/checkout', $this->checkoutPayload(['items' => [
            ['id' => $available->id, 'quantity' => 1],
            ['id' => $unavailable->id, 'quantity' => 1],
        ]]))->assertConflict()->assertJsonPath('message', 'Stok Stok Tipis tidak mencukupi.');

        $this->assertSame(2, $available->fresh()->stock);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_orders_are_isolated_per_user(): void
    {
        $buyer = User::factory()->create();
        $other = User::factory()->create();
        $product = Product::factory()->create(['stock' => 1]);
        $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload(['items' => [['id' => $product->id, 'quantity' => 1]]]));

        $this->actingAs($other)->getJson('/api/orders')->assertOk()->assertExactJson([]);
    }

    public function test_inactive_products_cannot_be_checked_out(): void
    {
        $product = Product::factory()->create(['is_active' => false, 'stock' => 2]);

        $this->actingAs(User::factory()->create())->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [['id' => $product->id, 'quantity' => 1]],
        ]))->assertUnprocessable()->assertJsonValidationErrors('items.0.id');

        $this->assertSame(2, $product->fresh()->stock);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_transactional_checkout_invariant_rejects_a_product_deactivated_after_validation(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['is_active' => false, 'stock' => 2]);

        try {
            app(CheckoutService::class)->create($user, [['id' => $product->id, 'quantity' => 1]]);
            $this->fail('Inactive product was accepted by the checkout service.');
        } catch (InsufficientStockException) {
            $this->assertSame(2, $product->fresh()->stock);
            $this->assertDatabaseCount('orders', 0);
        }
    }
}
