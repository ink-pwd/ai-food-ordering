<?php

use App\DTO\OrderingBackend\ProductData;
use App\Telegram\Formatting\CatalogMessageFormatter;

test('catalog formatter renders product details from backend contract', function (
    string $name,
    ?string $description,
    string $price,
    ?string $promotionPrice,
    string $currency,
    bool $available,
    string $expected,
): void {
    $product = new ProductData(
        id: 1,
        name: $name,
        description: $description,
        price: $price,
        promotionPrice: $promotionPrice,
        currency: $currency,
        isAvailable: $available,
    );

    expect((new CatalogMessageFormatter)->product($product))->toBe($expected);
})->with([
    'basic available' => ['Pizza', null, '100.00', null, 'UAH', true, "Pizza\n\nЗвичайна ціна: 100.00 UAH\nНаявність: У наявності"],
    'basic unavailable' => ['Pizza', null, '100.00', null, 'UAH', false, "Pizza\n\nЗвичайна ціна: 100.00 UAH\nНаявність: Немає в наявності"],
    'description' => ['Pizza', 'Cheese and tomato', '100.00', null, 'UAH', true, "Pizza\n\nCheese and tomato\n\nЗвичайна ціна: 100.00 UAH\nНаявність: У наявності"],
    'blank description omitted' => ['Pizza', '   ', '100.00', null, 'UAH', true, "Pizza\n\nЗвичайна ціна: 100.00 UAH\nНаявність: У наявності"],
    'promotion' => ['Pizza', null, '100.00', '80.00', 'UAH', true, "Pizza\n\nЗвичайна ціна: 100.00 UAH\nАкційна ціна: 80.00 UAH\nНаявність: У наявності"],
    'promotion unavailable' => ['Pizza', null, '100.00', '80.00', 'UAH', false, "Pizza\n\nЗвичайна ціна: 100.00 UAH\nАкційна ціна: 80.00 UAH\nНаявність: Немає в наявності"],
    'description and promotion' => ['Burger', 'Double', '150.00', '120.00', 'UAH', true, "Burger\n\nDouble\n\nЗвичайна ціна: 150.00 UAH\nАкційна ціна: 120.00 UAH\nНаявність: У наявності"],
    'usd currency' => ['Coffee', null, '3.50', null, 'USD', true, "Coffee\n\nЗвичайна ціна: 3.50 USD\nНаявність: У наявності"],
    'eur currency' => ['Coffee', 'Arabica', '4.00', null, 'EUR', true, "Coffee\n\nArabica\n\nЗвичайна ціна: 4.00 EUR\nНаявність: У наявності"],
    'zero price' => ['Water', null, '0.00', null, 'UAH', true, "Water\n\nЗвичайна ціна: 0.00 UAH\nНаявність: У наявності"],
    'unicode name' => ['Піца Маргарита', null, '199.00', null, 'UAH', true, "Піца Маргарита\n\nЗвичайна ціна: 199.00 UAH\nНаявність: У наявності"],
    'unicode description' => ['Суп', 'Гострий 🌶️', '90.00', '75.00', 'UAH', true, "Суп\n\nГострий 🌶️\n\nЗвичайна ціна: 90.00 UAH\nАкційна ціна: 75.00 UAH\nНаявність: У наявності"],
]);
