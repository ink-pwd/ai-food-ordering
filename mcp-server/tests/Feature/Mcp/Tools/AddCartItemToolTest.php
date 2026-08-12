<?php

use App\Mcp\Servers\FoodOrderingServer;
use App\Mcp\Tools\AddCartItemTool;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    configureOrderingToolBackend();
});

it('adds a local backend product ID and quantity to the existing current cart', function () {
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
        'https://ordering-backend.test/api/carts/current/items' => Http::response([
            'data' => $cart,
        ], 201),
    ]);

    FoodOrderingServer::tool(AddCartItemTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        'product_id' => 91,
        'quantity' => 3,
    ])
        ->assertOk()
        ->assertName('add_cart_item')
        ->assertStructuredContent([
            'cart' => orderingToolCartOutput($cart),
        ])
        ->assertDontSee([
            $cart['items'][0]['external_product_id'],
            'backend-session-token-secret',
            'internal-api-token-secret',
        ]);

    expect($cart['items'][0]['id'])->toBe(37)
        ->and($cart['items'][0]['product_id'])->toBe(91)
        ->and($cart['items'][0]['id'])->not->toBe($cart['items'][0]['product_id']);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://ordering-backend.test/api/carts/current/items'
            && $request->hasHeader('X-Internal-Api-Token', 'internal-api-token-secret')
            && $request->hasHeader('X-Session-Token', 'backend-session-token-secret')
            && $request->data() === [
                'product_id' => 91,
                'quantity' => 3,
            ];
    });
    Http::assertSentCount(1);
});

it('rejects invalid quantity :dataset before calling the backend', function (mixed $quantity, string $message) {
    Http::fake();

    FoodOrderingServer::tool(AddCartItemTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        'product_id' => 91,
        'quantity' => $quantity,
    ])->assertHasErrors([$message]);

    Http::assertNothingSent();
})->with([
    'zero' => [0, 'Аргумент quantity не соответствует минимальному ограничению 1.'],
    'negative' => [-1, 'Аргумент quantity не соответствует минимальному ограничению 1.'],
    'string' => ['two', 'Аргумент quantity должен быть целым числом.'],
    'fractional' => [1.5, 'Аргумент quantity должен быть целым числом.'],
]);

it('keeps a duplicate-product conflict and does not update the item or create a cart', function () {
    Http::fake([
        'https://ordering-backend.test/api/carts/current/items' => Http::response([
            'message' => 'backend-conflict-debug-secret',
        ], 409),
    ]);

    FoodOrderingServer::tool(AddCartItemTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        'product_id' => 91,
        'quantity' => 2,
    ])
        ->assertHasErrors(['Запрос конфликтует с текущим состоянием заказа.'])
        ->assertDontSee('backend-conflict-debug-secret');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://ordering-backend.test/api/carts/current/items');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PATCH'
        || $request->url() === 'https://ordering-backend.test/api/carts');
});

it('maps backend quantity validation failures safely', function () {
    Http::fake([
        'https://ordering-backend.test/api/carts/current/items' => Http::response([
            'message' => 'backend-validation-debug-secret',
            'errors' => ['quantity' => ['backend-quantity-detail-secret']],
        ], 422),
    ]);

    FoodOrderingServer::tool(AddCartItemTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        'product_id' => 91,
        'quantity' => 999,
    ])
        ->assertHasErrors(['Запрос отклонён: входные данные или состояние оформления заказа недействительны.'])
        ->assertDontSee(['backend-validation-debug-secret', 'backend-quantity-detail-secret']);

    Http::assertSentCount(1);
});

it('does not create a cart when adding to a missing current cart', function () {
    Http::fake([
        'https://ordering-backend.test/api/carts/current/items' => Http::response([
            'message' => 'missing current cart',
        ], 404),
    ]);

    FoodOrderingServer::tool(AddCartItemTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        'product_id' => 91,
        'quantity' => 1,
    ])->assertHasErrors(['Запрошенный ресурс не найден.']);

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://ordering-backend.test/api/carts');
});

it('rejects an invalid session handle before adding a cart item', function () {
    Http::fake();

    FoodOrderingServer::tool(AddCartItemTool::class, [
        'session_handle' => 'invalid-session-handle',
        'product_id' => 91,
        'quantity' => 1,
    ])->assertHasErrors(['Контекст заказа недействителен или истёк. Создайте новый контекст заказа.']);

    Http::assertNothingSent();
});

it('rejects forbidden add-item argument :dataset before calling the backend', function (string $argument, mixed $value) {
    Http::fake();

    FoodOrderingServer::tool(AddCartItemTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        'product_id' => 91,
        'quantity' => 1,
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
