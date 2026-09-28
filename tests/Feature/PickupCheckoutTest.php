<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PickupCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_snapshots_seller_enabled_pickup_handoff(): void
    {
        /** @var User $buyer */
        $buyer = User::factory()->create();
        $product = Product::factory()->create();
        $product->store()->update([
            'pickup_enabled' => true,
            'pickup_instructions' => 'Tunjukkan kode pesanan.',
            'city' => 'Bandung',
        ]);

        $order = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload([
            'pickup_seller_ids' => [$product->seller_id],
            'items' => [[
                'id' => $product->id,
                'variant_id' => $product->variants()->value('id'),
                'quantity' => 1,
            ]],
        ]))->assertCreated()->json('order');

        $this->assertSame('pickup', $order['fulfillments'][0]['handoff_method']);
        $this->assertSame('Tunjukkan kode pesanan.', $order['fulfillments'][0]['pickup_instructions']);
        $this->assertSame('Bandung', $order['fulfillments'][0]['pickup_city']);
    }

    public function test_checkout_rejects_pickup_when_store_did_not_enable_it(): void
    {
        /** @var User $buyer */
        $buyer = User::factory()->create();
        $product = Product::factory()->create();

        $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload([
            'pickup_seller_ids' => [$product->seller_id],
            'items' => [[
                'id' => $product->id,
                'variant_id' => $product->variants()->value('id'),
                'quantity' => 1,
            ]],
        ]))->assertUnprocessable()->assertJsonValidationErrors('pickup_seller_ids');
    }
}
