<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_items_merge_with_server_cart_and_newer_client_selection_wins(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $first = Product::factory()->create(['stock' => 3]);
        $second = Product::factory()->create(['stock' => 2]);
        $firstVariant = $first->variants()->firstOrFail();
        $secondVariant = $second->variants()->firstOrFail();
        $old = CarbonImmutable::parse('2026-09-20 10:00:00');
        $new = $old->addHour();

        $user->cartItems()->create([
            'product_id' => $first->id,
            'product_variant_id' => $firstVariant->id,
            'quantity' => 1,
            'client_updated_at' => $old,
        ]);

        $this->actingAs($user)->putJson('/api/cart', ['items' => [
            [
                'product_id' => $first->id,
                'product_variant_id' => $firstVariant->id,
                'quantity' => 2,
                'client_updated_at' => $new->toISOString(),
            ],
            [
                'product_id' => $second->id,
                'product_variant_id' => $secondVariant->id,
                'quantity' => 1,
                'client_updated_at' => $new->toISOString(),
            ],
        ]])->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.quantity', 2)
            ->assertJsonPath('items.0.product.selected_variant_id', $firstVariant->id);

        $this->assertDatabaseHas('cart_items', [
            'user_id' => $user->id,
            'product_variant_id' => $firstVariant->id,
            'quantity' => 2,
        ]);
    }

    public function test_replace_removes_absent_items_and_unavailable_items_are_not_restored(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $kept = Product::factory()->create();
        $removed = Product::factory()->create();
        $unavailable = Product::factory()->create(['status' => Product::STATUS_PAUSED]);
        foreach ([$kept, $removed] as $product) {
            $user->cartItems()->create([
                'product_id' => $product->id,
                'product_variant_id' => $product->variants()->value('id'),
                'quantity' => 1,
            ]);
        }

        $response = $this->actingAs($user)->putJson('/api/cart', [
            'replace' => true,
            'items' => [
                [
                    'product_id' => $kept->id,
                    'product_variant_id' => $kept->variants()->value('id'),
                    'quantity' => 1,
                ],
                [
                    'product_id' => $unavailable->id,
                    'product_variant_id' => $unavailable->variants()->value('id'),
                    'quantity' => 1,
                ],
            ],
        ])->assertOk()->assertJsonCount(1, 'items');

        $this->assertSame($kept->id, $response->json('items.0.product_id'));
        $this->assertDatabaseMissing('cart_items', ['user_id' => $user->id, 'product_id' => $removed->id]);
        $this->assertDatabaseMissing('cart_items', ['user_id' => $user->id, 'product_id' => $unavailable->id]);
    }

    public function test_checkout_removes_purchased_skus_from_persistent_cart(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 2]);
        $variant = $product->variants()->firstOrFail();
        $user->cartItems()->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
        ]);

        $this->actingAs($user)->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [[
                'id' => $product->id,
                'variant_id' => $variant->id,
                'quantity' => 1,
            ]],
        ]))->assertCreated();

        $this->assertDatabaseMissing('cart_items', [
            'user_id' => $user->id,
            'product_variant_id' => $variant->id,
        ]);
    }
}
