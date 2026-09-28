<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutFeeSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_snapshots_known_fee_components_without_inventing_shipping_cost(): void
    {
        /** @var User $buyer */
        $buyer = User::factory()->create();
        $product = Product::factory()->create([
            'type' => Product::TYPE_RENTAL,
            'price' => 150000,
            'rental_price_per_day' => 150000,
            'rental_deposit' => 200000,
            'stock' => 2,
        ]);
        $variant = $product->variants()->firstOrFail();

        $order = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [[
                'id' => $product->id,
                'variant_id' => $variant->id,
                'quantity' => 1,
                'start_date' => now()->addDays(2)->toDateString(),
                'end_date' => now()->addDays(3)->toDateString(),
            ]],
        ]))->assertCreated()->json('order');

        $this->assertSame(150000, $order['merchandise_subtotal']);
        $this->assertSame(200000, $order['rental_deposit']);
        $this->assertSame(0, $order['platform_fee']);
        $this->assertNull($order['shipping_fee']);
        $this->assertNull($order['grand_total']);
        $this->assertSame('IDR', $order['fee_currency']);

        $product->update(['price' => 999999, 'rental_deposit' => 999999]);
        $this->assertDatabaseHas('orders', [
            'id' => $order['id'],
            'merchandise_subtotal' => 150000,
            'rental_deposit' => 200000,
        ]);
    }
}
