<?php

use App\Exceptions\OrderingBackendException;
use App\Integrations\OrderingBackend\OrderingBackendClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.ordering_backend.url', 'http://ordering-backend.test');
    config()->set('services.ordering_backend.token', 'internal-api-secret');
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
                'city' => null,
                'restaurant' => null,
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
            'errors' => ['phone' => ['Invalid phone.']],
        ], 503),
    ]);

    $exception = captureOrderingBackendException(
        fn () => app(OrderingBackendClient::class)
            ->createTelegramSession('telegram-chat-123456'),
    );

    expect($exception->getMessage())->toBe('Unable to create an ordering backend session.')
        ->and($exception->statusCode())->toBe(503)
        ->and($exception->responseMessage())->toBe('Session service unavailable.')
        ->and($exception->responseErrors())->toBe(['phone' => ['Invalid phone.']]);
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
        ->and($exception->responseMessage())->toBeNull()
        ->and($exception->responseErrors())->toBeNull();
});

test('it updates and deletes the current session with the session header', function () {
    $sessionToken = str_repeat('s', 64);

    Http::fake([
        'ordering-backend.test/api/sessions/current/contact' => Http::response([
            'data' => [
                'session_id' => '01KSESSION',
                'contact' => [
                    'name' => 'Test User',
                    'phone' => '+380931234567',
                    'phone_verified' => false,
                ],
            ],
        ]),
        'ordering-backend.test/api/sessions/current' => Http::response([
            'data' => [
                'session_id' => '01KSESSION',
                'status' => 'closed',
            ],
        ]),
    ]);

    $client = app(OrderingBackendClient::class);

    $client->updateCurrentSessionContact($sessionToken, 'Test User', '+380931234567');

    expect($client->deleteCurrentSession($sessionToken))->toBe([
        'session_id' => '01KSESSION',
        'status' => 'closed',
    ]);

    Http::assertSentInOrder([
        fn (Request $request): bool => $request->method() === 'PUT'
            && $request->url() === 'http://ordering-backend.test/api/sessions/current/contact'
            && $request->hasHeader('X-Internal-Api-Token', 'internal-api-secret')
            && $request->hasHeader('X-Session-Token', $sessionToken)
            && $request->data() === [
                'name' => 'Test User',
                'phone' => '+380931234567',
            ],
        fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'http://ordering-backend.test/api/sessions/current'
            && $request->hasHeader('X-Internal-Api-Token', 'internal-api-secret')
            && $request->hasHeader('X-Session-Token', $sessionToken),
    ]);
    Http::assertSentCount(2);
});

test('it requests and verifies current session OTP using the backend contract', function () {
    $sessionToken = str_repeat('o', 64);

    Http::fake([
        'ordering-backend.test/api/sessions/current/otp' => Http::response([
            'data' => [
                'expires_in' => 300,
                'resend_available_in' => 60,
            ],
        ]),
        'ordering-backend.test/api/sessions/current/otp/verify' => Http::response([
            'data' => [
                'session_id' => '01KSESSION',
                'contact' => [
                    'name' => 'Test User',
                    'phone' => '+380931234567',
                    'phone_verified' => true,
                ],
            ],
        ]),
    ]);

    $client = app(OrderingBackendClient::class);

    expect($client->requestCurrentSessionOtp($sessionToken))->toBe([
        'expires_in' => 300,
        'resend_available_in' => 60,
    ])->and($client->verifyCurrentSessionOtp($sessionToken, '123456'))->toBe([
        'session_id' => '01KSESSION',
        'contact' => [
            'name' => 'Test User',
            'phone' => '+380931234567',
            'phone_verified' => true,
        ],
    ]);

    Http::assertSentInOrder([
        fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'http://ordering-backend.test/api/sessions/current/otp'
            && $request->hasHeader('X-Session-Token', $sessionToken)
            && $request->data() === [],
        fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'http://ordering-backend.test/api/sessions/current/otp/verify'
            && $request->hasHeader('X-Session-Token', $sessionToken)
            && $request->data() === ['code' => '123456'],
    ]);
    Http::assertSentCount(2);
});

