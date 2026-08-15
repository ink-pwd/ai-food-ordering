<?php

use App\Telegram\Session\TelegramSessionStore;
use App\Telegram\Support\RestaurantNavigationContext;
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

test('OTP code is submitted to backend and success leads to city selection without persisting the code', function () {
    $sessionToken = str_repeat('o', 64);
    storeOnboardingSession($sessionToken);

    Http::fake([
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
        'ordering-backend.test/api/cities' => Http::response([
            'data' => [onboardingCity()],
        ]),
    ]);

    onboardingBot()
        ->hearText('654987')
        ->reply()
        ->assertSequence(
            fn (FakeNutgram $bot) => $bot->assertReplyMessage(['text' => '✅ Номер телефону підтверджено.']),
            fn (FakeNutgram $bot) => $bot->assertReplyMessage([
                'text' => '🏙 Оберіть місто:',
                'reply_markup' => cityKeyboard(),
            ]),
        )
        ->assertRaw(function (TelegramRequest $request) use ($sessionToken): bool {
            expect((string) $request->getBody())
                ->not->toContain($sessionToken)
                ->not->toContain('internal-api-secret')
                ->not->toContain('654987');

            return true;
        }, index: 1);

    expect(app(TelegramSessionStore::class)->get('telegram-chat-123456'))->toBe($sessionToken);

    Http::assertSentInOrder([
        fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'http://ordering-backend.test/api/sessions/current/otp/verify'
            && $request->hasHeader('X-Session-Token', $sessionToken)
            && $request->data() === ['code' => '654987'],
        fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'http://ordering-backend.test/api/cities'
            && ! $request->hasHeader('X-Session-Token'),
    ]);
    Http::assertSentCount(2);
});

test('invalid OTP returns Ukrainian feedback and keeps resend and exit buttons', function () {
    storeOnboardingSession(str_repeat('i', 64));

    Http::fake([
        'ordering-backend.test/api/sessions/current/otp/verify' => Http::response([
            'message' => 'The given data was invalid.',
            'errors' => ['code' => ['Invalid code.']],
        ], 422),
    ]);

    onboardingBot()
        ->hearText('000000')
        ->reply()
        ->assertReplyMessage([
            'text' => '❌ Невірний код. Спробуйте ще раз.',
            'reply_markup' => otpKeyboard(),
        ]);

    Http::assertSentCount(1);
});

test('OTP resend callback requests backend OTP and handles cooldown safely', function () {
    $sessionToken = str_repeat('r', 64);
    storeOnboardingSession($sessionToken);

    Http::fake([
        'ordering-backend.test/api/sessions/current/otp' => Http::sequence()
            ->push([
                'data' => [
                    'expires_in' => 300,
                    'resend_available_in' => 60,
                ],
            ])
            ->push(['message' => 'Too many attempts.'], 409),
    ]);

    onboardingBot()
        ->hearCallbackQueryData('otp:resend')
        ->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertReplyMessage([
            'text' => '🔐 Новий код надіслано. Введіть його повідомленням у цей чат.',
            'reply_markup' => otpKeyboard(),
        ], index: 1, forceMethod: 'editMessageText');

    onboardingBot()
        ->hearCallbackQueryData('otp:resend')
        ->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertReplyMessage([
            'text' => '⏳ Повторно надіслати код можна трохи пізніше.',
            'reply_markup' => otpKeyboard(),
        ], index: 1, forceMethod: 'editMessageText')
        ->assertRaw(function (TelegramRequest $request) use ($sessionToken): bool {
            expect((string) $request->getBody())
                ->not->toContain($sessionToken)
                ->not->toContain('internal-api-secret')
                ->not->toContain('Too many attempts.');

            return true;
        }, index: 1);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://ordering-backend.test/api/sessions/current/otp'
        && $request->hasHeader('X-Session-Token', $sessionToken)
        && $request->data() === []);
    Http::assertSentCount(2);
});

