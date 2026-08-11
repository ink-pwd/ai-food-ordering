<?php

namespace App\Telegram\Keyboards;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class OrderKeyboard
{
    public function make(string $status): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make();

        if ($status === 'creating') {
            $keyboard->addRow(InlineKeyboardButton::make(
                text: '🔄 Обновить статус',
                callback_data: 'order:refresh',
            ));
        }

        return $this->addMainMenu($keyboard);
    }

    public function statusCheck(): InlineKeyboardMarkup
    {
        return $this->addMainMenu(
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make(
                    text: '🔄 Проверить заказ',
                    callback_data: 'order:refresh',
                )),
        );
    }

    public function backToCart(): InlineKeyboardMarkup
    {
        return $this->addMainMenu(
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make(
                    text: '🛒 Вернуться в корзину',
                    callback_data: 'menu:cart',
                )),
        );
    }

    private function addMainMenu(InlineKeyboardMarkup $keyboard): InlineKeyboardMarkup
    {
        return $keyboard->addRow(InlineKeyboardButton::make(
            text: '⬅️ Главное меню',
            callback_data: 'main_menu',
        ));
    }
}
