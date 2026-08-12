<?php

use App\Mcp\Servers\FoodOrderingServer;
use App\Mcp\Tools\RemoveCartItemTool;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    configureOrderingToolBackend();
});

it('removes exactly one line through its CartItem id and returns the backend cart', function () {
    $cart = orderingBackendCart([
        'subtotal' => '0.00',
        'total' => '0.00',
        'items' => [],
    ]);

    Http::fake([
        'https://ordering-backend.test/api/carts/current/items/37' => Http::response([
            'data' => $cart,
        ]),
    ]);

    FoodOrderingServer::tool(RemoveCartItemTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        'item_id' => 37,
    ])
        ->assertOk()
        ->assertName('remove_cart_item')
        ->assertStructuredContent([
            'cart' => orderingToolCartOutput($cart),
        ])
        ->assertDontSee('backend-session-token-secret');

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'DELETE'
            && $request->url() === 'https://ordering-backend.test/api/carts/current/items/37'
            && $request->data() === []
            && $request->hasHeader('X-Internal-Api-Token', 'internal-api-token-secret')
            && $request->hasHeader('X-Session-Token', 'backend-session-token-secret');
    });

    Http::assertSentCount(1);
});

it('uses CartItem id rather than Product id when removing a line', function () {
    $cart = orderingBackendCart(['items' => []]);

    Http::fake([
        'https://ordering-backend.test/api/carts/current/items/37' => Http::response([
            'data' => $cart,
        ]),
    ]);

    FoodOrderingServer::tool(RemoveCartItemTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        'item_id' => 37,
    ])->assertOk();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://ordering-backend.test/api/carts/current/items/37'
        && ! str_ends_with($request->url(), '/91'));
});

it('maps a missing cart item to a safe error without another mutation', function () {
    Http::fake([
        'https://ordering-backend.test/api/carts/current/items/999' => Http::response([
            'message' => 'Cart item backend-debug-secret',
        ], 404),
    ]);

    FoodOrderingServer::tool(RemoveCartItemTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        'item_id' => 999,
    ])
        ->assertHasErrors(['Запрошенный ресурс не найден.'])
        ->assertDontSee('backend-debug-secret');

    Http::assertSentCount(1);
});

it('rejects product id in place of the cart item argument before making an HTTP request', function () {
    Http::fake();

    FoodOrderingServer::tool(RemoveCartItemTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        'product_id' => 91,
    ])->assertHasErrors(['Аргумент product_id не поддерживается этим инструментом.']);

    Http::assertNothingSent();
});

it('rejects an invalid remove session handle without making an HTTP request', function () {
    Http::fake();

    FoodOrderingServer::tool(RemoveCartItemTool::class, [
        'session_handle' => 'invalid-session-handle',
        'item_id' => 37,
    ])->assertHasErrors(['Контекст заказа недействителен или истёк. Создайте новый контекст заказа.']);

    Http::assertNothingSent();
});
