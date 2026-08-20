<?php

use App\DTO\SessionData;
use App\Enums\PaymentType;
use App\Models\Restaurant;
use App\Services\Support\PaymentSelection;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

function paymentSelectionSession(mixed $paymentType): SessionData
{
    return new SessionData(
        id: 'session-1',
        cityId: 1,
        restaurantId: 1,
        channel: 'telegram',
        externalSessionId: 'chat-1',
        status: 'active',
        metadata: ['payment_type' => $paymentType],
        createdAt: '2026-01-01T00:00:00+00:00',
        expiresAt: '2099-01-01T00:00:00+00:00',
    );
}

test('payment selection resolves supported enum values from numeric metadata', function (int|string $value, PaymentType $expected): void {
    expect(PaymentSelection::type(paymentSelectionSession($value)))->toBe($expected);
})->with([
    'cash int' => [1, PaymentType::Cash],
    'online int' => [2, PaymentType::Online],
    'terminal int' => [3, PaymentType::Terminal],
    'cash numeric string' => ['1', PaymentType::Cash],
    'online numeric string' => ['2', PaymentType::Online],
    'terminal numeric string' => ['3', PaymentType::Terminal],
]);

test('payment selection rejects missing or unsupported metadata', function (mixed $value): void {
    expect(fn () => PaymentSelection::type(paymentSelectionSession($value)))
        ->toThrow(ConflictHttpException::class, 'Payment method must be selected.');
})->with([
    'null' => [null],
    'zero' => [0],
    'unknown number' => [4],
    'name instead of value' => ['online'],
    'blank' => [''],
]);

test('restaurant payment support accepts configured payment types', function (array $available, PaymentType $paymentType): void {
    $restaurant = new Restaurant;
    $restaurant->available_payment_types = $available;

    PaymentSelection::assertSupported($restaurant, $paymentType);

    expect(true)->toBeTrue();
})->with([
    'integer values' => [[1, 2, 3], PaymentType::Online],
    'string values from payload' => [['1', '3'], PaymentType::Terminal],
]);

test('restaurant payment support rejects unavailable payment types', function (array $available, PaymentType $paymentType): void {
    $restaurant = new Restaurant;
    $restaurant->available_payment_types = $available;

    expect(fn () => PaymentSelection::assertSupported($restaurant, $paymentType))
        ->toThrow(ConflictHttpException::class, 'Selected payment method is not available for this restaurant.');
})->with([
    'empty list' => [[], PaymentType::Cash],
    'different value' => [[1, 3], PaymentType::Online],
]);
