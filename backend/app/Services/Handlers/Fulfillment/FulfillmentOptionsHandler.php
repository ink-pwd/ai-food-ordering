<?php

namespace App\Services\Handlers\Fulfillment;

use App\DTO\SessionData;
use App\Enums\FulfillmentType;
use App\Services\Repositories\CityRepository;
use App\Services\Repositories\RestaurantAddressRepository;
use App\Services\Repositories\RestaurantRepository;
use App\Services\Support\FulfillmentSelection;
use App\Services\Support\SessionSelection;

class FulfillmentOptionsHandler
{
    use ResolvesSessionRestaurant;

    public function __construct(
        private readonly CityRepository $cities,
        private readonly RestaurantRepository $restaurants,
        private readonly RestaurantAddressRepository $addresses,
    ) {
    }

    /**
     * @return array<int, array{type: string}>
     */
    public function handle(SessionData $session): array
    {
        SessionSelection::assertPhoneVerified($session);
        $city = $this->resolveCity($session, $this->cities);
        $restaurant = $this->resolveRestaurant($session, $city, $this->restaurants);
        $options = [];

        if (FulfillmentSelection::supportsDelivery($restaurant)) {
            $options[] = ['type' => FulfillmentType::Delivery->value];
        }

        if (FulfillmentSelection::supportsPickup($restaurant)
            && $this->addresses->activeForRestaurant($restaurant)->isNotEmpty()
        ) {
            $options[] = ['type' => FulfillmentType::Pickup->value];
        }

        return $options;
    }
}