test('it lists and selects cities using backend local identifiers', function () {
    $sessionToken = str_repeat('c', 64);

    Http::fake([
        'ordering-backend.test/api/cities' => Http::response(['data' => [cityPayload()]]),
        'ordering-backend.test/api/sessions/current/city' => Http::response([
            'data' => [
                'session_id' => '01KSESSION',
                'city' => cityPayload(),
            ],
        ]),
    ]);

    $client = app(OrderingBackendClient::class);

    expect($client->cities())->toBe([normalizedCity()])
        ->and($client->selectCurrentSessionCity($sessionToken, 7))->toBe([
            'session_id' => '01KSESSION',
            'city' => normalizedCity(),
        ]);

    Http::assertSentInOrder([
        fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'http://ordering-backend.test/api/cities'
            && $request->hasHeader('X-Internal-Api-Token', 'internal-api-secret')
            && ! $request->hasHeader('X-Session-Token'),
        fn (Request $request): bool => $request->method() === 'PUT'
            && $request->url() === 'http://ordering-backend.test/api/sessions/current/city'
            && $request->hasHeader('X-Session-Token', $sessionToken)
            && $request->data() === ['city_id' => 7],
    ]);
    Http::assertSentCount(2);
});

test('it lists and selects restaurants exposing the backend restaurant slug context', function () {
    $sessionToken = str_repeat('r', 64);

    Http::fake([
        'ordering-backend.test/api/sessions/current/restaurants' => Http::response(['data' => [restaurantPayload()]]),
        'ordering-backend.test/api/sessions/current/restaurant' => Http::response([
            'data' => [
                'session_id' => '01KSESSION',
                'restaurant' => restaurantPayload(),
            ],
        ]),
    ]);

    $client = app(OrderingBackendClient::class);

    expect($client->currentSessionRestaurants($sessionToken))->toBe([normalizedRestaurant()])
        ->and($client->selectCurrentSessionRestaurant($sessionToken, 10))->toBe([
            'session_id' => '01KSESSION',
            'restaurant' => normalizedRestaurant(),
        ]);

    Http::assertSentInOrder([
        fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'http://ordering-backend.test/api/sessions/current/restaurants'
            && $request->hasHeader('X-Session-Token', $sessionToken),
        fn (Request $request): bool => $request->method() === 'PUT'
            && $request->url() === 'http://ordering-backend.test/api/sessions/current/restaurant'
            && $request->hasHeader('X-Session-Token', $sessionToken)
            && $request->data() === ['restaurant_id' => 10],
    ]);
    Http::assertSentCount(2);
});

test('it calls fulfillment endpoints with only contract-approved payloads', function () {
    $sessionToken = str_repeat('f', 64);

    Http::fake([
        'ordering-backend.test/api/sessions/current/fulfillment-options' => Http::response([
            'data' => [['type' => 'pickup'], ['type' => 'delivery']],
        ]),
        'ordering-backend.test/api/sessions/current/fulfillment' => Http::response(fulfillmentResponse(['type' => 'pickup'])),
        'ordering-backend.test/api/sessions/current/pickup-addresses' => Http::response([
            'data' => [[
                'id' => 5,
                'title' => 'Main counter',
                'latitude' => '50.4501',
                'longitude' => '30.5234',
            ]],
        ]),
        'ordering-backend.test/api/sessions/current/pickup-address' => Http::response(fulfillmentResponse(['restaurant_address_id' => 5])),
        'ordering-backend.test/api/sessions/current/delivery-address' => Http::response([
            'data' => [
                'session_id' => '01KSESSION',
                'delivery_available' => true,
                'reason' => null,
                'delivery_price' => '99.00',
                'dots_delivery_type' => 1,
                'fulfillment' => ['type' => 'delivery'],
            ],
        ]),
    ]);

    $client = app(OrderingBackendClient::class);

    expect($client->currentSessionFulfillmentOptions($sessionToken))->toBe([
        ['type' => 'pickup'],
        ['type' => 'delivery'],
    ])->and($client->selectCurrentSessionFulfillment($sessionToken, 'pickup'))->toBe([
        'session_id' => '01KSESSION',
        'fulfillment' => ['type' => 'pickup'],
    ])->and($client->currentSessionPickupAddresses($sessionToken))->toBe([[
        'id' => 5,
        'title' => 'Main counter',
        'latitude' => '50.4501',
        'longitude' => '30.5234',
    ]])->and($client->selectCurrentSessionPickupAddress($sessionToken, 5))->toBe([
        'session_id' => '01KSESSION',
        'fulfillment' => ['restaurant_address_id' => 5],
    ])->and($client->validateCurrentSessionDeliveryAddress($sessionToken, [
        'type' => 1,
        'street' => 'Main Street',
        'house' => '10',
        'flat' => '4',
        'stage' => '2',
        'note' => 'Door code',
        'title' => 'Home',
    ]))->toBe([
        'session_id' => '01KSESSION',
        'delivery_available' => true,
        'reason' => null,
        'delivery_price' => '99.00',
        'dots_delivery_type' => 1,
        'fulfillment' => ['type' => 'delivery'],
    ]);

    Http::assertSentInOrder([
        fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'http://ordering-backend.test/api/sessions/current/fulfillment-options',
        fn (Request $request): bool => $request->method() === 'PUT'
            && $request->url() === 'http://ordering-backend.test/api/sessions/current/fulfillment'
            && $request->data() === ['type' => 'pickup'],
        fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'http://ordering-backend.test/api/sessions/current/pickup-addresses',
        fn (Request $request): bool => $request->method() === 'PUT'
            && $request->url() === 'http://ordering-backend.test/api/sessions/current/pickup-address'
            && $request->data() === ['restaurant_address_id' => 5],
        fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'http://ordering-backend.test/api/sessions/current/delivery-address'
            && $request->data() === [
                'type' => 1,
                'street' => 'Main Street',
                'house' => '10',
                'flat' => '4',
                'stage' => '2',
                'note' => 'Door code',
                'title' => 'Home',
            ],
    ]);
    Http::assertSentCount(5);
});

