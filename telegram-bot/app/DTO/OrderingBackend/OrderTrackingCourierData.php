<?php

namespace App\DTO\OrderingBackend;

final readonly class OrderTrackingCourierData
{
    public function __construct(
        public ?string $name,
        public int|string|null $routeStatus,
        public ?int $duration,
        public ?int $lastUpdated,
        public ?OrderTrackingPositionData $position,
    ) {
    }
}
