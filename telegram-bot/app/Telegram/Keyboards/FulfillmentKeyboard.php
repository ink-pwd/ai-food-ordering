<?php

namespace App\Telegram\Keyboards;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class FulfillmentKeyboard
{
    /**
     * @param  list<array{type: string}>  $options
     */
    public function make(array $options, string $context): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make();

        foreach ($options as $option) {
            $keyboard->addRow(InlineKeyboardButton::make(
                text: $this->caption($option['type']),
                callback_data: "fulfillment:{$option['type']}:{$context}",
            ));
        }

        return $keyboard->addRow(InlineKeyboardButton::make(
            text: '🚪 Вийти',
            callback_data: 'exit',
        ));
    }

    private function caption(string $type): string
    {
        return match ($type) {
            'delivery' => '🚚 Доставка',
            'pickup' => '🏃 Самовивіз',
            default => "📦 {$type}",
        };
    }
}
