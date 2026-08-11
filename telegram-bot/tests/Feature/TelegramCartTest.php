<?php

use App\Exceptions\OrderingBackendException;
use App\Integrations\OrderingBackend\OrderingBackendClient;
use App\Telegram\Formatting\CartMessageFormatter;
use App\Telegram\Session\TelegramSessionStore;
use GuzzleHttp\Psr7\Request as TelegramRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;

beforeEach(function () {
    config()->set('cache.default', 'array');
    config()->set('services.ordering_backend.url', 'http://ordering-backend.test');
    config()->set('services.ordering_backend.token', 'internal-api-secret');
    config()->set('services.ordering_backend.timeout', 7);

    Http::preventStrayRequests();
});

test('the cart button resolves the session and accepts created or existing carts', function (int $status) {
    $sessionToken = str_repeat('7', 64);
    storeCartTelegramSession($sessionToken);

    Http::fake([
        'ordering-backend.test/api/carts' => Http::response(cartBackendResponse(), $status),
        'ordering-backend.test/api/carts/current' => Http::response(cartBackendResponse()),
    ]);

    cartTelegramBot()
        ->hearCallbackQueryData('menu:cart')
        ->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertCalled('editMessageText')
        ->assertReplyMessage([
            'text' => "🛒 Корзина\n\nКорзина пуста.\n\nПодытог: 0.00 UAH\nИтого: 0.00 UAH",
            'reply_markup' => cartKeyboard(),
        ], index: 1, forceMethod: 'editMessageText')
        ->assertRaw(function (TelegramRequest $request) use ($sessionToken): bool {
            expect((string) $request->getBody())
                ->not->toContain($sessionToken)
                ->not->toContain('internal-api-secret');

            return true;
        }, index: 1);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://ordering-backend.test/api/carts'
        && $request->hasHeader('X-Internal-Api-Token', 'internal-api-secret')
        && $request->hasHeader('X-Session-Token', $sessionToken)
        && $request->data() === []);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'http://ordering-backend.test/api/carts/current'
        && $request->hasHeader('X-Internal-Api-Token', 'internal-api-secret')
        && $request->hasHeader('X-Session-Token', $sessionToken));
    Http::assertSentCount(2);
})->with([
    'new cart' => [201],
    'existing cart' => [200],
]);

test('cart rendering and quantity controls use the flat backend cart item id', function () {
    storeCartTelegramSession(str_repeat('8', 64));

    $item = cartBackendItem([
        'id' => 37,
        'product_id' => 1,
        'quantity' => 3,
        'unit_price' => '7.11',
        'total' => '999.99',
    ]);

    Http::fake([
        'ordering-backend.test/api/carts' => Http::response(cartBackendResponse([
            'subtotal' => '1234.56',
            'total' => '1200.01',
        ])),
        'ordering-backend.test/api/carts/current' => Http::response(cartBackendResponse([
            'subtotal' => '1234.56',
            'total' => '1200.01',
            'items' => [$item],
        ])),
    ]);

    cartTelegramBot()
        ->hearCallbackQueryData('menu:cart')
        ->reply()
        ->assertReplyMessage([
            'text' => "🛒 Корзина\n\nMargherita\n3 × 7.11 UAH = 999.99 UAH\n\nПодытог: 1234.56 UAH\nИтого: 1200.01 UAH",
            'reply_markup' => cartKeyboard([$item]),
        ], index: 1, forceMethod: 'editMessageText')
        ->assertRaw(function (TelegramRequest $request): bool {
            $body = (string) $request->getBody();

            expect($body)
                ->toContain('cart:inc:37')
                ->toContain('cart:dec:37')
                ->toContain('cart:remove:37')
                ->toContain('cart:clear')
                ->not->toContain('cart:inc:1"')
                ->not->toContain('cart:dec:1"')
                ->not->toContain('cart:remove:1"');

            return true;
        }, index: 1);

    Http::assertSentCount(2);
});

