<?php

namespace App\Telegram\Keyboards;

use App\DTO\OrderingBackend\ProductSummaryData;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class CatalogKeyboard
{
    /**
     * @param  list<array{id: int, name: string}>  $categories
     */
    public function categories(array $categories, string $context): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make();

        foreach ($categories as $category) {
            $keyboard->addRow(InlineKeyboardButton::make(
                text: "📂 {$category['name']}",
                callback_data: "category:{$category['id']}:{$context}",
            ));
        }

        return $keyboard->addRow(InlineKeyboardButton::make(
            text: '⬅️ Головне меню',
            callback_data: "main_menu:{$context}",
        ));
    }

    /**
     * @param  list<ProductSummaryData>  $products
     */
    public function products(int $categoryId, array $products, string $context): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make();

        foreach ($products as $product) {
            $displayPrice = $product->promotionPrice ?? $product->price;

            $keyboard->addRow(InlineKeyboardButton::make(
                text: "🍽 {$product->name} — {$displayPrice} {$product->currency}",
                callback_data: "product:{$categoryId}:{$product->id}:{$context}",
            ));
        }

        return $keyboard->addRow(InlineKeyboardButton::make(
            text: '⬅️ Категорії',
            callback_data: "catalog:{$context}",
        ));
    }

    public function product(int $categoryId, int $productId, string $context): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(
                text: '🛒 Додати до кошика',
                callback_data: "cart:add:{$productId}:{$context}",
            ))
            ->addRow(InlineKeyboardButton::make(
                text: '⬅️ Назад',
                callback_data: "category:{$categoryId}:{$context}",
            ));
    }

    public function backToCategory(int $categoryId, string $context): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(
                text: '⬅️ Назад',
                callback_data: "category:{$categoryId}:{$context}",
            ));
    }
}
