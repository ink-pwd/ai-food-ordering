<?php

use App\Exceptions\OrderingBackendException;
use App\Integrations\OrderingBackend\OrderingBackendClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.ordering_backend.url', 'http://ordering-backend.test');
    config()->set('services.ordering_backend.token', 'internal-api-secret');
    config()->set('services.ordering_backend.restaurant_slug', 'test-restaurant');
    config()->set('services.ordering_backend.timeout', 7);

    Http::preventStrayRequests();
});

test('it creates a Telegram session using the backend contract', function () {
    $sessionToken = str_repeat('a', 64);

    Http::fake([
        'ordering-backend.test/api/sessions' => Http::response([
            'data' => [
                'session_id' => '01K23456789ABCDEFGHJKMNPQRS',
                'session_token' => $sessionToken,
                'channel' => 'telegram',
                'status' => 'active',
                'expires_at' => '2026-08-11T15:00:00+00:00',
                'restaurant' => [
                    'name' => 'Pizza House',
                    'slug' => 'pizza-house',
                    'currency' => 'UAH',
                    'locale' => 'uk-UA',
                    'timezone' => 'Europe/Kyiv',
                ],
            ],
        ], 201),
    ]);

    $result = app(OrderingBackendClient::class)
        ->createTelegramSession('telegram-chat-123456');

    expect($result)->toBe($sessionToken);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://ordering-backend.test/api/sessions'
        && $request->hasHeader('X-Internal-Api-Token', 'internal-api-secret')
        && $request->data() === [
            'channel' => 'telegram',
            'external_session_id' => 'telegram-chat-123456',
        ]);
    Http::assertSentCount(1);
});

test('it maps backend HTTP failures to a safe integration exception', function () {
    Http::fake([
        'ordering-backend.test/api/sessions' => Http::response([
            'message' => 'Session service unavailable.',
        ], 503),
    ]);

    $exception = captureOrderingBackendException(
        fn () => app(OrderingBackendClient::class)
            ->createTelegramSession('telegram-chat-123456'),
    );

    expect($exception->getMessage())->toBe('Unable to create an ordering backend session.')
        ->and($exception->statusCode())->toBe(503)
        ->and($exception->responseMessage())->toBe('Session service unavailable.');
});

test('it keeps malformed or missing backend response messages out of the integration exception', function (mixed $responseBody) {
    Http::fake([
        'ordering-backend.test/api/sessions' => Http::response($responseBody, 422),
    ]);

    $exception = captureOrderingBackendException(
        fn () => app(OrderingBackendClient::class)
            ->createTelegramSession('telegram-chat-123456'),
    );

    expect($exception->getMessage())->toBe('Unable to create an ordering backend session.')
        ->and($exception->statusCode())->toBe(422)
        ->and($exception->responseMessage())->toBeNull();
})->with([
    'missing message' => [['error' => 'Unavailable']],
    'empty message' => [['message' => '   ']],
    'non-string message' => [['message' => ['internal' => 'Unavailable']]],
    'malformed json' => ['not-json'],
]);

test('it has no backend response message for connection failures', function () {
    Http::fake([
        'ordering-backend.test/api/sessions' => Http::failedConnection(),
    ]);

    $exception = captureOrderingBackendException(
        fn () => app(OrderingBackendClient::class)
            ->createTelegramSession('telegram-chat-123456'),
    );

    expect($exception->getMessage())->toBe('Unable to create an ordering backend session.')
        ->and($exception->statusCode())->toBeNull()
        ->and($exception->responseMessage())->toBeNull();
});

test('it retrieves catalog categories using the configured restaurant and internal token', function () {
    Http::fake([
        'ordering-backend.test/api/restaurants/test-restaurant/categories' => Http::response([
            'data' => [
                [
                    'id' => 37,
                    'external_id' => 'external-category-id',
                    'name' => 'Пицца',
                    'slug' => 'pizza',
                    'sort_order' => 10,
                ],
            ],
        ]),
    ]);

    $categories = app(OrderingBackendClient::class)->categories();

    expect($categories)->toBe([
        [
            'id' => 37,
            'name' => 'Пицца',
        ],
    ]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'http://ordering-backend.test/api/restaurants/test-restaurant/categories'
        && $request->hasHeader('X-Internal-Api-Token', 'internal-api-secret')
        && ! $request->hasHeader('X-Session-Token'));
    Http::assertSentCount(1);
});