test('a literal production cart response remains intact and drives repeated add through patch', function () {
    $sessionToken = str_repeat('5', 64);
    storeCartTelegramSession($sessionToken);

    $ensureCartResponse = [
        'data' => [
            'id' => 1,
            'status' => 'active',
            'currency' => 'UAH',
            'subtotal' => '100.00',
            'total' => '100.00',
            'expires_at' => '2026-08-12T00:00:00+00:00',
            'items' => [],
        ],
    ];
    $productionCartResponse = [
        'data' => [
            'id' => 1,
            'status' => 'active',
            'currency' => 'UAH',
            'subtotal' => '100.00',
            'total' => '100.00',
            'expires_at' => '2026-08-12T00:00:00+00:00',
            'items' => [
                [
                    'id' => 51,
                    'product_id' => 1,
                    'external_product_id' => '...',
                    'name' => 'Pizza Gavaiskaya',
                    'quantity' => 1,
                    'unit_price' => '100.00',
                    'total' => '100.00',
                ],
            ],
        ],
    ];
    $updatedCartResponse = [
        'data' => [
            'id' => 1,
            'status' => 'active',
            'currency' => 'UAH',
            'subtotal' => '200.00',
            'total' => '200.00',
            'expires_at' => '2026-08-12T00:00:00+00:00',
            'items' => [
                [
                    'id' => 51,
                    'product_id' => 1,
                    'external_product_id' => '...',
                    'name' => 'Pizza Gavaiskaya',
                    'quantity' => 2,
                    'unit_price' => '100.00',
                    'total' => '200.00',
                ],
            ],
        ],
    ];

    Http::fake([
        'ordering-backend.test/api/carts' => Http::sequence()
            ->push($ensureCartResponse, 200)
            ->push($ensureCartResponse, 200),
        'ordering-backend.test/api/carts/current' => Http::sequence()
            ->push($productionCartResponse, 200)
            ->push($productionCartResponse, 200),
        'ordering-backend.test/api/carts/current/items/51' => Http::response($updatedCartResponse, 200),
    ]);

    $normalizedCart = app(OrderingBackendClient::class)->getOrCreateCurrentCart($sessionToken);
    $formattedCart = app(CartMessageFormatter::class)->format($normalizedCart);

    expect($normalizedCart)->toBe([
        'id' => 1,
        'status' => 'active',
        'currency' => 'UAH',
        'subtotal' => '100.00',
        'total' => '100.00',
        'expires_at' => '2026-08-12T00:00:00+00:00',
        'items' => [
            [
                'id' => 51,
                'product_id' => 1,
                'external_product_id' => '...',
                'name' => 'Pizza Gavaiskaya',
                'quantity' => 1,
                'unit_price' => '100.00',
                'total' => '100.00',
            ],
        ],
    ])->and($normalizedCart['items'])->toHaveCount(1)
        ->and($normalizedCart['items'][0]['name'])->toBe('Pizza Gavaiskaya')
        ->and($formattedCart)->toContain('Pizza Gavaiskaya')
        ->and($formattedCart)->not->toContain('Корзина пуста');

    cartTelegramBot()
        ->hearCallbackQueryData('cart:add:1')
        ->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertReplyMessage([
            'text' => "🛒 Корзина\n\nPizza Gavaiskaya\n2 × 100.00 UAH = 200.00 UAH\n\nПодытог: 200.00 UAH\nИтого: 200.00 UAH",
            'reply_markup' => cartKeyboard($updatedCartResponse['data']['items']),
        ], index: 1, forceMethod: 'editMessageText');

    Http::assertSentInOrder([
        fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'http://ordering-backend.test/api/carts',
        fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'http://ordering-backend.test/api/carts/current',
        fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'http://ordering-backend.test/api/carts',
        fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'http://ordering-backend.test/api/carts/current',
        fn (Request $request): bool => $request->method() === 'PATCH'
            && $request->url() === 'http://ordering-backend.test/api/carts/current/items/51'
            && $request->data() === ['quantity' => 2],
    ]);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://ordering-backend.test/api/carts/current/items');
    Http::assertSentCount(5);
});

test('a successful current cart response without an explicit items array is malformed', function () {
    $sessionToken = str_repeat('6', 64);

    Http::fake([
        'ordering-backend.test/api/carts' => Http::response(cartBackendResponse(), 200),
        'ordering-backend.test/api/carts/current' => Http::response([
            'data' => [
                'id' => 1,
                'status' => 'active',
                'currency' => 'UAH',
                'subtotal' => '100.00',
                'total' => '100.00',
                'expires_at' => '2026-08-12T00:00:00+00:00',
            ],
        ], 200),
    ]);

    expect(fn () => app(OrderingBackendClient::class)->getOrCreateCurrentCart($sessionToken))
        ->toThrow(OrderingBackendException::class, 'Ordering backend returned malformed cart data.');

    Http::assertSentCount(2);
});

test('add to cart ensures the cart first and submits only the local product id and quantity one', function () {
    $sessionToken = str_repeat('9', 64);
    storeCartTelegramSession($sessionToken);

    $item = cartBackendItem();

    Http::fake([
        'ordering-backend.test/api/carts' => Http::response(cartBackendResponse()),
        'ordering-backend.test/api/carts/current' => Http::response(cartBackendResponse()),
        'ordering-backend.test/api/carts/current/items' => Http::response(cartBackendResponse([
            'subtotal' => '190.00',
            'total' => '190.00',
            'items' => [$item],
        ]), 201),
    ]);

    cartTelegramBot()
        ->hearCallbackQueryData('cart:add:502')
        ->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertReplyMessage([
            'text' => "🛒 Корзина\n\nMargherita\n1 × 190.00 UAH = 190.00 UAH\n\nПодытог: 190.00 UAH\nИтого: 190.00 UAH",
            'reply_markup' => cartKeyboard([$item]),
        ], index: 1, forceMethod: 'editMessageText');

    Http::assertSentInOrder([
        fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'http://ordering-backend.test/api/carts'
            && $request->hasHeader('X-Internal-Api-Token', 'internal-api-secret')
            && $request->hasHeader('X-Session-Token', $sessionToken)
            && $request->data() === [],
        fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'http://ordering-backend.test/api/carts/current'
            && $request->hasHeader('X-Internal-Api-Token', 'internal-api-secret')
            && $request->hasHeader('X-Session-Token', $sessionToken),
        fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'http://ordering-backend.test/api/carts/current/items'
            && $request->hasHeader('X-Internal-Api-Token', 'internal-api-secret')
            && $request->hasHeader('X-Session-Token', $sessionToken)
            && $request->data() === [
                'product_id' => 502,
                'quantity' => 1,
            ],
    ]);
});

