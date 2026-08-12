<?php

use App\Mcp\Servers\FoodOrderingServer;
use App\Mcp\Tools\ClearCartTool;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    configureOrderingToolBackend();
});

it('clears all current cart lines without an item id or payload', function () {
    $cart = orderingBackendCart([
        'subtotal' => '0.00',
        'total' => '0.00',
        'items' => [],
    ]);

    Http::fake([
        'https://ordering-backend.test/api/carts/current/items' => Http::response([
            'data' => $cart,
        ]),
    ]);

    FoodOrderingServer::tool(ClearCartTool::class, [
        'session_handle' => orderingToolSessionHandle(),
    ])
        ->assertOk()
        ->assertName('clear_cart')
        ->assertStructuredContent([
            'cart' => orderingToolCartOutput($cart),
        ])
        ->assertDontSee('backend-session-token-secret');

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'DELETE'
            && $request->url() === 'https://ordering-backend.test/api/carts/current/items'
            && $request->data() === []
            && $request->hasHeader('X-Internal-Api-Token', 'internal-api-token-secret')
            && $request->hasHeader('X-Session-Token', 'backend-session-token-secret');
    });

    Http::assertSentCount(1);
});

it('preserves a valid already empty backend cart', function () {
    $cart = orderingBackendCart([
        'subtotal' => '0.00',
        'total' => '0.00',
        'items' => [],
    ]);

    Http::fake([
        'https://ordering-backend.test/api/carts/current/items' => Http::response([
            'data' => $cart,
        ]),
    ]);

    FoodOrderingServer::tool(ClearCartTool::class, [
        'session_handle' => orderingToolSessionHandle(),
    ])->assertStructuredContent([
        'cart' => orderingToolCartOutput($cart),
    ]);
});

it('maps a backend cart conflict safely', function () {
    Http::fake([
        'https://ordering-backend.test/api/carts/current/items' => Http::response([
            'message' => 'Cart is inactive backend-debug-secret',
        ], 409),
    ]);

    FoodOrderingServer::tool(ClearCartTool::class, [
        'session_handle' => orderingToolSessionHandle(),
    ])
        ->assertHasErrors(['Запрос конфликтует с текущим состоянием заказа.'])
        ->assertDontSee('backend-debug-secret');

    Http::assertSentCount(1);
});

it('rejects arguments other than the opaque session handle', function (string $argument, mixed $value) {
    Http::fake();

    FoodOrderingServer::tool(ClearCartTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        $argument => $value,
    ])->assertHasErrors(["Аргумент {$argument} не поддерживается этим инструментом."]);

    Http::assertNothingSent();
})->with([
    'item id' => ['item_id', 37],
    'cart id' => ['cart_id', 51],
    'total' => ['total', '0.00'],
    'session token' => ['session_token', 'model-controlled-token'],
]);

it('rejects an invalid clear session handle without making an HTTP request', function () {
    Http::fake();

    FoodOrderingServer::tool(ClearCartTool::class, [
        'session_handle' => 'invalid-session-handle',
    ])->assertHasErrors(['Контекст заказа недействителен или истёк. Создайте новый контекст заказа.']);

    Http::assertNothingSent();
});