test('it retrieves category products and product details by backend local ids', function () {
    Http::fake([
        'ordering-backend.test/api/restaurants/test-restaurant/categories/37/products' => Http::response([
            'data' => [catalogBackendProduct()],
        ]),
        'ordering-backend.test/api/restaurants/test-restaurant/products/502' => Http::response([
            'data' => catalogBackendProduct(),
        ]),
    ]);

    $client = app(OrderingBackendClient::class);

    expect($client->categoryProducts(37))->toBe([
        [
            'id' => 502,
            'name' => 'Margherita',
            'price' => '220.00',
            'promotion_price' => '190.00',
            'currency' => 'UAH',
        ],
    ])->and($client->product(502))->toBe([
        'id' => 502,
        'name' => 'Margherita',
        'description' => 'Tomato, mozzarella and basil',
        'price' => '220.00',
        'promotion_price' => '190.00',
        'currency' => 'UAH',
        'is_available' => true,
    ]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'http://ordering-backend.test/api/restaurants/test-restaurant/categories/37/products');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'http://ordering-backend.test/api/restaurants/test-restaurant/products/502');
    Http::assertSentCount(2);
});

test('it rejects malformed catalog responses with a safe integration exception', function () {
    Http::fake([
        'ordering-backend.test/api/restaurants/test-restaurant/categories' => Http::response('not-json'),
    ]);

    expect(fn () => app(OrderingBackendClient::class)->categories())
        ->toThrow(OrderingBackendException::class, 'Ordering backend returned malformed catalog data.');
});

test('cart deletes return authoritative carts fetched after each mutation', function () {
    $sessionToken = str_repeat('c', 64);
    $remainingCart = [
        'data' => [
            'id' => 1,
            'status' => 'active',
            'currency' => 'UAH',
            'subtotal' => '150.00',
            'total' => '150.00',
            'expires_at' => '2026-08-12T00:00:00+00:00',
            'items' => [
                [
                    'id' => 68,
                    'product_id' => 7,
                    'external_product_id' => 'pepperoni-external',
                    'name' => 'Pizza Pepperoni',
                    'quantity' => 1,
                    'unit_price' => '150.00',
                    'total' => '150.00',
                ],
            ],
        ],
    ];
    $emptyCart = [
        'data' => [
            'id' => 1,
            'status' => 'active',
            'currency' => 'UAH',
            'subtotal' => '0.00',
            'total' => '0.00',
            'expires_at' => '2026-08-12T00:00:00+00:00',
            'items' => [],
        ],
    ];

    Http::fake([
        'ordering-backend.test/api/carts/current/items/51' => Http::response(['data' => ['ignored' => true]]),
        'ordering-backend.test/api/carts/current/items' => Http::response(['data' => ['ignored' => true]]),
        'ordering-backend.test/api/carts/current' => Http::sequence()
            ->push($remainingCart)
            ->push($emptyCart),
    ]);

    $client = app(OrderingBackendClient::class);

    expect($client->removeCurrentCartItem(51, $sessionToken))
        ->toBe($remainingCart['data'])
        ->and($client->clearCurrentCart($sessionToken))
        ->toBe($emptyCart['data']);

    Http::assertSentInOrder([
        fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'http://ordering-backend.test/api/carts/current/items/51'
            && $request->hasHeader('X-Internal-Api-Token', 'internal-api-secret')
            && $request->hasHeader('X-Session-Token', $sessionToken)
            && $request->data() === [],
        fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'http://ordering-backend.test/api/carts/current',
        fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'http://ordering-backend.test/api/carts/current/items'
            && $request->hasHeader('X-Internal-Api-Token', 'internal-api-secret')
            && $request->hasHeader('X-Session-Token', $sessionToken)
            && $request->data() === [],
        fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'http://ordering-backend.test/api/carts/current',
    ]);
    Http::assertSentCount(4);
});

/**
 * @return array{id: int, external_id: string, name: string, description: string, price: string, promotion_price: string, currency: string, image_url: string, is_available: bool, sort_order: int}
 */
function catalogBackendProduct(): array
{
    return [
        'id' => 502,
        'external_id' => 'external-product-id',
        'name' => 'Margherita',
        'description' => 'Tomato, mozzarella and basil',
        'price' => '220.00',
        'promotion_price' => '190.00',
        'currency' => 'UAH',
        'image_url' => 'https://example.test/margherita.jpg',
        'is_available' => true,
        'sort_order' => 10,
    ];
}

function captureOrderingBackendException(Closure $operation): OrderingBackendException
{
    try {
        $operation();
    } catch (OrderingBackendException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected an OrderingBackendException to be thrown.');
}