test('adding an existing product patches its backend cart item with the next quantity', function () {
    $sessionToken = str_repeat('f', 64);
    storeCartTelegramSession($sessionToken);

    $currentItem = cartBackendItem([
        'id' => 37,
        'product_id' => 1,
        'quantity' => 2,
        'unit_price' => '190.00',
        'total' => '380.00',
    ]);
    $updatedItem = cartBackendItem([
        'id' => 37,
        'product_id' => 1,
        'quantity' => 3,
        'unit_price' => '190.00',
        'total' => '777.77',
    ]);
    $cartItemWhoseIdMatchesTheProductId = cartBackendItem([
        'id' => 1,
        'product_id' => 99,
        'name' => 'Different product',
        'quantity' => 8,
        'total' => '1520.00',
    ]);

    Http::fake([
        'ordering-backend.test/api/carts' => Http::response(cartBackendResponse([
            'subtotal' => '380.00',
            'total' => '380.00',
        ])),
        'ordering-backend.test/api/carts/current' => Http::response(cartBackendResponse([
            'subtotal' => '380.00',
            'total' => '380.00',
            'items' => [$cartItemWhoseIdMatchesTheProductId, $currentItem],
        ])),
        'ordering-backend.test/api/carts/current/items/37' => Http::response(cartBackendResponse([
            'subtotal' => '888.88',
            'total' => '777.77',
            'items' => [$updatedItem],
        ])),
    ]);

    cartTelegramBot()
        ->hearCallbackQueryData('cart:add:1')
        ->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertReplyMessage([
            'text' => "🛒 Корзина\n\nMargherita\n3 × 190.00 UAH = 777.77 UAH\n\nПодытог: 888.88 UAH\nИтого: 777.77 UAH",
            'reply_markup' => cartKeyboard([$updatedItem]),
        ], index: 1, forceMethod: 'editMessageText');

    Http::assertSentInOrder([
        fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'http://ordering-backend.test/api/carts'
            && $request->data() === [],
        fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'http://ordering-backend.test/api/carts/current',
        fn (Request $request): bool => $request->method() === 'PATCH'
            && $request->url() === 'http://ordering-backend.test/api/carts/current/items/37'
            && $request->hasHeader('X-Internal-Api-Token', 'internal-api-secret')
            && $request->hasHeader('X-Session-Token', $sessionToken)
            && $request->data() === ['quantity' => 3],
    ]);
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'http://ordering-backend.test/api/carts/current/items');
    Http::assertSentCount(3);
});

test('the plus callback increments from the fresh backend quantity', function () {
    $sessionToken = str_repeat('1', 64);
    storeCartTelegramSession($sessionToken);

    $currentItem = cartBackendItem(['id' => 37, 'product_id' => 1, 'quantity' => 2, 'total' => '380.00']);
    $updatedItem = cartBackendItem(['id' => 37, 'product_id' => 1, 'quantity' => 3, 'total' => '570.00']);
    $productIdMatch = cartBackendItem([
        'id' => 1,
        'product_id' => 37,
        'name' => 'Different product',
        'quantity' => 9,
        'total' => '1710.00',
    ]);

    Http::fake([
        'ordering-backend.test/api/carts' => Http::response(cartBackendResponse([
            'subtotal' => '380.00',
            'total' => '380.00',
        ])),
        'ordering-backend.test/api/carts/current' => Http::response(cartBackendResponse([
            'subtotal' => '380.00',
            'total' => '380.00',
            'items' => [$productIdMatch, $currentItem],
        ])),
        'ordering-backend.test/api/carts/current/items/37' => Http::response(cartBackendResponse([
            'subtotal' => '570.00',
            'total' => '570.00',
            'items' => [$updatedItem],
        ])),
    ]);

    cartTelegramBot()
        ->hearCallbackQueryData('cart:inc:37')
        ->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertReplyMessage([
            'text' => "🛒 Корзина\n\nMargherita\n3 × 190.00 UAH = 570.00 UAH\n\nПодытог: 570.00 UAH\nИтого: 570.00 UAH",
            'reply_markup' => cartKeyboard([$updatedItem]),
        ], index: 1, forceMethod: 'editMessageText');

    Http::assertSentInOrder([
        fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'http://ordering-backend.test/api/carts/current',
        fn (Request $request): bool => $request->method() === 'PATCH'
            && $request->url() === 'http://ordering-backend.test/api/carts/current/items/37'
            && $request->data() === ['quantity' => 3],
    ]);
    Http::assertSentCount(2);
});

test('the minus callback decrements from the fresh backend quantity when it is above one', function () {
    $sessionToken = str_repeat('2', 64);
    storeCartTelegramSession($sessionToken);

    $currentItem = cartBackendItem(['id' => 37, 'product_id' => 1, 'quantity' => 2, 'total' => '380.00']);
    $updatedItem = cartBackendItem(['id' => 37, 'product_id' => 1, 'quantity' => 1, 'total' => '190.00']);
    $productIdMatch = cartBackendItem([
        'id' => 1,
        'product_id' => 37,
        'name' => 'Different product',
        'quantity' => 9,
        'total' => '1710.00',
    ]);

    Http::fake([
        'ordering-backend.test/api/carts' => Http::response(cartBackendResponse([
            'subtotal' => '380.00',
            'total' => '380.00',
        ])),
        'ordering-backend.test/api/carts/current' => Http::response(cartBackendResponse([
            'subtotal' => '380.00',
            'total' => '380.00',
            'items' => [$productIdMatch, $currentItem],
        ])),
        'ordering-backend.test/api/carts/current/items/37' => Http::response(cartBackendResponse([
            'subtotal' => '190.00',
            'total' => '190.00',
            'items' => [$updatedItem],
        ])),
    ]);

    cartTelegramBot()
        ->hearCallbackQueryData('cart:dec:37')
        ->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertReplyMessage([
            'text' => "🛒 Корзина\n\nMargherita\n1 × 190.00 UAH = 190.00 UAH\n\nПодытог: 190.00 UAH\nИтого: 190.00 UAH",
            'reply_markup' => cartKeyboard([$updatedItem]),
        ], index: 1, forceMethod: 'editMessageText');

    Http::assertSentInOrder([
        fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'http://ordering-backend.test/api/carts/current',
        fn (Request $request): bool => $request->method() === 'PATCH'
            && $request->url() === 'http://ordering-backend.test/api/carts/current/items/37'
            && $request->data() === ['quantity' => 1],
    ]);
    Http::assertSentCount(2);
});

