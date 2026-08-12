<?php

use App\Mcp\Servers\FoodOrderingServer;
use App\Mcp\Tools\SearchProductsTool;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    configureOrderingToolBackend();
});

it('forwards product search to the trusted restaurant with the default limit', function () {
    $product = orderingBackendProduct(['is_available' => false]);

    Http::fake([
        'https://ordering-backend.test/api/restaurants/trusted-restaurant/products/search*' => Http::response([
            'data' => [$product],
        ]),
    ]);

    FoodOrderingServer::tool(SearchProductsTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        'query' => 'margherita',
    ])
        ->assertOk()
        ->assertName('search_products')
        ->assertStructuredContent([
            'products' => [orderingToolProductOutput($product)],
        ])
        ->assertDontSee([
            $product['external_id'],
            'backend-session-token-secret',
            'internal-api-token-secret',
        ]);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'GET'
            && $request->url() === 'https://ordering-backend.test/api/restaurants/trusted-restaurant/products/search?q=margherita&limit=10'
            && $request->data() === ['q' => 'margherita', 'limit' => 10]
            && $request->hasHeader('X-Internal-Api-Token', 'internal-api-token-secret')
            && ! $request->hasHeader('X-Session-Token');
    });
});

it('forwards Unicode and special characters with an explicit valid limit', function () {
    $query = 'піца & сир/груша?';

    Http::fake([
        'https://ordering-backend.test/api/restaurants/trusted-restaurant/products/search*' => Http::response([
            'data' => [],
        ]),
    ]);

    FoodOrderingServer::tool(SearchProductsTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        'query' => $query,
        'limit' => 25,
    ])
        ->assertOk()
        ->assertStructuredContent(['products' => []]);

    Http::assertSent(fn (Request $request): bool => $request->data() === [
        'q' => $query,
        'limit' => 25,
    ]);
});

it('rejects limits above the backend maximum before making an HTTP request', function () {
    Http::fake();

    FoodOrderingServer::tool(SearchProductsTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        'query' => 'pizza',
        'limit' => 51,
    ])->assertHasErrors(['Аргумент limit превышает максимально допустимое ограничение 50.']);

    Http::assertNothingSent();
});

it('returns an empty structured product list when the backend finds no matches', function () {
    Http::fake([
        'https://ordering-backend.test/api/restaurants/trusted-restaurant/products/search*' => Http::response([
            'data' => [],
        ]),
    ]);

    FoodOrderingServer::tool(SearchProductsTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        'query' => 'no matching product',
    ])
        ->assertOk()
        ->assertStructuredContent(['products' => []]);
});

it('maps backend search validation failures safely', function () {
    Http::fake([
        'https://ordering-backend.test/api/restaurants/trusted-restaurant/products/search*' => Http::response([
            'message' => 'backend-validation-secret',
            'errors' => ['q' => ['backend-query-detail-secret']],
        ], 422),
    ]);

    FoodOrderingServer::tool(SearchProductsTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        'query' => 'valid-at-mcp-boundary',
    ])
        ->assertHasErrors(['Запрос отклонён: входные данные или состояние оформления заказа недействительны.'])
        ->assertDontSee(['backend-validation-secret', 'backend-query-detail-secret']);

    Http::assertSentCount(1);
});

it('maps an unavailable ordering backend safely', function () {
    Http::fake([
        'https://ordering-backend.test/api/restaurants/trusted-restaurant/products/search*' => Http::failedConnection('connection-debug-secret'),
    ]);

    FoodOrderingServer::tool(SearchProductsTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        'query' => 'pizza',
    ])
        ->assertHasErrors(['Не удалось связаться с сервисом заказа. Повторите попытку позже.'])
        ->assertDontSee(['connection-debug-secret', 'backend-session-token-secret']);

    Http::assertSentCount(1);
});

it('rejects an invalid search session handle without making an HTTP request', function () {
    Http::fake();

    FoodOrderingServer::tool(SearchProductsTool::class, [
        'session_handle' => 'invalid-session-handle',
        'query' => 'pizza',
    ])
        ->assertHasErrors(['Контекст заказа недействителен или истёк. Создайте новый контекст заказа.'])
        ->assertDontSee('backend-session-token-secret');

    Http::assertNothingSent();
});
