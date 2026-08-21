<?php

namespace App\Integrations\OrderingBackend;

use App\DTO\OrderingBackend\CityData;
use App\DTO\OrderingBackend\RestaurantData;
use Illuminate\Http\Client\Response;

final readonly class SelectionOrderingBackendClient
{
    public function __construct(
        private OrderingBackendTransport $transport,
    ) {
    }

    /**
     * @return list<CityData>
     */
    public function cities(): array
    {
        $response = $this->transport->get(
            path: 'api/cities',
            operation: 'list_cities',
            message: 'Unable to retrieve ordering backend cities.',
        );

        $cities = $this->transport->responseData(
            $response,
            'list_cities',
            'Ordering backend returned malformed city data.',
        );

        if (
            ! is_array($cities)
            || ! array_is_list($cities)
        ) {
            throw $this->transport->invalidResponse(
                $response,
                'list_cities',
                'Ordering backend returned malformed city data.',
            );
        }

        return array_map(
            fn (mixed $city): CityData => $this->cityFromValue(
                $city,
                $response,
                'list_cities',
            ),
            $cities,
        );
    }

    /**
     * @return array{session_id: string, city: CityData}
     */
    public function selectCurrentSessionCity(
        string $sessionToken,
        int $cityId,
    ): array {
        $response = $this->transport->sessionBoundPut(
            sessionToken: $sessionToken,
            path: 'api/sessions/current/city',
            operation: 'select_current_session_city',
            message: 'Unable to select the ordering backend city.',
            data: [
                'city_id' => $cityId,
            ],
        );

        $data = $this->transport->responseData(
            $response,
            'select_current_session_city',
            'Ordering backend returned malformed city data.',
        );

        if (
            ! is_array($data)
            || ! $this->transport->isNonEmptyString(
                $data['session_id'] ?? null,
            )
        ) {
            throw $this->transport->invalidResponse(
                $response,
                'select_current_session_city',
                'Ordering backend returned malformed city data.',
            );
        }

        /** @var array{session_id: string, city?: mixed} $data */
        return [
            'session_id' => $data['session_id'],
            'city' => $this->cityFromValue(
                $data['city'] ?? null,
                $response,
                'select_current_session_city',
            ),
        ];
    }

    /**
     * @return list<RestaurantData>
     */
    public function currentSessionRestaurants(
        string $sessionToken,
    ): array {
        $response = $this->transport->sessionBoundGet(
            sessionToken: $sessionToken,
            path: 'api/sessions/current/restaurants',
            operation: 'list_current_session_restaurants',
            message: 'Unable to retrieve ordering backend restaurants.',
        );

        $restaurants = $this->transport->responseData(
            $response,
            'list_current_session_restaurants',
            'Ordering backend returned malformed restaurant data.',
        );

        if (
            ! is_array($restaurants)
            || ! array_is_list($restaurants)
        ) {
            throw $this->transport->invalidResponse(
                $response,
                'list_current_session_restaurants',
                'Ordering backend returned malformed restaurant data.',
            );
        }

        return array_map(
            fn (mixed $restaurant): RestaurantData => $this->restaurantFromValue(
                $restaurant,
                $response,
                'list_current_session_restaurants',
            ),
            $restaurants,
        );
    }

    /**
     * @return array{session_id: string, restaurant: RestaurantData}
     */
    public function selectCurrentSessionRestaurant(
        string $sessionToken,
        int $restaurantId,
    ): array {
        $response = $this->transport->sessionBoundPut(
            sessionToken: $sessionToken,
            path: 'api/sessions/current/restaurant',
            operation: 'select_current_session_restaurant',
            message: 'Unable to select the ordering backend restaurant.',
            data: [
                'restaurant_id' => $restaurantId,
            ],
        );

        $data = $this->transport->responseData(
            $response,
            'select_current_session_restaurant',
            'Ordering backend returned malformed restaurant data.',
        );

        if (
            ! is_array($data)
            || ! $this->transport->isNonEmptyString(
                $data['session_id'] ?? null,
            )
        ) {
            throw $this->transport->invalidResponse(
                $response,
                'select_current_session_restaurant',
                'Ordering backend returned malformed restaurant data.',
            );
        }

        /** @var array{session_id: string, restaurant?: mixed} $data */
        return [
            'session_id' => $data['session_id'],
            'restaurant' => $this->restaurantFromValue(
                $data['restaurant'] ?? null,
                $response,
                'select_current_session_restaurant',
            ),
        ];
    }

    private function isValidCity(mixed $city): bool
    {
        return is_array($city)
            && $this->transport->isPositiveInteger(
                $city['id'] ?? null,
            )
            && $this->transport->isNonEmptyString(
                $city['name'] ?? null,
            )
            && $this->transport->isNonEmptyString(
                $city['slug'] ?? null,
            )
            && $this->transport->isNonEmptyString(
                $city['currency'] ?? null,
            )
            && $this->transport->isNonEmptyString(
                $city['timezone'] ?? null,
            )
            && is_array($city['center'] ?? null)
            && $this->transport->isOptionalString(
                $city['center']['latitude'] ?? null,
            )
            && $this->transport->isOptionalString(
                $city['center']['longitude'] ?? null,
            );
    }

    private function isValidRestaurant(
        mixed $restaurant,
    ): bool {
        return is_array($restaurant)
            && $this->transport->isPositiveInteger(
                $restaurant['id'] ?? null,
            )
            && $this->transport->isNonEmptyString(
                $restaurant['name'] ?? null,
            )
            && $this->transport->isNonEmptyString(
                $restaurant['slug'] ?? null,
            )
            && $this->transport->isOptionalString(
                $restaurant['image_url'] ?? null,
            )
            && $this->transport->isNonEmptyString(
                $restaurant['currency'] ?? null,
            )
            && $this->transport->isNonEmptyString(
                $restaurant['locale'] ?? null,
            )
            && $this->transport->isNonEmptyString(
                $restaurant['timezone'] ?? null,
            )
            && $this->transport->isIntegerList(
                $restaurant['available_payment_types']
                    ?? null,
            )
            && $this->transport->isIntegerList(
                $restaurant['available_delivery_types']
                    ?? null,
            )
            && $this->transport->isOptionalString(
                $restaurant['delivery_time_text'] ?? null,
            )
            && $this->transport->isOptionalString(
                $restaurant['delivery_price_text'] ?? null,
            );
    }

    private function cityFromValue(
        mixed $city,
        Response $response,
        string $operation,
    ): CityData {
        $invalidMessage =
            'Ordering backend returned malformed city data.';

        if (! $this->isValidCity($city)) {
            throw $this->transport->invalidResponse(
                $response,
                $operation,
                $invalidMessage,
            );
        }

        /** @var array{id: int, name: string, slug: string, currency: string, timezone: string, center: array{latitude?: string|null, longitude?: string|null}} $city */
        return new CityData(
            id: $city['id'],
            name: $city['name'],
            slug: $city['slug'],
            currency: $city['currency'],
            timezone: $city['timezone'],
            centerLatitude: $city['center']['latitude'] ?? null,
            centerLongitude: $city['center']['longitude'] ?? null,
        );
    }

    private function restaurantFromValue(
        mixed $restaurant,
        Response $response,
        string $operation,
    ): RestaurantData {
        $invalidMessage =
            'Ordering backend returned malformed restaurant data.';

        if (! $this->isValidRestaurant($restaurant)) {
            throw $this->transport->invalidResponse(
                $response,
                $operation,
                $invalidMessage,
            );
        }

        /** @var array{id: int, name: string, slug: string, image_url?: string|null, currency: string, locale: string, timezone: string, available_payment_types: list<int>, available_delivery_types: list<int>, delivery_time_text?: string|null, delivery_price_text?: string|null} $restaurant */
        return new RestaurantData(
            id: $restaurant['id'],
            name: $restaurant['name'],
            slug: $restaurant['slug'],
            imageUrl: $restaurant['image_url'] ?? null,
            currency: $restaurant['currency'],
            locale: $restaurant['locale'],
            timezone: $restaurant['timezone'],
            availablePaymentTypes: $restaurant['available_payment_types'],
            availableDeliveryTypes: $restaurant['available_delivery_types'],
            deliveryTimeText: $restaurant['delivery_time_text'] ?? null,
            deliveryPriceText: $restaurant['delivery_price_text'] ?? null,
        );
    }
}