test('the minus callback removes quantity one and fetches the authoritative remaining cart', function () {
    $sessionToken = str_repeat('3', 64);
    storeCartTelegramSession($sessionToken);

    $gavaiskaya = cartBackendItem([
        'id' => 51,
        'product_id' => 1,
        'name' => 'Pizza Gavaiskaya',
        'quantity' => 2,
        'unit_price' => '100.00',
        'total' => '200.00',
    ]);
    $pepperoni = cartBackendItem([
        'id' => 68,
        'product_id' => 7,
        'name' => 'Pizza Pepperoni',
        'quantity' => 1,
        'unit_price' => '150.00',
        'total' => '150.00',
    ]);

    Http::fake([
        'ordering-backend.test/api/carts/current' => Http::sequence()
            ->push(cartBackendResponse([
                'subtotal' => '350.00',
                'total' => '350.00',
                'items' => [$gavaiskaya, $pepperoni],
            ]))
            ->push(cartBackendResponse([
                'subtotal' => '200.00',
                'total' => '200.00',
                'items' => [$gavaiskaya],
            ])),
        'ordering-backend.test/api/carts/current/items/68' => Http::response(cartBackendResponse([
            'subtotal' => '200.00',
            'total' => '200.00',
            'items' => [$gavaiskaya],
        ])),
    ]);

    cartTelegramBot()
        ->hearCallbackQueryData('cart:dec:68')
        ->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertReplyMessage([
            'text' => "🛒 Корзина\n\nPizza Gavaiskaya\n2 × 100.00 UAH = 200.00 UAH\n\nПодытог: 200.00 UAH\nИтого: 200.00 UAH",
            'reply_markup' => cartKeyboard([$gavaiskaya]),
        ], index: 1, forceMethod: 'editMessageText');

    Http::assertSentInOrder([
        fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'http://ordering-backend.test/api/carts/current',
        fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'http://ordering-backend.test/api/carts/current/items/68'
            && $request->hasHeader('X-Session-Token', $sessionToken)
            && $request->data() === [],
        fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'http://ordering-backend.test/api/carts/current',
    ]);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PATCH');
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'http://ordering-backend.test/api/carts/current/items/51');
    Http::assertSentCount(3);
});

test('explicit remove uses cart item 51 and leaves cart item 68 intact', function () {
    $sessionToken = str_repeat('6', 64);
    storeCartTelegramSession($sessionToken);

    $currentCartResponse = [
        'data' => [
            'id' => 1,
            'status' => 'active',
            'currency' => 'UAH',
            'subtotal' => '350.00',
            'total' => '350.00',
            'expires_at' => '2026-08-12T00:00:00+00:00',
            'items' => [
                [
                    'id' => 51,
                    'product_id' => 1,
                    'external_product_id' => 'gavaiskaya-external',
                    'name' => 'Pizza Gavaiskaya',
                    'quantity' => 2,
                    'unit_price' => '100.00',
                    'total' => '200.00',
                ],
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
    $updatedCartResponse = [
        'data' => [
            'id' => 1,
            'status' => 'active',
            'currency' => 'UAH',
            'subtotal' => '150.00',
            'total' => '150.00',
            'expires_at' => '2026-08-12T00:00:00+00:00',
            'items' => [$currentCartResponse['data']['items'][1]],
        ],
    ];

    Http::fake([
        'ordering-backend.test/api/carts/current' => Http::sequence()
            ->push($currentCartResponse)
            ->push($updatedCartResponse),
        'ordering-backend.test/api/carts/current/items/51' => Http::response($currentCartResponse),
    ]);

    cartTelegramBot()
        ->hearCallbackQueryData('cart:remove:51')
        ->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertReplyMessage([
            'text' => "🛒 Корзина\n\nPizza Pepperoni\n1 × 150.00 UAH = 150.00 UAH\n\nПодытог: 150.00 UAH\nИтого: 150.00 UAH",
            'reply_markup' => cartKeyboard($updatedCartResponse['data']['items']),
        ], index: 1, forceMethod: 'editMessageText')
        ->assertRaw(function (TelegramRequest $request): bool {
            $body = (string) $request->getBody();

            expect($body)
                ->toContain('Pizza Pepperoni')
                ->toContain('cart:remove:68')
                ->not->toContain('Pizza Gavaiskaya')
                ->not->toContain('cart:remove:51');

            return true;
        }, index: 1);

    Http::assertSentInOrder([
        fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'http://ordering-backend.test/api/carts/current',
        fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'http://ordering-backend.test/api/carts/current/items/51'
            && $request->hasHeader('X-Internal-Api-Token', 'internal-api-secret')
            && $request->hasHeader('X-Session-Token', $sessionToken)
            && $request->data() === [],
        fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'http://ordering-backend.test/api/carts/current',
    ]);
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'http://ordering-backend.test/api/carts/current/items/68');
    Http::assertSentCount(3);
});

test('an already missing item renders the fresh cart with a notice and no mutation', function () {
    storeCartTelegramSession(str_repeat('7', 64));

    $remainingItem = cartBackendItem([
        'id' => 68,
        'product_id' => 7,
        'name' => 'Pizza Pepperoni',
    ]);

    Http::fake([
        'ordering-backend.test/api/carts/current' => Http::response(cartBackendResponse([
            'subtotal' => '190.00',
            'total' => '190.00',
            'items' => [$remainingItem],
        ])),
    ]);

    cartTelegramBot()
        ->hearCallbackQueryData('cart:remove:51')
        ->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertReplyMessage([
            'text' => "Товар уже отсутствует в корзине.\n\n🛒 Корзина\n\nPizza Pepperoni\n1 × 190.00 UAH = 190.00 UAH\n\nПодытог: 190.00 UAH\nИтого: 190.00 UAH",
            'reply_markup' => cartKeyboard([$remainingItem]),
        ], index: 1, forceMethod: 'editMessageText');

    Http::assertNotSent(fn (Request $request): bool => in_array($request->method(), ['DELETE', 'PATCH', 'POST'], true));
    Http::assertSentCount(1);
});

test('an item disappearing during delete is refreshed without retrying the mutation', function () {
    storeCartTelegramSession(str_repeat('a', 64));

    $removedItem = cartBackendItem(['id' => 51, 'product_id' => 1, 'name' => 'Pizza Gavaiskaya']);
    $remainingItem = cartBackendItem(['id' => 68, 'product_id' => 7, 'name' => 'Pizza Pepperoni']);

    Http::fake([
        'ordering-backend.test/api/carts/current' => Http::sequence()
            ->push(cartBackendResponse([
                'subtotal' => '380.00',
                'total' => '380.00',
                'items' => [$removedItem, $remainingItem],
            ]))
            ->push(cartBackendResponse([
                'subtotal' => '190.00',
                'total' => '190.00',
                'items' => [$remainingItem],
            ])),
        'ordering-backend.test/api/carts/current/items/51' => Http::response([
            'message' => 'Cart item not found.',
        ], 404),
    ]);

    cartTelegramBot()
        ->hearCallbackQueryData('cart:remove:51')
        ->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertReplyMessage([
            'text' => "Товар уже отсутствует в корзине.\n\n🛒 Корзина\n\nPizza Pepperoni\n1 × 190.00 UAH = 190.00 UAH\n\nПодытог: 190.00 UAH\nИтого: 190.00 UAH",
            'reply_markup' => cartKeyboard([$remainingItem]),
        ], index: 1, forceMethod: 'editMessageText');

    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'DELETE'))
        ->toHaveCount(1);
    Http::assertSentInOrder([
        fn (Request $request): bool => $request->method() === 'GET',
        fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'http://ordering-backend.test/api/carts/current/items/51',
        fn (Request $request): bool => $request->method() === 'GET',
    ]);
    Http::assertSentCount(3);
});

