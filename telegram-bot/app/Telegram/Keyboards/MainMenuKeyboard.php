<?php

namespace App\Telegram\Keyboards;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class MainMenuKeyboard
{
    public function make(string $context): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🍕 Каталог', callback_data: "catalog:{$context}"))
            ->addRow(InlineKeyboardButton::make('🛒 Кошик', callback_data: "menu:cart:{$context}"))
            ->addRow(InlineKeyboardButton::make('🚚 Спосіб отримання', callback_data: "fulfillment:menu:{$context}"))
            ->addRow(InlineKeyboardButton::make('🚪 Вийти', callback_data: 'exit'));
    }
}
