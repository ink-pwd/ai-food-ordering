<?php

use App\DTO\OrderFulfillmentSnapshotData;

test('order fulfillment snapshot preserves its persistence keys', function (): void {
    $snapshot = new OrderFulfillmentSnapshotData(
        cityId: 7,
        externalCityId: 'city-external',
        restaurantId: 11,
        externalCompanyId: 'company-external',
        type: 'delivery',
        dotsDeliveryType: 1,
        deliveryPrice: '75.00',
        priceValidationDeliveryPrice: '75.00',
        paymentType: 2,
        restaurantAddressId: null,
        externalAddressId: null,
        deliveryAddress: [
            'street' => 'Main Street',
            'house' => '10',
        ],
    );

    expect($snapshot->toArray())->toBe([
        'city_id' => 7,
        'external_city_id' => 'city-external',
        'restaurant_id' => 11,
        'external_company_id' => 'company-external',
        'type' => 'delivery',
        'dots_delivery_type' => 1,
        'delivery_price' => '75.00',
        'price_validation_delivery_price' => '75.00',
        'payment_type' => 2,
        'restaurant_address_id' => null,
        'external_address_id' => null,
        'delivery_address' => [
            'street' => 'Main Street',
            'house' => '10',
        ],
    ]);
});
