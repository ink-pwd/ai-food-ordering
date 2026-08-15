<?php

namespace App\Telegram\Keyboards;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class RestaurantKeyboard
{
    /**
     * @param  list<array{id: int, name: string}>  $restaurants
     */
    public function make(array $restaurants): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make();

        foreach ($restaurants as $restaurant) {
            $keyboard->addRow(InlineKeyboardButton::make(
                text: "🍽 {$restaurant['name']}",
                callback_data: "restaurant:{$restaurant['id']}",
            ));
        }

        return $keyboard->addRow(InlineKeyboardButton::make(
            text: '🚪 Вийти',
            callback_data: 'exit',
        ));
    }
}
