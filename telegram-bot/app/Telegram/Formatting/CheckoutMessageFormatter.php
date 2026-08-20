<?php

namespace App\Telegram\Formatting;

use App\DTO\OrderingBackend\CartData;

final class CheckoutMessageFormatter
{
    public function confirmation(CartData $cart): string
    {
        return implode("\n\n", [
            'Оформлення замовлення',
            implode("\n", [
                'Самовивіз',
                'Оплата готівкою',
                'Час: якнайшвидше',
            ]),
            "Разом: {$cart->total} {$cart->currency}",
            'Підтвердити замовлення?',
        ]);
    }
}
