<?php

use App\Enums\CartStatus;
use App\Enums\CatalogSyncStatus;
use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\CatalogSyncLog;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Restaurant;

test('model casts preserve domain types without persistence', function (string $modelClass, string $attribute, mixed $value, mixed $expected): void {
    $model = new $modelClass;
    $model->{$attribute} = $value;

    expect($model->{$attribute})->toBe($expected);
})->with([
    'cart status enum' => [Cart::class, 'status', 'active', CartStatus::Active],
    'catalog status enum' => [CatalogSyncLog::class, 'status', 'failed', CatalogSyncStatus::Failed],
    'order status enum' => [Order::class, 'status', 'created', OrderStatus::Created],
    'category sort order integer' => [Category::class, 'sort_order', '7', 7],
    'product availability boolean' => [Product::class, 'is_available', 0, false],
    'restaurant payment types array' => [Restaurant::class, 'available_payment_types', [1, 2, 3], [1, 2, 3]],
]);
