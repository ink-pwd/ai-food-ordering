<?php

namespace App\Telegram\Formatting;

final class CatalogMessageFormatter
{
    public function categories(): string
    {
        return 'Категории';
    }

    public function products(): string
    {
        return 'Товары категории';
    }

    /**
     * @param  array{id: int, name: string, description: ?string, price: string, promotion_price: ?string, currency: string, is_available: bool}  $product
     */
    public function product(array $product): string
    {
        $sections = [$product['name']];

        if ($product['description'] !== null && trim($product['description']) !== '') {
            $sections[] = $product['description'];
        }

        $priceLines = ["Обычная цена: {$product['price']} {$product['currency']}"];

        if ($product['promotion_price'] !== null) {
            $priceLines[] = "Акционная цена: {$product['promotion_price']} {$product['currency']}";
        }

        $priceLines[] = 'Доступность: '.($product['is_available'] ? 'В наличии' : 'Нет в наличии');
        $sections[] = implode("\n", $priceLines);

        return implode("\n\n", $sections);
    }
}
