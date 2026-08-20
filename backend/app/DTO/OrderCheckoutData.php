<?php

namespace App\DTO;

use App\Enums\PaymentType;
use App\Enums\ReceivingType;
use App\Models\Cart;
use App\Models\City;
use App\Models\Restaurant;

final readonly class OrderCheckoutData
{
    public function __construct(
        public City $city,
        public Restaurant $restaurant,
        public PaymentType $paymentType,
        public Cart $cart,
        public string $customerName,
        public string $customerPhone,
        public ReceivingType $receivingType,
        public OrderFulfillmentContextData $fulfillmentContext,
    ) {
    }
}
