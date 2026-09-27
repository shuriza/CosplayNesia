<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuyerAddressTest extends TestCase
{
    use RefreshDatabase;

    public function test_buyer_manages_only_their_own_addresses_and_one_default(): void
    {
        /** @var User $buyer */
        $buyer = User::factory()->create();
        /** @var User $other */
        $other = User::factory()->create();

        $first = $this->actingAs($buyer)->postJson('/api/addresses', $this->address(['label' => 'Rumah']))
            ->assertCreated()->assertJsonPath('data.is_default', true)->json('data');
        $second = $this->actingAs($buyer)->postJson('/api/addresses', $this->address(['label' => 'Kantor', 'is_default' => true]))
            ->assertCreated()->assertJsonPath('data.is_default', true)->json('data');

        $this->assertDatabaseHas('buyer_addresses', ['id' => $first['id'], 'is_default' => false]);
        $this->actingAs($other)->deleteJson('/api/addresses/'.$second['id'])->assertNotFound();
        $this->actingAs($buyer)->getJson('/api/addresses')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_checkout_uses_an_immutable_snapshot_from_owned_saved_address(): void
    {
        /** @var User $buyer */
        $buyer = User::factory()->create();
        $product = Product::factory()->create();
        $variant = $product->variants()->firstOrFail();
        $saved = $buyer->buyerAddresses()->create($this->address(['label' => 'Rumah', 'is_default' => true]));
        $payload = $this->checkoutPayload([
            'address_id' => $saved->id,
            'items' => [['id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1]],
            'recipient' => ['name' => 'Tampered Name', 'phone' => '0800000000', 'email' => $buyer->email],
            'address' => ['line1' => 'Tampered address', 'city' => 'Bogor', 'province' => 'Jawa Barat', 'postal_code' => '16111'],
        ]);

        $orderId = $this->actingAs($buyer)->postJson('/api/checkout', $payload)->assertCreated()->json('order.id');
        $saved->update(['address_line1' => 'Alamat Baru']);

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'recipient_name' => 'Penerima Test',
            'address_line1' => 'Jl. Test No. 1',
            'postal_code' => '40123',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function address(array $overrides = []): array
    {
        return array_merge([
            'label' => 'Rumah',
            'recipient_name' => 'Penerima Test',
            'recipient_phone' => '081234567890',
            'address_line1' => 'Jl. Test No. 1',
            'address_line2' => null,
            'city' => 'Bandung',
            'province' => 'Jawa Barat',
            'postal_code' => '40123',
        ], $overrides);
    }
}