test('city selection calls backend and leads to restaurant list with emoji buttons', function () {
    $sessionToken = str_repeat('c', 64);
    storeOnboardingSession($sessionToken);

    Http::fake([
        'ordering-backend.test/api/sessions/current/city' => Http::response([
            'data' => [
                'session_id' => '01KSESSION',
                'city' => onboardingCity(),
            ],
        ]),
        'ordering-backend.test/api/sessions/current/restaurants' => Http::response([
            'data' => [onboardingRestaurant()],
        ]),
    ]);

    onboardingBot()
        ->hearCallbackQueryData('city:7')
        ->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertReplyMessage([
            'text' => '🍽 Оберіть ресторан:',
            'reply_markup' => restaurantKeyboard(),
        ], index: 1, forceMethod: 'editMessageText');

    Http::assertSentInOrder([
        fn (Request $request): bool => $request->method() === 'PUT'
            && $request->url() === 'http://ordering-backend.test/api/sessions/current/city'
            && $request->hasHeader('X-Session-Token', $sessionToken)
            && $request->data() === ['city_id' => 7],
        fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'http://ordering-backend.test/api/sessions/current/restaurants'
            && $request->hasHeader('X-Session-Token', $sessionToken),
    ]);
    Http::assertSentCount(2);
});

test('stale city conflict does not create a new session and moves forward safely', function () {
    $sessionToken = str_repeat('s', 64);
    storeOnboardingSession($sessionToken);

    Http::fake([
        'ordering-backend.test/api/sessions/current/city' => Http::response(['message' => 'City already selected.'], 409),
        'ordering-backend.test/api/sessions/current/restaurants' => Http::response([
            'data' => [onboardingRestaurant()],
        ]),
    ]);

    onboardingBot()
        ->hearCallbackQueryData('city:7')
        ->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertReplyMessage([
            'text' => '🏙 Місто вже обрано для цієї сесії. Продовжимо з вибором ресторану.',
        ], index: 1, forceMethod: 'editMessageText')
        ->assertReplyMessage([
            'text' => '🍽 Оберіть ресторан:',
            'reply_markup' => restaurantKeyboard(),
        ], index: 2, forceMethod: 'sendMessage');

    expect(app(TelegramSessionStore::class)->get('telegram-chat-123456'))->toBe($sessionToken);
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'http://ordering-backend.test/api/sessions');
    Http::assertSentCount(2);
});

test('restaurant selection calls backend and hands off to fulfillment options instead of catalog', function () {
    $sessionToken = str_repeat('f', 64);
    storeOnboardingSession($sessionToken);

    Http::fake([
        'ordering-backend.test/api/sessions/current/restaurant' => Http::response([
            'data' => [
                'session_id' => '01KSESSION',
                'restaurant' => onboardingRestaurant(),
            ],
        ]),
        'ordering-backend.test/api/sessions/current/fulfillment-options' => Http::response([
            'data' => [['type' => 'delivery'], ['type' => 'pickup']],
        ]),
    ]);

    $context = app(RestaurantNavigationContext::class)->encode(10, $sessionToken);

    onboardingBot()
        ->hearCallbackQueryData('restaurant:10')
        ->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertReplyMessage([
            'text' => '📦 Оберіть спосіб отримання замовлення:',
            'reply_markup' => fulfillmentKeyboard($context),
        ], index: 1, forceMethod: 'editMessageText')
        ->assertRaw(function (TelegramRequest $request) use ($sessionToken): bool {
            $body = (string) $request->getBody();

            expect($body)
                ->toContain('🚚 Доставка')
                ->toContain('🏃 Самовивіз')
                ->not->toContain($sessionToken)
                ->not->toContain('internal-api-secret')
                ->not->toContain('🍕 Каталог')
                ->not->toContain('🛒 Кошик');

            return true;
        }, index: 1);

    Http::assertSentInOrder([
        fn (Request $request): bool => $request->method() === 'PUT'
            && $request->url() === 'http://ordering-backend.test/api/sessions/current/restaurant'
            && $request->hasHeader('X-Session-Token', $sessionToken)
            && $request->data() === ['restaurant_id' => 10],
        fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'http://ordering-backend.test/api/sessions/current/fulfillment-options'
            && $request->hasHeader('X-Session-Token', $sessionToken),
    ]);
    Http::assertSentCount(2);
});

