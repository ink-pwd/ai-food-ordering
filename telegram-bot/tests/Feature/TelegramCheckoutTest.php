<?php

use App\Telegram\Handlers\CheckoutHandler;
use App\Telegram\Keyboards\CartKeyboard;
use App\Telegram\Session\TelegramSessionStore;
use GuzzleHttp\Psr7\Request as TelegramRequest;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
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

test('checkout action is rendered only for non-empty active carts', function (array $items, string $status, bool $expected) {
    $keyboard = json_encode(
        app(CartKeyboard::class)->make($items, $status),
        JSON_THROW_ON_ERROR,
    );

    expect(Str::contains($keyboard, 'checkout'))->toBe($expected);
})->with([
    'active cart with items' => [[checkoutCartItem()], 'active', true],
    'empty active cart' => [[], 'active', false],
    'non-active cart with items' => [[checkoutCartItem()], 'checked_out', false],
]);

test('checkout confirmation uses backend totals and embeds a valid callback UUID within Telegram limits', function () {
    $sessionToken = str_repeat('1', 64);
    storeCheckoutSession($sessionToken);

    Http::fake([
        'ordering-backend.test/api/carts/current' => Http::response(checkoutCartResponse([
            'subtotal' => '400.00',
            'total' => '350.00',
            'items' => [checkoutCartItem()],
        ])),
    ]);

    checkoutTelegramBot()
        ->hearCallbackQueryData('checkout')
        ->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertReplyMessage([
            'text' => "Оформление заказа\n\nСамовывоз\nОплата наличными\nВремя: как можно скорее\n\nИтого: 350.00 UAH\n\nПодтвердить заказ?",
        ], index: 1, forceMethod: 'editMessageText')
        ->assertRaw(function (TelegramRequest $request): bool {
            $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $callback = $body['reply_markup']['inline_keyboard'][0][0]['callback_data'];
            $idempotencyKey = Str::after($callback, 'order:confirm:');

            expect($callback)
                ->toStartWith('order:confirm:')
                ->and($idempotencyKey)->toBeUuid()
                ->and(strlen($callback))->toBeLessThanOrEqual(64)
                ->and($body['reply_markup']['inline_keyboard'][1][0]['callback_data'])->toBe('order:cancel');

            return true;
        }, index: 1);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'http://ordering-backend.test/api/carts/current'
        && $request->hasHeader('X-Session-Token', $sessionToken));
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    Http::assertSentCount(1);
});

test('checkout stops and renders the authoritative cart when it is empty', function () {
    storeCheckoutSession(str_repeat('2', 64));

    Http::fake([
        'ordering-backend.test/api/carts/current' => Http::response(checkoutCartResponse()),
    ]);

    checkoutTelegramBot()
        ->hearCallbackQueryData('checkout')
        ->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertReplyMessage([
            'text' => "🛒 Корзина\n\nКорзина пуста.\n\nПодытог: 0.00 UAH\nИтого: 0.00 UAH",
        ], index: 1, forceMethod: 'editMessageText')
        ->assertRaw(function (TelegramRequest $request): bool {
            expect((string) $request->getBody())
                ->not->toContain('order:confirm:')
                ->not->toContain('checkout');

            return true;
        }, index: 1);

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    Http::assertSentCount(1);
});

test('successful order confirmation ensures the next cart after the order and renders backend order values', function (int $status) {
    $sessionToken = str_repeat('3', 64);
    $idempotencyKey = '550e8400-e29b-41d4-a716-446655440000';
    storeCheckoutSession($sessionToken);

    Http::fake([
        'ordering-backend.test/api/orders' => Http::response(checkoutOrderResponse(), $status),
        'ordering-backend.test/api/carts' => Http::response(checkoutCartResponse([
            'id' => 92,
        ]), 201),
    ]);

    checkoutTelegramBot()
        ->hearCallbackQueryData("order:confirm:{$idempotencyKey}")
        ->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertReplyMessage([
            'text' => "Заказ #77\n\nСтатус: Создаётся\nПолучение: Самовывоз\nИтого: 350.00 UAH\n\nPizza Gavaiskaya\n2 × 100.00 UAH = 200.00 UAH\n\nPizza Pepperoni\n1 × 150.00 UAH = 150.00 UAH",
            'reply_markup' => creatingOrderKeyboard(),
        ], index: 1, forceMethod: 'editMessageText');

    Http::assertSentInOrder([
        fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'http://ordering-backend.test/api/orders'
            && $request->hasHeader('X-Internal-Api-Token', 'internal-api-secret')
            && $request->hasHeader('X-Session-Token', $sessionToken)
            && $request->hasHeader('Idempotency-Key', $idempotencyKey)
            && $request->data() === ['delivery_time' => 0],
        fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'http://ordering-backend.test/api/carts'
            && $request->hasHeader('X-Internal-Api-Token', 'internal-api-secret')
            && $request->hasHeader('X-Session-Token', $sessionToken)
            && $request->data() === [],
    ]);
    Http::assertSentCount(2);
})->with([
    'new order' => [201],
    'existing idempotent order' => [200],
]);

