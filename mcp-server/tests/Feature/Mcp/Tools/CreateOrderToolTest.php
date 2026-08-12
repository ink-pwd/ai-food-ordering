<?php

use App\Mcp\Servers\FoodOrderingServer;
use App\Mcp\Tools\CreateOrderTool;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    configureOrderingToolBackend();
});

it('creates an order only after confirmation using trusted context and backend cart truth', function () {
    $cart = orderingBackendCart(['id' => 51]);
    $order = orderingBackendOrder([
        'status' => 'creating',
        'failure_message' => 'raw-dots-failure-message-secret',
        'total' => '180.01',
        'items' => [
            [
                'product_id' => 91,
                'external_product_id' => '33333333-3333-3333-3333-333333333333',
                'name' => 'Пицца Маргарита',
                'quantity' => 2,
                'unit_price' => '99.95',
                'total' => '199.90',
            ],
            [
                'product_id' => null,
                'external_product_id' => '55555555-5555-4555-8555-555555555555',
                'name' => 'Pepperoni Pizza',
                'quantity' => 1,
                'unit_price' => '20.10',
                'total' => '20.10',
            ],
        ],
    ]);

    Http::fake([
        'https://ordering-backend.test/api/carts/current' => Http::response([
            'data' => $cart,
        ]),
        'https://ordering-backend.test/api/orders' => Http::response([
            'data' => $order,
        ], 201),
    ]);

    $expectedIdempotencyKey = hash(
        'sha256',
        'mcp-order:backend-session-id:51',
    );

    FoodOrderingServer::tool(CreateOrderTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        'confirmation' => true,
        'delivery_time' => 1786550400,
    ])
        ->assertOk()
        ->assertName('create_order')
        ->assertStructuredContent([
            'order' => orderingToolOrderOutput($order),
        ])
        ->assertDontSee([
            'raw-dots-failure-message-secret',
            $order['items'][0]['external_product_id'],
            $order['items'][1]['external_product_id'],
            'backend-session-token-secret',
            'internal-api-token-secret',
            $expectedIdempotencyKey,
        ]);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'GET'
            && $request->url() === 'https://ordering-backend.test/api/carts/current'
            && $request->hasHeader('X-Internal-Api-Token', 'internal-api-token-secret')
            && $request->hasHeader('X-Session-Token', 'backend-session-token-secret')
            && ! $request->hasHeader('Idempotency-Key')
            && $request->data() === [];
    });

    Http::assertSent(function (Request $request) use ($expectedIdempotencyKey): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://ordering-backend.test/api/orders'
            && $request->hasHeader('X-Internal-Api-Token', 'internal-api-token-secret')
            && $request->hasHeader('X-Session-Token', 'backend-session-token-secret')
            && $request->hasHeader('Idempotency-Key', $expectedIdempotencyKey)
            && $request->data() === ['delivery_time' => 1786550400]
            && ! array_key_exists('confirmation', $request->data());
    });

    Http::assertSentCount(2);
});

it('rejects missing false or invalid confirmation before session restoration or HTTP', function (array $arguments) {
    Http::fake();

    FoodOrderingServer::tool(CreateOrderTool::class, $arguments)
        ->assertHasErrors(['Пользователь не подтвердил создание заказа.']);

    Http::assertNothingSent();
})->with([
    'missing' => [[
        'session_handle' => 'invalid-handle-that-must-not-be-restored',
        'delivery_time' => 0,
    ]],
    'false' => [[
        'session_handle' => 'invalid-handle-that-must-not-be-restored',
        'confirmation' => false,
        'delivery_time' => 0,
    ]],
    'string true' => [[
        'session_handle' => 'invalid-handle-that-must-not-be-restored',
        'confirmation' => 'true',
        'delivery_time' => 0,
    ]],
    'integer one' => [[
        'session_handle' => 'invalid-handle-that-must-not-be-restored',
        'confirmation' => 1,
        'delivery_time' => 0,
    ]],
    'null' => [[
        'session_handle' => 'invalid-handle-that-must-not-be-restored',
        'confirmation' => null,
        'delivery_time' => 0,
    ]],
]);

