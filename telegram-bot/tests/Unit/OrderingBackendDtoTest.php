<?php

use App\DTO\OrderingBackend\CartData;
use App\DTO\OrderingBackend\CartItemData;
use App\DTO\OrderingBackend\CityData;
use App\DTO\OrderingBackend\OrderData;
use App\DTO\OrderingBackend\OrderItemData;
use App\DTO\OrderingBackend\OrderPaymentData;
use App\DTO\OrderingBackend\RestaurantData;

test('cart dto keeps typed cart items', function (): void {
    $item = new CartItemData(
        id: 5,
        productId: 12,
        externalProductId: 'product-external',
        name: 'Pizza',
        quantity: 2,
        unitPrice: '100.00',
        total: '200.00',
    );

    $cart = new CartData(
        id: 3,
        status: 'active',
        currency: 'UAH',
        subtotal: '200.00',
        total: '200.00',
        expiresAt: '2026-08-20T12:00:00+00:00',
        items: [$item],
    );

    expect($cart->items)->toHaveCount(1)
        ->and($cart->items[0])->toBe($item)
        ->and($cart->items[0]->quantity)->toBe(2);
});

test('order dto keeps payment and order item objects', function (): void {
    $payment = new OrderPaymentData(
        status: 'ready',
        checkoutUrl: 'https://payment.example/checkout',
        paymentReceivedAt: null,
        qrReady: true,
    );

    $item = new OrderItemData(
        productId: 12,
        externalProductId: 'product-external',
        name: 'Pizza',
        quantity: 1,
        unitPrice: '100.00',
        total: '100.00',
    );

    $order = new OrderData(
        id: 9,
        externalOrderId: 'dots-order',
        status: 'created',
        failureMessage: null,
        receivingType: 'delivery',
        paymentType: 2,
        fulfillment: ['delivery_address' => ['street' => 'Main Street', 'house' => '10']],
        total: '100.00',
        currency: 'UAH',
        payment: $payment,
        items: [$item],
    );

    expect($order->payment)->toBe($payment)
        ->and($order->items)->toBe([$item])
        ->and($order->payment->qrReady)->toBeTrue();
});

test('selection dtos preserve backend identifiers and context', function (): void {
    $city = new CityData(
        id: 7,
        name: 'Kyiv',
        slug: 'kyiv',
        currency: 'UAH',
        timezone: 'Europe/Kyiv',
        centerLatitude: '50.4501',
        centerLongitude: '30.5234',
    );

    $restaurant = new RestaurantData(
        id: 11,
        name: 'Restaurant',
        slug: 'restaurant',
        imageUrl: null,
        currency: 'UAH',
        locale: 'uk-UA',
        timezone: 'Europe/Kyiv',
        availablePaymentTypes: [1, 2, 3],
        availableDeliveryTypes: [0, 1, 2],
        deliveryTimeText: '30-45 min',
        deliveryPriceText: 'from 50 UAH',
    );

    expect($city->id)->toBe(7)
        ->and($city->slug)->toBe('kyiv')
        ->and($restaurant->id)->toBe(11)
        ->and($restaurant->slug)->toBe('restaurant')
        ->and($restaurant->availablePaymentTypes)->toBe([1, 2, 3])
        ->and($restaurant->availableDeliveryTypes)->toBe([0, 1, 2]);
});
