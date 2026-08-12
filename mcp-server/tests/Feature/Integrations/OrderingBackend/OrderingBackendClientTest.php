<?php

use App\Integrations\OrderingBackend\OrderingBackendClient;
use App\Integrations\OrderingBackend\OrderingBackendException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.ordering_backend', [
        'url' => 'https://backend-url-secret.test/base/',
        'internal_api_token' => 'internal-api-token-secret',
        'timeout' => 7,
    ]);

    Http::preventStrayRequests();
});

it('uses the configured base URL and sends JSON requests with required and optional headers', function () {
    Http::fake([
        'https://backend-url-secret.test/base/api/orders' => Http::response([
            'data' => ['id' => 91],
        ]),
    ]);

    $result = app(OrderingBackendClient::class)->post(
        '/api/orders',
        ['delivery_time' => '2026-08-12T18:00:00+03:00'],
        'backend-session-token-secret',
        'stable-idempotency-key-secret',
    );

    expect($result)->toBe(['data' => ['id' => 91]]);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://backend-url-secret.test/base/api/orders'
            && $request->hasHeader('Accept', 'application/json')
            && $request->hasHeader('Content-Type', 'application/json')
            && $request->hasHeader('X-Internal-Api-Token', 'internal-api-token-secret')
            && $request->hasHeader('X-Session-Token', 'backend-session-token-secret')
            && $request->hasHeader('Idempotency-Key', 'stable-idempotency-key-secret')
            && $request->data() === ['delivery_time' => '2026-08-12T18:00:00+03:00'];
    });
});

it('forwards query parameters without adding optional headers', function () {
    Http::fake([
        'https://backend-url-secret.test/base/api/products/search*' => Http::response([
            'data' => [],
        ]),
    ]);

    $result = app(OrderingBackendClient::class)->get('/api/products/search', [
        'q' => 'pizza',
        'limit' => 10,
    ]);

    expect($result)->toBe(['data' => []]);

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'GET'
            && $request->url() === 'https://backend-url-secret.test/base/api/products/search?q=pizza&limit=10'
            && $request->hasHeader('X-Internal-Api-Token', 'internal-api-token-secret')
            && ! $request->hasHeader('X-Session-Token')
            && ! $request->hasHeader('Idempotency-Key');
    });
});

it('sends JSON bodies for every backend write verb', function (string $method) {
    Http::fake([
        'https://backend-url-secret.test/base/api/example' => Http::response([
            'data' => ['quantity' => 2],
        ]),
    ]);

    $result = app(OrderingBackendClient::class)->{$method}(
        '/api/example',
        ['quantity' => 2],
        'backend-session-token-secret',
    );

    expect($result)->toBe(['data' => ['quantity' => 2]]);

    Http::assertSent(fn (Request $request): bool => $request->method() === mb_strtoupper($method)
        && $request->data() === ['quantity' => 2]
        && $request->hasHeader('Content-Type', 'application/json'));
})->with([
    'post' => 'post',
    'put' => 'put',
    'patch' => 'patch',
    'delete' => 'delete',
]);

it('applies the configured request timeout', function () {
    $observedTimeout = null;
    $factory = new Factory;
    $factory->globalMiddleware(function (callable $handler) use (&$observedTimeout): callable {
        return function ($request, array $options) use ($handler, &$observedTimeout) {
            $observedTimeout = $options['timeout'];

            return $handler($request, $options);
        };
    });
    $factory->preventStrayRequests();
    $factory->fake([
        'https://backend-url-secret.test/base/api/status' => Factory::response(['data' => ['status' => 'ok']]),
    ]);

    (new OrderingBackendClient($factory))->get('/api/status');

    expect($observedTimeout)->toBe(7);
});

