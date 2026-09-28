<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_supports_search_category_and_sorting(): void
    {
        $older = Product::factory()->create(['name' => 'Furina Deluxe', 'category' => 'Game', 'price' => 200000, 'popular' => 5]);
        $newer = Product::factory()->create(['name' => 'Frieren Robe', 'category' => 'Anime', 'price' => 100000, 'popular' => 20]);
        $older->forceFill(['created_at' => now()->subDay()])->save();
        $newer->forceFill(['created_at' => now()])->save();

        $this->getJson('/api/products?q=Frieren')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Frieren Robe');
        $this->getJson('/api/products?category=Game')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Furina Deluxe');
        $this->getJson('/api/products?sort=low')->assertJsonPath('data.0.price', 100000);
        $this->getJson('/api/products?sort=high')->assertJsonPath('data.0.price', 200000);
        $this->getJson('/api/products?sort=newest')->assertJsonPath('data.0.name', 'Frieren Robe');
    }

    public function test_trigram_search_index_backfills_and_tracks_product_changes(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            $this->assertSame([
                'products_search_delete',
                'products_search_insert',
                'products_search_update',
            ], collect(DB::select(
                "SELECT name FROM sqlite_master WHERE type = 'trigger' AND name LIKE 'products_search_%' ORDER BY name",
            ))->pluck('name')->all());
        } elseif ($driver === 'pgsql') {
            $this->assertSame('products_search_trigram_index', DB::table('pg_indexes')
                ->where('schemaname', 'public')
                ->where('tablename', 'products')
                ->where('indexname', 'products_search_trigram_index')
                ->value('indexname'));
        }

        $owner = $this->user(['name' => 'Kitsune Search']);
        $product = Product::factory()->for($owner, 'owner')->create([
            'name' => 'Furina Deluxe',
            'series' => 'Genshin Impact',
            'city' => 'Bandung',
        ]);

        $this->getJson('/api/products?q=urina')->assertOk()->assertJsonPath('data.0.id', $product->id);
        $product->update(['name' => 'Navia Premium']);
        $this->getJson('/api/products?q=urina')->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/products?q=avia')->assertOk()->assertJsonPath('data.0.id', $product->id);

        $owner->products()->update(['seller' => 'Maison Fontaine']);
        $this->getJson('/api/products?q=Fontaine')->assertOk()->assertJsonPath('data.0.id', $product->id);
        $this->getJson('/api/products?q=Na')->assertOk()->assertJsonPath('data.0.id', $product->id);

        $product->delete();
        if ($driver === 'sqlite') {
            $this->assertDatabaseMissing('product_search', ['product_id' => $product->id]);
        }
        $this->getJson('/api/products?q=avia')->assertOk()->assertJsonPath('data', []);
    }

    public function test_authenticated_user_can_create_and_only_list_owned_products(): void
    {
        $user = $this->user(['name' => 'Kitsune Rental']);
        $other = $this->user();
        Store::factory()->for($user, 'owner')->create(['name' => 'Kitsune Rental']);
        Product::factory()->for($other, 'owner')->create();

        $this->actingAs($user)->postJson('/api/products', [
            'name' => 'Kafka Premium',
            'category' => 'Game',
            'rental_price_per_day' => 50000,
            'rental_deposit' => 200000,
            'rental_min_days' => 3,
            'rental_max_days' => 14,
            'type' => 'Sewa',
            'stock' => 2,
        ])->assertCreated()
            ->assertJsonPath('seller_id', $user->id)
            ->assertJsonPath('status', Product::STATUS_DRAFT);

        $this->getJson('/api/products')->assertOk()->assertJsonMissing(['name' => 'Kafka Premium']);
        $created = Product::query()->where('name', 'Kafka Premium')->firstOrFail();
        $this->actingAs($user)->patchJson("/api/products/{$created->id}/status", [
            'status' => Product::STATUS_PUBLISHED,
        ])->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->actingAs($user)->getJson('/api/my-products')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.seller', 'Kitsune Rental');
    }

    public function test_product_input_is_validated(): void
    {
        $user = $this->user();
        Store::factory()->for($user, 'owner')->create();
        $this->actingAs($user)->postJson('/api/products', [
            'name' => '<script>alert(1)</script>',
            'category' => 'Invalid',
            'sale_price' => -1,
            'type' => 'Invalid',
            'stock' => -1,
        ])->assertUnprocessable()->assertJsonValidationErrors(['category', 'sale_price', 'type', 'stock']);
    }

    public function test_listing_persists_structured_attributes_measurements_and_care_rules(): void
    {
        $owner = $this->user();
        Store::factory()->for($owner, 'owner')->create();

        $response = $this->actingAs($owner)->postJson('/api/products', [
            'name' => 'Furina Complete Set',
            'category' => 'Game',
            'category_attributes' => [
                'character' => 'Furina',
                'material' => 'Polyester',
                'fit' => 'women',
            ],
            'costume_includes' => ['Dress', 'Wig', 'Hat'],
            'rental_price_per_day' => 75000,
            'rental_deposit' => 300000,
            'rental_min_days' => 2,
            'rental_max_days' => 10,
            'type' => Product::TYPE_RENTAL,
            'size' => 'M',
            'measurements' => ['chest' => 88.5, 'waist' => 72, 'hips' => 92, 'height' => 165],
            'care_instructions' => 'Dry clean. Jangan setrika aksesori.',
            'stock' => 2,
        ])->assertCreated()
            ->assertJsonPath('category_attributes.character', 'Furina')
            ->assertJsonPath('costume_includes.2', 'Hat')
            ->assertJsonPath('measurements.chest', 88.5)
            ->assertJsonPath('price', 75000)
            ->assertJsonPath('rental_price_per_day', 75000)
            ->assertJsonPath('rental_deposit', 300000)
            ->assertJsonPath('rental_min_days', 2)
            ->assertJsonPath('rental_max_days', 10)
            ->assertJsonPath('care_instructions', 'Dry clean. Jangan setrika aksesori.');

        $this->assertDatabaseHas('products', ['id' => $response->json('id'), 'care_instructions' => 'Dry clean. Jangan setrika aksesori.']);

        $this->actingAs($owner)->postJson('/api/products', [
            'name' => 'Durasi Invalid',
            'category' => 'Anime',
            'type' => Product::TYPE_RENTAL,
            'rental_price_per_day' => 50000,
            'rental_deposit' => 0,
            'rental_min_days' => 10,
            'rental_max_days' => 3,
            'stock' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('rental_max_days');
    }

    public function test_owner_can_update_pause_and_publish_a_product(): void
    {
        $owner = $this->user();
        $moderator = $this->user(['role' => User::ROLE_MODERATOR]);
        $product = Product::factory()->for($owner, 'owner')->create([
            'name' => 'Old Name',
            'price' => 100000,
            'status' => Product::STATUS_PUBLISHED,
        ]);
        $this->actingAs($owner)->getJson("/api/products/{$product->id}/preview")
            ->assertOk()
            ->assertJsonPath('readiness.ready', true);

        $this->actingAs($owner)->patchJson("/api/products/{$product->id}", [
            'name' => 'Updated Costume',
            'sale_price' => 125000,
            'series' => '',
            'size' => '',
            'city' => '',
        ])->assertOk()
            ->assertJsonPath('name', 'Updated Costume')
            ->assertJsonPath('status', Product::STATUS_DRAFT)
            ->assertJsonPath('price', 125000)
            ->assertJsonPath('series', 'Original')
            ->assertJsonPath('size', 'All size')
            ->assertJsonPath('city', 'Online');

        $this->getJson('/api/products')->assertOk()->assertJsonMissing(['id' => $product->id]);
        $this->actingAs($owner)->patchJson("/api/products/{$product->id}/status", [
            'status' => Product::STATUS_PUBLISHED,
        ])->assertOk()
            ->assertJsonPath('status', Product::STATUS_DRAFT)
            ->assertJsonPath('submitted_for_review_at', fn ($value) => filled($value));
        $this->actingAs($moderator)->getJson('/api/admin/products')
            ->assertOk()
            ->assertJsonPath('data.0.id', $product->id);
        $this->actingAs($moderator)->patchJson("/api/admin/products/{$product->id}/review", [
            'status' => Product::STATUS_PUBLISHED,
        ])->assertOk()
            ->assertJsonPath('status', Product::STATUS_PUBLISHED)
            ->assertJsonPath('approved_version', 2);
        $this->getJson('/api/products')->assertOk()->assertJsonFragment(['id' => $product->id]);

        $this->actingAs($owner)->patchJson("/api/products/{$product->id}/status", [
            'status' => Product::STATUS_PAUSED,
        ])->assertOk()->assertJsonPath('status', Product::STATUS_PAUSED);
        $this->actingAs($owner)->patchJson("/api/products/{$product->id}/status", [
            'status' => Product::STATUS_PUBLISHED,
        ])->assertOk()->assertJsonPath('status', Product::STATUS_PUBLISHED);
    }

    public function test_moderator_rejection_requires_reason_and_edit_clears_rejection(): void
    {
        $owner = $this->user();
        $buyer = $this->user();
        $moderator = $this->user(['role' => User::ROLE_MODERATOR]);
        $product = Product::factory()->for($owner, 'owner')->create([
            'status' => Product::STATUS_DRAFT,
            'approved_version' => null,
        ]);

        $this->actingAs($owner)->patchJson("/api/products/{$product->id}/status", [
            'status' => Product::STATUS_PUBLISHED,
        ])->assertOk();
        $this->actingAs($buyer)->getJson('/api/admin/products')->assertForbidden();
        $this->actingAs($moderator)->patchJson("/api/admin/products/{$product->id}/review", [
            'status' => Product::STATUS_REJECTED,
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($moderator)->patchJson("/api/admin/products/{$product->id}/review", [
            'status' => Product::STATUS_REJECTED,
            'reason' => 'Foto utama buram.',
        ])->assertOk()
            ->assertJsonPath('status', Product::STATUS_REJECTED)
            ->assertJsonPath('rejection_reason', 'Foto utama buram.');

        $this->actingAs($owner)->patchJson("/api/products/{$product->id}", [
            'name' => 'Foto Diperbaiki',
        ])->assertOk()
            ->assertJsonPath('status', Product::STATUS_DRAFT)
            ->assertJsonPath('rejection_reason', null);
    }

    public function test_other_user_cannot_update_or_delete_a_product(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $product = Product::factory()->for($owner, 'owner')->create();

        $this->actingAs($other)->patchJson("/api/products/{$product->id}", [
            'name' => 'Taken Over',
        ])->assertForbidden();
        $this->actingAs($other)->deleteJson("/api/products/{$product->id}")->assertForbidden();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => $product->name]);
    }

    public function test_owner_uploads_reorders_and_deletes_validated_gallery_images(): void
    {
        Storage::fake('public');
        config(['cosplaynesia.listing_media_disk' => 'public']);
        $owner = $this->user();
        Store::factory()->for($owner, 'owner')->create();

        $response = $this->actingAs($owner)->post('/api/products', [
            'name' => 'Gallery Costume',
            'category' => 'Anime',
            'sale_price' => 125000,
            'type' => Product::TYPE_SALE,
            'stock' => 1,
            'images' => [
                UploadedFile::fake()->image('front.jpg', 800, 1000),
                UploadedFile::fake()->image('back.png', 800, 1000),
            ],
        ])->assertCreated()->assertJsonCount(2, 'images');

        $product = Product::findOrFail($response->json('id'));
        $images = $product->images()->orderBy('position')->get();
        Storage::disk('public')->assertExists($images[0]->path);
        Storage::disk('public')->assertExists($images[1]->path);
        $this->assertSame([0, 1], $images->pluck('position')->all());

        $this->patchJson('/api/products/'.$product->id.'/images/order', [
            'image_ids' => [$images[1]->id, $images[0]->id],
        ])->assertOk()->assertJsonPath('images.0.id', $images[1]->id);

        $deletedPath = $images[1]->path;
        $this->deleteJson('/api/products/'.$product->id.'/images/'.$images[1]->id)
            ->assertOk()->assertJsonCount(1, 'images');
        Storage::disk('public')->assertMissing($deletedPath);

        $this->withHeader('Accept', 'application/json')
            ->post('/api/products/'.$product->id.'/images', [
                'images' => [UploadedFile::fake()->create('payload.pdf', 20, 'application/pdf')],
            ])->assertUnprocessable()->assertJsonValidationErrors('images.0');
    }

    public function test_owner_manages_stock_bearing_sku_variants(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $product = Product::factory()->for($owner, 'owner')->create(['stock' => 2, 'size' => 'M']);
        $default = $product->variants()->sole();

        $this->assertTrue($default->is_default);
        $this->assertSame(2, $default->stock);

        $created = $this->actingAs($owner)->postJson("/api/products/{$product->id}/variants", [
            'sku' => ' furina-l-blue ',
            'label' => 'L / Biru',
            'size' => 'L',
            'color' => 'Biru',
            'condition' => 'like_new',
            'stock' => 3,
        ])->assertCreated()
            ->assertJsonPath('stock', 5)
            ->assertJsonPath('variants.1.sku', 'FURINA-L-BLUE')
            ->assertJsonPath('variants.1.stock', 3);

        $variantId = $created->json('variants.1.id');
        $this->actingAs($owner)->patchJson("/api/products/{$product->id}/variants/{$variantId}", [
            'stock' => 4,
        ])->assertOk()->assertJsonPath('stock', 6);

        $this->actingAs($other)->deleteJson("/api/products/{$product->id}/variants/{$variantId}")
            ->assertForbidden();
        $this->actingAs($owner)->deleteJson("/api/products/{$product->id}/variants/{$variantId}")
            ->assertOk()->assertJsonPath('stock', 2)->assertJsonCount(1, 'variants');
        $this->actingAs($owner)->deleteJson("/api/products/{$product->id}/variants/{$default->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('variant');
    }

    public function test_owner_archives_a_product_without_losing_order_item_snapshot(): void
    {
        $owner = $this->user();
        $buyer = $this->user();
        $product = Product::factory()->for($owner, 'owner')->create([
            'name' => 'Archived Costume',
            'price' => 175000,
            'stock' => 1,
        ]);

        $this->actingAs($buyer)->postJson('/api/checkout', $this->checkoutPayload([
            'items' => [['id' => $product->id, 'quantity' => 1]],
        ]))->assertCreated();
        $this->actingAs($owner)->deleteJson("/api/products/{$product->id}")->assertNoContent();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'status' => Product::STATUS_ARCHIVED]);
        $this->assertDatabaseHas('order_items', [
            'product_id' => $product->id,
            'product_name' => 'Archived Costume',
            'unit_price' => 175000,
        ]);
        $this->actingAs($owner)->patchJson("/api/products/{$product->id}/status", [
            'status' => Product::STATUS_PUBLISHED,
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_catalog_cursor_pagination_is_deterministic_and_preserves_filters(): void
    {
        Product::factory()->count(11)->sequence(
            fn ($sequence) => [
                'name' => 'Cursor Product '.str_pad((string) $sequence->index, 2, '0', STR_PAD_LEFT),
                'category' => $sequence->index < 7 ? 'Game' : 'Anime',
                'popular' => 50,
                'price' => 100000,
            ],
        )->create();

        $first = $this->getJson('/api/products?category=Game&sort=popular&per_page=4')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('pagination.per_page', 4)
            ->assertJsonPath('pagination.has_more', true)
            ->assertJsonStructure(['pagination' => ['next_cursor']])
            ->assertJsonMissingPath('pagination.total');
        $firstIds = collect($first->json('data'))->pluck('id');
        $cursor = $first->json('pagination.next_cursor');

        $second = $this->getJson('/api/products?category=Game&sort=popular&per_page=4&cursor='.urlencode($cursor))
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('pagination.has_more', false)
            ->assertJsonPath('pagination.next_cursor', null);
        $secondIds = collect($second->json('data'))->pluck('id');

        $this->assertCount(0, $firstIds->intersect($secondIds));
        $this->assertSame(7, $firstIds->merge($secondIds)->unique()->count());
        $this->assertSame(
            $firstIds->merge($secondIds)->sortDesc()->values()->all(),
            $firstIds->merge($secondIds)->values()->all(),
        );
    }

    public function test_catalog_pagination_validates_page_size_and_scopes_favorites(): void
    {
        $user = $this->user();
        $other = $this->user();
        $products = Product::factory()->count(5)->create();
        $user->favoriteProducts()->attach([$products[1]->id, $products[3]->id]);
        $other->favoriteProducts()->attach([$products[0]->id]);

        $this->getJson('/api/products?per_page=25')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
        $this->getJson('/api/products?favorites=1')->assertUnauthorized();

        $response = $this->actingAs($user)->getJson('/api/products?favorites=1&per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('pagination.has_more', true)
            ->assertJsonMissingPath('pagination.total');
        $firstId = $response->json('data.0.id');
        $cursor = $response->json('pagination.next_cursor');

        $second = $this->actingAs($user)->getJson('/api/products?favorites=1&per_page=1&cursor='.urlencode($cursor))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('pagination.has_more', false);

        $this->assertContains($firstId, [$products[1]->id, $products[3]->id]);
        $this->assertContains($second->json('data.0.id'), [$products[1]->id, $products[3]->id]);
        $this->assertNotSame($firstId, $second->json('data.0.id'));
    }

    public function test_owned_inventory_cursor_is_stable_bounded_and_isolated(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $timestamp = now()->startOfSecond();
        $products = Product::factory()->count(7)->for($owner, 'owner')->create();
        $products->each(fn (Product $product) => $product->forceFill([
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ])->save());
        Product::factory()->for($other, 'owner')->create();

        $first = $this->actingAs($owner)->getJson('/api/my-products?per_page=3')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('pagination.per_page', 3)
            ->assertJsonPath('pagination.has_more', true)
            ->assertJsonMissingPath('pagination.total');
        $firstIds = collect($first->json('data'))->pluck('id');

        $second = $this->actingAs($owner)->getJson('/api/my-products?per_page=3&cursor='.urlencode($first->json('pagination.next_cursor')))
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('pagination.has_more', true);
        $secondIds = collect($second->json('data'))->pluck('id');

        $this->assertCount(0, $firstIds->intersect($secondIds));
        $this->assertSame($products->sortByDesc('id')->pluck('id')->take(6)->values()->all(), $firstIds->merge($secondIds)->all());
        $this->actingAs($owner)->getJson('/api/my-products?per_page=21')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }
}
