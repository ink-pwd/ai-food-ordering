<?php

namespace App\DTO;

final readonly class DeliveryAddressValidationResultData
{
    public function __construct(
        public SessionData $session,
        public bool $deliveryAvailable,
        public ?string $reason,
        public mixed $deliveryPrice,
        public ?int $dotsDeliveryType,
    ) {
    }
}
