<?php

namespace Database\Factories;

use App\Models\City;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<City>
 */
class CityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->city();

        return [
            'external_city_id' => fake()->uuid(),
            'name' => $name,
            'slug' => fake()->unique()->slug(2),
            'is_active' => true,
            'center_latitude' => fake()->latitude(44, 53),
            'center_longitude' => fake()->longitude(22, 40),
            'currency' => 'UAH',
            'timezone' => 'Europe/Kyiv',
        ];
    }
}
