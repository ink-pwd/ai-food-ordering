<?php

use App\Mcp\Servers\FoodOrderingServer;
use App\Mcp\Tools\GetCategoriesTool;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    configureOrderingToolBackend();
});

it('gets categories for the trusted restaurant and exposes only local category identifiers', function () {
    $category = orderingBackendCategory();

    Http::fake([
        'https://ordering-backend.test/api/restaurants/trusted-restaurant/categories' => Http::response([
            'data' => [$category],
        ]),
    ]);

    FoodOrderingServer::tool(GetCategoriesTool::class, [
        'session_handle' => orderingToolSessionHandle(),
    ])
        ->assertOk()
        ->assertName('get_categories')
        ->assertStructuredContent([
            'categories' => [[
                'id' => 17,
                'name' => 'Pizza',
                'slug' => 'pizza',
                'sort_order' => 2,
            ]],
        ])
        ->assertDontSee([$category['external_id'], 'backend-session-token-secret', 'Пицца']);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'GET'
            && $request->url() === 'https://ordering-backend.test/api/restaurants/trusted-restaurant/categories'
            && $request->hasHeader('X-Internal-Api-Token', 'internal-api-token-secret')
            && ! $request->hasHeader('X-Session-Token');
    });
});

it('preserves Russian category text exactly as returned by the backend', function () {
    $category = orderingBackendCategory([
        'name' => 'Пицца и паста',
        'slug' => 'pizza-and-pasta',
    ]);

    Http::fake([
        'https://ordering-backend.test/api/restaurants/trusted-restaurant/categories' => Http::response([
            'data' => [$category],
        ]),
    ]);

    FoodOrderingServer::tool(GetCategoriesTool::class, [
        'session_handle' => orderingToolSessionHandle(),
    ])
        ->assertOk()
        ->assertStructuredContent([
            'categories' => [[
                'id' => 17,
                'name' => 'Пицца и паста',
                'slug' => 'pizza-and-pasta',
                'sort_order' => 2,
            ]],
        ]);
});

it('rejects a model-controlled restaurant argument before calling the backend', function () {
    Http::fake();

    FoodOrderingServer::tool(GetCategoriesTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        'restaurant_slug' => 'model-controlled-restaurant',
    ])->assertHasErrors(['Аргумент restaurant_slug не поддерживается этим инструментом.']);

    Http::assertNothingSent();
});

it('rejects an invalid category session handle without making an HTTP request', function () {
    Http::fake();

    FoodOrderingServer::tool(GetCategoriesTool::class, [
        'session_handle' => 'invalid-session-handle',
    ])->assertHasErrors(['Контекст заказа недействителен или истёк. Создайте новый контекст заказа.']);

    Http::assertNothingSent();
});
