<?php

use App\DTO\OrderingBackend\CartData;
use App\DTO\OrderingBackend\CartItemData;
use App\Telegram\Formatting\CartMessageFormatter;

test('cart formatter renders an empty cart', function (): void {
    $cart = new CartData(
        id: 1,
        status: 'active',
        currency: 'UAH',
        subtotal: '0.00',
        total: '0.00',
        expiresAt: '2026-08-20T12:00:00+00:00',
        items: [],
    );

    expect((new CartMessageFormatter)->format($cart))->toBe(
        "🛒 Кошик\n\nКошик порожній.\n\nПроміжний підсумок: 0.00 UAH\nРазом: 0.00 UAH",
    );
});

test('cart formatter renders cart items and notice', function (): void {
    $cart = new CartData(
        id: 1,
        status: 'active',
        currency: 'UAH',
        subtotal: '200.00',
        total: '200.00',
        expiresAt: '2026-08-20T12:00:00+00:00',
        items: [
            new CartItemData(
                id: 5,
                productId: 12,
                externalProductId: 'product-external',
                name: 'Pizza',
                quantity: 2,
                unitPrice: '100.00',
                total: '200.00',
            ),
        ],
    );

    $formatter = new CartMessageFormatter;

    expect($formatter->format($cart))->toContain('Pizza')
        ->and($formatter->format($cart))->toContain('2 × 100.00 UAH = 200.00 UAH')
        ->and($formatter->formatWithNotice($cart, 'Notice'))->toStartWith("Notice\n\n🛒 Кошик");
});
