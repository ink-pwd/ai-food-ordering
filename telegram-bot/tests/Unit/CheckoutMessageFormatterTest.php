<?php

use App\DTO\OrderingBackend\CartData;
use App\Telegram\Formatting\CheckoutMessageFormatter;

test('checkout confirmation preserves total and currency', function (string $total, string $currency): void {
    $cart = new CartData(1, 'active', $currency, $total, $total, 'expires', []);
    $message = (new CheckoutMessageFormatter)->confirmation($cart);

    expect($message)->toBe(
        "Оформлення замовлення\n\nСамовивіз\nОплата готівкою\nЧас: якнайшвидше\n\nРазом: {$total} {$currency}\n\nПідтвердити замовлення?",
    );
})->with([
    ['0.00', 'UAH'],
    ['100.00', 'UAH'],
    ['12.50', 'USD'],
    ['9999.99', 'EUR'],
]);
