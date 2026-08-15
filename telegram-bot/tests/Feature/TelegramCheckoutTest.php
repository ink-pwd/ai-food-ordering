<?php

use App\Telegram\Handlers\CheckoutHandler;
use App\Telegram\Keyboards\CartKeyboard;
use App\Telegram\Session\TelegramSessionStore;
use App\Telegram\Support\RestaurantNavigationContext;
use GuzzleHttp\Psr7\Request as TelegramRequest;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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

test('checkout action is rendered only for non-empty active carts and keeps context', function (array $items, string $status, bool $expected) {
    $context = checkoutContext(str_repeat('k', 64));
    $keyboard = json_encode(app(CartKeyboard::class)->make($items, $status, $context), JSON_THROW_ON_ERROR);

    expect(Str::contains($keyboard, "checkout:{$context}"))->toBe($expected);
})->with([
    'active cart with items' => [[checkoutCartItem()], 'active', true],
    'empty active cart' => [[], 'active', false],
    'non-active cart with items' => [[checkoutCartItem()], 'checked_out', false],
]);

test('checkout confirmation uses backend totals and context-aware cancel callback', function () {
    $sessionToken = str_repeat('1', 64);
    storeCheckoutSession($sessionToken);
    $context = checkoutContext($sessionToken);

    Http::fake(checkoutContextFakes([
        'ordering-backend.test/api/carts/current' => Http::response(checkoutCartResponse([
            'subtotal' => '400.00',
            'total' => '350.00',
            'items' => [checkoutCartItem()],
        ])),
    ]));

    checkoutTelegramBot()
        ->hearCallbackQueryData("checkout:{$context}")
        ->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertReplyMessage([
            'text' => "Оформлення замовлення\n\nСамовивіз\nОплата готівкою\nЧас: якнайшвидше\n\nРазом: 350.00 UAH\n\nПідтвердити замовлення?",
        ], index: 1, forceMethod: 'editMessageText')
        ->assertRaw(function (TelegramRequest $request) use ($context): bool {
            $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $callback = $body['reply_markup']['inline_keyboard'][0][0]['callback_data'];
            $parts = explode(':', $callback);

            expect($parts)->toHaveCount(4)
                ->and($parts[0])->toBe('oc')
                ->and($parts[1])->toBeUuid()
                ->and($parts[2])->toBe('10')
                ->and($parts[3])->toBe(Str::after($context, '10:'))
                ->and(strlen($callback))->toBeLessThanOrEqual(64)
                ->and(strlen('oc:550e8400-e29b-41d4-a716-446655440000:2147483647:'.str_repeat('a', 12)))->toBeLessThanOrEqual(64)
                ->and($body['reply_markup']['inline_keyboard'][1][0]['callback_data'])->toBe("order:cancel:{$context}");

            return true;
        }, index: 1);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'http://ordering-backend.test/api/carts/current'
        && $request->hasHeader('X-Session-Token', $sessionToken));
    Http::assertSentCount(2);
});

test('checkout stops and renders authoritative empty cart with context navigation', function () {
    $sessionToken = str_repeat('2', 64);
    storeCheckoutSession($sessionToken);
    $context = checkoutContext($sessionToken);

    Http::fake(checkoutContextFakes([
        'ordering-backend.test/api/carts/current' => Http::response(checkoutCartResponse()),
    ]));

    checkoutTelegramBot()
        ->hearCallbackQueryData("checkout:{$context}")
        ->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertReplyMessage([
            'text' => "🛒 Кошик\n\nКошик порожній.\n\nПроміжний підсумок: 0.00 UAH\nРазом: 0.00 UAH",
            'reply_markup' => checkoutCartKeyboard([], $context),
        ], index: 1, forceMethod: 'editMessageText');

    Http::assertSentCount(2);
});