test('clear cart first shows confirmation without backend work or state changes', function () {
    $sessionToken = str_repeat('8', 64);
    storeCartTelegramSession($sessionToken);

    cartTelegramBot()
        ->hearCallbackQueryData('cart:clear')
        ->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertReplyMessage([
            'text' => 'Очистить всю корзину?',
            'reply_markup' => clearCartConfirmationKeyboard(),
        ], index: 1, forceMethod: 'editMessageText');

    expect(app(TelegramSessionStore::class)->get('telegram-chat-123456'))->toBe($sessionToken);
    Http::assertNothingSent();
});

test('clear confirmation deletes all items and renders an explicitly empty backend cart', function () {
    $sessionToken = str_repeat('9', 64);
    storeCartTelegramSession($sessionToken);

    $emptyCartResponse = [
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
        'ordering-backend.test/api/carts/current/items' => Http::response(cartBackendResponse([
            'subtotal' => '0.00',
            'total' => '0.00',
            'items' => [],
        ])),
        'ordering-backend.test/api/carts/current' => Http::response($emptyCartResponse),
    ]);

    cartTelegramBot()
        ->hearCallbackQueryData('cart:clear:confirm')
        ->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertReplyMessage([
            'text' => "🛒 Корзина\n\nКорзина пуста.\n\nПодытог: 0.00 UAH\nИтого: 0.00 UAH",
            'reply_markup' => cartKeyboard(),
        ], index: 1, forceMethod: 'editMessageText')
        ->assertRaw(function (TelegramRequest $request): bool {
            $body = (string) $request->getBody();

            expect($body)
                ->toContain('🍕 Перейти в каталог')
                ->not->toContain('cart:inc:')
                ->not->toContain('cart:dec:')
                ->not->toContain('cart:remove:')
                ->not->toContain('cart:clear');

            return true;
        }, index: 1);

    Http::assertSentInOrder([
        fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'http://ordering-backend.test/api/carts/current/items'
            && $request->hasHeader('X-Internal-Api-Token', 'internal-api-secret')
            && $request->hasHeader('X-Session-Token', $sessionToken)
            && $request->data() === [],
        fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'http://ordering-backend.test/api/carts/current',
    ]);
    Http::assertSentCount(2);
});

test('clear cancellation fetches and renders the unchanged current cart without mutation', function () {
    $sessionToken = str_repeat('0', 64);
    storeCartTelegramSession($sessionToken);

    $items = [
        cartBackendItem(['id' => 51, 'product_id' => 1, 'name' => 'Pizza Gavaiskaya']),
        cartBackendItem(['id' => 68, 'product_id' => 7, 'name' => 'Pizza Pepperoni']),
    ];

    Http::fake([
        'ordering-backend.test/api/carts/current' => Http::response(cartBackendResponse([
            'subtotal' => '380.00',
            'total' => '380.00',
            'items' => $items,
        ])),
    ]);

    cartTelegramBot()
        ->hearCallbackQueryData('cart:clear:cancel')
        ->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertReplyMessage([
            'text' => "🛒 Корзина\n\nPizza Gavaiskaya\n1 × 190.00 UAH = 190.00 UAH\n\nPizza Pepperoni\n1 × 190.00 UAH = 190.00 UAH\n\nПодытог: 380.00 UAH\nИтого: 380.00 UAH",
            'reply_markup' => cartKeyboard($items),
        ], index: 1, forceMethod: 'editMessageText');

    expect(app(TelegramSessionStore::class)->get('telegram-chat-123456'))->toBe($sessionToken);
    Http::assertNotSent(fn (Request $request): bool => in_array($request->method(), ['DELETE', 'PATCH', 'POST'], true));
    Http::assertSentCount(1);
});

