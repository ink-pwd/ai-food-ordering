<?php

use App\Mcp\Servers\FoodOrderingServer;
use App\Mcp\Tools\GetOrderStatusTool;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    configureOrderingToolBackend();
});

it('gets authoritative current order state with the trusted session token', function () {
    $order = orderingBackendOrder([
        'status' => 'created',
        'failure_message' => 'raw-dots-status-message-secret',
        'total' => '8765.43',
        'items' => [
            [
                'product_id' => 91,
                'external_product_id' => '33333333-3333-3333-3333-333333333333',
                'name' => 'Борщ по-домашнему',
                'quantity' => 3,
                'unit_price' => '111.11',
                'total' => '333.33',
            ],
            [
                'product_id' => null,
                'external_product_id' => '55555555-5555-4555-8555-555555555555',
                'name' => 'English Product Name',
                'quantity' => 1,
                'unit_price' => '22.20',
                'total' => '22.20',
            ],
        ],
    ]);

    Http::fake([
        'https://ordering-backend.test/api/orders/current' => Http::response([
            'data' => $order,
        ]),
    ]);

    FoodOrderingServer::tool(GetOrderStatusTool::class, [
        'session_handle' => orderingToolSessionHandle(),
    ])
        ->assertOk()
        ->assertName('get_order_status')
        ->assertStructuredContent([
            'order' => orderingToolOrderOutput($order),
        ])
        ->assertDontSee([
            'raw-dots-status-message-secret',
            $order['items'][0]['external_product_id'],
            $order['items'][1]['external_product_id'],
            'backend-session-token-secret',
            'internal-api-token-secret',
            'Idempotency-Key',
        ]);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'GET'
            && $request->url() === 'https://ordering-backend.test/api/orders/current'
            && $request->hasHeader('X-Internal-Api-Token', 'internal-api-token-secret')
            && $request->hasHeader('X-Session-Token', 'backend-session-token-secret')
            && ! $request->hasHeader('Idempotency-Key')
            && $request->data() === [];
    });

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => str_contains(
        $request->url(),
        'dots',
    ));
});

it('preserves nullable backend order identifiers without inventing values', function () {
    $order = orderingBackendOrder([
        'external_order_id' => null,
        'status' => 'failed',
        'failure_message' => null,
        'items' => [[
            'product_id' => null,
            'external_product_id' => '33333333-3333-3333-3333-333333333333',
            'name' => 'Deleted local product snapshot',
            'quantity' => 1,
            'unit_price' => '10.00',
            'total' => '10.00',
        ]],
    ]);

    Http::fake([
        'https://ordering-backend.test/api/orders/current' => Http::response([
            'data' => $order,
        ]),
    ]);

    FoodOrderingServer::tool(GetOrderStatusTool::class, [
        'session_handle' => orderingToolSessionHandle(),
    ])->assertStructuredContent([
        'order' => orderingToolOrderOutput($order),
    ]);
});

it('maps a missing current order safely', function () {
    Http::fake([
        'https://ordering-backend.test/api/orders/current' => Http::response([
            'message' => 'backend-current-order-secret',
        ], 404),
    ]);

    FoodOrderingServer::tool(GetOrderStatusTool::class, [
        'session_handle' => orderingToolSessionHandle(),
    ])
        ->assertHasErrors(['Запрошенный ресурс не найден.'])
        ->assertDontSee('backend-current-order-secret');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://ordering-backend.test/api/orders/current');
});

it('rejects invalid tampered and expired session handles before HTTP', function () {
    Http::fake();

    $handles = [
        'invalid-session-handle',
        orderingToolSessionHandle().'tampered',
        orderingToolSessionHandle(
            expiresAt: CarbonImmutable::now()->subSecond(),
        ),
    ];

    foreach ($handles as $sessionHandle) {
        FoodOrderingServer::tool(GetOrderStatusTool::class, [
            'session_handle' => $sessionHandle,
        ])->assertHasErrors([
            'Контекст заказа недействителен или истёк. Создайте новый контекст заказа.',
        ]);
    }

    Http::assertNothingSent();
});

it('rejects forbidden order-status argument :dataset before HTTP', function (
    string $argument,
    mixed $value,
) {
    Http::fake();

    FoodOrderingServer::tool(GetOrderStatusTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        $argument => $value,
    ])->assertHasErrors([
        "Аргумент {$argument} не поддерживается этим инструментом.",
    ]);

    Http::assertNothingSent();
})->with([
    'order id' => ['order_id', 73],
    'external order id' => ['external_order_id', 'model-controlled-order'],
    'cart id' => ['cart_id', 51],
    'restaurant id' => ['restaurant_id', 12],
    'confirmation' => ['confirmation', true],
    'idempotency key' => ['idempotency_key', 'model-controlled-key'],
    'session token' => ['session_token', 'model-controlled-token'],
]);

it('maps a malformed current-order response safely', function () {
    Http::fake([
        'https://ordering-backend.test/api/orders/current' => Http::response([
            'data' => orderingBackendOrder([
                'status' => 'invented-status',
                'total' => 10.00,
            ]),
        ]),
    ]);

    FoodOrderingServer::tool(GetOrderStatusTool::class, [
        'session_handle' => orderingToolSessionHandle(),
    ])->assertHasErrors([
        'Сервис заказа вернул некорректный ответ. Повторите попытку позже.',
    ]);

    Http::assertSentCount(1);
});
