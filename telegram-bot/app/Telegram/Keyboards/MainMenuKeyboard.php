<?php

namespace App\Telegram\Keyboards;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class MainMenuKeyboard
{
    public function make(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🍕 Каталог', callback_data: 'catalog'))
            ->addRow(InlineKeyboardButton::make('🛒 Корзина', callback_data: 'menu:cart'));
    }
}
