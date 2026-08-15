<?php

namespace App\Telegram\Keyboards;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class DeliveryAddressKeyboard
{
    public function types(string $context): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(
                text: '🏢 Квартира',
                callback_data: "delivery:type:0:{$context}",
            ))
            ->addRow(InlineKeyboardButton::make(
                text: '🏠 Приватний будинок',
                callback_data: "delivery:type:1:{$context}",
            ))
            ->addRow(InlineKeyboardButton::make(
                text: '🏢 Офіс',
                callback_data: "delivery:type:2:{$context}",
            ))
            ->addRow(InlineKeyboardButton::make(
                text: '📍 Інше',
                callback_data: "delivery:type:3:{$context}",
            ))
            ->addRow(InlineKeyboardButton::make(
                text: '🚪 Вийти',
                callback_data: 'exit',
            ));
    }

    public function retry(string $context): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(
                text: '🔄 Ввести адресу ще раз',
                callback_data: "delivery:retry:{$context}",
            ))
            ->addRow(InlineKeyboardButton::make(
                text: '🚪 Вийти',
                callback_data: 'exit',
            ));
    }

    public function unavailable(string $context): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(
                text: '🏃 Перейти на самовивіз',
                callback_data: "fulfillment:pickup:{$context}",
            ))
            ->addRow(InlineKeyboardButton::make(
                text: '🚪 Вийти',
                callback_data: 'exit',
            ));
    }
    public function serviceUnavailable(string $context): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(
                text: '🔄 Ввести адресу ще раз',
                callback_data: "delivery:retry:{$context}",
            ))
            ->addRow(InlineKeyboardButton::make(
                text: '🏃 Перейти на самовивіз',
                callback_data: "fulfillment:pickup:{$context}",
            ))
            ->addRow(InlineKeyboardButton::make(
                text: '🚪 Вийти',
                callback_data: 'exit',
            ));
    }
}
