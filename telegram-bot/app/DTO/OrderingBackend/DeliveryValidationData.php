<?php

namespace App\DTO\OrderingBackend;

final readonly class DeliveryValidationData
{
    /**
     * @param  array<string, mixed>  $fulfillment
     */
    public function __construct(
        public string $sessionId,
        public bool $deliveryAvailable,
        public ?string $reason,
        public mixed $deliveryPrice,
        public ?int $dotsDeliveryType,
        public array $fulfillment,
    ) {
    }
}
