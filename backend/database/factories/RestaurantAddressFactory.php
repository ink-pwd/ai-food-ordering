<?php

namespace Database\Factories;

use App\Models\Restaurant;
use App\Models\RestaurantAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestaurantAddress>
 */
class RestaurantAddressFactory extends Factory
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
            'external_address_id' => fake()->uuid(),
            'title' => fake()->streetAddress(),
            'latitude' => fake()->latitude(44, 53),
            'longitude' => fake()->longitude(22, 40),
            'polygon' => null,
            'is_active' => true,
        ];
    }
}
