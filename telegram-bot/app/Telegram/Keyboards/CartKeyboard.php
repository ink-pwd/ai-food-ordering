<?php

namespace App\Telegram\Keyboards;

use App\DTO\OrderingBackend\CartItemData;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class CartKeyboard
{
    /**
     * @param  list<CartItemData>  $items
     */
    public function make(array $items = [], string $status = 'active', string $context = ''): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make();

        foreach ($items as $cartItem) {
            $keyboard->addRow(InlineKeyboardButton::make(
                text: "🍽 {$cartItem->name}",
                callback_data: "cart:noop:{$cartItem->id}:{$context}",
            ))->addRow(
                InlineKeyboardButton::make(
                    text: '➖',
                    callback_data: "cart:dec:{$cartItem->id}:{$context}",
                ),
                InlineKeyboardButton::make(
                    text: "🔢 {$cartItem->quantity}",
                    callback_data: "cart:noop:{$cartItem->id}:{$context}",
                ),
                InlineKeyboardButton::make(
                    text: '➕',
                    callback_data: "cart:inc:{$cartItem->id}:{$context}",
                ),
            )->addRow(
                InlineKeyboardButton::make(
                    text: '🗑 Видалити',
                    callback_data: "cart:remove:{$cartItem->id}:{$context}",
                ),
            );
        }

        if ($items !== []) {
            if ($status === 'active') {
                $keyboard->addRow(InlineKeyboardButton::make(
                    text: '✅ Оформити замовлення',
                    callback_data: "checkout:{$context}",
                ));
            }

            $keyboard->addRow(InlineKeyboardButton::make(
                text: '🧹 Очистити кошик',
                callback_data: "cart:clear:{$context}",
            ));
        }

        return $keyboard
            ->addRow(InlineKeyboardButton::make(
                text: $items === [] ? '🍕 Перейти до каталогу' : '🍕 Продовжити покупки',
                callback_data: "catalog:{$context}",
            ))
            ->addRow(InlineKeyboardButton::make(
                text: '⬅️ Головне меню',
                callback_data: "main_menu:{$context}",
            ))
            ->addRow(InlineKeyboardButton::make(
                text: '🚪 Вийти',
                callback_data: 'exit',
            ));
    }

    public function clearConfirmation(string $context): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(
                text: '✅ Так, очистити',
                callback_data: "cart:clear:confirm:{$context}",
            ))
            ->addRow(InlineKeyboardButton::make(
                text: '❌ Скасувати',
                callback_data: "cart:clear:cancel:{$context}",
            ));
    }

    public function duplicateProduct(string $context): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(
                text: '🛒 Відкрити кошик',
                callback_data: "menu:cart:{$context}",
            ))
            ->addRow(InlineKeyboardButton::make(
                text: '🍕 Продовжити покупки',
                callback_data: "catalog:{$context}",
            ))
            ->addRow(InlineKeyboardButton::make(
                text: '⬅️ Головне меню',
                callback_data: "main_menu:{$context}",
            ))
            ->addRow(InlineKeyboardButton::make(
                text: '🚪 Вийти',
                callback_data: 'exit',
            ));
    }
}
