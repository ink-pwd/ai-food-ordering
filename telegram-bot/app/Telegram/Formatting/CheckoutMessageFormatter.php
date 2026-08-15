<?php

namespace App\Telegram\Formatting;

final class CheckoutMessageFormatter
{
    /**
     * @param  array{id: int, status: string, currency: string, subtotal: string, total: string, expires_at: string, items: list<array{id: int, product_id: int, external_product_id: string, name: string, quantity: int, unit_price: string, total: string}>}  $cart
     */
    public function confirmation(array $cart): string
    {
        return implode("\n\n", [
            'Оформлення замовлення',
            implode("\n", [
                'Самовивіз',
                'Оплата готівкою',
                'Час: якнайшвидше',
            ]),
            "Разом: {$cart['total']} {$cart['currency']}",
            'Підтвердити замовлення?',
        ]);
    }
}
