<?php

namespace App\Telegram\Keyboards;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class PickupAddressKeyboard
{
    /**
     * @param  list<array{id: int, title: ?string}>  $addresses
     */
    public function make(array $addresses, string $context): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make();

        foreach ($addresses as $address) {
            $keyboard->addRow(InlineKeyboardButton::make(
                text: '📍 '.$this->caption($address),
                callback_data: "pickup:{$address['id']}:{$context}",
            ));
        }

        return $keyboard->addRow(InlineKeyboardButton::make(
            text: '🚪 Вийти',
            callback_data: 'exit',
        ));
    }

    /**
     * @param  array{id: int, title: ?string}  $address
     */
    private function caption(array $address): string
    {
        if (is_string($address['title']) && trim($address['title']) !== '') {
            return $address['title'];
        }

        return "Точка самовивозу #{$address['id']}";
    }
}