test('order cancellation returns to the current cart with restaurant context', function () {
    $sessionToken = str_repeat('6', 64);
    storeCheckoutSession($sessionToken);
    $context = checkoutContext($sessionToken);

    Http::fake(checkoutContextFakes([
        'ordering-backend.test/api/carts/current' => Http::response(checkoutCartResponse([
            'subtotal' => '200.00',
            'total' => '200.00',
            'items' => [checkoutCartItem()],
        ])),
    ]));

    checkoutTelegramBot()
        ->hearCallbackQueryData("order:cancel:{$context}")
        ->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertReplyMessage([
            'text' => "🛒 Кошик\n\nPizza Gavaiskaya\n2 × 100.00 UAH = 200.00 UAH\n\nПроміжний підсумок: 200.00 UAH\nРазом: 200.00 UAH",
            'reply_markup' => checkoutCartKeyboard([checkoutCartItem()], $context),
        ], index: 1, forceMethod: 'editMessageText');

    Http::assertSentCount(2);
});

test('stale checkout callback does not read or mutate backend cart', function () {
    $oldToken = str_repeat('o', 64);
    $newToken = str_repeat('n', 64);
    storeCheckoutSession($newToken);
    $context = checkoutContext($oldToken);

    checkoutTelegramBot()
        ->hearCallbackQueryData("checkout:{$context}")
        ->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertReplyMessage(['text' => RestaurantNavigationContext::STALE_MESSAGE], index: 1, forceMethod: 'editMessageText');

    Http::assertNothingSent();
});

test('successful order confirmation preserves callback idempotency key and enters order flow', function () {
    $sessionToken = str_repeat('3', 64);
    $idempotencyKey = '550e8400-e29b-41d4-a716-446655440000';
    storeCheckoutSession($sessionToken);
    $context = checkoutContext($sessionToken);

    Http::fake(checkoutContextFakes([
        'ordering-backend.test/api/orders' => Http::response(checkoutOrderResponse(), 201),
    ]));

    checkoutTelegramBot()
        ->hearCallbackQueryData("oc:{$idempotencyKey}:{$context}")
        ->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertReplyMessage([
            'text' => "⏳ Замовлення створюється.\n\nЗамовлення #77\nСтатус: Створюється\nОтримання: 🏃 Самовивіз\nОплата: 💳 Онлайн\nРазом: 350.00 UAH\n\nPizza Gavaiskaya\n2 × 100.00 UAH = 200.00 UAH\n\nPizza Pepperoni\n1 × 150.00 UAH = 150.00 UAH",
        ], index: 1, forceMethod: 'editMessageText');

    Http::assertSentInOrder([
        fn (Request $request): bool => $request->url() === 'http://ordering-backend.test/api/sessions/current/restaurants'
            && $request->method() === 'GET',
        fn (Request $request): bool => $request->url() === 'http://ordering-backend.test/api/orders'
            && $request->method() === 'POST'
            && $request->hasHeader('Idempotency-Key', $idempotencyKey),
    ]);
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'http://ordering-backend.test/api/carts'
        && $request->method() === 'POST');
});

