<?php

namespace App\Services\Handlers\Fulfillment;

use App\Enums\FulfillmentType;
use App\Services\Repositories\CartRepository;
use App\Services\Repositories\CityRepository;
use App\Services\Repositories\RestaurantRepository;
use App\Services\Repositories\SessionRepository;
use App\Services\Support\FulfillmentSelection;
use App\Services\Support\SessionSelection;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SelectFulfillmentTypeHandler
{
    use ResolvesSessionRestaurant;

    public function __construct(
        private readonly CityRepository $cities,
        private readonly RestaurantRepository $restaurants,
        private readonly CartRepository $carts,
        private readonly SessionRepository $sessions,
    ) {}

    /**
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    public function handle(string $plainToken, array $session, FulfillmentType $type): array
    {
        SessionSelection::assertPhoneVerified($session);
        $city = $this->resolveCity($session, $this->cities);
        $restaurant = $this->resolveRestaurant($session, $city, $this->restaurants);
        FulfillmentSelection::assertMutable($this->carts, $restaurant, $session['id']);

        if ($type === FulfillmentType::Delivery && ! FulfillmentSelection::supportsDelivery($restaurant)) {
            throw new ConflictHttpException('Delivery is not available for this restaurant.');
        }

        if ($type === FulfillmentType::Pickup && ! FulfillmentSelection::supportsPickup($restaurant)) {
            throw new ConflictHttpException('Pickup is not available for this restaurant.');
        }

        $fulfillment = [
            'type' => $type->value,
            'dots_delivery_type' => null,
            'delivery_price' => null,
            'delivery_address' => null,
            'restaurant_address_id' => null,
            'external_address_id' => null,
        ];

        $updatedSession = $this->sessions->updateFulfillment($plainToken, $fulfillment);

        if ($updatedSession === null) {
            throw new NotFoundHttpException;
        }

        return $updatedSession;
    }
}
