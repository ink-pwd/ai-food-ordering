<?php

namespace App\Services\Handlers\Synchronization;

use App\Services\Repositories\CityRepository;
use App\Services\Repositories\RestaurantAddressRepository;
use App\Services\Repositories\RestaurantRepository;
use Illuminate\Support\Facades\DB;

readonly class DeactivateMissingDotsTopologyHandler
{
    public function __construct(
        private CityRepository $cities,
        private RestaurantRepository $restaurants,
        private RestaurantAddressRepository $addresses,
    ) {
    }

    /**
     * @param  array<int, string>  $presentCityIds
     * @return array{
     *     cities: int,
     *     restaurants: int,
     *     addresses: int
     * }
     */
    public function handle(
        array $presentCityIds,
    ): array {
        // City, restaurant, and address deactivation must commit as one topology update.
        return DB::transaction(
            fn (): array => $this->deactivateWithinTransaction(
                $presentCityIds,
            ),
        );
    }

    /**
     * @param  array<int, string>  $presentCityIds
     * @return array{
     *     cities: int,
     *     restaurants: int,
     *     addresses: int
     * }
     */
    private function deactivateWithinTransaction(
        array $presentCityIds,
    ): array {
        return [
            'cities' => $this->cities->deactivateMissing(
                $presentCityIds,
            ),

            'restaurants' => $this->restaurants
                    ->deactivateRestaurantsForInactiveCities(),

            'addresses' => $this->addresses
                    ->deactivateAddressesForInactiveRestaurants(),
        ];
    }
}
