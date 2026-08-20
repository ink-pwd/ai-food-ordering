<?php

namespace App\Telegram\Formatting;

use App\DTO\OrderingBackend\ProductData;

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

    public function product(ProductData $product): string
    {
        $sections = [$product->name];

        if (
            $product->description !== null
            && trim($product->description) !== ''
        ) {
            $sections[] = $product->description;
        }

        $priceLines = [
            "Звичайна ціна: {$product->price} {$product->currency}",
        ];

        if ($product->promotionPrice !== null) {
            $priceLines[] =
                "Акційна ціна: {$product->promotionPrice} {$product->currency}";
        }

        $priceLines[] = 'Наявність: '
            .($product->isAvailable
                ? 'У наявності'
                : 'Немає в наявності');

        $sections[] = implode("\n", $priceLines);

        return implode("\n\n", $sections);
    }
}