it('maps backend failures to safe categorized exceptions without retrying', function (
    int $status,
    string $category,
    string $message,
) {
    Http::fake([
        'https://backend-url-secret.test/*' => Http::response([
            'message' => 'backend-body-secret',
            'token' => 'response-token-secret',
        ], $status),
    ]);

    try {
        app(OrderingBackendClient::class)->post(
            '/api/orders',
            ['secret' => 'request-body-secret'],
            'backend-session-token-secret',
            'stable-idempotency-key-secret',
        );
    } catch (OrderingBackendException $exception) {
        expect($exception->category())->toBe($category)
            ->and($exception->backendStatus())->toBe($status)
            ->and($exception->getMessage())->toBe($message)
            ->and($exception->getMessage())
            ->not->toContain('backend-url-secret')
            ->not->toContain('internal-api-token-secret')
            ->not->toContain('backend-session-token-secret')
            ->not->toContain('stable-idempotency-key-secret')
            ->not->toContain('backend-body-secret')
            ->not->toContain('response-token-secret')
            ->and($exception->getPrevious())->toBeNull();

        Http::assertSentCount(1);

        return;
    }

    $this->fail('Expected an OrderingBackendException.');
})->with([
    'unauthorized' => [
        401,
        'authentication',
        'Не удалось авторизовать запрос к сервису заказа. Создайте новый контекст заказа.',
    ],
    'not found' => [404, 'not_found', 'Запрошенный ресурс не найден.'],
    'conflict' => [409, 'conflict', 'Запрос конфликтует с текущим состоянием заказа.'],
    'validation' => [
        422,
        'validation',
        'Запрос отклонён: входные данные или состояние оформления заказа недействительны.',
    ],
    'bad request' => [400, 'request_rejected', 'Сервис заказа отклонил запрос.'],
    'internal server error' => [
        500,
        'service_unavailable',
        'Сервис заказа временно недоступен.',
    ],
    'bad gateway' => [
        502,
        'service_unavailable',
        'Сервис заказа временно недоступен.',
    ],
    'service unavailable' => [
        503,
        'service_unavailable',
        'Сервис заказа временно недоступен.',
    ],
]);

it('maps connection failures safely without retrying', function () {
    Http::fake([
        'https://backend-url-secret.test/*' => Http::failedConnection('connection-detail-secret'),
    ]);

    try {
        app(OrderingBackendClient::class)->post(
            '/api/orders',
            [],
            'backend-session-token-secret',
            'stable-idempotency-key-secret',
        );
    } catch (OrderingBackendException $exception) {
        expect($exception->category())->toBe('connection')
            ->and($exception->backendStatus())->toBeNull()
            ->and($exception->getMessage())->toBe('Не удалось связаться с сервисом заказа. Повторите попытку позже.')
            ->and($exception->getMessage())
            ->not->toContain('backend-url-secret')
            ->not->toContain('internal-api-token-secret')
            ->not->toContain('backend-session-token-secret')
            ->not->toContain('stable-idempotency-key-secret')
            ->not->toContain('connection-detail-secret')
            ->and($exception->getPrevious())->toBeNull();

        Http::assertSentCount(1);

        return;
    }

    $this->fail('Expected an OrderingBackendException.');
});

it('rejects malformed successful JSON without exposing the response', function () {
    Http::fake([
        'https://backend-url-secret.test/*' => Http::response(
            '{"backend-body-secret":',
            200,
            ['Content-Type' => 'application/json'],
        ),
    ]);

    try {
        app(OrderingBackendClient::class)->get('/api/status', sessionToken: 'backend-session-token-secret');
    } catch (OrderingBackendException $exception) {
        expect($exception->category())->toBe('invalid_response')
            ->and($exception->backendStatus())->toBe(200)
            ->and($exception->getMessage())->toBe('Сервис заказа вернул некорректный ответ. Повторите попытку позже.')
            ->and($exception->getMessage())
            ->not->toContain('backend-url-secret')
            ->not->toContain('internal-api-token-secret')
            ->not->toContain('backend-session-token-secret')
            ->not->toContain('backend-body-secret')
            ->and($exception->getPrevious())->toBeNull();

        Http::assertSentCount(1);

        return;
    }

    $this->fail('Expected an OrderingBackendException.');
});
