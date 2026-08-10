<?php

namespace Database\Factories;

use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Restaurant>
 */
class RestaurantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company().' Restaurant';

        return [
            'external_company_id' => fake()->uuid(),
            'name' => $name,
            'slug' => fake()->unique()->slug(3),
            'currency' => 'UAH',
            'locale' => 'uk-UA',
            'timezone' => 'Europe/Kyiv',
            'is_active' => true,
        ];
    }
}
