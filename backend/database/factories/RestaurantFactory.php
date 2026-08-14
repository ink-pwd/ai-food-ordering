<?php

namespace Database\Factories;

use App\Models\City;
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
            'city_id' => City::factory(),
            'external_company_id' => fake()->uuid(),
            'name' => $name,
            'slug' => fake()->unique()->slug(3),
            'currency' => 'UAH',
            'locale' => 'uk-UA',
            'timezone' => 'Europe/Kyiv',
            'is_active' => true,
            'image_url' => null,
            'available_payment_types' => [],
            'available_delivery_types' => [],
            'schedule' => null,
            'delivery_time_text' => null,
            'delivery_price_text' => null,
        ];
    }
}
