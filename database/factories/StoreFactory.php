<?php

namespace Database\Factories;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class StoreFactory extends Factory
{
    protected $model = Store::class;

    public function definition(): array
    {
        $name = fake()->company();

        return [
            'user_id' => User::factory()->state(['role' => User::ROLE_SELLER]),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'name' => $name,
            'description' => fake()->sentence(),
            'city' => fake()->city(),
            'status' => Store::STATUS_APPROVED,
            'legal_name' => fake()->name(),
            'identity_number_hash' => hash('sha256', fake()->numerify('################')),
            'submitted_at' => now(),
            'approved_at' => now(),
            'reviewed_at' => now(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (): array => ['status' => Store::STATUS_DRAFT, 'submitted_at' => null, 'approved_at' => null]);
    }
}
