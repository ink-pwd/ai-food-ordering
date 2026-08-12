<?php

use App\Mcp\Servers\FoodOrderingServer;
use App\Mcp\Tools\GetCategoryProductsTool;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    configureOrderingToolBackend();
});

it('gets category products with the trusted restaurant and local category ID', function () {
    $product = orderingBackendProduct();

    Http::fake([
        'https://ordering-backend.test/api/restaurants/trusted-restaurant/categories/17/products' => Http::response([
            'data' => [$product],
        ]),
    ]);

    FoodOrderingServer::tool(GetCategoryProductsTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        'category_id' => 17,
    ])
        ->assertOk()
        ->assertName('get_category_products')
        ->assertStructuredContent([
            'products' => [orderingToolProductOutput($product)],
        ])
        ->assertDontSee([$product['external_id'], 'backend-session-token-secret']);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'GET'
            && $request->url() === 'https://ordering-backend.test/api/restaurants/trusted-restaurant/categories/17/products'
            && ! $request->hasHeader('X-Session-Token');
    });
});

it('maps a missing category to a safe MCP error', function () {
    Http::fake([
        'https://ordering-backend.test/api/restaurants/trusted-restaurant/categories/999/products' => Http::response([
            'message' => 'App\\Models\\Category backend-debug-secret',
        ], 404),
    ]);

    FoodOrderingServer::tool(GetCategoryProductsTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        'category_id' => 999,
    ])
        ->assertHasErrors(['Запрошенный ресурс не найден.'])
        ->assertDontSee('backend-debug-secret');

    Http::assertSentCount(1);
});

it('rejects an invalid category-products session handle without making an HTTP request', function () {
    Http::fake();

    FoodOrderingServer::tool(GetCategoryProductsTool::class, [
        'session_handle' => 'invalid-session-handle',
        'category_id' => 17,
    ])->assertHasErrors(['Контекст заказа недействителен или истёк. Создайте новый контекст заказа.']);

    Http::assertNothingSent();
});
