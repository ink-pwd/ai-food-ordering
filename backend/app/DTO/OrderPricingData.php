<?php

namespace App\DTO;

final readonly class OrderPricingData
{
    /** @param  array<string, mixed>  $validation */
    public function __construct(
        public array $validation,
        public string $validatedTotal,
        public OrderFulfillmentSnapshotData $fulfillmentSnapshot,
    ) {
    }
}
