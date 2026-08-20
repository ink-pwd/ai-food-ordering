<?php

namespace App\DTO;

final readonly class OrderFulfillmentSnapshotData
{
    /** @param  array<string, mixed>|null  $deliveryAddress */
    public function __construct(
        public int $cityId,
        public string $externalCityId,
        public int $restaurantId,
        public string $externalCompanyId,
        public string $type,
        public int $dotsDeliveryType,
        public mixed $deliveryPrice,
        public mixed $priceValidationDeliveryPrice,
        public int $paymentType,
        public ?int $restaurantAddressId,
        public ?string $externalAddressId,
        public ?array $deliveryAddress,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'city_id' => $this->cityId,
            'external_city_id' => $this->externalCityId,
            'restaurant_id' => $this->restaurantId,
            'external_company_id' => $this->externalCompanyId,
            'type' => $this->type,
            'dots_delivery_type' => $this->dotsDeliveryType,
            'delivery_price' => $this->deliveryPrice,
            'price_validation_delivery_price' => $this->priceValidationDeliveryPrice,
            'payment_type' => $this->paymentType,
            'restaurant_address_id' => $this->restaurantAddressId,
            'external_address_id' => $this->externalAddressId,
            'delivery_address' => $this->deliveryAddress,
        ];
    }
}
