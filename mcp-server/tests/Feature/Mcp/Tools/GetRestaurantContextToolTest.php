<?php

use App\Mcp\Servers\FoodOrderingServer;
use App\Mcp\Support\SessionContextHandle;
use App\Mcp\Tools\GetRestaurantContextTool;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    configureOrderingToolBackend();
});

it('creates a backend ChatGPT session and returns safe restaurant context in an opaque handle', function () {
    $backendSessionToken = str_repeat('a', 64);
    $backendSessionId = '01JZXYZSESSION000000000001';
    $restaurant = [
        'name' => 'Kyiv Pizza',
        'slug' => 'kyiv-pizza',
        'currency' => 'UAH',
        'locale' => 'uk-UA',
        'timezone' => 'Europe/Kyiv',
    ];

    Http::fake([
        'https://ordering-backend.test/api/sessions' => Http::response([
            'data' => [
                'session_id' => $backendSessionId,
                'session_token' => $backendSessionToken,
                'channel' => 'chatgpt',
                'status' => 'active',
                'expires_at' => now()->addHour()->toAtomString(),
                'restaurant' => $restaurant,
            ],
        ], 201),
    ]);

    $sessionHandle = null;

    FoodOrderingServer::tool(GetRestaurantContextTool::class)
        ->assertOk()
        ->assertName('get_restaurant_context')
        ->assertStructuredContent(function (AssertableJson $json) use (&$sessionHandle, $restaurant): void {
            $sessionHandle = $json->toArray()['session_handle'];

            $json
                ->whereType('session_handle', 'string')
                ->where('restaurant', $restaurant);
        })
        ->assertDontSee([$backendSessionToken, $backendSessionId]);

    expect($sessionHandle)->toBeString()->not->toBeEmpty();

    $context = app(SessionContextHandle::class)->restore($sessionHandle);

    expect($context->backendSessionId())->toBe($backendSessionId)
        ->and($context->backendSessionToken())->toBe($backendSessionToken)
        ->and($context->restaurantSlug())->toBe('kyiv-pizza');

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        return $request->method() === 'POST'
            && $request->url() === 'https://ordering-backend.test/api/sessions'
            && $request->hasHeader('X-Internal-Api-Token', 'internal-api-token-secret')
            && array_keys($payload) === ['channel', 'external_session_id']
            && $payload['channel'] === 'chatgpt'
            && is_string($payload['external_session_id'])
            && Str::isUuid($payload['external_session_id']);
    });
});

it('rejects model-controlled bootstrap arguments before calling the backend', function () {
    Http::fake();

    FoodOrderingServer::tool(GetRestaurantContextTool::class, [
        'external_session_id' => 'model-controlled-session',
        'restaurant_slug' => 'model-controlled-restaurant',
        'account_id' => 99,
    ])
        ->assertHasErrors(['Аргумент external_session_id не поддерживается этим инструментом.'])
        ->assertDontSee('model-controlled-session');

    Http::assertNothingSent();
});

it('maps backend session creation failures to a safe MCP error', function () {
    Http::fake([
        'https://ordering-backend.test/api/sessions' => Http::response([
            'message' => 'backend-debug-secret',
            'session_token' => 'backend-response-token-secret',
        ], 503),
    ]);

    FoodOrderingServer::tool(GetRestaurantContextTool::class)
        ->assertHasErrors(['Сервис заказа временно недоступен.'])
        ->assertDontSee([
            'backend-debug-secret',
            'backend-response-token-secret',
            'internal-api-token-secret',
        ]);

    Http::assertSentCount(1);
});
