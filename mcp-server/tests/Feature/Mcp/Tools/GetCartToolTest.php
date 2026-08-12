<?php

use App\Mcp\Servers\FoodOrderingServer;
use App\Mcp\Tools\GetCartTool;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    configureOrderingToolBackend();
});

it('gets the current cart with the trusted session token and preserves backend cart truth', function () {
    $cart = orderingBackendCart([
        'subtotal' => '1234.56',
        'total' => '1200.01',
        'items' => [[
            'id' => 37,
            'product_id' => 91,
            'external_product_id' => '33333333-3333-3333-3333-333333333333',
            'name' => 'Pepperoni Pizza',
            'quantity' => 4,
            'unit_price' => '308.64',
            'total' => '1234.56',
        ]],
    ]);

    Http::fake([
        'https://ordering-backend.test/api/carts/current' => Http::response([
            'data' => $cart,
        ]),
    ]);

    FoodOrderingServer::tool(GetCartTool::class, [
        'session_handle' => orderingToolSessionHandle(),
    ])
        ->assertOk()
        ->assertName('get_cart')
        ->assertStructuredContent([
            'cart' => orderingToolCartOutput($cart),
        ])
        ->assertDontSee([
            $cart['items'][0]['external_product_id'],
            'backend-session-token-secret',
            'internal-api-token-secret',
        ]);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'GET'
            && $request->url() === 'https://ordering-backend.test/api/carts/current'
            && $request->hasHeader('X-Internal-Api-Token', 'internal-api-token-secret')
            && $request->hasHeader('X-Session-Token', 'backend-session-token-secret')
            && $request->data() === [];
    });
    Http::assertSentCount(1);
});

it('returns an empty current cart unchanged', function () {
    $cart = orderingBackendCart([
        'subtotal' => '0.00',
        'total' => '0.00',
        'items' => [],
    ]);

    Http::fake([
        'https://ordering-backend.test/api/carts/current' => Http::response([
            'data' => $cart,
        ]),
    ]);

    FoodOrderingServer::tool(GetCartTool::class, [
        'session_handle' => orderingToolSessionHandle(),
    ])
        ->assertOk()
        ->assertStructuredContent([
            'cart' => orderingToolCartOutput($cart),
        ]);
});

it('maps a missing current cart safely without implicitly creating one', function () {
    Http::fake([
        'https://ordering-backend.test/api/carts/current' => Http::response([
            'message' => 'backend-cart-debug-secret',
        ], 404),
    ]);

    FoodOrderingServer::tool(GetCartTool::class, [
        'session_handle' => orderingToolSessionHandle(),
    ])
        ->assertHasErrors(['Запрошенный ресурс не найден.'])
        ->assertDontSee('backend-cart-debug-secret');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://ordering-backend.test/api/carts/current');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

it('rejects an invalid session handle before reading the cart', function () {
    Http::fake();

    FoodOrderingServer::tool(GetCartTool::class, [
        'session_handle' => 'invalid-session-handle',
    ])->assertHasErrors(['Контекст заказа недействителен или истёк. Создайте новый контекст заказа.']);

    Http::assertNothingSent();
});

it('rejects forbidden current-cart argument :dataset before calling the backend', function (string $argument, mixed $value) {
    Http::fake();

    FoodOrderingServer::tool(GetCartTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        $argument => $value,
    ])->assertHasErrors(["Аргумент {$argument} не поддерживается этим инструментом."]);

    Http::assertNothingSent();
})->with([
    'restaurant_id' => ['restaurant_id', 12],
    'cart_id' => ['cart_id', 51],
]);