test('a failed next-cart initialization is logged safely and still renders the successful order', function () {
    $sessionToken = str_repeat('e', 64);
    $idempotencyKey = '550e8400-e29b-41d4-a716-446655440000';
    storeCheckoutSession($sessionToken);

    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('channel')
        ->once()
        ->andReturnSelf();
    $logger->shouldReceive('debug')
        ->zeroOrMoreTimes();
    $logger->shouldReceive('error')
        ->once()
        ->with('Ordering backend request failed.', [
            'operation' => 'ensure_current_cart',
            'status' => 503,
            'exception' => RequestException::class,
        ]);
    app()->instance(LoggerInterface::class, $logger);
    $bot = checkoutTelegramBot();

    Http::fake([
        'ordering-backend.test/api/orders' => Http::response(checkoutOrderResponse(), 201),
        'ordering-backend.test/api/carts' => Http::response([
            'message' => 'raw-next-cart-internal-error',
        ], 503),
    ]);

    $bot
        ->hearCallbackQueryData("order:confirm:{$idempotencyKey}")
        ->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertReplyMessage([
            'text' => "Заказ #77\n\nСтатус: Создаётся\nПолучение: Самовывоз\nИтого: 350.00 UAH\n\nPizza Gavaiskaya\n2 × 100.00 UAH = 200.00 UAH\n\nPizza Pepperoni\n1 × 150.00 UAH = 150.00 UAH",
            'reply_markup' => creatingOrderKeyboard(),
        ], index: 1, forceMethod: 'editMessageText')
        ->assertRaw(function (TelegramRequest $request): bool {
            expect((string) $request->getBody())->not->toContain('raw-next-cart-internal-error');

            return true;
        }, index: 1);

    Http::assertSentInOrder([
        fn (Request $request): bool => $request->url() === 'http://ordering-backend.test/api/orders',
        fn (Request $request): bool => $request->url() === 'http://ordering-backend.test/api/carts',
    ]);
    expect(Http::recorded(fn (Request $request): bool => $request->url() === 'http://ordering-backend.test/api/orders'))
        ->toHaveCount(1);
    Http::assertSentCount(2);
});

