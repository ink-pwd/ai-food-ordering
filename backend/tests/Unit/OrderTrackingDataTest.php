<?php

use App\DTO\OrderTrackingData;

test('order tracking data preserves the internal api contract', function (): void {
    $tracking = new OrderTrackingData(
        orderId: 42,
        status: 'created',
        externalOrderId: 'dots-order-id',
        trackingAvailable: true,
        number: '976-91940',
        companyName: 'Jack\'s Burgers',
        completedTime: null,
        deliveryType: 'Door delivery',
        deliveryAddress: 'Main Street, 10',
        courierName: 'Andrew 127',
        courierRouteStatus: 10,
        courierRouteDuration: 54,
        courierLastUpdated: 1622118255,
        courierLatitude: 51.496267236017,
        courierLongitude: 31.306502453193,
    );

    expect($tracking->toArray())->toBe([
        'order_id' => 42,
        'status' => 'created',
        'external_order_id' => 'dots-order-id',
        'tracking_available' => true,
        'number' => '976-91940',
        'company_name' => 'Jack\'s Burgers',
        'completed_time' => null,
        'delivery' => [
            'type' => 'Door delivery',
            'address' => 'Main Street, 10',
        ],
        'courier' => [
            'name' => 'Andrew 127',
            'route_status' => 10,
            'duration' => 54,
            'last_updated' => 1622118255,
            'position' => [
                'latitude' => 51.496267236017,
                'longitude' => 31.306502453193,
            ],
        ],
    ]);
});

test('order tracking data omits an unavailable courier', function (): void {
    $tracking = new OrderTrackingData(
        orderId: 42,
        status: 'creating',
        externalOrderId: 'dots-order-id',
        trackingAvailable: false,
        number: null,
        companyName: null,
        completedTime: null,
        deliveryType: null,
        deliveryAddress: null,
        courierName: null,
        courierRouteStatus: null,
        courierRouteDuration: null,
        courierLastUpdated: null,
        courierLatitude: null,
        courierLongitude: null,
    );

    expect($tracking->toArray()['courier'])->toBeNull();
});
