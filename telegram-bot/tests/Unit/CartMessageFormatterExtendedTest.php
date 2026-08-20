<?php

use App\DTO\OrderingBackend\CartData;
use App\DTO\OrderingBackend\CartItemData;
use App\Telegram\Formatting\CartMessageFormatter;

function cartFormatterCart(array $items, string $subtotal = '100.00', string $total = '100.00', string $currency = 'UAH'): CartData
{
    return new CartData(1, 'active', $currency, $subtotal, $total, 'expires', $items);
}

test('cart formatter preserves item quantity and price representation', function (int $quantity, string $unitPrice, string $total): void {
    $item = new CartItemData(1, 2, 'external', 'Item', $quantity, $unitPrice, $total);
    $message = (new CartMessageFormatter)->format(cartFormatterCart([$item], $total, $total));

    expect($message)->toContain("{$quantity} × {$unitPrice} UAH = {$total} UAH");
})->with([
    [1, '100.00', '100.00'],
    [2, '50.00', '100.00'],
    [10, '9.99', '99.90'],
]);

test('cart formatter keeps multiple items in their input order', function (): void {
    $first = new CartItemData(1, 1, 'first', 'First', 1, '10.00', '10.00');
    $second = new CartItemData(2, 2, 'second', 'Second', 2, '20.00', '40.00');
    $message = (new CartMessageFormatter)->format(cartFormatterCart([$first, $second], '50.00', '50.00'));

    expect(strpos($message, 'First'))->toBeLessThan(strpos($message, 'Second'));
});

test('cart notice is placed before cart contents unchanged', function (): void {
    $cart = cartFormatterCart([], '0.00', '0.00');

    expect((new CartMessageFormatter)->formatWithNotice($cart, '⚠️ Notice'))
        ->toStartWith("⚠️ Notice\n\n🛒 Кошик");
});
