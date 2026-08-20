<?php

namespace App\Services\Handlers\Fulfillment;

use App\DTO\SessionData;
use App\Enums\FulfillmentType;
use App\Services\Repositories\CartRepository;
use App\Services\Repositories\CityRepository;
use App\Services\Repositories\RestaurantAddressRepository;
use App\Services\Repositories\RestaurantRepository;
use App\Services\Repositories\SessionRepository;
use App\Services\Support\FulfillmentSelection;
use App\Services\Support\SessionSelection;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SelectPickupAddressHandler
{
    use ResolvesSessionRestaurant;

    public function __construct(
        private readonly CityRepository $cities,
        private readonly RestaurantRepository $restaurants,
        private readonly RestaurantAddressRepository $addresses,
        private readonly CartRepository $carts,
        private readonly SessionRepository $sessions,
    ) {
    }

    public function handle(
        string $plainToken,
        SessionData $session,
        int $addressId,
    ): SessionData {
        SessionSelection::assertPhoneVerified($session);
        $city = $this->resolveCity($session, $this->cities);
        $restaurant = $this->resolveRestaurant($session, $city, $this->restaurants);
        FulfillmentSelection::assertMutable($this->carts, $restaurant, $session->id);

        if (($session->fulfillment['type'] ?? null) !== FulfillmentType::Pickup->value) {
            throw new ConflictHttpException('Pickup fulfillment must be selected.');
        }

        $address = $this->addresses->findActiveForRestaurantById($restaurant, $addressId);

        if ($address === null) {
            throw new NotFoundHttpException('Pickup address not found.');
        }

        $fulfillment = array_merge($session->fulfillment, [
            'restaurant_address_id' => $address->id,
            'external_address_id' => $address->external_address_id,
            'dots_delivery_type' => null,
            'delivery_price' => null,
            'delivery_address' => null,
        ]);

        $updatedSession = $this->sessions->updateFulfillment($plainToken, $fulfillment);

        if ($updatedSession === null) {
            throw new NotFoundHttpException;
        }

        return SessionData::fromArray($updatedSession);
    }
}
