<?php

namespace Database\Factories;

use App\Enums\CartStatus;
use App\Models\Cart;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Cart>
 */
class CartFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'session_id' => (string) Str::ulid(),
            'status' => CartStatus::Active,
            'currency' => 'UAH',
            'subtotal' => 0,
            'total' => 0,
            'expires_at' => now()->addHours(fake()->numberBetween(1, 24)),
        ];
    }
}
