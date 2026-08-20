<?php

use App\Telegram\Support\RestaurantNavigationContext;

test('restaurant navigation context is deterministic for a session token', function (): void {
    $context = new RestaurantNavigationContext;
    $token = str_repeat('a', 64);

    expect($context->fingerprint($token))->toHaveLength(12)
        ->and($context->encode(11, $token))->toBe('11:'.$context->fingerprint($token));
});

test('restaurant navigation context validates restaurant and token fingerprint', function (): void {
    $context = new RestaurantNavigationContext;
    $token = str_repeat('a', 64);
    $fingerprint = $context->fingerprint($token);

    expect($context->isValid(11, $fingerprint, $token))->toBeTrue()
        ->and($context->isValid(0, $fingerprint, $token))->toBeFalse()
        ->and($context->isValid(11, $fingerprint, str_repeat('b', 64)))->toBeFalse();
});
