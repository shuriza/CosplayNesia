<?php

namespace Tests\Feature;

use App\Models\OrderFulfillment;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StoreOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_buyer_submits_onboarding_and_cannot_list_until_admin_approval(): void
    {
        Storage::fake('private');
        $buyer = User::factory()->create();
        $payload = $this->onboardingPayload();

        $this->actingAs($buyer)->postJson('/api/products', $this->productPayload())->assertForbidden();
        $submitted = $this->postJson('/api/me/store/onboarding', $payload)
            ->assertCreated()
            ->assertJsonPath('store.status', Store::STATUS_SUBMITTED)
            ->assertJsonMissingPath('store.identity_number_hash')
            ->assertJsonMissingPath('store.document_path');
        $store = Store::findOrFail($submitted->json('store.id'));
        Storage::disk('private')->assertExists($store->document_path);
        $this->get('/api/me/store/document')->assertOk();
        $this->assertDatabaseHas('security_events', [
            'user_id' => $buyer->id,
            'type' => 'store.document_accessed',
        ]);
        $this->postJson('/api/products', $this->productPayload())->assertForbidden();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->flushSession()->actingAsGuest();
        $this->actingAs(User::factory()->create())->patchJson("/api/admin/stores/{$store->id}/review", [
            'status' => Store::STATUS_APPROVED,
        ])->assertForbidden();
        $this->flushSession()->actingAsGuest();
        $this->actingAs($admin)->patchJson("/api/admin/stores/{$store->id}/review", [
            'status' => Store::STATUS_APPROVED,
        ])->assertOk()->assertJsonPath('store.status', Store::STATUS_APPROVED);

        $this->flushSession()->actingAsGuest();
        $this->actingAs($buyer->fresh())->postJson('/api/products', $this->productPayload())
            ->assertCreated()
            ->assertJsonPath('store_id', $store->id)
            ->assertJsonPath('seller', 'Kitsune Verified Store');
        $this->assertSame('seller', $buyer->fresh()->role);
    }

    public function test_approved_storefront_is_public_but_private_onboarding_data_is_not(): void
    {
        $store = Store::factory()->create([
            'legal_name' => 'Legal Secret Name',
            'identity_number_hash' => hash('sha256', 'secret'),
            'document_path' => 'seller-documents/private.pdf',
        ]);
        Product::factory()->for($store->owner, 'owner')->create(['store_id' => $store->id, 'name' => 'Public Costume']);

        $this->getJson('/api/stores/'.$store->slug)
            ->assertOk()
            ->assertJsonPath('store.name', $store->name)
            ->assertJsonPath('products.0.name', 'Public Costume')
            ->assertJsonMissingPath('store.legal_name')
            ->assertJsonMissingPath('store.document_path')
            ->assertJsonMissingPath('store.identity_number_hash');

        $store->update(['status' => Store::STATUS_REJECTED]);
        $this->getJson('/api/stores/'.$store->slug)->assertNotFound();
    }

    public function test_suspension_hides_listing_and_blocks_new_checkout_but_existing_fulfillment_continues(): void
    {
        $store = Store::factory()->create();
        $seller = $store->owner;
        $buyer = User::factory()->create();
        $product = Product::factory()->for($seller, 'owner')->create([
            'store_id' => $store->id,
            'stock' => 2,
            'status' => Product::STATUS_PUBLISHED,
        ]);
        $created = $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [['id' => $product->id, 'quantity' => 1]],
        ]))->assertCreated();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->flushSession()->actingAsGuest();
        $this->actingAs($admin)->patchJson("/api/admin/stores/{$store->id}/review", [
            'status' => Store::STATUS_SUSPENDED,
            'note' => 'Dokumen perlu diperiksa ulang.',
        ])->assertOk();

        $this->getJson('/api/products')->assertOk()->assertJsonMissing(['id' => $product->id]);
        $this->flushSession()->actingAsGuest();
        $this->actingAs(User::factory()->create())->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [['id' => $product->id, 'quantity' => 1]],
        ]))->assertUnprocessable()->assertJsonValidationErrors('items.0.id');

        $path = '/api/seller/fulfillments/'.$created->json('order.fulfillments.0.id').'/status';
        $this->flushSession()->actingAsGuest();
        $this->actingAs($seller)->patchJson($path, ['status' => OrderFulfillment::STATUS_ACCEPTED])->assertOk();
    }

    public function test_rejection_requires_reason_and_allows_resubmission(): void
    {
        Storage::fake('private');
        $user = User::factory()->create();
        $storeId = $this->actingAs($user)->postJson('/api/me/store/onboarding', $this->onboardingPayload())->json('store.id');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->flushSession()->actingAsGuest();
        $this->actingAs($admin)->patchJson("/api/admin/stores/{$storeId}/review", [
            'status' => Store::STATUS_REJECTED,
        ])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->patchJson("/api/admin/stores/{$storeId}/review", [
            'status' => Store::STATUS_REJECTED,
            'note' => 'Dokumen tidak terbaca.',
        ])->assertOk();

        $this->flushSession()->actingAsGuest();
        $this->actingAs($user)->postJson('/api/me/store/onboarding', $this->onboardingPayload(['name' => 'Kitsune Revised Store']))
            ->assertOk()->assertJsonPath('store.status', Store::STATUS_SUBMITTED);
    }

    public function test_moderator_can_review_stores_but_support_cannot(): void
    {
        $store = Store::factory()->create(['status' => Store::STATUS_SUBMITTED]);
        $support = User::factory()->create(['role' => User::ROLE_SUPPORT]);
        $moderator = User::factory()->create(['role' => User::ROLE_MODERATOR]);

        $this->actingAs($support)->getJson('/api/admin/stores')->assertForbidden();
        $this->flushSession()->actingAsGuest();
        $this->actingAs($moderator)->getJson('/api/admin/stores')
            ->assertOk()
            ->assertJsonPath('data.0.id', $store->id);
    }

    private function onboardingPayload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Kitsune Verified Store',
            'description' => 'Rental kostum terawat untuk komunitas.',
            'city' => 'Bandung',
            'legal_name' => 'Kitsune Wardrobe Indonesia',
            'identity_number' => '3273012345678901',
            'operating_hours' => 'Senin-Sabtu 09:00-18:00',
            'policy' => 'Barang wajib dikembalikan dalam kondisi baik.',
            'document' => UploadedFile::fake()->create('identity.pdf', 200, 'application/pdf'),
        ], $overrides);
    }

    private function productPayload(): array
    {
        return ['name' => 'Approved Listing', 'category' => 'Anime', 'sale_price' => 100000, 'type' => 'Beli', 'stock' => 1];
    }
}
