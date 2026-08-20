<?php

namespace App\Integrations\OrderingBackend;

use App\DTO\OrderingBackend\DeliveryAddressData;
use App\DTO\OrderingBackend\DeliveryValidationData;
use App\DTO\OrderingBackend\PickupAddressData;
use Illuminate\Http\Client\Response;

final readonly class FulfillmentOrderingBackendClient
{
    public function __construct(
        private OrderingBackendTransport $transport,
    ) {
    }

    /**
     * @return list<array{type: string}>
     */
    public function currentSessionFulfillmentOptions(
        string $sessionToken,
    ): array {
        $response = $this->transport->sessionBoundGet(
            sessionToken: $sessionToken,
            path: 'api/sessions/current/fulfillment-options',
            operation: 'list_current_session_fulfillment_options',
            message: 'Unable to retrieve ordering backend fulfillment options.',
        );

        $options = $this->transport->responseData(
            $response,
            'list_current_session_fulfillment_options',
            'Ordering backend returned malformed fulfillment data.',
        );

        if (
            ! is_array($options)
            || ! array_is_list($options)
        ) {
            throw $this->transport->invalidResponse(
                $response,
                'list_current_session_fulfillment_options',
                'Ordering backend returned malformed fulfillment data.',
            );
        }

        return array_map(
            function (mixed $option) use ($response): array {
                if (
                    ! is_array($option)
                    || ! $this->transport->isNonEmptyString(
                        $option['type'] ?? null,
                    )
                ) {
                    throw $this->transport->invalidResponse(
                        $response,
                        'list_current_session_fulfillment_options',
                        'Ordering backend returned malformed fulfillment data.',
                    );
                }

                /** @var array{type: string} $option */
                return [
                    'type' => $option['type'],
                ];
            },
            $options,
        );
    }

    /**
     * @return array{session_id: string, fulfillment: array<string, mixed>}
     */
    public function selectCurrentSessionFulfillment(
        string $sessionToken,
        string $type,
    ): array {
        $response = $this->transport->sessionBoundPut(
            sessionToken: $sessionToken,
            path: 'api/sessions/current/fulfillment',
            operation: 'select_current_session_fulfillment',
            message: 'Unable to select the ordering backend fulfillment type.',
            data: [
                'type' => $type,
            ],
        );

        return $this->fulfillmentStateFromResponse(
            $response,
            'select_current_session_fulfillment',
        );
    }

    /**
     * @return list<PickupAddressData>
     */
    public function currentSessionPickupAddresses(
        string $sessionToken,
    ): array {
        $response = $this->transport->sessionBoundGet(
            sessionToken: $sessionToken,
            path: 'api/sessions/current/pickup-addresses',
            operation: 'list_current_session_pickup_addresses',
            message: 'Unable to retrieve ordering backend pickup addresses.',
        );

        $addresses = $this->transport->responseData(
            $response,
            'list_current_session_pickup_addresses',
            'Ordering backend returned malformed pickup address data.',
        );

        if (
            ! is_array($addresses)
            || ! array_is_list($addresses)
        ) {
            throw $this->transport->invalidResponse(
                $response,
                'list_current_session_pickup_addresses',
                'Ordering backend returned malformed pickup address data.',
            );
        }

        return array_map(
            function (mixed $address) use ($response): PickupAddressData {
                if (! $this->isValidPickupAddress($address)) {
                    throw $this->transport->invalidResponse(
                        $response,
                        'list_current_session_pickup_addresses',
                        'Ordering backend returned malformed pickup address data.',
                    );
                }

                /** @var array{id: int, title?: string|null, latitude?: string|null, longitude?: string|null} $address */
                return new PickupAddressData(
                    id: $address['id'],
                    title: $address['title'] ?? null,
                    latitude: $address['latitude'] ?? null,
                    longitude: $address['longitude'] ?? null,
                );
            },
            $addresses,
        );
    }

    /**
     * @return array{session_id: string, fulfillment: array<string, mixed>}
     */
    public function selectCurrentSessionPickupAddress(
        string $sessionToken,
        int $restaurantAddressId,
    ): array {
        $response = $this->transport->sessionBoundPut(
            sessionToken: $sessionToken,
            path: 'api/sessions/current/pickup-address',
            operation: 'select_current_session_pickup_address',
            message: 'Unable to select the ordering backend pickup address.',
            data: [
                'restaurant_address_id' => $restaurantAddressId,
            ],
        );

        return $this->fulfillmentStateFromResponse(
            $response,
            'select_current_session_pickup_address',
        );
    }

    public function validateCurrentSessionDeliveryAddress(
        string $sessionToken,
        DeliveryAddressData $address,
    ): DeliveryValidationData {
        $response = $this->transport->sessionBoundPost(
            sessionToken: $sessionToken,
            path: 'api/sessions/current/delivery-address',
            operation: 'validate_current_session_delivery_address',
            message: 'Unable to validate the ordering backend delivery address.',
            data: $address->toArray(),
        );

        $data = $this->transport->responseData(
            $response,
            'validate_current_session_delivery_address',
            'Ordering backend returned malformed delivery address data.',
        );

        if (! $this->isValidDeliveryValidation($data)) {
            throw $this->transport->invalidResponse(
                $response,
                'validate_current_session_delivery_address',
                'Ordering backend returned malformed delivery address data.',
            );
        }

        /** @var array{session_id: string, delivery_available: bool, reason?: string|null, delivery_price: mixed, dots_delivery_type?: int|null, fulfillment: array<string, mixed>} $data */
        return new DeliveryValidationData(
            sessionId: $data['session_id'],
            deliveryAvailable: $data['delivery_available'],
            reason: $data['reason'] ?? null,
            deliveryPrice: $data['delivery_price'],
            dotsDeliveryType: $data['dots_delivery_type'] ?? null,
            fulfillment: $data['fulfillment'],
        );
    }

    private function isValidPickupAddress(
        mixed $address,
    ): bool {
        return is_array($address)
            && $this->transport->isPositiveInteger(
                $address['id'] ?? null,
            )
            && $this->transport->isOptionalString(
                $address['title'] ?? null,
            )
            && $this->transport->isOptionalString(
                $address['latitude'] ?? null,
            )
            && $this->transport->isOptionalString(
                $address['longitude'] ?? null,
            );
    }

    private function isValidDeliveryValidation(
        mixed $data,
    ): bool {
        return is_array($data)
            && $this->transport->isNonEmptyString(
                $data['session_id'] ?? null,
            )
            && is_bool(
                $data['delivery_available'] ?? null,
            )
            && $this->transport->isOptionalString(
                $data['reason'] ?? null,
            )
            && array_key_exists(
                'delivery_price',
                $data,
            )
            && $this->transport->isOptionalInteger(
                $data['dots_delivery_type'] ?? null,
            )
            && is_array(
                $data['fulfillment'] ?? null,
            );
    }

    private function isValidFulfillmentState(
        mixed $data,
    ): bool {
        return is_array($data)
            && $this->transport->isNonEmptyString(
                $data['session_id'] ?? null,
            )
            && is_array(
                $data['fulfillment'] ?? null,
            );
    }

    /**
     * @return array{session_id: string, fulfillment: array<string, mixed>}
     */
    private function fulfillmentStateFromResponse(
        Response $response,
        string $operation,
    ): array {
        $invalidMessage =
            'Ordering backend returned malformed fulfillment data.';

        $data = $this->transport->responseData(
            $response,
            $operation,
            $invalidMessage,
        );

        if (! $this->isValidFulfillmentState($data)) {
            throw $this->transport->invalidResponse(
                $response,
                $operation,
                $invalidMessage,
            );
        }

        /** @var array{session_id: string, fulfillment: array<string, mixed>} $data */
        return [
            'session_id' => $data['session_id'],
            'fulfillment' => $data['fulfillment'],
        ];
    }
}
