<?php

namespace App\Services\Handlers\Order;

use App\DTO\CustomerContactData;
use App\DTO\OrderCheckoutData;
use App\DTO\OrderFulfillmentContextData;
use App\DTO\SessionData;
use App\Enums\FulfillmentType;
use App\Enums\ReceivingType;
use App\Integrations\Dots\FulfillmentApi;
use App\Models\City;
use App\Models\Restaurant;
use App\Services\Repositories\CartRepository;
use App\Services\Repositories\CityRepository;
use App\Services\Repositories\RestaurantAddressRepository;
use App\Services\Repositories\RestaurantRepository;
use App\Services\Repositories\SessionRepository;
use App\Services\Support\FulfillmentSelection;
use App\Services\Support\PaymentSelection;
use App\Services\Support\SessionSelection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

readonly class PrepareOrderCheckoutHandler
{
    public function __construct(
        private CityRepository $cities,
        private RestaurantRepository $restaurants,
        private RestaurantAddressRepository $addresses,
        private CartRepository $carts,
        private SessionRepository $sessions,
        private FulfillmentApi $fulfillmentApi,
        private ValidateOrderCartHandler $validateCart,
    ) {
    }

    public function handle(
        SessionData $session,
        string $plainToken,
    ): OrderCheckoutData {
        SessionSelection::assertPhoneVerified($session);
        FulfillmentSelection::assertReady($session);

        $city = $this->resolveCity($session);

        $restaurant = $this->resolveRestaurant(
            $session,
            $city,
        );

        $paymentType = PaymentSelection::type($session);

        PaymentSelection::assertSupported(
            $restaurant,
            $paymentType,
        );

        $cart = $this->carts->findForSession(
            $restaurant,
            $session->id,
        );

        if ($cart === null) {
            throw new NotFoundHttpException(
                'Active cart was not found.',
            );
        }

        $this->validateCart->handle($cart);

        $contact = $this->contact($session);

        $fulfillmentContext =
            $this->fulfillmentContext(
                $plainToken,
                $session,
                $city,
                $restaurant,
            );

        $receivingType =
            $fulfillmentContext->receivingType;

        return new OrderCheckoutData(
            city: $city,
            restaurant: $restaurant,
            paymentType: $paymentType,
            cart: $cart,
            customerName: $contact->name,
            customerPhone: $contact->phone,
            receivingType: $receivingType,
            fulfillmentContext: $fulfillmentContext,
        );
    }

    private function resolveCity(
        SessionData $session,
    ): City {
        $city = $this->cities->findActiveById(
            SessionSelection::cityId($session),
        );

        if ($city === null) {
            throw new NotFoundHttpException(
                'City not found.',
            );
        }

        return $city;
    }

    private function resolveRestaurant(
        SessionData $session,
        City $city,
    ): Restaurant {
        $restaurant =
            $this->restaurants->findActiveForCityById(
                $city,
                SessionSelection::restaurantId($session),
            );

        if ($restaurant === null) {
            throw new NotFoundHttpException(
                'Restaurant not found.',
            );
        }

        return $restaurant;
    }

    private function fulfillmentContext(
        string $plainToken,
        SessionData $session,
        City $city,
        Restaurant $restaurant,
    ): OrderFulfillmentContextData {
        /**
         * @var array{
         *     type: string,
         *     restaurant_address_id: int|null,
         *     dots_delivery_type?: int|null,
         *     delivery_price?: mixed,
         *     delivery_address?: array<string, mixed>|null
         * } $fulfillment
         */
        $fulfillment = $session->fulfillment;

        if (
            ($fulfillment['type'] ?? null)
            === FulfillmentType::Pickup->value
        ) {
            $address = $this->addresses
                ->findActiveForRestaurantById(
                    $restaurant,
                    (int) $fulfillment[
                    'restaurant_address_id'
                    ],
                );

            if ($address === null) {
                throw new ConflictHttpException(
                    'Pickup location is no longer available.',
                );
            }

            return new OrderFulfillmentContextData(
                type: FulfillmentType::Pickup->value,
                receivingType: ReceivingType::Pickup,
                city: $city,
                restaurant: $restaurant,
                restaurantAddress: $address,
                deliveryType: 2,
                deliveryPrice: null,
                deliveryAddress: null,
            );
        }

        $deliveryAddress =
            $fulfillment['delivery_address'] ?? null;

        if (
            ($fulfillment['type'] ?? null)
            !== FulfillmentType::Delivery->value
            || ! is_array($deliveryAddress)
        ) {
            throw new ConflictHttpException(
                'Validated delivery address is required.',
            );
        }

        /** @var array<string, mixed> $deliveryAddress */
        $freshDeliveryType =
            $this->freshDeliveryType(
                $restaurant,
                $deliveryAddress,
            );

        if ($freshDeliveryType === null) {
            $this->sessions->updateFulfillment(
                $plainToken,
                array_merge(
                    $fulfillment,
                    [
                        'dots_delivery_type' => null,
                        'delivery_price' => null,
                        'delivery_address' => null,
                    ],
                ),
            );

            throw new ConflictHttpException(
                'delivery_unavailable',
            );
        }

        /** @var int|string $freshDeliveryTypeValue */
        $freshDeliveryTypeValue =
            $freshDeliveryType['type'];

        $freshFulfillment = array_merge(
            $fulfillment,
            [
                'dots_delivery_type' => (int) $freshDeliveryTypeValue,
                'delivery_price' => $freshDeliveryType['price'],
            ],
        );

        $this->sessions->updateFulfillment(
            $plainToken,
            $freshFulfillment,
        );

        return new OrderFulfillmentContextData(
            type: FulfillmentType::Delivery->value,
            receivingType: ReceivingType::Delivery,
            city: $city,
            restaurant: $restaurant,
            restaurantAddress: null,
            deliveryType: (int) $freshDeliveryTypeValue,
            deliveryPrice: $freshDeliveryType['price'],
            deliveryAddress: $deliveryAddress,
        );
    }

    /**
     * @param  array<string, mixed>  $deliveryAddress
     * @return array<string, mixed>|null
     */
    private function freshDeliveryType(
        Restaurant $restaurant,
        array $deliveryAddress,
    ): ?array {
        /** @var int|float|string $latitude */
        $latitude = $deliveryAddress['latitude'];

        /** @var int|float|string $longitude */
        $longitude = $deliveryAddress['longitude'];

        $response = $this->fulfillmentApi
            ->getCompanyDeliveryTypes(
                $restaurant->external_company_id,
                (string) $latitude,
                (string) $longitude,
            );

        $items = array_is_list($response)
            ? $response
            : (
                $response['items']
                ?? $response['deliveryTypes']
                ?? []
            );

        /** @var array<int, array<string, mixed>> $items */
        return is_array($items)
            ? FulfillmentSelection::acceptableDeliveryType(
                $items,
            )
            : null;
    }

    private function contact(
        SessionData $session,
    ): CustomerContactData {
        $contact = SessionSelection::contact($session);

        if (
            $contact === null
            || ($contact['phone_verified'] ?? null) !== true
        ) {
            throw ValidationException::withMessages([
                'contact' => [
                    'Verified customer contact is required before checkout.',
                ],
            ]);
        }

        return new CustomerContactData(
            name: trim($contact['name']),
            phone: ltrim(
                trim($contact['phone']),
                '+',
            ),
        );
    }
}
