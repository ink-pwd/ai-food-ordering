<?php

use App\Mcp\Servers\FoodOrderingServer;
use App\Mcp\Tools\GetProductTool;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    configureOrderingToolBackend();
});

it('gets a product from the trusted restaurant and preserves unavailable status', function () {
    $product = orderingBackendProduct([
        'id' => 91,
        'is_available' => false,
        'image_url' => null,
    ]);

    Http::fake([
        'https://ordering-backend.test/api/restaurants/trusted-restaurant/products/91' => Http::response([
            'data' => $product,
        ]),
    ]);

    FoodOrderingServer::tool(GetProductTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        'product_id' => 91,
    ])
        ->assertOk()
        ->assertName('get_product')
        ->assertStructuredContent([
            'product' => orderingToolProductOutput($product),
        ])
        ->assertDontSee([
            $product['external_id'],
            'backend-session-token-secret',
            'Маргарита',
            'Томаты и сыр',
        ]);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'GET'
            && $request->url() === 'https://ordering-backend.test/api/restaurants/trusted-restaurant/products/91'
            && ! $request->hasHeader('X-Session-Token');
    });
});

it('preserves Russian product text exactly as returned by the backend', function () {
    $product = orderingBackendProduct([
        'id' => 91,
        'name' => 'Пицца Маргарита',
        'description' => 'Томаты и сыр',
    ]);

    Http::fake([
        'https://ordering-backend.test/api/restaurants/trusted-restaurant/products/91' => Http::response([
            'data' => $product,
        ]),
    ]);

    FoodOrderingServer::tool(GetProductTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        'product_id' => 91,
    ])
        ->assertOk()
        ->assertStructuredContent([
            'product' => orderingToolProductOutput($product),
        ]);
});

it('maps a missing product to a safe MCP error', function () {
    Http::fake([
        'https://ordering-backend.test/api/restaurants/trusted-restaurant/products/999' => Http::response([
            'message' => 'App\\Models\\Product backend-debug-secret',
        ], 404),
    ]);

    FoodOrderingServer::tool(GetProductTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        'product_id' => 999,
    ])
        ->assertHasErrors(['Запрошенный ресурс не найден.'])
        ->assertDontSee('backend-debug-secret');

    Http::assertSentCount(1);
});

it('rejects an invalid product session handle without making an HTTP request', function () {
    Http::fake();

    FoodOrderingServer::tool(GetProductTool::class, [
        'session_handle' => 'invalid-session-handle',
        'product_id' => 91,
    ])->assertHasErrors(['Контекст заказа недействителен или истёк. Создайте новый контекст заказа.']);

    Http::assertNothingSent();
});
