<?php

namespace App\DTO\OrderingBackend;

final readonly class OrderData
{
    /**
     * @param  array<string, mixed>  $fulfillment
     * @param  list<OrderItemData>  $items
     */
    public function __construct(
        public int $id,
        public ?string $externalOrderId,
        public string $status,
        public ?string $failureMessage,
        public string $receivingType,
        public int $paymentType,
        public array $fulfillment,
        public string $total,
        public string $currency,
        public OrderPaymentData $payment,
        public array $items,
    ) {
    }
}
