<?php

namespace App\DTO\OrderingBackend;

final readonly class CurrentPaymentData
{
    public function __construct(
        public string $status,
        public ?string $checkoutUrl,
        public ?string $paymentReceivedAt,
        public int $httpStatus,
    ) {
    }
}
