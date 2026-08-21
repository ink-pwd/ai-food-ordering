<?php

namespace App\DTO\OrderingBackend;

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
        public ?OrderTrackingCourierData $courier,
    ) {
    }
}
