<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FavoriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_favorites_are_idempotent_and_isolated_per_user(): void
    {
        $product = Product::factory()->create();
        /** @var User $first */
        $first = User::factory()->create();
        /** @var User $second */
        $second = User::factory()->create();

        $this->actingAs($first)->postJson('/api/favorites', ['product_id' => $product->id])->assertOk();
        $this->actingAs($first)->postJson('/api/favorites', ['product_id' => $product->id])->assertOk();
        $this->assertDatabaseCount('favorites', 1);
        $this->actingAs($first)->getJson('/api/products')->assertOk()->assertJsonPath('data.0.is_favorite', true);
        $this->actingAs($second)->getJson('/api/products')->assertOk()->assertJsonPath('data.0.is_favorite', false);

        $this->actingAs($first)->deleteJson("/api/favorites/{$product->id}")->assertOk();
        $this->assertDatabaseCount('favorites', 0);
        $this->actingAs($first)->getJson('/api/products')->assertOk()->assertJsonPath('data.0.is_favorite', false);
    }

    public function test_guest_cannot_manage_favorites(): void
    {
        $product = Product::factory()->create();

        $this->getJson('/api/products')->assertOk()->assertJsonPath('data.0.is_favorite', false);
        $this->getJson('/api/favorites')->assertMethodNotAllowed();
        $this->postJson('/api/favorites', ['product_id' => $product->id])->assertUnauthorized();
        $this->deleteJson("/api/favorites/{$product->id}")->assertUnauthorized();
    }
}
