<?php

use App\Services\Support\FulfillmentSelection;

test('delivery type selection prefers type zero regardless of response order', function (): void {
    $typeOne = ['type' => 1, 'price' => '50.00'];
    $typeZero = ['type' => 0, 'price' => '75.00'];

    expect(FulfillmentSelection::acceptableDeliveryType([$typeOne, $typeZero]))->toBe($typeZero);
});

test('delivery type selection falls back to type one', function (): void {
    $typeOne = ['type' => 1, 'price' => '50.00'];

    expect(FulfillmentSelection::acceptableDeliveryType([
        ['type' => 2, 'price' => '0.00'],
        $typeOne,
    ]))->toBe($typeOne);
});

test('delivery type selection returns null when delivery is unavailable', function (): void {
    expect(FulfillmentSelection::acceptableDeliveryType([
        ['type' => 2, 'price' => '0.00'],
    ]))->toBeNull();
});