test('an unchanged order render does not repeat a successful order creation or send a fallback message', function () {
    $sessionToken = str_repeat('d', 64);
    $idempotencyKey = '550e8400-e29b-41d4-a716-446655440000';
    storeCheckoutSession($sessionToken);

    Http::fake([
        'ordering-backend.test/api/orders' => Http::response(checkoutOrderResponse(), 201),
        'ordering-backend.test/api/carts' => Http::response(checkoutCartResponse([
            'id' => 92,
        ]), 201),
    ]);

    $bot = checkoutMessageEditFailureTelegramBot();

    app(CheckoutHandler::class)->confirm($bot, $idempotencyKey);

    $bot
        ->assertCalled('answerCallbackQuery', 1)
        ->assertCalled('editMessageText', 1)
        ->assertCalled('sendMessage', 0);

    $orderRequests = Http::recorded(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://ordering-backend.test/api/orders')->values();

    expect($orderRequests)->toHaveCount(1)
        ->and($orderRequests[0][0]->header('Idempotency-Key'))->toBe([$idempotencyKey])
        ->and($orderRequests[0][0]->data())->toBe(['delivery_time' => 0]);
    Http::assertSentCount(2);
});

test('repeated handling of one confirmation callback reuses the unchanged idempotency key', function () {
    $sessionToken = str_repeat('4', 64);
    $idempotencyKey = 'c56a4180-65aa-42ec-a945-5fd21dec0538';
    storeCheckoutSession($sessionToken);

    Http::fake([
        'ordering-backend.test/api/orders' => Http::sequence()
            ->push(checkoutOrderResponse(), 201)
            ->push(checkoutOrderResponse(), 200),
        'ordering-backend.test/api/carts' => Http::sequence()
            ->push(checkoutCartResponse(['id' => 92]), 201)
            ->push(checkoutCartResponse(['id' => 92]), 200),
    ]);

    checkoutTelegramBot()->hearCallbackQueryData("order:confirm:{$idempotencyKey}")->reply();
    checkoutTelegramBot()->hearCallbackQueryData("order:confirm:{$idempotencyKey}")->reply();

    $orderRequests = Http::recorded(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://ordering-backend.test/api/orders')->values();

    expect($orderRequests)->toHaveCount(2)
        ->and($orderRequests[0][0]->header('Idempotency-Key'))->toBe([$idempotencyKey])
        ->and($orderRequests[1][0]->header('Idempotency-Key'))->toBe([$idempotencyKey])
        ->and($orderRequests[0][0]->data())->toBe(['delivery_time' => 0])
        ->and($orderRequests[1][0]->data())->toBe(['delivery_time' => 0])
        ->and(app(TelegramSessionStore::class)->get('telegram-chat-123456'))->toBe($sessionToken);

    Http::assertSentInOrder([
        fn (Request $request): bool => $request->url() === 'http://ordering-backend.test/api/orders',
        fn (Request $request): bool => $request->url() === 'http://ordering-backend.test/api/carts'
            && $request->data() === [],
        fn (Request $request): bool => $request->url() === 'http://ordering-backend.test/api/orders',
        fn (Request $request): bool => $request->url() === 'http://ordering-backend.test/api/carts'
            && $request->data() === [],
    ]);
    Http::assertSentCount(4);
});

test('order refresh gets and renders the authoritative current order', function () {
    $sessionToken = str_repeat('5', 64);
    storeCheckoutSession($sessionToken);

    Http::fake([
        'ordering-backend.test/api/orders/current' => Http::response(checkoutOrderResponse([
            'external_order_id' => 'dots-order-123',
            'status' => 'created',
        ])),
    ]);

    checkoutTelegramBot()
        ->hearCallbackQueryData('order:refresh')
        ->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertReplyMessage([
            'text' => "Заказ #77\n\nСтатус: Создан\nПолучение: Самовывоз\nИтого: 350.00 UAH\n\nPizza Gavaiskaya\n2 × 100.00 UAH = 200.00 UAH\n\nPizza Pepperoni\n1 × 150.00 UAH = 150.00 UAH",
            'reply_markup' => completedOrderKeyboard(),
        ], index: 1, forceMethod: 'editMessageText');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'http://ordering-backend.test/api/orders/current'
        && $request->hasHeader('X-Session-Token', $sessionToken));
    Http::assertSentCount(1);
});

test('order cancellation returns to the current cart without creating an order', function () {
    $sessionToken = str_repeat('6', 64);
    storeCheckoutSession($sessionToken);

    Http::fake([
        'ordering-backend.test/api/carts/current' => Http::response(checkoutCartResponse([
            'subtotal' => '350.00',
            'total' => '350.00',
            'items' => [checkoutCartItem()],
        ])),
    ]);

    checkoutTelegramBot()
        ->hearCallbackQueryData('order:cancel')
        ->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertReplyMessage([
            'text' => "🛒 Корзина\n\nPizza Gavaiskaya\n2 × 100.00 UAH = 200.00 UAH\n\nПодытог: 350.00 UAH\nИтого: 350.00 UAH",
        ], index: 1, forceMethod: 'editMessageText');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'http://ordering-backend.test/api/carts/current');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://ordering-backend.test/api/orders');
    Http::assertSentCount(1);
});

test('invalid confirmation UUIDs stop before session or backend work', function () {
    checkoutTelegramBot()
        ->hearCallbackQueryData('order:confirm:not-a-uuid')
        ->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertReplyMessage([
            'text' => 'Не удалось подтвердить заказ. Вернитесь в корзину и повторите оформление.',
            'reply_markup' => backToCartOrderKeyboard(),
        ], index: 1, forceMethod: 'editMessageText');

    Http::assertNothingSent();
});

test('a stale order confirmation performs no backend request', function () {
    $bot = staleCheckoutTelegramBot();

    app(CheckoutHandler::class)->confirm($bot, '550e8400-e29b-41d4-a716-446655440000');

    $bot->assertCalled('answerCallbackQuery', 1);
    Http::assertNothingSent();
});