test('remove failures render safe messages without leaking backend details', function (string $failure, string $expectedMessage) {
    $sessionToken = str_repeat('b', 64);
    storeCartTelegramSession($sessionToken);

    $deleteResponse = match ($failure) {
        'connection' => Http::failedConnection(),
        default => Http::response(['message' => 'raw-remove-backend-error'], (int) $failure),
    };

    Http::fake([
        'ordering-backend.test/api/carts/current' => Http::response(cartBackendResponse([
            'subtotal' => '190.00',
            'total' => '190.00',
            'items' => [cartBackendItem(['id' => 51])],
        ])),
        'ordering-backend.test/api/carts/current/items/51' => $deleteResponse,
    ]);

    cartTelegramBot()
        ->hearCallbackQueryData('cart:remove:51')
        ->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertReplyMessage([
            'text' => $expectedMessage,
            'reply_markup' => cartKeyboard(),
        ], index: 1, forceMethod: 'editMessageText')
        ->assertRaw(function (TelegramRequest $request) use ($sessionToken): bool {
            expect((string) $request->getBody())
                ->not->toContain('raw-remove-backend-error')
                ->not->toContain($sessionToken)
                ->not->toContain('internal-api-secret');

            return true;
        }, index: 1);

    Http::assertSentCount(2);
})->with([
    'conflict' => ['409', 'Не удалось удалить товар из корзины.'],
    'unprocessable' => ['422', 'Не удалось удалить товар из корзины.'],
    'unavailable' => ['connection', 'Сервис корзины временно недоступен. Попробуйте снова позже.'],
]);

test('a malformed authoritative cart after delete renders a safe message', function () {
    storeCartTelegramSession(str_repeat('c', 64));

    Http::fake([
        'ordering-backend.test/api/carts/current' => Http::sequence()
            ->push(cartBackendResponse([
                'subtotal' => '190.00',
                'total' => '190.00',
                'items' => [cartBackendItem(['id' => 51])],
            ]))
            ->push(['data' => ['items' => 'invalid']]),
        'ordering-backend.test/api/carts/current/items/51' => Http::response(cartBackendResponse()),
    ]);

    cartTelegramBot()
        ->hearCallbackQueryData('cart:remove:51')
        ->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertReplyMessage([
            'text' => 'Сервис корзины временно недоступен. Попробуйте снова позже.',
            'reply_markup' => cartKeyboard(),
        ], index: 1, forceMethod: 'editMessageText');

    Http::assertSentCount(3);
});

test('quantity callbacks always derive their mutation from a newly fetched cart', function () {
    storeCartTelegramSession(str_repeat('4', 64));

    Http::fake([
        'ordering-backend.test/api/carts' => Http::sequence()
            ->push(cartBackendResponse())
            ->push(cartBackendResponse()),
        'ordering-backend.test/api/carts/current' => Http::sequence()
            ->push(cartBackendResponse([
                'items' => [
                    cartBackendItem(['id' => 44, 'product_id' => 912]),
                    cartBackendItem(['id' => 912, 'product_id' => 44, 'quantity' => 1]),
                ],
            ]))
            ->push(cartBackendResponse([
                'items' => [
                    cartBackendItem(['id' => 44, 'product_id' => 912]),
                    cartBackendItem(['id' => 912, 'product_id' => 44, 'quantity' => 7]),
                ],
            ])),
        'ordering-backend.test/api/carts/current/items/912' => Http::sequence()
            ->push(cartBackendResponse([
                'items' => [cartBackendItem(['id' => 912, 'product_id' => 44, 'quantity' => 2])],
            ]))
            ->push(cartBackendResponse([
                'items' => [cartBackendItem(['id' => 912, 'product_id' => 44, 'quantity' => 8])],
            ])),
    ]);

    cartTelegramBot()->hearCallbackQueryData('cart:inc:912')->reply();
    cartTelegramBot()->hearCallbackQueryData('cart:inc:912')->reply();

    $quantities = Http::recorded()
        ->filter(fn (array $requestAndResponse): bool => $requestAndResponse[0]->method() === 'PATCH')
        ->map(fn (array $requestAndResponse): mixed => $requestAndResponse[0]->data()['quantity'])
        ->values()
        ->all();

    expect($quantities)->toBe([2, 8]);
    Http::assertSentCount(4);
});

test('the quantity display callback is acknowledged without mutating the cart', function () {
    cartTelegramBot()
        ->hearCallbackQueryData('cart:noop:850')
        ->reply()
        ->assertCalled('answerCallbackQuery');

    Http::assertNothingSent();
});

test('a duplicate product conflict renders a safe open cart action', function () {
    $sessionToken = str_repeat('a', 64);
    storeCartTelegramSession($sessionToken);

    Http::fake([
        'ordering-backend.test/api/carts' => Http::response(cartBackendResponse()),
        'ordering-backend.test/api/carts/current' => Http::response(cartBackendResponse()),
        'ordering-backend.test/api/carts/current/items' => Http::response([
            'message' => 'Product already exists in cart.',
        ], 409),
    ]);

    cartTelegramBot()
        ->hearCallbackQueryData('cart:add:502')
        ->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertReplyMessage([
            'text' => 'Этот товар уже есть в корзине.',
            'reply_markup' => duplicateCartKeyboard(),
        ], index: 1, forceMethod: 'editMessageText')
        ->assertRaw(function (TelegramRequest $request) use ($sessionToken): bool {
            expect((string) $request->getBody())
                ->not->toContain('Product already exists in cart.')
                ->not->toContain($sessionToken)
                ->not->toContain('internal-api-secret');

            return true;
        }, index: 1);

    Http::assertSentCount(3);
});

