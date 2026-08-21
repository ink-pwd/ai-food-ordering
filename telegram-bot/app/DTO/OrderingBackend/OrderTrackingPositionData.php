<?php

namespace App\DTO\OrderingBackend;

final readonly class OrderTrackingPositionData
{
    public function __construct(
        public float $latitude,
        public float $longitude,
    ) {
    }
}
