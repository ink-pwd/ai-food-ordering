<?php

namespace App\Telegram\Keyboards;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class CartKeyboard
{
    /**
     * @param  list<array{id: int, product_id: int, external_product_id: string, name: string, quantity: int, unit_price: string, total: string}>  $items
     */
    public function make(array $items = [], string $status = 'active'): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make();

        foreach ($items as $cartItem) {
            $keyboard->addRow(InlineKeyboardButton::make(
                text: $cartItem['name'],
                callback_data: "cart:noop:{$cartItem['id']}",
            ))->addRow(
                InlineKeyboardButton::make(
                    text: '➖',
                    callback_data: "cart:dec:{$cartItem['id']}",
                ),
                InlineKeyboardButton::make(
                    text: (string) $cartItem['quantity'],
                    callback_data: "cart:noop:{$cartItem['id']}",
                ),
                InlineKeyboardButton::make(
                    text: '➕',
                    callback_data: "cart:inc:{$cartItem['id']}",
                ),
            )->addRow(
                InlineKeyboardButton::make(
                    text: '🗑 Удалить',
                    callback_data: "cart:remove:{$cartItem['id']}",
                ),
            );
        }

        if ($items !== []) {
            if ($status === 'active') {
                $keyboard->addRow(InlineKeyboardButton::make(
                    text: '✅ Оформить заказ',
                    callback_data: 'checkout',
                ));
            }

            $keyboard->addRow(InlineKeyboardButton::make(
                text: '🧹 Очистить корзину',
                callback_data: 'cart:clear',
            ));
        }

        return $keyboard
            ->addRow(InlineKeyboardButton::make(
                text: $items === [] ? '🍕 Перейти в каталог' : '🍕 Продолжить покупки',
                callback_data: 'catalog',
            ))
            ->addRow(InlineKeyboardButton::make(
                text: '⬅️ Главное меню',
                callback_data: 'main_menu',
            ));
    }

    public function clearConfirmation(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(
                text: '✅ Да, очистить',
                callback_data: 'cart:clear:confirm',
            ))
            ->addRow(InlineKeyboardButton::make(
                text: '❌ Отмена',
                callback_data: 'cart:clear:cancel',
            ));
    }

    public function duplicateProduct(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(
                text: '🛒 Открыть корзину',
                callback_data: 'menu:cart',
            ))
            ->addRow(InlineKeyboardButton::make(
                text: '🍕 Продолжить покупки',
                callback_data: 'catalog',
            ))
            ->addRow(InlineKeyboardButton::make(
                text: '⬅️ Главное меню',
                callback_data: 'main_menu',
            ));
    }
}
