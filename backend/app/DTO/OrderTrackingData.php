<?php

namespace App\DTO;

final readonly class OrderTrackingData
{
    public function __construct(
        public int $orderId,
        public string $status,
        public ?string $externalOrderId,
        public bool $trackingAvailable,
        public ?string $number,
        public ?string $companyName,
        public ?int $completedTime,
        public ?string $deliveryType,
        public ?string $deliveryAddress,
        public ?string $courierName,
        public int|string|null $courierRouteStatus,
        public ?int $courierRouteDuration,
        public ?int $courierLastUpdated,
        public ?float $courierLatitude,
        public ?float $courierLongitude,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'order_id' => $this->orderId,
            'status' => $this->status,
            'external_order_id' => $this->externalOrderId,
            'tracking_available' => $this->trackingAvailable,
            'number' => $this->number,
            'company_name' => $this->companyName,
            'completed_time' => $this->completedTime,
            'delivery' => [
                'type' => $this->deliveryType,
                'address' => $this->deliveryAddress,
            ],
            'courier' => $this->courierName === null
                && $this->courierRouteStatus === null
                && $this->courierRouteDuration === null
                && $this->courierLastUpdated === null
                && $this->courierLatitude === null
                && $this->courierLongitude === null
                    ? null
                    : [
                        'name' => $this->courierName,
                        'route_status' => $this->courierRouteStatus,
                        'duration' => $this->courierRouteDuration,
                        'last_updated' => $this->courierLastUpdated,
                        'position' => $this->courierLatitude === null || $this->courierLongitude === null
                            ? null
                            : [
                                'latitude' => $this->courierLatitude,
                                'longitude' => $this->courierLongitude,
                            ],
                    ],
        ];
    }
}
