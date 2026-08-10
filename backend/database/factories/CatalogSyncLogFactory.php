<?php

namespace Database\Factories;

use App\Enums\CatalogSyncStatus;
use App\Models\CatalogSyncLog;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogSyncLog>
 */
class CatalogSyncLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = now()->subMinutes(fake()->numberBetween(5, 30));

        return [
            'restaurant_id' => Restaurant::factory(),
            'status' => CatalogSyncStatus::Succeeded,
            'started_at' => $startedAt,
            'finished_at' => $startedAt->copy()->addMinutes(fake()->numberBetween(1, 4)),
            'summary' => [
                'categories_created' => 2,
                'categories_updated' => 3,
                'products_created' => 10,
                'products_updated' => 5,
                'products_deactivated' => 1,
            ],
            'error_message' => null,
        ];
    }
}