test('order refresh is context-aware and read-only', function () {
    $sessionToken = str_repeat('4', 64);
    storeCheckoutSession($sessionToken);
    $context = checkoutContext($sessionToken);

    Http::fake(checkoutContextFakes([
        'ordering-backend.test/api/orders/current' => Http::response(checkoutOrderResponse([
            'status' => 'created',
            'payment' => ['status' => 'pending', 'checkout_url' => null, 'payment_received_at' => null, 'qr_ready' => false],
        ])),
    ]));

    checkoutTelegramBot()
        ->hearCallbackQueryData("order:refresh:{$context}")
        ->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertReplyMessage([
            'text' => "✅ Замовлення створено.\n\nЗамовлення #77\nСтатус: Створено\nОтримання: 🏃 Самовивіз\nОплата: 💳 Онлайн\nРазом: 350.00 UAH\n\nPizza Gavaiskaya\n2 × 100.00 UAH = 200.00 UAH\n\nPizza Pepperoni\n1 × 150.00 UAH = 150.00 UAH\n\n⏳ Платіжні дані ще готуються.",
            'reply_markup' => paymentPendingKeyboard($context),
        ], index: 1, forceMethod: 'editMessageText');

    Http::assertSentInOrder([
        fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'http://ordering-backend.test/api/sessions/current/restaurants',
        fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'http://ordering-backend.test/api/orders/current',
    ]);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

test('payment refresh treats HTTP 202 as pending and is read-only', function () {
    $sessionToken = str_repeat('5', 64);
    storeCheckoutSession($sessionToken);
    $context = checkoutContext($sessionToken);

    Http::fake(checkoutContextFakes([
        'ordering-backend.test/api/orders/current/payment' => Http::response([
            'data' => ['status' => 'pending', 'checkout_url' => null, 'payment_received_at' => null],
        ], 202),
    ]));

    checkoutTelegramBot()
        ->hearCallbackQueryData("payment:refresh:{$context}")
        ->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertReplyMessage([
            'text' => '⏳ Платіжні дані ще готуються.',
            'reply_markup' => paymentPendingKeyboard($context),
        ], index: 1, forceMethod: 'editMessageText');

    Http::assertSentInOrder([
        fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'http://ordering-backend.test/api/sessions/current/restaurants',
        fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'http://ordering-backend.test/api/orders/current/payment',
    ]);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

test('payment ready shows checkout URL button without treating ready as paid', function () {
    $sessionToken = str_repeat('7', 64);
    $checkoutUrl = 'https://checkout.example.test/pay/77';
    storeCheckoutSession($sessionToken);
    $context = checkoutContext($sessionToken);

    Http::fake(checkoutContextFakes([
        'ordering-backend.test/api/orders/current/payment' => Http::response([
            'data' => ['status' => 'ready', 'checkout_url' => $checkoutUrl, 'payment_received_at' => null],
        ]),
    ]));

    checkoutTelegramBot()
        ->hearCallbackQueryData("payment:refresh:{$context}")
        ->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertReplyMessage([
            'text' => "💳 Оплата готова.\n\nНатисніть кнопку нижче, щоб перейти до захищеної сторінки оплати.",
            'reply_markup' => paymentReadyKeyboard($checkoutUrl, $context),
        ], index: 1, forceMethod: 'editMessageText')
        ->assertRaw(function (TelegramRequest $request) use ($checkoutUrl): bool {
            $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $payButton = $body['reply_markup']['inline_keyboard'][0][0];

            expect($body['text'])->not->toContain('Оплату отримано')
                ->and($payButton['text'])->toBe('💳 Оплатити')
                ->and($payButton['url'])->toBe($checkoutUrl)
                ->and($payButton)->not->toHaveKey('callback_data');

            return true;
        }, index: 1);
});

test('payment received is shown only from authoritative received timestamp', function () {
    $sessionToken = str_repeat('8', 64);
    storeCheckoutSession($sessionToken);
    $context = checkoutContext($sessionToken);

    Http::fake(checkoutContextFakes([
        'ordering-backend.test/api/orders/current/payment' => Http::response([
            'data' => ['status' => 'ready', 'checkout_url' => 'https://checkout.example.test/pay/77', 'payment_received_at' => '2026-08-14T12:00:00+00:00'],
        ]),
    ]));

    checkoutTelegramBot()
        ->hearCallbackQueryData("payment:refresh:{$context}")
        ->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertReplyMessage([
            'text' => '✅ Оплату отримано.',
            'reply_markup' => paymentReceivedKeyboard($context),
        ], index: 1, forceMethod: 'editMessageText');
});

test('ready order sends backend QR PNG bytes and keeps payment URL usable', function () {
    $sessionToken = str_repeat('9', 64);
    $idempotencyKey = '550e8400-e29b-41d4-a716-446655440001';
    $checkoutUrl = 'https://checkout.example.test/pay/qr';
    $png = "\x89PNG\r\n\x1a\nbackend-qr-bytes";
    storeCheckoutSession($sessionToken);
    $context = checkoutContext($sessionToken);

    Http::fake(checkoutContextFakes([
        'ordering-backend.test/api/orders' => Http::response(checkoutOrderResponse([
            'status' => 'created',
            'payment' => ['status' => 'ready', 'checkout_url' => $checkoutUrl, 'payment_received_at' => null, 'qr_ready' => true],
        ]), 201),
        'ordering-backend.test/api/orders/current/payment/qr' => Http::response($png, 200, ['Content-Type' => 'image/png']),
    ]));

    checkoutTelegramBot()
        ->hearCallbackQueryData("oc:{$idempotencyKey}:{$context}")
        ->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertCalled('sendPhoto', 1)
        ->assertReplyMessage([
            'reply_markup' => paymentReadyKeyboard($checkoutUrl, $context),
        ], index: 2, forceMethod: 'editMessageText')
        ->assertRaw(function (TelegramRequest $request) use ($png): bool {
            expect((string) $request->getBody())->toContain($png);

            return true;
        }, index: 1);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'http://ordering-backend.test/api/orders/current/payment/qr');
});

test('QR pending or failure is non-fatal while payment URL remains available', function (int $qrStatus, array|string $qrBody, string $notice) {
    $sessionToken = str_repeat('q', 64);
    $idempotencyKey = '550e8400-e29b-41d4-a716-446655440002';
    $checkoutUrl = 'https://checkout.example.test/pay/qr-fallback';
    storeCheckoutSession($sessionToken);
    $context = checkoutContext($sessionToken);

    Http::fake(checkoutContextFakes([
        'ordering-backend.test/api/orders' => Http::response(checkoutOrderResponse([
            'status' => 'created',
            'payment' => ['status' => 'ready', 'checkout_url' => $checkoutUrl, 'payment_received_at' => null, 'qr_ready' => true],
        ]), 201),
        'ordering-backend.test/api/orders/current/payment/qr' => Http::response($qrBody, $qrStatus),
    ]));

    checkoutTelegramBot()
        ->hearCallbackQueryData("oc:{$idempotencyKey}:{$context}")
        ->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertCalled('sendPhoto', 0)
        ->assertReplyMessage([
            'reply_markup' => paymentReadyKeyboard($checkoutUrl, $context),
        ], index: 1, forceMethod: 'editMessageText')
        ->assertRaw(function (TelegramRequest $request) use ($notice, $checkoutUrl): bool {
            $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);

            expect($body['text'])->toContain($notice)
                ->and($body['reply_markup']['inline_keyboard'][0][0]['url'])->toBe($checkoutUrl);

            return true;
        }, index: 1);
})->with([
    'pending QR' => [202, [
        'data' => ['status' => 'pending', 'checkout_url' => null, 'payment_received_at' => null],
    ], '⏳ QR-код ще готується.'],
    'failed QR' => [503, [
        'message' => 'QR unavailable.',
    ], '⚠️ QR-код тимчасово недоступний. Скористайтеся кнопкою оплати.'],
]);

test('stale order confirmation performs no backend request when Telegram rejects callback acknowledgement', function () {
    $bot = staleCheckoutTelegramBot();

    app(CheckoutHandler::class)->confirm($bot, '550e8400-e29b-41d4-a716-446655440000', 10, 'abcdef123456');

    $bot->assertCalled('answerCallbackQuery', 1);
    Http::assertNothingSent();
});

function checkoutTelegramBot(): FakeNutgram
{
    /** @var FakeNutgram $bot */
    $bot = app(Nutgram::class);

    return $bot
        ->setCommonChat(Chat::make(id: 123456, type: ChatType::PRIVATE))
        ->setCommonUser(User::make(id: 654321, is_bot: false, first_name: 'Test'));
}

function staleCheckoutTelegramBot(): FakeNutgram
{
    return Nutgram::fake(responses: [
        new Response(
            status: 400,
            headers: ['Content-Type' => 'application/json'],
            body: json_encode([
                'ok' => false,
                'error_code' => 400,
                'description' => 'Bad Request: query is too old and response timeout expired or query ID is invalid',
            ], JSON_THROW_ON_ERROR),
        ),
    ]);
}

function storeCheckoutSession(string $sessionToken): void
{
    app(TelegramSessionStore::class)->put('telegram-chat-123456', $sessionToken);
}

function checkoutContext(string $sessionToken, int $restaurantId = 10): string
{
    return app(RestaurantNavigationContext::class)->encode($restaurantId, $sessionToken);
}

/** @param array<string, mixed> $fakes */
function checkoutContextFakes(array $fakes): array
{
    return ['ordering-backend.test/api/sessions/current/restaurants' => Http::response(['data' => [checkoutRestaurant()]])] + $fakes;
}

/** @return array<string, mixed> */
function checkoutRestaurant(): array
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

/** @param array<string, mixed> $overrides */
function checkoutCartResponse(array $overrides = []): array
{
    return ['data' => array_replace([
        'id' => 91,
        'status' => 'active',
        'currency' => 'UAH',
        'subtotal' => '0.00',
        'total' => '0.00',
        'expires_at' => '2026-08-12T12:00:00+00:00',
        'items' => [],
    ], $overrides)];
}

/** @param array<string, mixed> $overrides */
function checkoutCartItem(array $overrides = []): array
{
    return array_replace([
        'id' => 51,
        'product_id' => 1,
        'external_product_id' => 'gavaiskaya-external',
        'name' => 'Pizza Gavaiskaya',
        'quantity' => 2,
        'unit_price' => '100.00',
        'total' => '200.00',
    ], $overrides);
}

/** @param array<string, mixed> $overrides */
function checkoutOrderResponse(array $overrides = []): array
{
    return ['data' => array_replace([
        'id' => 77,
        'external_order_id' => null,
        'status' => 'creating',
        'failure_message' => null,
        'receiving_type' => 'pickup',
        'payment_type' => 2,
        'fulfillment' => ['type' => 'pickup'],
        'total' => '350.00',
        'currency' => 'UAH',
        'payment' => ['status' => 'pending', 'checkout_url' => null, 'payment_received_at' => null, 'qr_ready' => false],
        'items' => [
            ['product_id' => 1, 'external_product_id' => 'gavaiskaya-external', 'name' => 'Pizza Gavaiskaya', 'quantity' => 2, 'unit_price' => '100.00', 'total' => '200.00'],
            ['product_id' => 7, 'external_product_id' => 'pepperoni-external', 'name' => 'Pizza Pepperoni', 'quantity' => 1, 'unit_price' => '150.00', 'total' => '150.00'],
        ],
    ], $overrides)];
}

/** @param list<array<string, mixed>> $items */
function checkoutCartKeyboard(array $items, string $context): array
{
    $rows = [];

    foreach ($items as $item) {
        $rows[] = [['text' => "🍽 {$item['name']}", 'callback_data' => "cart:noop:{$item['id']}:{$context}"]];
        $rows[] = [
            ['text' => '➖', 'callback_data' => "cart:dec:{$item['id']}:{$context}"],
            ['text' => "🔢 {$item['quantity']}", 'callback_data' => "cart:noop:{$item['id']}:{$context}"],
            ['text' => '➕', 'callback_data' => "cart:inc:{$item['id']}:{$context}"],
        ];
        $rows[] = [['text' => '🗑 Видалити', 'callback_data' => "cart:remove:{$item['id']}:{$context}"]];
    }

    if ($items !== []) {
        $rows[] = [['text' => '✅ Оформити замовлення', 'callback_data' => "checkout:{$context}"]];
        $rows[] = [['text' => '🧹 Очистити кошик', 'callback_data' => "cart:clear:{$context}"]];
    }

    $rows[] = [['text' => $items === [] ? '🍕 Перейти до каталогу' : '🍕 Продовжити покупки', 'callback_data' => "catalog:{$context}"]];
    $rows[] = [['text' => '⬅️ Головне меню', 'callback_data' => "main_menu:{$context}"]];
    $rows[] = [['text' => '🚪 Вийти', 'callback_data' => 'exit']];

    return ['inline_keyboard' => $rows];
}

function paymentPendingKeyboard(string $context): array
{
    return [
        'inline_keyboard' => [
            [['text' => '🔄 Оновити оплату', 'callback_data' => "payment:refresh:{$context}"]],
            [['text' => '🚪 Вийти', 'callback_data' => 'exit']],
        ],
    ];
}

function paymentReadyKeyboard(string $checkoutUrl, string $context): array
{
    return [
        'inline_keyboard' => [
            [['text' => '💳 Оплатити', 'url' => $checkoutUrl]],
            [['text' => '🔄 Оновити оплату', 'callback_data' => "payment:refresh:{$context}"]],
            [['text' => '🚪 Вийти', 'callback_data' => 'exit']],
        ],
    ];
}

function paymentReceivedKeyboard(string $context): array
{
    return [
        'inline_keyboard' => [
            [['text' => '🔄 Оновити оплату', 'callback_data' => "payment:refresh:{$context}"]],
            [['text' => '🚪 Вийти', 'callback_data' => 'exit']],
        ],
    ];
}
