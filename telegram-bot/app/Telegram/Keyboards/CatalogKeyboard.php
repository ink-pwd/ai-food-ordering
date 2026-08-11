<?php

namespace App\Telegram\Keyboards;

use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class CatalogKeyboard
{
    /**
     * @param  list<array{id: int, name: string}>  $categories
     */
    public function categories(array $categories): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make();

        foreach ($categories as $category) {
            $keyboard->addRow(InlineKeyboardButton::make(
                text: $category['name'],
                callback_data: "category:{$category['id']}",
            ));
        }

        return $keyboard->addRow(InlineKeyboardButton::make(
            text: '⬅️ Главное меню',
            callback_data: 'main_menu',
        ));
    }

    /**
     * @param  list<array{id: int, name: string, price: string, promotion_price: ?string, currency: string}>  $products
     */
    public function products(int $categoryId, array $products): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make();

        foreach ($products as $product) {
            $displayPrice = $product['promotion_price'] ?? $product['price'];

            $keyboard->addRow(InlineKeyboardButton::make(
                text: "{$product['name']} — {$displayPrice} {$product['currency']}",
                callback_data: "product:{$categoryId}:{$product['id']}",
            ));
        }

        return $keyboard->addRow(InlineKeyboardButton::make(
            text: '⬅️ Категории',
            callback_data: 'catalog',
        ));
    }

    public function product(int $categoryId, int $productId): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(
                text: '🛒 Добавить в корзину',
                callback_data: "cart:add:{$productId}",
            ))
            ->addRow(InlineKeyboardButton::make(
                text: '⬅️ Назад',
                callback_data: "category:{$categoryId}",
            ));
    }

    public function backToCategory(int $categoryId): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(
                text: '⬅️ Назад',
                callback_data: "category:{$categoryId}",
            ));
    }
}