test('a missing cart session creates a fresh session and returns to contact onboarding', function () {
    $freshSessionToken = str_repeat('b', 64);

    Http::fake([
        'ordering-backend.test/api/sessions' => Http::response([
            'data' => [
                'session_token' => $freshSessionToken,
            ],
        ], 201),
    ]);

    cartTelegramBot()
        ->hearCallbackQueryData('menu:cart')
        ->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertReplyMessage([
            'text' => 'Сессия истекла. Пожалуйста, снова поделитесь контактом.',
            'reply_markup' => cartContactRequestKeyboard(),
        ], index: 1, forceMethod: 'sendMessage');

    expect(app(TelegramSessionStore::class)->get('telegram-chat-123456'))
        ->toBe($freshSessionToken);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://ordering-backend.test/api/sessions');
    Http::assertSentCount(1);
});

test('an update item 401 recovers the session without retrying the cart mutation', function () {
    $staleSessionToken = str_repeat('c', 64);
    $freshSessionToken = str_repeat('d', 64);
    storeCartTelegramSession($staleSessionToken);

    Http::fake([
        'ordering-backend.test/api/carts' => Http::response(cartBackendResponse([
            'subtotal' => '380.00',
            'total' => '380.00',
        ])),
        'ordering-backend.test/api/carts/current' => Http::response(cartBackendResponse([
            'subtotal' => '380.00',
            'total' => '380.00',
            'items' => [cartBackendItem(['id' => 849, 'quantity' => 2, 'total' => '380.00'])],
        ])),
        'ordering-backend.test/api/carts/current/items/849' => Http::response([
            'message' => 'Unauthenticated.',
        ], 401),
        'ordering-backend.test/api/sessions' => Http::response([
            'data' => [
                'session_token' => $freshSessionToken,
            ],
        ], 201),
    ]);

    cartTelegramBot()
        ->hearCallbackQueryData('cart:add:502')
        ->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertReplyMessage([
            'text' => 'Сессия истекла. Пожалуйста, снова поделитесь контактом.',
            'reply_markup' => cartContactRequestKeyboard(),
        ], index: 1, forceMethod: 'sendMessage');

    expect(app(TelegramSessionStore::class)->get('telegram-chat-123456'))
        ->toBe($freshSessionToken)
        ->not->toBe($staleSessionToken);

    Http::assertSentInOrder([
        fn (Request $request): bool => $request->url() === 'http://ordering-backend.test/api/carts'
            && $request->hasHeader('X-Session-Token', $staleSessionToken),
        fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'http://ordering-backend.test/api/carts/current'
            && $request->hasHeader('X-Session-Token', $staleSessionToken),
        fn (Request $request): bool => $request->method() === 'PATCH'
            && $request->url() === 'http://ordering-backend.test/api/carts/current/items/849'
            && $request->hasHeader('X-Session-Token', $staleSessionToken)
            && $request->data() === ['quantity' => 3],
        fn (Request $request): bool => $request->url() === 'http://ordering-backend.test/api/sessions'
            && ! $request->hasHeader('X-Session-Token'),
    ]);
    Http::assertSentCount(4);
});

test('a remove item 401 recovers the session without retrying delete', function () {
    $staleSessionToken = str_repeat('1', 64);
    $freshSessionToken = str_repeat('2', 64);
    storeCartTelegramSession($staleSessionToken);

    $item = cartBackendItem(['id' => 51, 'product_id' => 1, 'name' => 'Pizza Gavaiskaya']);

    Http::fake([
        'ordering-backend.test/api/carts/current' => Http::response(cartBackendResponse([
            'subtotal' => '190.00',
            'total' => '190.00',
            'items' => [$item],
        ])),
        'ordering-backend.test/api/carts/current/items/51' => Http::response([
            'message' => 'Unauthenticated.',
        ], 401),
        'ordering-backend.test/api/sessions' => Http::response([
            'data' => [
                'session_token' => $freshSessionToken,
            ],
        ], 201),
    ]);

    cartTelegramBot()
        ->hearCallbackQueryData('cart:remove:51')
        ->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertReplyMessage([
            'text' => 'Сессия истекла. Пожалуйста, снова поделитесь контактом.',
            'reply_markup' => cartContactRequestKeyboard(),
        ], index: 1, forceMethod: 'sendMessage');

    expect(app(TelegramSessionStore::class)->get('telegram-chat-123456'))
        ->toBe($freshSessionToken)
        ->not->toBe($staleSessionToken);

    Http::assertSentInOrder([
        fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'http://ordering-backend.test/api/carts/current'
            && $request->hasHeader('X-Session-Token', $staleSessionToken),
        fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'http://ordering-backend.test/api/carts/current/items/51'
            && $request->hasHeader('X-Session-Token', $staleSessionToken),
        fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'http://ordering-backend.test/api/sessions'
            && ! $request->hasHeader('X-Session-Token'),
    ]);

    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'http://ordering-backend.test/api/carts/current/items/51'))
        ->toHaveCount(1);
    Http::assertSentCount(3);
});