test('it retrieves catalog categories using an explicit restaurant slug and internal token', function () {
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

    $categories = app(OrderingBackendClient::class)->categories('test-restaurant');

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

test('it retrieves category products search results and product details by explicit restaurant slug', function () {
    Http::fake([
        'ordering-backend.test/api/restaurants/test-restaurant/categories/37/products' => Http::response([
            'data' => [catalogBackendProduct()],
        ]),
        'ordering-backend.test/api/restaurants/test-restaurant/products/search?q=pizza&limit=5' => Http::response([
            'data' => [catalogBackendProduct()],
        ]),
        'ordering-backend.test/api/restaurants/test-restaurant/products/502' => Http::response([
            'data' => catalogBackendProduct(),
        ]),
    ]);

    $client = app(OrderingBackendClient::class);

    $products = [[
        'id' => 502,
        'name' => 'Margherita',
        'price' => '220.00',
        'promotion_price' => '190.00',
        'currency' => 'UAH',
    ]];

    expect($client->categoryProducts('test-restaurant', 37))->toBe($products)
        ->and($client->searchProducts('test-restaurant', 'pizza', 5))->toBe($products)
        ->and($client->product('test-restaurant', 502))->toBe([
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
        && $request->url() === 'http://ordering-backend.test/api/restaurants/test-restaurant/products/search?q=pizza&limit=5');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'http://ordering-backend.test/api/restaurants/test-restaurant/products/502');
    Http::assertSentCount(3);
});

test('it rejects malformed catalog responses with a safe integration exception', function () {
    Http::fake([
        'ordering-backend.test/api/restaurants/test-restaurant/categories' => Http::response('not-json'),
    ]);

    expect(fn () => app(OrderingBackendClient::class)->categories('test-restaurant'))
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

test('it normalizes current order payment fields from the v2 order response', function () {
    $sessionToken = str_repeat('p', 64);

    Http::fake([
        'ordering-backend.test/api/orders/current' => Http::response([
            'data' => orderPayload(),
        ]),
    ]);

    expect(app(OrderingBackendClient::class)->currentOrder($sessionToken))->toBe(orderPayload());

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'http://ordering-backend.test/api/orders/current'
        && $request->hasHeader('X-Session-Token', $sessionToken));
    Http::assertSentCount(1);
});

test('it represents current payment ready and pending responses without treating pending as fatal', function () {
    $sessionToken = str_repeat('p', 64);

    Http::fake([
        'ordering-backend.test/api/orders/current/payment' => Http::sequence()
            ->push(['data' => paymentPayload('ready', 'https://checkout.example.test/pay/123')], 200)
            ->push(['data' => paymentPayload('pending', null)], 202),
    ]);

    $client = app(OrderingBackendClient::class);

    expect($client->currentPayment($sessionToken))->toBe(paymentPayload('ready', 'https://checkout.example.test/pay/123') + [
        'http_status' => 200,
    ])->and($client->currentPayment($sessionToken))->toBe(paymentPayload('pending', null) + [
        'http_status' => 202,
    ]);

    Http::assertSentCount(2);
});

test('it returns QR PNG bytes and represents pending QR JSON separately', function () {
    $sessionToken = str_repeat('q', 64);
    $png = "\x89PNG\r\n\x1a\nraw-bytes";

    Http::fake([
        'ordering-backend.test/api/orders/current/payment/qr' => Http::sequence()
            ->push($png, 200, ['Content-Type' => 'image/png'])
            ->push(['data' => paymentPayload('pending', null)], 202),
    ]);

    $client = app(OrderingBackendClient::class);

    expect($client->currentPaymentQr($sessionToken))->toBe([
        'status' => 'ready',
        'content_type' => 'image/png',
        'contents' => $png,
    ])->and($client->currentPaymentQr($sessionToken))->toBe([
        'status' => 'pending',
        'payment' => paymentPayload('pending', null) + ['http_status' => 202],
    ]);

    Http::assertSentCount(2);
});

test('QR normal backend errors still use existing exception behavior', function () {
    Http::fake([
        'ordering-backend.test/api/orders/current/payment/qr' => Http::response([
            'message' => 'Order was not found.',
        ], 404),
    ]);

    $exception = captureOrderingBackendException(
        fn () => app(OrderingBackendClient::class)->currentPaymentQr(str_repeat('q', 64)),
    );

    expect($exception->getMessage())->toBe('Unable to retrieve the current ordering backend payment QR.')
        ->and($exception->statusCode())->toBe(404)
        ->and($exception->responseMessage())->toBe('Order was not found.');
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

/** @return array{id: int, name: string, slug: string, currency: string, timezone: string, center: array{latitude: string, longitude: string}} */
function cityPayload(): array
{
    return [
        'id' => 7,
        'name' => 'Kyiv',
        'slug' => 'kyiv',
        'currency' => 'UAH',
        'timezone' => 'Europe/Kyiv',
        'center' => [
            'latitude' => '50.4501',
            'longitude' => '30.5234',
        ],
    ];
}

/** @return array{id: int, name: string, slug: string, currency: string, timezone: string, center: array{latitude: string, longitude: string}} */
function normalizedCity(): array
{
    return cityPayload();
}

/** @return array{id: int, name: string, slug: string, image_url: ?string, currency: string, locale: string, timezone: string, available_payment_types: list<int>, available_delivery_types: list<int>, delivery_time_text: string, delivery_price_text: string} */
function restaurantPayload(): array
{
    return [
        'id' => 10,
        'name' => 'Pizza House',
        'slug' => 'pizza-house',
        'image_url' => null,
        'currency' => 'UAH',
        'locale' => 'uk-UA',
        'timezone' => 'Europe/Kyiv',
        'available_payment_types' => [2],
        'available_delivery_types' => [1, 2],
        'delivery_time_text' => '45 хв',
        'delivery_price_text' => '99 UAH',
    ];
}

/** @return array{id: int, name: string, slug: string, image_url: ?string, currency: string, locale: string, timezone: string, available_payment_types: list<int>, available_delivery_types: list<int>, delivery_time_text: string, delivery_price_text: string} */
function normalizedRestaurant(): array
{
    return restaurantPayload();
}

/**
 * @param  array<string, mixed>  $fulfillment
 * @return array{data: array{session_id: string, fulfillment: array<string, mixed>}}
 */
function fulfillmentResponse(array $fulfillment): array
{
    return [
        'data' => [
            'session_id' => '01KSESSION',
            'fulfillment' => $fulfillment,
        ],
    ];
}

/** @return array{id: int, external_order_id: ?string, status: string, failure_message: ?string, receiving_type: string, payment_type: int, fulfillment: array<string, mixed>, total: string, currency: string, payment: array{status: string, checkout_url: ?string, payment_received_at: ?string, qr_ready: bool}, items: list<array{product_id: ?int, external_product_id: string, name: string, quantity: int, unit_price: string, total: string}>} */
function orderPayload(): array
{
    return [
        'id' => 44,
        'external_order_id' => 'dots-order-123',
        'status' => 'created',
        'failure_message' => null,
        'receiving_type' => 'delivery',
        'payment_type' => 2,
        'fulfillment' => ['type' => 'delivery', 'delivery_price' => '99.00'],
        'total' => '319.00',
        'currency' => 'UAH',
        'payment' => paymentPayload('ready', 'https://checkout.example.test/pay/123') + ['qr_ready' => true],
        'items' => [[
            'product_id' => 502,
            'external_product_id' => 'external-product-id',
            'name' => 'Margherita',
            'quantity' => 1,
            'unit_price' => '220.00',
            'total' => '220.00',
        ]],
    ];
}

/** @return array{status: string, checkout_url: ?string, payment_received_at: ?string} */
function paymentPayload(string $status, ?string $checkoutUrl): array
{
    return [
        'status' => $status,
        'checkout_url' => $checkoutUrl,
        'payment_received_at' => null,
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
