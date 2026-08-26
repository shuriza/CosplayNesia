<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_supports_search_category_and_sorting(): void
    {
        Product::factory()->create(['name' => 'Furina Deluxe', 'category' => 'Game', 'price' => 200000, 'popular' => 5, 'newest' => 1]);
        Product::factory()->create(['name' => 'Frieren Robe', 'category' => 'Anime', 'price' => 100000, 'popular' => 20, 'newest' => 2]);

        $this->getJson('/api/products?q=Frieren')->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'Frieren Robe');
        $this->getJson('/api/products?category=Game')->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'Furina Deluxe');
        $this->getJson('/api/products?sort=low')->assertJsonPath('0.price', 100000);
        $this->getJson('/api/products?sort=high')->assertJsonPath('0.price', 200000);
        $this->getJson('/api/products?sort=newest')->assertJsonPath('0.name', 'Frieren Robe');
    }

    public function test_authenticated_user_can_create_and_only_list_owned_products(): void
    {
        $user = User::factory()->create(['name' => 'Kitsune Rental']);
        $other = User::factory()->create();
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
            ->assertJsonCount(1)
            ->assertJsonPath('0.seller', 'Kitsune Rental');
    }

    public function test_product_input_is_validated(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/api/products', [
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
        $owner = User::factory()->create();
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
            ->assertJsonPath('0.is_active', false);
    }

    public function test_other_user_cannot_update_or_delete_a_product(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $product = Product::factory()->for($owner, 'owner')->create();

        $this->actingAs($other)->patchJson("/api/products/{$product->id}", [
            'name' => 'Taken Over',
        ])->assertForbidden();
        $this->actingAs($other)->deleteJson("/api/products/{$product->id}")->assertForbidden();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => $product->name]);
    }

    public function test_owner_can_delete_a_product_without_losing_order_item_snapshot(): void
    {
        $owner = User::factory()->create();
        $buyer = User::factory()->create();
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
}
