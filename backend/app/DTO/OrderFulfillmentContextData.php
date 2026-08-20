<?php

namespace App\DTO;

use App\Enums\ReceivingType;
use App\Models\City;
use App\Models\Restaurant;
use App\Models\RestaurantAddress;

final readonly class OrderFulfillmentContextData
{
    /** @param  array<string, mixed>|null  $deliveryAddress */
    public function __construct(
        public string $type,
        public ReceivingType $receivingType,
        public City $city,
        public Restaurant $restaurant,
        public ?RestaurantAddress $restaurantAddress,
        public int $deliveryType,
        public mixed $deliveryPrice,
        public ?array $deliveryAddress,
    ) {
    }
}
