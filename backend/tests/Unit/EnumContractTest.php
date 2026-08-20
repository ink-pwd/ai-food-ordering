<?php

use App\Enums\CartStatus;
use App\Enums\CatalogSyncStatus;
use App\Enums\FulfillmentType;
use App\Enums\OrderStatus;
use App\Enums\PaymentType;
use App\Enums\ReceivingType;
use App\Enums\SessionChannel;
use App\Enums\SessionStatus;

/** @param class-string<BackedEnum> $enumClass */
test('backed enum values remain stable', function (string $enumClass, string $case, string|int $expected): void {
    $enum = constant($enumClass.'::'.$case);

    expect($enum)->toBeInstanceOf($enumClass)
        ->and($enum->value)->toBe($expected);
})->with([
    'cart active' => [CartStatus::class, 'Active', 'active'],
    'cart checked out' => [CartStatus::class, 'CheckedOut', 'checked_out'],
    'cart expired' => [CartStatus::class, 'Expired', 'expired'],
    'cart abandoned' => [CartStatus::class, 'Abandoned', 'abandoned'],
    'catalog running' => [CatalogSyncStatus::class, 'Running', 'running'],
    'catalog succeeded' => [CatalogSyncStatus::class, 'Succeeded', 'succeeded'],
    'catalog failed' => [CatalogSyncStatus::class, 'Failed', 'failed'],
    'fulfillment delivery' => [FulfillmentType::class, 'Delivery', 'delivery'],
    'fulfillment pickup' => [FulfillmentType::class, 'Pickup', 'pickup'],
    'order draft' => [OrderStatus::class, 'Draft', 'draft'],
    'order creating' => [OrderStatus::class, 'Creating', 'creating'],
    'order created' => [OrderStatus::class, 'Created', 'created'],
    'order awaiting payment' => [OrderStatus::class, 'AwaitingPayment', 'awaiting_payment'],
    'order paid' => [OrderStatus::class, 'Paid', 'paid'],
    'order failed' => [OrderStatus::class, 'Failed', 'failed'],
    'order cancelled' => [OrderStatus::class, 'Cancelled', 'cancelled'],
    'payment cash' => [PaymentType::class, 'Cash', 1],
    'payment online' => [PaymentType::class, 'Online', 2],
    'payment terminal' => [PaymentType::class, 'Terminal', 3],
    'receiving delivery' => [ReceivingType::class, 'Delivery', 'delivery'],
    'receiving pickup' => [ReceivingType::class, 'Pickup', 'pickup'],
    'channel chatgpt' => [SessionChannel::class, 'ChatGPT', 'chatgpt'],
    'channel telegram' => [SessionChannel::class, 'Telegram', 'telegram'],
    'channel api' => [SessionChannel::class, 'Api', 'api'],
    'session active' => [SessionStatus::class, 'Active', 'active'],
    'session closed' => [SessionStatus::class, 'Closed', 'closed'],
]);

test('representative backed enums reject unknown values', function (string $enumClass, string|int $value): void {
    expect($enumClass::tryFrom($value))->toBeNull();
})->with([
    'cart status' => [CartStatus::class, 'unknown'],
    'order status' => [OrderStatus::class, 'unknown'],
    'payment type' => [PaymentType::class, 999],
    'session channel' => [SessionChannel::class, 'unknown'],
]);
