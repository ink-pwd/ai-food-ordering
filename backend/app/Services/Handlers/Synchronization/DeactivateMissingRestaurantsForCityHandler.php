<?php

namespace App\Services\Handlers\Synchronization;

use App\Models\City;
use App\Services\Repositories\RestaurantRepository;

readonly class DeactivateMissingRestaurantsForCityHandler
{
    public function __construct(
        private RestaurantRepository $restaurants,
    ) {
    }

    /**
     * @param  array<int, string>  $presentCompanyIds
     */
    public function handle(
        City $city,
        array $presentCompanyIds,
    ): int {
        return $this->restaurants
            ->deactivateMissingForCity(
                $city,
                $presentCompanyIds,
            );
    }
}
