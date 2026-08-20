<?php

namespace App\DTO\OrderingBackend;

final readonly class OrderPaymentData
{
    public function __construct(
        public string $status,
        public ?string $checkoutUrl,
        public ?string $paymentReceivedAt,
        public bool $qrReady,
    ) {
    }
}