test('restaurant conflict moves forward to fulfillment without creating a new session', function () {
    $sessionToken = str_repeat('m', 64);
    storeOnboardingSession($sessionToken);

    Http::fake([
        'ordering-backend.test/api/sessions/current/restaurant' => Http::response(['message' => 'Restaurant already selected.'], 409),
        'ordering-backend.test/api/sessions/current/fulfillment-options' => Http::response([
            'data' => [['type' => 'pickup']],
        ]),
    ]);

    $context = app(RestaurantNavigationContext::class)->encode(10, $sessionToken);

    onboardingBot()
        ->hearCallbackQueryData('restaurant:10')
        ->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertReplyMessage([
            'text' => '🍽 Ресторан уже обрано для цієї сесії. Продовжимо з вибором способу отримання.',
        ], index: 1, forceMethod: 'editMessageText')
        ->assertReplyMessage([
            'text' => '📦 Оберіть спосіб отримання замовлення:',
            'reply_markup' => [
                'inline_keyboard' => [
                    [[
                        'text' => '🏃 Самовивіз',
                        'callback_data' => "fulfillment:pickup:{$context}",
                    ]],
                    [[
                        'text' => '🚪 Вийти',
                        'callback_data' => 'exit',
                    ]],
                ],
            ],
        ], index: 2, forceMethod: 'sendMessage');

    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'http://ordering-backend.test/api/sessions');
    Http::assertSentCount(2);
});

test('exit deletes backend session when possible forgets local token and restarts onboarding', function () {
    $oldToken = str_repeat('x', 64);
    $newToken = str_repeat('n', 64);
    storeOnboardingSession($oldToken);

    Http::fake([
        'ordering-backend.test/api/sessions/current' => Http::response([
            'data' => [
                'session_id' => '01KSESSION',
                'status' => 'closed',
            ],
        ]),
        'ordering-backend.test/api/sessions' => Http::response([
            'data' => [
                'session_token' => $newToken,
            ],
        ], 201),
    ]);

    onboardingBot()
        ->hearCallbackQueryData('exit')
        ->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertReplyMessage([
            'text' => '🚪 Ви вийшли. Почнімо спочатку: надішліть свій номер телефону.',
            'reply_markup' => contactKeyboard(),
        ], index: 1, forceMethod: 'sendMessage');

    expect(app(TelegramSessionStore::class)->get('telegram-chat-123456'))->toBe($newToken);

    Http::assertSentInOrder([
        fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'http://ordering-backend.test/api/sessions/current'
            && $request->hasHeader('X-Session-Token', $oldToken),
        fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'http://ordering-backend.test/api/sessions'
            && ! $request->hasHeader('X-Session-Token'),
    ]);
    Http::assertSentCount(2);
});

test('session 401 during OTP starts fresh contact onboarding', function () {
    $staleToken = str_repeat('u', 64);
    $freshToken = str_repeat('v', 64);
    storeOnboardingSession($staleToken);

    Http::fake([
        'ordering-backend.test/api/sessions/current/otp/verify' => Http::response(['message' => 'Unauthenticated.'], 401),
        'ordering-backend.test/api/sessions' => Http::response([
            'data' => [
                'session_token' => $freshToken,
            ],
        ], 201),
    ]);

    onboardingBot()
        ->hearText('123456')
        ->reply()
        ->assertReplyMessage([
            'text' => 'Сесію завершено. Почнімо спочатку: надішліть свій номер телефону.',
            'reply_markup' => contactKeyboard(),
        ]);

    expect(app(TelegramSessionStore::class)->get('telegram-chat-123456'))->toBe($freshToken);
    Http::assertSentCount(2);
});