it('uses the same internal key for the same trusted session and current cart', function () {
    $cart = orderingBackendCart(['id' => 51]);
    $order = orderingBackendOrder();

    Http::fake([
        'https://ordering-backend.test/api/carts/current' => Http::response([
            'data' => $cart,
        ]),
        'https://ordering-backend.test/api/orders' => Http::response([
            'data' => $order,
        ]),
    ]);

    $sessionHandle = orderingToolSessionHandle(
        backendSessionId: 'trusted-session-for-idempotency',
    );

    foreach ([0, 1786550400] as $deliveryTime) {
        FoodOrderingServer::tool(CreateOrderTool::class, [
            'session_handle' => $sessionHandle,
            'confirmation' => true,
            'delivery_time' => $deliveryTime,
        ])->assertOk();
    }

    $keys = Http::recorded()
        ->map(fn (array $record): Request => $record[0])
        ->filter(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://ordering-backend.test/api/orders')
        ->map(fn (Request $request): string => $request->header('Idempotency-Key')[0])
        ->values();

    expect($keys)->toHaveCount(2)
        ->and($keys->unique()->values()->all())->toBe([
            hash('sha256', 'mcp-order:trusted-session-for-idempotency:51'),
        ]);
});

it('uses different internal keys for different trusted current carts', function () {
    Http::fake([
        'https://ordering-backend.test/api/carts/current' => Http::sequence()
            ->push(['data' => orderingBackendCart(['id' => 51])])
            ->push(['data' => orderingBackendCart(['id' => 52])]),
        'https://ordering-backend.test/api/orders' => Http::response([
            'data' => orderingBackendOrder(),
        ]),
    ]);

    $sessionHandle = orderingToolSessionHandle(
        backendSessionId: 'trusted-session-for-different-carts',
    );

    foreach ([0, 0] as $deliveryTime) {
        FoodOrderingServer::tool(CreateOrderTool::class, [
            'session_handle' => $sessionHandle,
            'confirmation' => true,
            'delivery_time' => $deliveryTime,
        ])->assertOk();
    }

    $keys = Http::recorded()
        ->map(fn (array $record): Request => $record[0])
        ->filter(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://ordering-backend.test/api/orders')
        ->map(fn (Request $request): string => $request->header('Idempotency-Key')[0])
        ->values();

    expect($keys->all())->toBe([
        hash('sha256', 'mcp-order:trusted-session-for-different-carts:51'),
        hash('sha256', 'mcp-order:trusted-session-for-different-carts:52'),
    ])->and($keys->unique())->toHaveCount(2);
});

it('does not post an order when the current cart is missing', function () {
    Http::fake([
        'https://ordering-backend.test/api/carts/current' => Http::response([
            'message' => 'backend-current-cart-secret',
        ], 404),
    ]);

    FoodOrderingServer::tool(CreateOrderTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        'confirmation' => true,
        'delivery_time' => 0,
    ])
        ->assertHasErrors(['Запрошенный ресурс не найден.'])
        ->assertDontSee('backend-current-cart-secret');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://ordering-backend.test/api/carts/current'
        && $request->hasHeader('X-Session-Token', 'backend-session-token-secret'));
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

it('does not post an order when the backend cart response is malformed', function () {
    Http::fake([
        'https://ordering-backend.test/api/carts/current' => Http::response([
            'data' => orderingBackendCart(['id' => 'model-selected-cart']),
        ]),
    ]);

    FoodOrderingServer::tool(CreateOrderTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        'confirmation' => true,
        'delivery_time' => 0,
    ])->assertHasErrors([
        'Сервис заказа вернул некорректный ответ. Повторите попытку позже.',
    ]);

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

it('maps backend order failures safely without retrying the order POST', function (
    int $status,
    string $safeMessage,
) {
    Http::fake([
        'https://ordering-backend.test/api/carts/current' => Http::response([
            'data' => orderingBackendCart(),
        ]),
        'https://ordering-backend.test/api/orders' => Http::response([
            'message' => 'backend-order-failure-secret',
            'debug' => 'backend-order-debug-secret',
        ], $status),
    ]);

    FoodOrderingServer::tool(CreateOrderTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        'confirmation' => true,
        'delivery_time' => 0,
    ])
        ->assertHasErrors([$safeMessage])
        ->assertDontSee([
            'backend-order-failure-secret',
            'backend-order-debug-secret',
        ]);

    $orderPosts = Http::recorded()
        ->map(fn (array $record): Request => $record[0])
        ->filter(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://ordering-backend.test/api/orders');

    expect($orderPosts)->toHaveCount(1);
    Http::assertSentCount(2);
})->with([
    'checkout conflict' => [
        409,
        'Запрос конфликтует с текущим состоянием заказа.',
    ],
    'checkout rejection' => [
        422,
        'Не удалось оформить заказ. Возможно, ресторан сейчас не принимает заказы или выбранное время получения недоступно',
    ],
    'backend server failure' => [
        500,
        'Сервис заказа временно недоступен.',
    ],
    'bad gateway' => [
        502,
        'Сервис заказа временно недоступен.',
    ],
    'service unavailable' => [
        503,
        'Сервис заказа временно недоступен.',
    ],
]);

it('makes exactly one order POST after an ambiguous connection or timeout failure', function () {
    Http::fake([
        'https://ordering-backend.test/api/carts/current' => Http::response([
            'data' => orderingBackendCart(),
        ]),
        'https://ordering-backend.test/api/orders' => Http::failedConnection(
            'cURL error 28: operation timed out with secret transport details',
        ),
    ]);

    FoodOrderingServer::tool(CreateOrderTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        'confirmation' => true,
        'delivery_time' => 0,
    ])
        ->assertHasErrors([
            'Не удалось связаться с сервисом заказа. Повторите попытку позже.',
        ])
        ->assertDontSee('secret transport details');

    $orderPosts = Http::recorded()
        ->map(fn (array $record): Request => $record[0])
        ->filter(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://ordering-backend.test/api/orders');

    expect($orderPosts)->toHaveCount(1);
    Http::assertSentCount(2);
});

it('rejects an invalid session handle before the cart lookup or order POST', function () {
    Http::fake();

    FoodOrderingServer::tool(CreateOrderTool::class, [
        'session_handle' => 'invalid-session-handle',
        'confirmation' => true,
        'delivery_time' => 0,
    ])->assertHasErrors([
        'Контекст заказа недействителен или истёк. Создайте новый контекст заказа.',
    ]);

    Http::assertNothingSent();
});

it('validates delivery_time before making HTTP requests', function (
    array $arguments,
    string $message,
) {
    Http::fake();

    FoodOrderingServer::tool(CreateOrderTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        'confirmation' => true,
        ...$arguments,
    ])->assertHasErrors([$message]);

    Http::assertNothingSent();
})->with([
    'missing' => [[], 'Аргумент delivery_time обязателен.'],
    'negative' => [
        ['delivery_time' => -1],
        'Аргумент delivery_time не соответствует минимальному ограничению 0.',
    ],
    'not an integer' => [
        ['delivery_time' => 'завтра'],
        'Аргумент delivery_time должен быть целым числом.',
    ],
]);

it('rejects forbidden create-order argument :dataset before HTTP', function (
    string $argument,
    mixed $value,
) {
    Http::fake();

    FoodOrderingServer::tool(CreateOrderTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        'confirmation' => true,
        'delivery_time' => 0,
        $argument => $value,
    ])->assertHasErrors([
        "Аргумент {$argument} не поддерживается этим инструментом.",
    ]);

    Http::assertNothingSent();
})->with([
    'cart id' => ['cart_id', 999],
    'idempotency key' => ['idempotency_key', 'model-controlled-key'],
    'idempotency header' => ['Idempotency-Key', 'model-controlled-key'],
    'restaurant id' => ['restaurant_id', 12],
    'session id' => ['session_id', 'model-controlled-session'],
    'customer id' => ['customer_id', 8],
    'product id' => ['product_id', 91],
    'price' => ['price', '0.01'],
    'unit price' => ['unit_price', '0.01'],
    'subtotal' => ['subtotal', '0.01'],
    'total' => ['total', '0.01'],
    'currency' => ['currency', 'UAH'],
    'receiving type' => ['receiving_type', 'pickup'],
    'payment type' => ['payment_type', 'cash'],
    'external order id' => ['external_order_id', 'model-controlled-order'],
    'external product id' => ['external_product_id', 'model-controlled-product'],
    'session token' => ['session_token', 'model-controlled-token'],
]);

it('maps a malformed successful order response safely', function () {
    Http::fake([
        'https://ordering-backend.test/api/carts/current' => Http::response([
            'data' => orderingBackendCart(),
        ]),
        'https://ordering-backend.test/api/orders' => Http::response([
            'data' => orderingBackendOrder(['total' => 180.01]),
        ], 201),
    ]);

    FoodOrderingServer::tool(CreateOrderTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        'confirmation' => true,
        'delivery_time' => 0,
    ])->assertHasErrors([
        'Сервис заказа вернул некорректный ответ. Повторите попытку позже.',
    ]);

    Http::assertSentCount(2);
});
