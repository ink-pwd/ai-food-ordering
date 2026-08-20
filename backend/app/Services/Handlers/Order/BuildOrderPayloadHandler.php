<?php

namespace App\Services\Handlers\Order;

use App\DTO\OrderCheckoutData;
use App\Enums\FulfillmentType;
use App\Models\CartItem;
use Illuminate\Database\Eloquent\Collection;

class BuildOrderPayloadHandler
{
    /**
     * @return array<string, mixed>
     */
    public function handle(
        OrderCheckoutData $checkout,
        int $deliveryTime,
    ): array {
        $context = $checkout->fulfillmentContext;

        /** @var Collection<int, CartItem> $items */
        $items = $checkout->cart->items;

        $fields = [
            'cityId' => $checkout->city->external_city_id,
            'companyId' => $checkout->restaurant->external_company_id,
            'userName' => $checkout->customerName,
            'userPhone' => $checkout->customerPhone,
            'deliveryType' => $context->deliveryType,
            'paymentType' => $checkout->paymentType->value,
            'deliveryTime' => $deliveryTime,
            'cartItems' => $items
                ->map(static fn (CartItem $item): array => [
                    'id' => $item->external_product_id,
                    'count' => $item->quantity,
                ])
                ->values()
                ->all(),
        ];

        if (
            $context->type
            === FulfillmentType::Pickup->value
        ) {
            /** @var \App\Models\RestaurantAddress $address */
            $address = $context->restaurantAddress;

            $fields['companyAddressId'] =
                $address->external_address_id;
        } else {
            /** @var array<string, mixed> $deliveryAddress */
            $deliveryAddress = $context->deliveryAddress;

            $fields['deliveryAddressStreet'] =
                $deliveryAddress['street'];

            $fields['deliveryAddressHouse'] =
                $deliveryAddress['house'];
        }

        return [
            'orderFields' => $fields,
        ];
    }
}
