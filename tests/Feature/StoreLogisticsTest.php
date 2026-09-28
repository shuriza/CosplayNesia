<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreLogisticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_seller_configures_shipping_origin_and_pickup(): void
    {
        /** @var User $seller */
        $seller = User::factory()->create(['role' => User::ROLE_SELLER]);
        Store::factory()->for($seller, 'owner')->create(['status' => Store::STATUS_APPROVED]);

        $this->actingAs($seller)->patchJson('/api/me/store/logistics', [
            'origin_address_line1' => 'Jl. Seller No. 8',
            'origin_province' => 'Jawa Barat',
            'origin_postal_code' => '40123',
            'pickup_enabled' => true,
            'pickup_instructions' => 'Tunjukkan kode pesanan kepada petugas.',
        ])->assertOk()
            ->assertJsonPath('store.origin_postal_code', '40123')
            ->assertJsonPath('store.pickup_enabled', true);
    }

    public function test_unapproved_store_cannot_configure_logistics(): void
    {
        /** @var User $seller */
        $seller = User::factory()->create(['role' => User::ROLE_SELLER]);
        Store::factory()->for($seller, 'owner')->create(['status' => Store::STATUS_SUBMITTED]);

        $this->actingAs($seller)->patchJson('/api/me/store/logistics', [
            'origin_address_line1' => 'Jl. Seller No. 8',
            'origin_province' => 'Jawa Barat',
            'origin_postal_code' => '40123',
            'pickup_enabled' => false,
        ])->assertForbidden();
    }
}