test('an order 401 recovers the session without retrying creation', function () {
    $staleSessionToken = str_repeat('7', 64);
    $freshSessionToken = str_repeat('8', 64);
    $idempotencyKey = '550e8400-e29b-41d4-a716-446655440000';
    storeCheckoutSession($staleSessionToken);

    Http::fake([
        'ordering-backend.test/api/orders' => Http::response(['message' => 'Unauthenticated.'], 401),
        'ordering-backend.test/api/sessions' => Http::response([
            'data' => ['session_token' => $freshSessionToken],
        ], 201),
    ]);

    checkoutTelegramBot()
        ->hearCallbackQueryData("order:confirm:{$idempotencyKey}")
        ->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertReplyMessage([
            'text' => 'Сессия истекла. Пожалуйста, снова поделитесь контактом.',
            'reply_markup' => checkoutContactRequestKeyboard(),
        ], index: 1, forceMethod: 'sendMessage');

    $orderRequests = Http::recorded(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://ordering-backend.test/api/orders');

    expect($orderRequests)->toHaveCount(1)
        ->and($orderRequests[0][0]->header('Idempotency-Key'))->toBe([$idempotencyKey])
        ->and(app(TelegramSessionStore::class)->get('telegram-chat-123456'))->toBe($freshSessionToken);
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'http://ordering-backend.test/api/carts');
    Http::assertSentCount(2);
});

test('a next-cart 401 recovers the session without retrying the successful order', function () {
    $staleSessionToken = str_repeat('f', 64);
    $freshSessionToken = str_repeat('0', 64);
    $idempotencyKey = '550e8400-e29b-41d4-a716-446655440000';
    storeCheckoutSession($staleSessionToken);

    Http::fake([
        'ordering-backend.test/api/orders' => Http::response(checkoutOrderResponse(), 201),
        'ordering-backend.test/api/carts' => Http::response(['message' => 'Unauthenticated.'], 401),
        'ordering-backend.test/api/sessions' => Http::response([
            'data' => ['session_token' => $freshSessionToken],
        ], 201),
    ]);

    checkoutTelegramBot()
        ->hearCallbackQueryData("order:confirm:{$idempotencyKey}")
        ->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertReplyMessage([
            'text' => 'Сессия истекла. Пожалуйста, снова поделитесь контактом.',
            'reply_markup' => checkoutContactRequestKeyboard(),
        ], index: 1, forceMethod: 'sendMessage')
        ->assertReplyMessage([
            'text' => "Заказ #77\n\nСтатус: Создаётся\nПолучение: Самовывоз\nИтого: 350.00 UAH\n\nPizza Gavaiskaya\n2 × 100.00 UAH = 200.00 UAH\n\nPizza Pepperoni\n1 × 150.00 UAH = 150.00 UAH",
            'reply_markup' => creatingOrderKeyboard(),
        ], index: 2, forceMethod: 'editMessageText');

    Http::assertSentInOrder([
        fn (Request $request): bool => $request->url() === 'http://ordering-backend.test/api/orders'
            && $request->hasHeader('X-Session-Token', $staleSessionToken),
        fn (Request $request): bool => $request->url() === 'http://ordering-backend.test/api/carts'
            && $request->hasHeader('X-Session-Token', $staleSessionToken),
        fn (Request $request): bool => $request->url() === 'http://ordering-backend.test/api/sessions',
    ]);
    expect(Http::recorded(fn (Request $request): bool => $request->url() === 'http://ordering-backend.test/api/orders'))
        ->toHaveCount(1)
        ->and(app(TelegramSessionStore::class)->get('telegram-chat-123456'))->toBe($freshSessionToken);
    Http::assertSentCount(3);
});

test('an ambiguous order timeout is not retried and offers a status check', function () {
    $sessionToken = str_repeat('9', 64);
    storeCheckoutSession($sessionToken);

    Http::fake([
        'ordering-backend.test/api/orders' => Http::failedConnection(),
    ]);

    checkoutTelegramBot()
        ->hearCallbackQueryData('order:confirm:550e8400-e29b-41d4-a716-446655440000')
        ->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertReplyMessage([
            'text' => 'Не удалось однозначно определить результат оформления. Проверьте статус заказа перед повторной попыткой.',
            'reply_markup' => statusCheckOrderKeyboard(),
        ], index: 1, forceMethod: 'editMessageText');

    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://ordering-backend.test/api/orders'))
        ->toHaveCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'http://ordering-backend.test/api/carts');
    Http::assertSentCount(1);
});

