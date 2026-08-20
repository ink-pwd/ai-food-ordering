<?php

namespace App\DTO\OrderingBackend;

final readonly class PickupAddressData
{
    public function __construct(
        public int $id,
        public ?string $title,
        public ?string $latitude,
        public ?string $longitude,
    ) {
    }
}
