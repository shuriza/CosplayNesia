<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $this->assertSame([
            'products_search_delete',
            'products_search_insert',
            'products_search_update',
        ], collect(DB::select(
            "SELECT name FROM sqlite_master WHERE type = 'trigger' AND name LIKE 'products_search_%' ORDER BY name",
        ))->pluck('name')->all());

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
        $this->assertDatabaseMissing('product_search', ['product_id' => $product->id]);
    }

    public function test_authenticated_user_can_create_and_only_list_owned_products(): void
    {
        $user = $this->user(['name' => 'Kitsune Rental']);
        $other = $this->user();
        Product::factory()->for($other, 'owner')->create();

        $this->actingAs($user)->postJson('/api/products', [
            'name' => 'Kafka Premium',
            'category' => 'Game',
            'price' => 150000,
            'type' => 'Sewa',
            'stock' => 2,
        ])->assertCreated()->assertJsonPath('seller_id', $user->id);

        $this->actingAs($user)->getJson('/api/my-products')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.seller', 'Kitsune Rental');
    }

    public function test_product_input_is_validated(): void
    {
        $this->actingAs($this->user())->postJson('/api/products', [
            'name' => '<script>alert(1)</script>',
            'category' => 'Invalid',
            'price' => -1,
            'type' => 'Invalid',
            'stock' => -1,
            'image' => 'javascript:alert(1)',
        ])->assertUnprocessable()->assertJsonValidationErrors(['category', 'price', 'type', 'stock', 'image']);
    }

    public function test_owner_can_update_and_deactivate_a_product(): void
    {
        $owner = $this->user();
        $product = Product::factory()->for($owner, 'owner')->create([
            'name' => 'Old Name',
            'price' => 100000,
            'is_active' => true,
        ]);

        $this->actingAs($owner)->patchJson("/api/products/{$product->id}", [
            'name' => 'Updated Costume',
            'price' => 125000,
            'series' => '',
            'size' => '',
            'city' => '',
            'image' => '',
        ])->assertOk()
            ->assertJsonPath('name', 'Updated Costume')
            ->assertJsonPath('price', 125000)
            ->assertJsonPath('series', 'Original')
            ->assertJsonPath('size', 'All size')
            ->assertJsonPath('city', 'Online')
            ->assertJsonPath('image', config('cosplaynesia.default_product_image'));

        $this->actingAs($owner)->patchJson("/api/products/{$product->id}", [
            'is_active' => false,
        ])->assertOk()->assertJsonPath('is_active', false);

        $this->getJson('/api/products')->assertOk()->assertJsonMissing(['id' => $product->id]);
        $this->actingAs($owner)->getJson('/api/my-products')
            ->assertOk()
            ->assertJsonPath('data.0.is_active', false);
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

    public function test_owner_can_delete_a_product_without_losing_order_item_snapshot(): void
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

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseHas('order_items', [
            'product_id' => null,
            'product_name' => 'Archived Costume',
            'unit_price' => 175000,
        ]);
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
