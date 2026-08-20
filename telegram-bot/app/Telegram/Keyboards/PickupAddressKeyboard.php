<?php

namespace App\Telegram\Keyboards;

use App\DTO\OrderingBackend\PickupAddressData;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class PickupAddressKeyboard
{
    /**
     * @param  list<PickupAddressData>  $addresses
     */
    public function make(array $addresses, string $context): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make();

        foreach ($addresses as $address) {
            $keyboard->addRow(InlineKeyboardButton::make(
                text: '📍 '.$this->caption($address),
                callback_data: "pickup:{$address->id}:{$context}",
            ));
        }

        return $keyboard->addRow(InlineKeyboardButton::make(
            text: '🚪 Вийти',
            callback_data: 'exit',
        ));
    }

    private function caption(PickupAddressData $address): string
    {
        if (
            is_string($address->title)
            && trim($address->title) !== ''
        ) {
            return $address->title;
        }

        return "Точка самовивозу #{$address->id}";
    }
}