test('cart backend failures render safe messages', function (string $failure, string $expectedMessage) {
    $sessionToken = str_repeat('e', 64);
    storeCartTelegramSession($sessionToken);

    $itemResponse = match ($failure) {
        'connection' => Http::failedConnection(),
        'malformed' => Http::response(['data' => ['items' => 'invalid']], 201),
        default => Http::response([
            'message' => 'raw-cart-backend-error',
        ], (int) $failure),
    };

    Http::fake([
        'ordering-backend.test/api/carts' => Http::response(cartBackendResponse()),
        'ordering-backend.test/api/carts/current' => Http::response(cartBackendResponse()),
        'ordering-backend.test/api/carts/current/items' => $itemResponse,
    ]);

    cartTelegramBot()
        ->hearCallbackQueryData('cart:add:502')
        ->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertReplyMessage([
            'text' => $expectedMessage,
            'reply_markup' => cartKeyboard(),
        ], index: 1, forceMethod: 'editMessageText')
        ->assertRaw(function (TelegramRequest $request) use ($sessionToken): bool {
            expect((string) $request->getBody())
                ->not->toContain('raw-cart-backend-error')
                ->not->toContain($sessionToken)
                ->not->toContain('internal-api-secret');

            return true;
        }, index: 1);

    expect(app(TelegramSessionStore::class)->get('telegram-chat-123456'))->toBe($sessionToken);
    Http::assertSentCount(3);
})->with([
    'not found' => ['404', 'Товар не найден.'],
    'unprocessable' => ['422', 'Не удалось добавить товар в корзину.'],
    'unavailable' => ['connection', 'Сервис корзины временно недоступен. Попробуйте снова позже.'],
    'malformed response' => ['malformed', 'Сервис корзины временно недоступен. Попробуйте снова позже.'],
]);

function cartTelegramBot(): FakeNutgram
{
    /** @var FakeNutgram $bot */
    $bot = app(Nutgram::class);

    return $bot
        ->setCommonChat(Chat::make(id: 123456, type: ChatType::PRIVATE))
        ->setCommonUser(User::make(id: 654321, is_bot: false, first_name: 'Test'));
}

function storeCartTelegramSession(string $sessionToken): void
{
    app(TelegramSessionStore::class)->put('telegram-chat-123456', $sessionToken);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array{data: array<string, mixed>}
 */
function cartBackendResponse(array $overrides = []): array
{
    return [
        'data' => array_replace([
            'id' => 91,
            'status' => 'active',
            'currency' => 'UAH',
            'subtotal' => '0.00',
            'total' => '0.00',
            'expires_at' => '2026-08-12T12:00:00+00:00',
            'items' => [],
        ], $overrides),
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function cartBackendItem(array $overrides = []): array
{
    return array_replace([
        'id' => 701,
        'product_id' => 502,
        'external_product_id' => 'external-product-id',
        'name' => 'Margherita',
        'quantity' => 1,
        'unit_price' => '190.00',
        'total' => '190.00',
    ], $overrides);
}

/**
 * @param  list<array<string, mixed>>  $items
 * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
 */
function cartKeyboard(array $items = []): array
{
    $rows = [];

    foreach ($items as $item) {
        $rows[] = [[
            'text' => $item['name'],
            'callback_data' => "cart:noop:{$item['id']}",
        ]];
        $rows[] = [
            [
                'text' => '➖',
                'callback_data' => "cart:dec:{$item['id']}",
            ],
            [
                'text' => (string) $item['quantity'],
                'callback_data' => "cart:noop:{$item['id']}",
            ],
            [
                'text' => '➕',
                'callback_data' => "cart:inc:{$item['id']}",
            ],
        ];
        $rows[] = [[
            'text' => '🗑 Удалить',
            'callback_data' => "cart:remove:{$item['id']}",
        ]];
    }

    if ($items !== []) {
        $rows[] = [[
            'text' => '✅ Оформить заказ',
            'callback_data' => 'checkout',
        ]];
        $rows[] = [[
            'text' => '🧹 Очистить корзину',
            'callback_data' => 'cart:clear',
        ]];
    }

    $rows[] = [[
        'text' => $items === [] ? '🍕 Перейти в каталог' : '🍕 Продолжить покупки',
        'callback_data' => 'catalog',
    ]];
    $rows[] = [[
        'text' => '⬅️ Главное меню',
        'callback_data' => 'main_menu',
    ]];

    return [
        'inline_keyboard' => $rows,
    ];
}

/** @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>} */
function clearCartConfirmationKeyboard(): array
{
    return [
        'inline_keyboard' => [
            [[
                'text' => '✅ Да, очистить',
                'callback_data' => 'cart:clear:confirm',
            ]],
            [[
                'text' => '❌ Отмена',
                'callback_data' => 'cart:clear:cancel',
            ]],
        ],
    ];
}

/** @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>} */
function duplicateCartKeyboard(): array
{
    return [
        'inline_keyboard' => [
            [[
                'text' => '🛒 Открыть корзину',
                'callback_data' => 'menu:cart',
            ]],
            [[
                'text' => '🍕 Продолжить покупки',
                'callback_data' => 'catalog',
            ]],
            [[
                'text' => '⬅️ Главное меню',
                'callback_data' => 'main_menu',
            ]],
        ],
    ];
}

/** @return array{keyboard: list<list<array{text: string, request_contact: bool}>>, resize_keyboard: bool, one_time_keyboard: bool} */
function cartContactRequestKeyboard(): array
{
    return [
        'keyboard' => [
            [[
                'text' => '📱 Поделиться контактом',
                'request_contact' => true,
            ]],
        ],
        'resize_keyboard' => true,
        'one_time_keyboard' => true,
    ];
}
