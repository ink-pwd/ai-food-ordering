<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->words(2, true);
        $price = fake()->randomFloat(2, 50, 500);

        return [
            'restaurant_id' => Restaurant::factory(),
            'external_id' => fake()->uuid(),
            'name' => str($name)->title()->toString(),
            'description' => fake()->sentence(),
            'price' => $price,
            'promotion_price' => null,
            'currency' => 'UAH',
            'image_url' => fake()->imageUrl(640, 480, 'food'),
            'is_available' => true,
            'sort_order' => 0,
            'original_payload' => [
                'id' => fake()->uuid(),
                'name' => $name,
                'price' => $price,
                'modifiers' => [],
            ],
        ];
    }
}
