<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'seller_id' => User::factory(),
            'name' => fake()->words(3, true),
            'series' => 'Original',
            'category' => fake()->randomElement(['Anime', 'Game', 'VTuber', 'Aksesoris']),
            'price' => fake()->numberBetween(50000, 500000),
            'type' => 'Beli',
            'size' => 'M',
            'seller' => fake()->name(),
            'city' => fake()->city(),
            'rating' => 4.8,
            'popular' => fake()->numberBetween(1, 100),
            'newest' => fake()->numberBetween(1, 100),
            'badge' => 'Baru',
            'image' => config('cosplaynesia.default_product_image'),
            'stock' => 3,
        ];
    }
}