test('a known restaurant-hours rejection renders the safe closed message without retrying', function (string $backendMessage) {
    storeCheckoutSession(str_repeat('1', 64));

    Http::fake([
        'ordering-backend.test/api/orders' => Http::response([
            'message' => $backendMessage,
        ], 422),
    ]);

    checkoutTelegramBot()
        ->hearCallbackQueryData('order:confirm:550e8400-e29b-41d4-a716-446655440000')
        ->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertReplyMessage([
            'text' => 'Сейчас ресторан не принимает заказы. Попробуйте оформить заказ в рабочее время.',
            'reply_markup' => backToCartOrderKeyboard(),
        ], index: 1, forceMethod: 'editMessageText')
        ->assertRaw(function (TelegramRequest $request) use ($backendMessage): bool {
            expect((string) $request->getBody())->not->toContain($backendMessage);

            return true;
        }, index: 1);

    expect(Http::recorded(fn (Request $request): bool => $request->url() === 'http://ordering-backend.test/api/orders'))
        ->toHaveCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'http://ordering-backend.test/api/carts');
    Http::assertSentCount(1);
})->with([
    'known Ukrainian response' => ['Компанiя не працює у вказаний в замовленні час'],
    'current Russian response' => ['Компания не работает в это время'],
]);

test('order creation failures use safe actions without exposing backend errors', function (string $failure, string $expectedMessage, array $expectedKeyboard) {
    storeCheckoutSession(str_repeat('a', 64));

    $response = $failure === 'malformed'
        ? Http::response(['data' => ['status' => 'creating']], 201)
        : Http::response(['message' => 'raw-order-provider-error'], (int) $failure);

    Http::fake([
        'ordering-backend.test/api/orders' => $response,
    ]);

    checkoutTelegramBot()
        ->hearCallbackQueryData('order:confirm:550e8400-e29b-41d4-a716-446655440000')
        ->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertReplyMessage([
            'text' => $expectedMessage,
            'reply_markup' => $expectedKeyboard,
        ], index: 1, forceMethod: 'editMessageText')
        ->assertRaw(function (TelegramRequest $request): bool {
            expect((string) $request->getBody())->not->toContain('raw-order-provider-error');

            return true;
        }, index: 1);

    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'http://ordering-backend.test/api/carts');
    Http::assertSentCount(1);
})->with([
    'conflict' => [
        '409',
        'Заказ уже оформляется или корзина изменилась. Проверьте текущий заказ.',
        statusCheckOrderKeyboard(),
    ],
    'missing active cart' => [
        '404',
        'Не удалось найти активную корзину для оформления заказа.',
        backToCartOrderKeyboard(),
    ],
    'rejected' => [
        '422',
        'Не удалось оформить заказ. Проверьте корзину и контактные данные.',
        backToCartOrderKeyboard(),
    ],
    'backend unavailable' => [
        '503',
        'Не удалось однозначно определить результат оформления. Проверьте статус заказа перед повторной попыткой.',
        statusCheckOrderKeyboard(),
    ],
    'gateway failure' => [
        '502',
        'Не удалось однозначно определить результат оформления. Проверьте статус заказа перед повторной попыткой.',
        statusCheckOrderKeyboard(),
    ],
    'malformed success response' => [
        'malformed',
        'Не удалось однозначно определить результат оформления. Проверьте статус заказа перед повторной попыткой.',
        statusCheckOrderKeyboard(),
    ],
]);

test('failed orders never expose arbitrary backend failure messages', function () {
    storeCheckoutSession(str_repeat('b', 64));

    Http::fake([
        'ordering-backend.test/api/orders/current' => Http::response(checkoutOrderResponse([
            'status' => 'failed',
            'failure_message' => 'Dots stack trace with provider secrets',
        ])),
    ]);

    checkoutTelegramBot()
        ->hearCallbackQueryData('order:refresh')
        ->reply()
        ->assertReplyMessage([
            'text' => "Заказ #77\n\nСтатус: Ошибка\nПолучение: Самовывоз\nИтого: 350.00 UAH\n\nPizza Gavaiskaya\n2 × 100.00 UAH = 200.00 UAH\n\nPizza Pepperoni\n1 × 150.00 UAH = 150.00 UAH\n\nНе удалось создать заказ. Проверьте статус позже или обратитесь в поддержку.",
            'reply_markup' => completedOrderKeyboard(),
        ], index: 1, forceMethod: 'editMessageText')
        ->assertRaw(function (TelegramRequest $request): bool {
            expect((string) $request->getBody())->not->toContain('Dots stack trace with provider secrets');

            return true;
        }, index: 1);

    Http::assertSentCount(1);
});

