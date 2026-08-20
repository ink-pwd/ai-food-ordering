<?php

use App\DTO\SessionData;
use App\Models\Restaurant;
use App\Services\Support\FulfillmentSelection;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

function fulfillmentSelectionSession(?array $fulfillment): SessionData
{
    return new SessionData(
        id: 'session-1',
        cityId: 1,
        restaurantId: 1,
        channel: 'telegram',
        externalSessionId: 'chat-1',
        status: 'active',
        metadata: [],
        createdAt: '2026-01-01T00:00:00+00:00',
        expiresAt: '2099-01-01T00:00:00+00:00',
        fulfillment: $fulfillment,
    );
}

test('pickup support follows Dots delivery type two', function (array $types, bool $expected): void {
    $restaurant = new Restaurant;
    $restaurant->available_delivery_types = $types;

    expect(FulfillmentSelection::supportsPickup($restaurant))->toBe($expected);
})->with([
    'pickup integer' => [[2], true],
    'pickup string' => [['2'], true],
    'mixed types' => [[0, 2], true],
    'delivery only' => [[0, 1], false],
]);

test('delivery support accepts Dots delivery type zero or one', function (array $types, bool $expected): void {
    $restaurant = new Restaurant;
    $restaurant->available_delivery_types = $types;

    expect(FulfillmentSelection::supportsDelivery($restaurant))->toBe($expected);
})->with([
    'type zero' => [[0], true],
    'type one' => [[1], true],
    'string zero' => [['0'], true],
    'pickup only' => [[2], false],
    'empty' => [[], false],
]);

test('ready fulfillment accepts complete pickup and delivery selections', function (array $fulfillment): void {
    FulfillmentSelection::assertReady(fulfillmentSelectionSession($fulfillment));

    expect(true)->toBeTrue();
})->with([
    'pickup' => [['type' => 'pickup', 'restaurant_address_id' => 7]],
    'pickup first id' => [['type' => 'pickup', 'restaurant_address_id' => 1]],
    'delivery type zero' => [[
        'type' => 'delivery',
        'dots_delivery_type' => 0,
        'delivery_price' => '0.00',
        'delivery_address' => ['street' => 'Main', 'house' => '10'],
    ]],
    'delivery type one' => [[
        'type' => 'delivery',
        'dots_delivery_type' => 1,
        'delivery_price' => '50.00',
        'delivery_address' => [],
    ]],
]);

test('ready fulfillment rejects incomplete selection', function (?array $fulfillment, string $message): void {
    expect(fn () => FulfillmentSelection::assertReady(fulfillmentSelectionSession($fulfillment)))
        ->toThrow(ConflictHttpException::class, $message);
})->with([
    'nothing selected' => [null, 'Fulfillment must be selected.'],
    'pickup address missing' => [['type' => 'pickup'], 'Pickup location must be selected.'],
    'pickup address zero' => [['type' => 'pickup', 'restaurant_address_id' => 0], 'Pickup location must be selected.'],
    'delivery type missing' => [[
        'type' => 'delivery',
        'delivery_price' => '50.00',
        'delivery_address' => [],
    ], 'Validated delivery address is required.'],
    'delivery price missing' => [[
        'type' => 'delivery',
        'dots_delivery_type' => 1,
        'delivery_address' => [],
    ], 'Validated delivery address is required.'],
    'unknown fulfillment type' => [['type' => 'courier'], 'Fulfillment must be selected.'],
]);

test('acceptable delivery type accepts numeric string values and preserves the selected payload', function (array $types, ?array $expected): void {
    expect(FulfillmentSelection::acceptableDeliveryType($types))->toBe($expected);
})->with([
    'numeric string zero' => [[['type' => '0', 'price' => '10.00']], ['type' => '0', 'price' => '10.00']],
    'numeric string one' => [[['type' => '2'], ['type' => '1', 'price' => '20.00']], ['type' => '1', 'price' => '20.00']],
    'no recognized delivery type' => [[['type' => 2], ['type' => 3]], null],
]);
