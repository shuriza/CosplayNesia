<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function ($product): void {
            if ($product->seller_id && ! $product->store_id) {
                $store = Store::query()->firstOrCreate(
                    ['user_id' => $product->seller_id],
                    Store::factory()->make(['user_id' => $product->seller_id])->getAttributes(),
                );
                $product->update(['store_id' => $store->id]);
            }
            if ($product->images()->doesntExist()) {
                $product->images()->create([
                    'disk' => 'public',
                    'path' => 'factory/product-'.$product->id.'.jpg',
                    'mime_type' => 'image/jpeg',
                    'size_bytes' => 1024,
                    'position' => 0,
                ]);
            }
        });
    }

    public function definition(): array
    {
        return [
            'seller_id' => User::factory(),
            'name' => fake()->words(3, true),
            'series' => 'Original',
            'category' => fake()->randomElement(['Anime', 'Game', 'VTuber', 'Aksesoris']),
            'category_attributes' => ['character' => 'Original Character', 'material' => 'Polyester', 'fit' => 'unisex'],
            'costume_includes' => ['Kostum utama'],
            'price' => fake()->numberBetween(50000, 500000),
            'type' => 'Beli',
            'size' => 'M',
            'measurements' => ['chest' => 90, 'waist' => 75, 'height' => 165],
            'care_instructions' => 'Cuci lembut dan keringkan tanpa mesin.',
            'seller' => fake()->name(),
            'city' => fake()->city(),
            'popular' => fake()->numberBetween(1, 100),
            'badge' => 'Baru',
            'image' => config('cosplaynesia.default_product_image'),
            'stock' => 3,
            'status' => Product::STATUS_PUBLISHED,
            'approved_version' => 1,
            'published_at' => now(),
        ];
    }
}
