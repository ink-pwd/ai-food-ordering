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

        return $this->addBackToMainMenu($keyboard, $context);
    }

    public function paymentPending(string $context): InlineKeyboardMarkup
    {
        return $this->addBackToMainMenu(
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make(
                    text: '🔄 Оновити оплату',
                    callback_data: "payment:refresh:{$context}",
                )),
            $context,
        );
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

        $keyboard->addRow(InlineKeyboardButton::make(
            text: '🔄 Оновити оплату',
            callback_data: "payment:refresh:{$context}",
        ));

        return $this->addBackToMainMenu($keyboard, $context);
    }

    public function statusCheck(string $context): InlineKeyboardMarkup
    {
        return $this->addBackToMainMenu(
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make(
                    text: '🔄 Оновити замовлення',
                    callback_data: "order:refresh:{$context}",
                )),
            $context,
        );
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

    private function addBackToMainMenu(
        InlineKeyboardMarkup $keyboard,
        string $context,
    ): InlineKeyboardMarkup {
        return $keyboard->addRow(InlineKeyboardButton::make(
            text: '⬅️ Назад',
            callback_data: "post_order:main_menu:{$context}",
        ));
    }
}