test('a missing current order renders a safe recovery action', function () {
    storeCheckoutSession(str_repeat('c', 64));

    Http::fake([
        'ordering-backend.test/api/orders/current' => Http::response([
            'message' => 'raw-current-order-error',
        ], 404),
    ]);

    checkoutTelegramBot()
        ->hearCallbackQueryData('order:refresh')
        ->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertReplyMessage([
            'text' => 'Текущий заказ не найден.',
            'reply_markup' => backToCartOrderKeyboard(),
        ], index: 1, forceMethod: 'editMessageText')
        ->assertRaw(function (TelegramRequest $request): bool {
            expect((string) $request->getBody())->not->toContain('raw-current-order-error');

            return true;
        }, index: 1);

    Http::assertSentCount(1);
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

function checkoutMessageEditFailureTelegramBot(): FakeNutgram
{
    return Nutgram::fake(responses: [
        new Response(
            status: 200,
            headers: ['Content-Type' => 'application/json'],
            body: json_encode([
                'ok' => true,
                'result' => true,
            ], JSON_THROW_ON_ERROR),
        ),
        new Response(
            status: 400,
            headers: ['Content-Type' => 'application/json'],
            body: json_encode([
                'ok' => false,
                'error_code' => 400,
                'description' => 'Bad Request: message is not modified: specified new message content and reply markup are exactly the same',
            ], JSON_THROW_ON_ERROR),
        ),
    ])
        ->setCommonChat(Chat::make(id: 123456, type: ChatType::PRIVATE))
        ->setCommonUser(User::make(id: 654321, is_bot: false, first_name: 'Test'))
        ->hearCallbackQueryData('order:confirm:550e8400-e29b-41d4-a716-446655440000')
        ->reply();
}

function storeCheckoutSession(string $sessionToken): void
{
    app(TelegramSessionStore::class)->put('telegram-chat-123456', $sessionToken);
}

/** @param array<string, mixed> $overrides */
function checkoutCartResponse(array $overrides = []): array
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
    return [
        'data' => array_replace([
            'id' => 77,
            'external_order_id' => null,
            'status' => 'creating',
            'failure_message' => null,
            'receiving_type' => 'pickup',
            'total' => '350.00',
            'currency' => 'UAH',
            'items' => [
                [
                    'product_id' => 1,
                    'external_product_id' => 'gavaiskaya-external',
                    'name' => 'Pizza Gavaiskaya',
                    'quantity' => 2,
                    'unit_price' => '100.00',
                    'total' => '200.00',
                ],
                [
                    'product_id' => 7,
                    'external_product_id' => 'pepperoni-external',
                    'name' => 'Pizza Pepperoni',
                    'quantity' => 1,
                    'unit_price' => '150.00',
                    'total' => '150.00',
                ],
            ],
        ], $overrides),
    ];
}

/** @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>} */
function creatingOrderKeyboard(): array
{
    return [
        'inline_keyboard' => [
            [[
                'text' => '🔄 Обновить статус',
                'callback_data' => 'order:refresh',
            ]],
            [[
                'text' => '⬅️ Главное меню',
                'callback_data' => 'main_menu',
            ]],
        ],
    ];
}

/** @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>} */
function completedOrderKeyboard(): array
{
    return [
        'inline_keyboard' => [
            [[
                'text' => '⬅️ Главное меню',
                'callback_data' => 'main_menu',
            ]],
        ],
    ];
}

/** @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>} */
function statusCheckOrderKeyboard(): array
{
    return [
        'inline_keyboard' => [
            [[
                'text' => '🔄 Проверить заказ',
                'callback_data' => 'order:refresh',
            ]],
            [[
                'text' => '⬅️ Главное меню',
                'callback_data' => 'main_menu',
            ]],
        ],
    ];
}

/** @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>} */
function backToCartOrderKeyboard(): array
{
    return [
        'inline_keyboard' => [
            [[
                'text' => '🛒 Вернуться в корзину',
                'callback_data' => 'menu:cart',
            ]],
            [[
                'text' => '⬅️ Главное меню',
                'callback_data' => 'main_menu',
            ]],
        ],
    ];
}

/** @return array{keyboard: list<list<array{text: string, request_contact: bool}>>, resize_keyboard: bool, one_time_keyboard: bool} */
function checkoutContactRequestKeyboard(): array
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
