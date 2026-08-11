<?php

namespace App\Telegram\Keyboards;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class CheckoutKeyboard
{
    public function confirmation(string $idempotencyKey): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(
                text: '✅ Подтвердить',
                callback_data: "order:confirm:{$idempotencyKey}",
            ))
            ->addRow(InlineKeyboardButton::make(
                text: '❌ Отмена',
                callback_data: 'order:cancel',
            ));
    }
}
