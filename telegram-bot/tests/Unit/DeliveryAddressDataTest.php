<?php

use App\DTO\OrderingBackend\DeliveryAddressData;

test('delivery address serializes required fields only when optionals are absent', function (): void {
    $address = new DeliveryAddressData(
        type: 1,
        street: 'Main Street',
        house: '10',
    );

    expect($address->toArray())->toBe([
        'type' => 1,
        'street' => 'Main Street',
        'house' => '10',
    ]);
});

test('delivery address preserves optional fields when provided', function (): void {
    $address = new DeliveryAddressData(
        type: 1,
        street: 'Main Street',
        house: '10',
        flat: '12',
        stage: '3',
        note: 'Call on arrival',
        title: 'Home',
    );

    expect($address->toArray())->toBe([
        'type' => 1,
        'street' => 'Main Street',
        'house' => '10',
        'flat' => '12',
        'stage' => '3',
        'note' => 'Call on arrival',
        'title' => 'Home',
    ]);
});
