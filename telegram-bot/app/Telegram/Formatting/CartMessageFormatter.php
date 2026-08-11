<?php

namespace App\Telegram\Formatting;

final class CartMessageFormatter
{
    /**
     * @param  array{id: int, status: string, currency: string, subtotal: string, total: string, expires_at: string, items: list<array{id: int, product_id: int, external_product_id: string, name: string, quantity: int, unit_price: string, total: string}>}  $cart
     */
    public function format(array $cart): string
    {
        $sections = ['🛒 Корзина'];

        if ($cart['items'] === []) {
            $sections[] = 'Корзина пуста.';
        } else {
            foreach ($cart['items'] as $item) {
                $sections[] = implode("\n", [
                    $item['name'],
                    "{$item['quantity']} × {$item['unit_price']} {$cart['currency']} = {$item['total']} {$cart['currency']}",
                ]);
            }
        }

        $sections[] = implode("\n", [
            "Подытог: {$cart['subtotal']} {$cart['currency']}",
            "Итого: {$cart['total']} {$cart['currency']}",
        ]);

        return implode("\n\n", $sections);
    }

    /**
     * @param  array{id: int, status: string, currency: string, subtotal: string, total: string, expires_at: string, items: list<array{id: int, product_id: int, external_product_id: string, name: string, quantity: int, unit_price: string, total: string}>}  $cart
     */
    public function formatWithNotice(array $cart, string $notice): string
    {
        return $notice."\n\n".$this->format($cart);
    }
}
