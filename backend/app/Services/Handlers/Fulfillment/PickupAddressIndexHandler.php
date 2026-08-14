<?php

namespace App\Services\Handlers\Fulfillment;

use App\Services\Repositories\CityRepository;
use App\Services\Repositories\RestaurantAddressRepository;
use App\Services\Repositories\RestaurantRepository;
use App\Services\Support\SessionSelection;
use Illuminate\Database\Eloquent\Collection;

class PickupAddressIndexHandler
{
    use ResolvesSessionRestaurant;

    public function __construct(
        private readonly CityRepository $cities,
        private readonly RestaurantRepository $restaurants,
        private readonly RestaurantAddressRepository $addresses,
    ) {}

    /**
     * @param  array<string, mixed>  $session
     * @return Collection<int, \App\Models\RestaurantAddress>
     */
    public function handle(array $session): Collection
    {
        SessionSelection::assertPhoneVerified($session);
        $city = $this->resolveCity($session, $this->cities);
        $restaurant = $this->resolveRestaurant($session, $city, $this->restaurants);

        return $this->addresses->activeForRestaurant($restaurant);
    }
}
