<?php

namespace App\Telegram\Keyboards;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class CityKeyboard
{
    /**
     * @param  list<array{id: int, name: string}>  $cities
     */
    public function make(array $cities): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make();

        foreach ($cities as $city) {
            $keyboard->addRow(InlineKeyboardButton::make(
                text: "🏙 {$city['name']}",
                callback_data: "city:{$city['id']}",
            ));
        }

        return $keyboard->addRow(InlineKeyboardButton::make(
            text: '🚪 Вийти',
            callback_data: 'exit',
        ));
    }
}
