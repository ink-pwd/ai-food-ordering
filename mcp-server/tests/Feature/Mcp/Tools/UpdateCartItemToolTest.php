<?php

use App\Mcp\Servers\FoodOrderingServer;
use App\Mcp\Tools\UpdateCartItemTool;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    configureOrderingToolBackend();
});

it('updates quantity through the cart item id path and sends only quantity', function () {
    $cart = orderingBackendCart([
        'subtotal' => '299.85',
        'total' => '299.85',
        'items' => [[
            'id' => 37,
            'product_id' => 91,
            'external_product_id' => '33333333-3333-3333-3333-333333333333',
            'name' => 'Пицца Маргарита',
            'quantity' => 3,
            'unit_price' => '99.95',
            'total' => '299.85',
        ]],
    ]);

    Http::fake([
        'https://ordering-backend.test/api/carts/current/items/37' => Http::response([
            'data' => $cart,
        ]),
    ]);

    FoodOrderingServer::tool(UpdateCartItemTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        'item_id' => 37,
        'quantity' => 3,
    ])
        ->assertOk()
        ->assertName('update_cart_item')
        ->assertStructuredContent([
            'cart' => orderingToolCartOutput($cart),
        ])
        ->assertDontSee([
            $cart['items'][0]['external_product_id'],
            'backend-session-token-secret',
        ]);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'PATCH'
            && $request->url() === 'https://ordering-backend.test/api/carts/current/items/37'
            && $request->data() === ['quantity' => 3]
            && $request->hasHeader('X-Internal-Api-Token', 'internal-api-token-secret')
            && $request->hasHeader('X-Session-Token', 'backend-session-token-secret');
    });

    Http::assertSentCount(1);
});

it('uses CartItem id rather than Product id when updating a line', function () {
    $cart = orderingBackendCart();

    expect($cart['items'][0]['id'])->toBe(37)
        ->and($cart['items'][0]['product_id'])->toBe(91);

    Http::fake([
        'https://ordering-backend.test/api/carts/current/items/37' => Http::response([
            'data' => $cart,
        ]),
    ]);

    FoodOrderingServer::tool(UpdateCartItemTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        'item_id' => $cart['items'][0]['id'],
        'quantity' => 2,
    ])->assertOk();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://ordering-backend.test/api/carts/current/items/37'
        && ! str_ends_with($request->url(), '/91')
        && ! array_key_exists('product_id', $request->data()));
});

it('maps a missing cart item to a safe error', function () {
    Http::fake([
        'https://ordering-backend.test/api/carts/current/items/999' => Http::response([
            'message' => 'Cart item backend-debug-secret',
        ], 404),
    ]);

    FoodOrderingServer::tool(UpdateCartItemTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        'item_id' => 999,
        'quantity' => 2,
    ])
        ->assertHasErrors(['Запрошенный ресурс не найден.'])
        ->assertDontSee('backend-debug-secret');

    Http::assertSentCount(1);
});

it('maps backend quantity validation failures without exposing details', function () {
    Http::fake([
        'https://ordering-backend.test/api/carts/current/items/37' => Http::response([
            'message' => 'backend-validation-secret',
            'errors' => ['quantity' => ['backend-quantity-secret']],
        ], 422),
    ]);

    FoodOrderingServer::tool(UpdateCartItemTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        'item_id' => 37,
        'quantity' => 2,
    ])
        ->assertHasErrors(['Запрос отклонён: входные данные или состояние оформления заказа недействительны.'])
        ->assertDontSee(['backend-validation-secret', 'backend-quantity-secret']);

    Http::assertSentCount(1);
});

it('rejects invalid update quantity before making an HTTP request', function (mixed $quantity) {
    Http::fake();

    FoodOrderingServer::tool(UpdateCartItemTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        'item_id' => 37,
        'quantity' => $quantity,
    ])->assertHasErrors();

    Http::assertNothingSent();
})->with([
    'zero' => 0,
    'negative' => -1,
    'string' => 'three',
]);

it('rejects forbidden update arguments before making an HTTP request', function (string $argument, mixed $value) {
    Http::fake();

    FoodOrderingServer::tool(UpdateCartItemTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        'item_id' => 37,
        'quantity' => 2,
        $argument => $value,
    ])->assertHasErrors(["Аргумент {$argument} не поддерживается этим инструментом."]);

    Http::assertNothingSent();
})->with([
    'product id' => ['product_id', 91],
    'cart id' => ['cart_id', 51],
    'price' => ['price', '0.01'],
    'total' => ['total', '0.02'],
    'external product id' => ['external_product_id', 'dots-product-id'],
    'session token' => ['session_token', 'model-controlled-token'],
]);

it('rejects an invalid update session handle without making an HTTP request', function () {
    Http::fake();

    FoodOrderingServer::tool(UpdateCartItemTool::class, [
        'session_handle' => 'invalid-session-handle',
        'item_id' => 37,
        'quantity' => 2,
    ])->assertHasErrors(['Контекст заказа недействителен или истёк. Создайте новый контекст заказа.']);

    Http::assertNothingSent();
});
