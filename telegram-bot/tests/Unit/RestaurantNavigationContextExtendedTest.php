<?php

use App\Telegram\Support\RestaurantNavigationContext;

test('navigation fingerprint is deterministic for identical token', function (): void {
    $context = new RestaurantNavigationContext;

    expect($context->fingerprint('token'))->toBe($context->fingerprint('token'));
});

test('navigation fingerprint changes when token changes', function (): void {
    $context = new RestaurantNavigationContext;

    expect($context->fingerprint('token-a'))->not->toBe($context->fingerprint('token-b'));
});

test('navigation fingerprint is a twelve character lowercase hex digest prefix', function (): void {
    expect((new RestaurantNavigationContext)->fingerprint('token'))->toMatch('/\A[a-f0-9]{12}\z/');
});

test('navigation encoding preserves restaurant id and current fingerprint', function (int $restaurantId): void {
    $context = new RestaurantNavigationContext;
    $token = 'session-token';

    expect($context->encode($restaurantId, $token))->toBe($restaurantId.':'.$context->fingerprint($token));
})->with([1, 42, 999]);

test('navigation validation rejects invalid restaurant or fingerprint', function (int $restaurantId, string $fingerprint, bool $expected): void {
    $context = new RestaurantNavigationContext;
    $token = 'session-token';
    $actualFingerprint = $context->fingerprint($token);
    $resolvedFingerprint = $fingerprint === 'valid' ? $actualFingerprint : $fingerprint;

    expect($context->isValid($restaurantId, $resolvedFingerprint, $token))->toBe($expected);
})->with([
    'zero restaurant' => [0, 'valid', false],
    'wrong fingerprint' => [10, '000000000000', false],
]);
