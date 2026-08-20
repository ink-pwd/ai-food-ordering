<?php

namespace App\DTO\OrderingBackend;

final readonly class RestaurantData
{
    /**
     * @param  list<int>  $availablePaymentTypes
     * @param  list<int>  $availableDeliveryTypes
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public ?string $imageUrl,
        public string $currency,
        public string $locale,
        public string $timezone,
        public array $availablePaymentTypes,
        public array $availableDeliveryTypes,
        public ?string $deliveryTimeText,
        public ?string $deliveryPriceText,
    ) {
    }
}
