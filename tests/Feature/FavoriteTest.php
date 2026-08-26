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
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->actingAs($first)->postJson('/api/favorites', ['product_id' => $product->id])->assertOk();
        $this->actingAs($first)->postJson('/api/favorites', ['product_id' => $product->id])->assertOk();
        $this->assertDatabaseCount('favorites', 1);
        $this->actingAs($first)->getJson('/api/favorites')->assertExactJson([$product->id]);
        $this->actingAs($second)->getJson('/api/favorites')->assertExactJson([]);

        $this->actingAs($first)->deleteJson("/api/favorites/{$product->id}")->assertOk();
        $this->assertDatabaseCount('favorites', 0);
    }

    public function test_guest_cannot_manage_favorites(): void
    {
        $this->getJson('/api/favorites')->assertUnauthorized();
    }
}
