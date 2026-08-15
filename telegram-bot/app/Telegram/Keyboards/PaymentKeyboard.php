<?php

namespace App\Telegram\Keyboards;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class PaymentKeyboard
{
    /**
     * @param list<int> $paymentTypes
     */
    public function make(array $paymentTypes, string $context): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make();

        foreach (array_unique($paymentTypes) as $paymentType) {
            $label = match ($paymentType) {
                1 => '💵 Готівка',
                2 => '💳 Онлайн',
                3 => '💳 Термінал',
                default => null,
            };

            if ($label === null) {
                continue;
            }

            $keyboard->addRow(
                InlineKeyboardButton::make(
                    text: $label,
                    callback_data: "checkout:payment:{$paymentType}:{$context}",
                ),
            );
        }

        return $keyboard
            ->addRow(
                InlineKeyboardButton::make(
                    text: '⬅️ Назад до кошика',
                    callback_data: "menu:cart:{$context}",
                ),
            )
            ->addRow(
                InlineKeyboardButton::make(
                    text: '🚪 Вийти',
                    callback_data: 'exit',
                ),
            );
    }
}
