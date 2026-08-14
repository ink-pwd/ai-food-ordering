<?php

namespace App\Services\Handlers\Fulfillment;

use App\Enums\FulfillmentType;
use App\Integrations\Dots\FulfillmentApi;
use App\Services\Repositories\CartRepository;
use App\Services\Repositories\CityRepository;
use App\Services\Repositories\RestaurantRepository;
use App\Services\Repositories\SessionRepository;
use App\Services\Support\FulfillmentSelection;
use App\Services\Support\SessionSelection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ValidateDeliveryAddressHandler
{
    use ResolvesSessionRestaurant;

    public function __construct(
        private readonly CityRepository $cities,
        private readonly RestaurantRepository $restaurants,
        private readonly CartRepository $carts,
        private readonly SessionRepository $sessions,
        private readonly FulfillmentApi $fulfillmentApi,
    ) {}

    /**
     * @param  array<string, mixed>  $session
     * @param  array<string, mixed>  $address
     * @return array{session: array<string, mixed>, delivery_available: bool, reason: string|null, delivery_price: mixed|null, dots_delivery_type: int|null}
     */
    public function handle(string $plainToken, array $session, array $address): array
    {
        SessionSelection::assertPhoneVerified($session);
        $city = $this->resolveCity($session, $this->cities);
        $restaurant = $this->resolveRestaurant($session, $city, $this->restaurants);
        FulfillmentSelection::assertMutable($this->carts, $restaurant, $session['id']);

        if (($session['fulfillment']['type'] ?? null) !== FulfillmentType::Delivery->value) {
            throw new ConflictHttpException('Delivery fulfillment must be selected.');
        }

        $clearedFulfillment = array_merge($session['fulfillment'], [
            'dots_delivery_type' => null,
            'delivery_price' => null,
            'delivery_address' => null,
            'restaurant_address_id' => null,
            'external_address_id' => null,
        ]);
        $this->sessions->updateFulfillment($plainToken, $clearedFulfillment);

        $validatedAddress = $this->fulfillmentApi->validateUserAddress(array_merge($address, [
            'cityId' => $city->external_city_id,
        ]));
        $this->validateAddressResponse($validatedAddress);

        if (($validatedAddress['cityId'] ?? null) !== $city->external_city_id
            || ($validatedAddress['inCityPolygon'] ?? null) !== true
            || Arr::get($validatedAddress, 'position.latitude') === null
            || Arr::get($validatedAddress, 'position.longitude') === null
        ) {
            return $this->unavailable($plainToken, $clearedFulfillment, 'invalid_address');
        }

        $latitude = (string) Arr::get($validatedAddress, 'position.latitude');
        $longitude = (string) Arr::get($validatedAddress, 'position.longitude');
        $deliveryTypesResponse = $this->fulfillmentApi->getCompanyDeliveryTypes(
            $restaurant->external_company_id,
            $latitude,
            $longitude,
        );
        $deliveryTypes = $this->deliveryTypes($deliveryTypesResponse);
        $acceptedDeliveryType = FulfillmentSelection::acceptableDeliveryType($deliveryTypes);

        if ($acceptedDeliveryType === null) {
            return $this->unavailable($plainToken, $clearedFulfillment, 'outside_delivery_zone');
        }

        $fulfillment = array_merge($clearedFulfillment, [
            'dots_delivery_type' => (int) $acceptedDeliveryType['type'],
            'delivery_price' => $acceptedDeliveryType['price'],
            'delivery_address' => [
                'city_id' => $validatedAddress['cityId'],
                'street' => $validatedAddress['street'] ?? null,
                'house' => $validatedAddress['house'] ?? null,
                'flat' => $validatedAddress['flat'] ?? null,
                'stage' => $validatedAddress['stage'] ?? null,
                'note' => $validatedAddress['note'] ?? null,
                'title' => $validatedAddress['title'] ?? null,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'in_city_polygon' => true,
                'validated_at' => now()->toIso8601String(),
            ],
        ]);
        $updatedSession = $this->sessions->updateFulfillment($plainToken, $fulfillment);

        if ($updatedSession === null) {
            throw new NotFoundHttpException;
        }

        return [
            'session' => $updatedSession,
            'delivery_available' => true,
            'reason' => null,
            'delivery_price' => $acceptedDeliveryType['price'],
            'dots_delivery_type' => (int) $acceptedDeliveryType['type'],
        ];
    }

    /** @param array<string, mixed> $response */
    private function validateAddressResponse(array $response): void
    {
        Validator::make($response, [
            'cityId' => ['required', 'uuid'],
            'position' => ['nullable', 'array'],
            'position.latitude' => ['nullable', 'numeric'],
            'position.longitude' => ['nullable', 'numeric'],
            'inCityPolygon' => ['required', 'boolean'],
            'street' => ['nullable', 'string'],
            'house' => ['nullable', 'string'],
            'flat' => ['nullable'],
            'stage' => ['nullable'],
            'note' => ['nullable'],
            'title' => ['nullable'],
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<int, array<string, mixed>>
     */
    private function deliveryTypes(array $response): array
    {
        $items = array_is_list($response) ? $response : ($response['items'] ?? $response['deliveryTypes'] ?? null);

        if (! is_array($items) || ! array_is_list($items)) {
            throw ValidationException::withMessages([
                'delivery_types' => ['Dots returned an invalid delivery-types response.'],
            ]);
        }

        Validator::make(['items' => $items], [
            'items' => ['array', 'list'],
            'items.*.type' => ['required', 'integer'],
            'items.*.price' => ['required', 'numeric'],
        ])->validate();

        return $items;
    }

    /**
     * @param  array<string, mixed>  $fulfillment
     * @return array{session: array<string, mixed>, delivery_available: false, reason: string, delivery_price: null, dots_delivery_type: null}
     */
    private function unavailable(string $plainToken, array $fulfillment, string $reason): array
    {
        $updatedSession = $this->sessions->updateFulfillment($plainToken, $fulfillment);

        if ($updatedSession === null) {
            throw new NotFoundHttpException;
        }

        return [
            'session' => $updatedSession,
            'delivery_available' => false,
            'reason' => $reason,
            'delivery_price' => null,
            'dots_delivery_type' => null,
        ];
    }
}
