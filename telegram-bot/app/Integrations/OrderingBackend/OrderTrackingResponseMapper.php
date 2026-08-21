<?php

namespace App\Integrations\OrderingBackend;

use App\DTO\OrderingBackend\OrderTrackingCourierData;
use App\DTO\OrderingBackend\OrderTrackingData;
use App\DTO\OrderingBackend\OrderTrackingPositionData;
use Illuminate\Http\Client\Response;

final readonly class OrderTrackingResponseMapper
{
    private const OPERATION = 'get_order_tracking';

    private const INVALID_MESSAGE = 'Ordering backend returned malformed order tracking data.';

    public function __construct(
        private OrderingBackendTransport $transport,
    ) {
    }

    public function fromResponse(Response $response): OrderTrackingData
    {
        $tracking = $this->transport->responseData(
            $response,
            self::OPERATION,
            self::INVALID_MESSAGE,
        );

        if (! $this->isValidRoot($tracking)) {
            $this->invalid($response);
        }

        /** @var array{
         *     order_id: int,
         *     status: string,
         *     external_order_id: string|null,
         *     tracking_available: bool,
         *     number: string|null,
         *     company_name: string|null,
         *     completed_time: int|null,
         *     delivery: mixed,
         *     courier: mixed
         * } $tracking
         */
        $delivery = $this->delivery(
            $tracking['delivery'],
            $response,
        );

        return new OrderTrackingData(
            orderId: $tracking['order_id'],
            status: $tracking['status'],
            externalOrderId: $tracking['external_order_id'],
            trackingAvailable: $tracking['tracking_available'],
            number: $tracking['number'],
            companyName: $tracking['company_name'],
            completedTime: $tracking['completed_time'],
            deliveryType: $delivery['type'],
            deliveryAddress: $delivery['address'],
            courier: $this->courier(
                $tracking['courier'],
                $response,
            ),
        );
    }

    private function isValidRoot(mixed $tracking): bool
    {
        return is_array($tracking)
            && $this->transport->isPositiveInteger(
                $tracking['order_id'] ?? null,
            )
            && $this->transport->isNonEmptyString(
                $tracking['status'] ?? null,
            )
            && array_key_exists('external_order_id', $tracking)
            && $this->transport->isOptionalNonEmptyString(
                $tracking['external_order_id'],
            )
            && is_bool($tracking['tracking_available'] ?? null)
            && array_key_exists('number', $tracking)
            && $this->transport->isOptionalNonEmptyString(
                $tracking['number'],
            )
            && array_key_exists('company_name', $tracking)
            && $this->transport->isOptionalNonEmptyString(
                $tracking['company_name'],
            )
            && array_key_exists('completed_time', $tracking)
            && $this->transport->isOptionalInteger(
                $tracking['completed_time'],
            )
            && array_key_exists('delivery', $tracking)
            && array_key_exists('courier', $tracking);
    }

    /** @return array{type: string|null, address: string|null} */
    private function delivery(
        mixed $delivery,
        Response $response,
    ): array {
        if (
            ! is_array($delivery)
            || ! array_key_exists('type', $delivery)
            || ! $this->transport->isOptionalNonEmptyString(
                $delivery['type'],
            )
            || ! array_key_exists('address', $delivery)
            || ! $this->transport->isOptionalString(
                $delivery['address'],
            )
        ) {
            $this->invalid($response);
        }

        /** @var array{type: string|null, address: string|null} $delivery */
        return $delivery;
    }

    private function courier(
        mixed $courier,
        Response $response,
    ): ?OrderTrackingCourierData {
        if ($courier === null) {
            return null;
        }

        if (! $this->isValidCourier($courier)) {
            $this->invalid($response);
        }

        /** @var array{
         *     name: string|null,
         *     route_status: int|string|null,
         *     duration: int|null,
         *     last_updated: int|null,
         *     position: mixed
         * } $courier
         */
        return new OrderTrackingCourierData(
            name: $courier['name'],
            routeStatus: $courier['route_status'],
            duration: $courier['duration'],
            lastUpdated: $courier['last_updated'],
            position: $this->position(
                $courier['position'],
                $response,
            ),
        );
    }

    private function isValidCourier(mixed $courier): bool
    {
        if (! is_array($courier)) {
            return false;
        }

        $routeStatus = $courier['route_status'] ?? null;

        return array_key_exists('name', $courier)
            && $this->transport->isOptionalNonEmptyString(
                $courier['name'],
            )
            && array_key_exists('route_status', $courier)
            && ($routeStatus === null
                || is_int($routeStatus)
                || is_string($routeStatus))
            && array_key_exists('duration', $courier)
            && $this->transport->isOptionalInteger(
                $courier['duration'],
            )
            && array_key_exists('last_updated', $courier)
            && $this->transport->isOptionalInteger(
                $courier['last_updated'],
            )
            && array_key_exists('position', $courier);
    }

    private function position(
        mixed $position,
        Response $response,
    ): ?OrderTrackingPositionData {
        if ($position === null) {
            return null;
        }

        if (
            ! is_array($position)
            || ! $this->isCoordinate($position['latitude'] ?? null)
            || ! $this->isCoordinate($position['longitude'] ?? null)
        ) {
            $this->invalid($response);
        }

        /** @var array{latitude: int|float, longitude: int|float} $position */
        return new OrderTrackingPositionData(
            latitude: (float) $position['latitude'],
            longitude: (float) $position['longitude'],
        );
    }

    private function isCoordinate(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }

    private function invalid(Response $response): never
    {
        throw $this->transport->invalidResponse(
            $response,
            self::OPERATION,
            self::INVALID_MESSAGE,
        );
    }
}
