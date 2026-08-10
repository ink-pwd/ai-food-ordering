<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'restaurant_id' => Restaurant::factory(),
            'external_id' => fake()->uuid(),
            'name' => $name,
            'slug' => Str::slug($name),
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
