<?php

namespace App\DTO\OrderingBackend;

final readonly class CityData
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public string $currency,
        public string $timezone,
        public ?string $centerLatitude,
        public ?string $centerLongitude,
    ) {
    }
}
