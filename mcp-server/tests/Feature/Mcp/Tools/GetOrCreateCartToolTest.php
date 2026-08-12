<?php

use App\Mcp\Servers\FoodOrderingServer;
use App\Mcp\Tools\GetOrCreateCartTool;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    configureOrderingToolBackend();
});

it('asks the backend to get or create the active cart with trusted session context and no payload', function () {
    $cart = orderingBackendCart([
        'subtotal' => '710.43',
        'total' => '699.17',
        'items' => [[
            'id' => 37,
            'product_id' => 91,
            'external_product_id' => '33333333-3333-3333-3333-333333333333',
            'name' => 'Пицца Маргарита',
            'quantity' => 3,
            'unit_price' => '236.81',
            'total' => '710.43',
        ]],
    ]);

    Http::fake([
        'https://ordering-backend.test/api/carts' => Http::response([
            'data' => $cart,
        ], 201),
    ]);

    FoodOrderingServer::tool(GetOrCreateCartTool::class, [
        'session_handle' => orderingToolSessionHandle(),
    ])
        ->assertOk()
        ->assertName('get_or_create_cart')
        ->assertStructuredContent([
            'cart' => orderingToolCartOutput($cart),
        ])
        ->assertDontSee([
            $cart['items'][0]['external_product_id'],
            'backend-session-token-secret',
            'internal-api-token-secret',
        ]);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://ordering-backend.test/api/carts'
            && $request->hasHeader('X-Internal-Api-Token', 'internal-api-token-secret')
            && $request->hasHeader('X-Session-Token', 'backend-session-token-secret')
            && $request->data() === [];
    });
    Http::assertSentCount(1);
});

it('returns an empty active cart without treating it as an error', function () {
    $cart = orderingBackendCart([
        'subtotal' => '0.00',
        'total' => '0.00',
        'items' => [],
    ]);

    Http::fake([
        'https://ordering-backend.test/api/carts' => Http::response([
            'data' => $cart,
        ]),
    ]);

    FoodOrderingServer::tool(GetOrCreateCartTool::class, [
        'session_handle' => orderingToolSessionHandle(),
    ])
        ->assertOk()
        ->assertStructuredContent([
            'cart' => orderingToolCartOutput($cart),
        ]);
});

it('rejects an invalid session handle before requesting a cart', function () {
    Http::fake();

    FoodOrderingServer::tool(GetOrCreateCartTool::class, [
        'session_handle' => 'invalid-session-handle',
    ])->assertHasErrors(['Контекст заказа недействителен или истёк. Создайте новый контекст заказа.']);

    Http::assertNothingSent();
});

it('rejects forbidden cart creation argument :dataset before calling the backend', function (string $argument, mixed $value) {
    Http::fake();

    FoodOrderingServer::tool(GetOrCreateCartTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        $argument => $value,
    ])->assertHasErrors(["Аргумент {$argument} не поддерживается этим инструментом."]);

    Http::assertNothingSent();
})->with([
    'restaurant_id' => ['restaurant_id', 12],
    'cart_id' => ['cart_id', 51],
    'price' => ['price', '0.01'],
    'total' => ['total', '0.01'],
    'external_product_id' => ['external_product_id', 'model-controlled-external-id'],
    'session_token' => ['session_token', 'model-controlled-session-token'],
]);

it('maps a malformed backend cart response to a safe error', function () {
    $malformedCart = orderingBackendCart();
    unset($malformedCart['items'][0]['external_product_id']);

    Http::fake([
        'https://ordering-backend.test/api/carts' => Http::response([
            'data' => $malformedCart,
            'debug' => 'backend-response-debug-secret',
        ]),
    ]);

    FoodOrderingServer::tool(GetOrCreateCartTool::class, [
        'session_handle' => orderingToolSessionHandle(),
    ])
        ->assertHasErrors(['Сервис заказа вернул некорректный ответ. Повторите попытку позже.'])
        ->assertDontSee(['backend-response-debug-secret', 'backend-session-token-secret']);

    Http::assertSentCount(1);
});