test('onboarding callback data never carries session tokens or secrets', function () {
    $context = app(RestaurantNavigationContext::class)->encode(10, str_repeat('x', 64));
    $callbacks = array_merge(
        callbackDataFromKeyboard(otpKeyboard()),
        callbackDataFromKeyboard(cityKeyboard()),
        callbackDataFromKeyboard(restaurantKeyboard()),
        callbackDataFromKeyboard(fulfillmentKeyboard($context)),
    );

    expect($callbacks)->toBe([
        'otp:resend',
        'exit',
        'city:7',
        'exit',
        'restaurant:10',
        'exit',
        "fulfillment:delivery:{$context}",
        "fulfillment:pickup:{$context}",
        'exit',
    ]);

    foreach ($callbacks as $callbackData) {
        expect(strlen($callbackData))->toBeLessThanOrEqual(64)
            ->and($callbackData)->not->toContain('token')
            ->and($callbackData)->not->toContain('internal-api-secret');
    }
});

function onboardingBot(): FakeNutgram
{
    /** @var FakeNutgram $bot */
    $bot = app(Nutgram::class);

    return $bot
        ->setCommonChat(Chat::make(id: 123456, type: ChatType::PRIVATE))
        ->setCommonUser(User::make(id: 654321, is_bot: false, first_name: 'Test'));
}

function storeOnboardingSession(string $sessionToken): void
{
    app(TelegramSessionStore::class)->put('telegram-chat-123456', $sessionToken);
}

/** @return array{id: int, name: string, slug: string, currency: string, timezone: string, center: array{latitude: string, longitude: string}} */
function onboardingCity(): array
{
    return [
        'id' => 7,
        'name' => 'Київ',
        'slug' => 'kyiv',
        'currency' => 'UAH',
        'timezone' => 'Europe/Kyiv',
        'center' => [
            'latitude' => '50.4501',
            'longitude' => '30.5234',
        ],
    ];
}

/** @return array{id: int, name: string, slug: string, image_url: ?string, currency: string, locale: string, timezone: string, available_payment_types: list<int>, available_delivery_types: list<int>, delivery_time_text: string, delivery_price_text: string} */
function onboardingRestaurant(): array
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

/** @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>} */
function otpKeyboard(): array
{
    return [
        'inline_keyboard' => [
            [[
                'text' => '🔄 Надіслати код повторно',
                'callback_data' => 'otp:resend',
            ]],
            [[
                'text' => '🚪 Вийти',
                'callback_data' => 'exit',
            ]],
        ],
    ];
}

/** @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>} */
function cityKeyboard(): array
{
    return [
        'inline_keyboard' => [
            [[
                'text' => '🏙 Київ',
                'callback_data' => 'city:7',
            ]],
            [[
                'text' => '🚪 Вийти',
                'callback_data' => 'exit',
            ]],
        ],
    ];
}

/** @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>} */
function restaurantKeyboard(): array
{
    return [
        'inline_keyboard' => [
            [[
                'text' => '🍽 Pizza House',
                'callback_data' => 'restaurant:10',
            ]],
            [[
                'text' => '🚪 Вийти',
                'callback_data' => 'exit',
            ]],
        ],
    ];
}

/** @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>} */
function fulfillmentKeyboard(string $context): array
{
    return [
        'inline_keyboard' => [
            [[
                'text' => '🚚 Доставка',
                'callback_data' => "fulfillment:delivery:{$context}",
            ]],
            [[
                'text' => '🏃 Самовивіз',
                'callback_data' => "fulfillment:pickup:{$context}",
            ]],
            [[
                'text' => '🚪 Вийти',
                'callback_data' => 'exit',
            ]],
        ],
    ];
}

/** @return array{keyboard: array<int, array<int, array{text: string, request_contact: bool}>>, resize_keyboard: bool, one_time_keyboard: bool} */
function contactKeyboard(): array
{
    return [
        'keyboard' => [
            [[
                'text' => '📱 Надіслати номер телефону',
                'request_contact' => true,
            ]],
        ],
        'resize_keyboard' => true,
        'one_time_keyboard' => true,
    ];
}

/**
 * @param  array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}  $keyboard
 * @return list<string>
 */
function callbackDataFromKeyboard(array $keyboard): array
{
    return collect($keyboard['inline_keyboard'])
        ->flatten(1)
        ->pluck('callback_data')
        ->values()
        ->all();
}
