<?php

use App\DTO\OrderingBackend\OrderTrackingCourierData;
use App\DTO\OrderingBackend\OrderTrackingData;
use App\DTO\OrderingBackend\OrderTrackingPositionData;
use App\Telegram\Formatting\OrderTrackingMessageFormatter;

function trackingForFormatterTest(
    ?string $deliveryAddress = 'Main Street, 10, 12',
    bool $trackingAvailable = true,
): OrderTrackingData {
    return new OrderTrackingData(
        orderId: 42,
        status: 'created',
        externalOrderId: 'dots-order-id',
        trackingAvailable: $trackingAvailable,
        number: $trackingAvailable ? '976-91940' : null,
        companyName: $trackingAvailable ? "Jack's Burgers" : null,
        completedTime: null,
        deliveryType: $trackingAvailable ? 'Door delivery' : null,
        deliveryAddress: $trackingAvailable ? $deliveryAddress : null,
        courier: $trackingAvailable
            ? new OrderTrackingCourierData(
                name: 'Andrew 127',
                routeStatus: 10,
                duration: 54,
                lastUpdated: 1622118255,
                position: new OrderTrackingPositionData(
                    latitude: 51.496267,
                    longitude: 31.306502,
                ),
            )
            : null,
    );
}

test('order tracking formatter masks delivery address after the street', function (): void {
    $message = (new OrderTrackingMessageFormatter)->format(
        trackingForFormatterTest(),
    );

    expect($message)->toContain('Замовлення #42')
        ->and($message)->toContain("🍽 Ресторан: Jack's Burgers")
        ->and($message)->toContain('📍 Адреса: Main Street, **')
        ->and($message)->toContain("🛵 Кур'єр: Andrew 127")
        ->and($message)->not->toContain('Main Street, 10')
        ->and($message)->not->toContain(', 12');
});

test('order tracking formatter never exposes an unstructured delivery address', function (): void {
    $message = (new OrderTrackingMessageFormatter)->format(
        trackingForFormatterTest('Main Street 10 apartment 12'),
    );

    expect($message)->toContain('📍 Адреса: **')
        ->and($message)->not->toContain('Main Street 10 apartment 12');
});

test('order tracking formatter explains when live tracking is not available yet', function (): void {
    $message = (new OrderTrackingMessageFormatter)->format(
        trackingForFormatterTest(trackingAvailable: false),
    );

    expect($message)->toContain('Замовлення #42')
        ->and($message)->toContain('Актуальні дані Dots ще недоступні')
        ->and($message)->not->toContain('📍 Адреса:');
});
