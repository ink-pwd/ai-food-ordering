<?php

namespace App\Telegram\Formatting;

use App\DTO\OrderingBackend\CartData;

final class CartMessageFormatter
{
    public function format(CartData $cart): string
    {
        $sections = ['🛒 Кошик'];

        if ($cart->items === []) {
            $sections[] = 'Кошик порожній.';
        } else {
            foreach ($cart->items as $item) {
                $sections[] = implode("\n", [
                    $item->name,
                    "{$item->quantity} × {$item->unitPrice} {$cart->currency} = {$item->total} {$cart->currency}",
                ]);
            }
        }

        $sections[] = implode("\n", [
            "Проміжний підсумок: {$cart->subtotal} {$cart->currency}",
            "Разом: {$cart->total} {$cart->currency}",
        ]);

        return implode("\n\n", $sections);
    }

    public function formatWithNotice(CartData $cart, string $notice): string
    {
        return $notice."\n\n".$this->format($cart);
    }
}
