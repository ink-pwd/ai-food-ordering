<?php

namespace App\Telegram\Keyboards;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class OrderKeyboard
{
    public function order(string $status, string $context): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make();

        if ($status === 'creating') {
            $keyboard->addRow(InlineKeyboardButton::make(
                text: '🔄 Оновити замовлення',
                callback_data: "order:refresh:{$context}",
            ));
        }

        return $this->addExit($keyboard);
    }

    public function paymentPending(string $context): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(
                text: '🔄 Оновити оплату',
                callback_data: "payment:refresh:{$context}",
            ))
            ->addRow(InlineKeyboardButton::make(
                text: '🚪 Вийти',
                callback_data: 'exit',
            ));
    }

    public function paymentReady(string $checkoutUrl, string $context, bool $includePayButton = true): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make();

        if ($includePayButton) {
            $keyboard->addRow(InlineKeyboardButton::make(
                text: '💳 Оплатити',
                url: $checkoutUrl,
            ));
        }

        return $keyboard
            ->addRow(InlineKeyboardButton::make(
                text: '🔄 Оновити оплату',
                callback_data: "payment:refresh:{$context}",
            ))
            ->addRow(InlineKeyboardButton::make(
                text: '🚪 Вийти',
                callback_data: 'exit',
            ));
    }

    public function statusCheck(string $context): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(
                text: '🔄 Оновити замовлення',
                callback_data: "order:refresh:{$context}",
            ))
            ->addRow(InlineKeyboardButton::make(
                text: '🚪 Вийти',
                callback_data: 'exit',
            ));
    }

    public function backToCart(string $context): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(
                text: '🛒 Повернутися до кошика',
                callback_data: "menu:cart:{$context}",
            ))
            ->addRow(InlineKeyboardButton::make(
                text: '🚪 Вийти',
                callback_data: 'exit',
            ));
    }

    private function addExit(InlineKeyboardMarkup $keyboard): InlineKeyboardMarkup
    {
        return $keyboard->addRow(InlineKeyboardButton::make(
            text: '🚪 Вийти',
            callback_data: 'exit',
        ));
    }
}
