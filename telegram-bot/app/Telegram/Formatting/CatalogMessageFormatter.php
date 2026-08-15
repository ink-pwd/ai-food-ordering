<?php

namespace App\Telegram\Formatting;

final class CatalogMessageFormatter
{
    public function categories(): string
    {
        return 'Категорії';
    }

    public function products(): string
    {
        return 'Товари категорії';
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

        $priceLines = ["Звичайна ціна: {$product['price']} {$product['currency']}"];

        if ($product['promotion_price'] !== null) {
            $priceLines[] = "Акційна ціна: {$product['promotion_price']} {$product['currency']}";
        }

        $priceLines[] = 'Наявність: '.($product['is_available'] ? 'У наявності' : 'Немає в наявності');
        $sections[] = implode("\n", $priceLines);

        return implode("\n\n", $sections);
    }
}
