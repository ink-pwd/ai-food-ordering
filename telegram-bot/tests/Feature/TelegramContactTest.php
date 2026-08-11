<?php

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

test('a foreign contact is rejected without calling the backend', function () {
    storeTelegramContactSession(str_repeat('d', 64));

    telegramContactBot()
        ->hearMessage(telegramContactMessage(userId: 999999))
        ->reply()
        ->assertReplyMessage([
            'text' => 'Пожалуйста, отправьте свой собственный контакт.',
            'reply_markup' => contactRequestKeyboard(),
        ]);

    Http::assertNothingSent();
});

test('a valid contact is saved before the contact keyboard is removed and main menu is shown', function () {
    $sessionToken = str_repeat('e', 64);
    storeTelegramContactSession($sessionToken);

    Http::fake([
        'ordering-backend.test/api/sessions/current/contact' => Http::response(
            telegramContactBackendResponse(),
        ),
    ]);

    telegramContactBot(firstName: 'Ada', lastName: 'Lovelace')
        ->hearMessage(telegramContactMessage(
            userId: 654321,
            phone: '+380 (93) 123-45-67',
        ))
        ->reply()
        ->assertSequence(
            fn (FakeNutgram $bot) => $bot->assertReplyMessage([
                'text' => 'Контакт сохранён.',
                'reply_markup' => [
                    'remove_keyboard' => true,
                ],
            ]),
            fn (FakeNutgram $bot) => $bot->assertReplyMessage([
                'text' => 'Приветствуем! Выберите действие:',
                'reply_markup' => mainMenuKeyboard(),
            ]),
        )
        ->assertRaw(function (TelegramRequest $request) use ($sessionToken): bool {
            expect((string) $request->getBody())
                ->not->toContain($sessionToken)
                ->not->toContain('internal-api-secret');

            return true;
        }, index: 0)
        ->assertRaw(function (TelegramRequest $request) use ($sessionToken): bool {
            expect((string) $request->getBody())
                ->not->toContain($sessionToken)
                ->not->toContain('internal-api-secret');

            return true;
        }, index: 1);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === 'http://ordering-backend.test/api/sessions/current/contact'
        && $request->hasHeader('X-Internal-Api-Token', 'internal-api-secret')
        && $request->hasHeader('X-Session-Token', $sessionToken)
        && $request->data() === [
            'name' => 'Ada Lovelace',
            'phone' => '+380 (93) 123-45-67',
        ]
        && ! array_key_exists('phone_verified', $request->data()));
    Http::assertSentCount(1);
});

test('a contact without an in-memory session creates a fresh session and restarts onboarding', function () {
    $freshSessionToken = str_repeat('4', 64);

    Http::fake([
        'ordering-backend.test/api/sessions' => Http::response([
            'data' => [
                'session_token' => $freshSessionToken,
            ],
        ], 201),
    ]);

    telegramContactBot()
        ->hearMessage(telegramContactMessage(userId: 654321))
        ->reply()
        ->assertReplyMessage([
            'text' => 'Сессия истекла. Пожалуйста, снова поделитесь контактом.',
            'reply_markup' => contactRequestKeyboard(),
        ]);

    expect(app(TelegramSessionStore::class)->get('telegram-chat-123456'))
        ->toBe($freshSessionToken);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://ordering-backend.test/api/sessions'
        && $request->hasHeader('X-Internal-Api-Token', 'internal-api-secret')
        && ! $request->hasHeader('X-Session-Token')
        && $request->data() === [
            'channel' => 'telegram',
            'external_session_id' => 'telegram-chat-123456',
        ]);
    Http::assertSentCount(1);
});

test('backend validation errors produce a safe response and keep requiring contact', function () {
    $sessionToken = str_repeat('f', 64);
    storeTelegramContactSession($sessionToken);

    Http::fake([
        'ordering-backend.test/api/sessions/current/contact' => Http::response([
            'message' => 'Raw backend validation detail.',
            'errors' => ['phone' => ['Raw phone validation detail.']],
        ], 422),
    ]);

    telegramContactBot()
        ->hearMessage(telegramContactMessage(userId: 654321))
        ->reply()
        ->assertReplyMessage([
            'text' => 'Не удалось принять контакт. Проверьте номер и попробуйте снова.',
            'reply_markup' => contactRequestKeyboard(),
        ])
        ->assertRaw(function (TelegramRequest $request) use ($sessionToken): bool {
            expect((string) $request->getBody())
                ->not->toContain('Raw backend validation detail.')
                ->not->toContain('Raw phone validation detail.')
                ->not->toContain($sessionToken)
                ->not->toContain('internal-api-secret');

            return true;
        });

    expect(app(TelegramSessionStore::class)->get('telegram-chat-123456'))->toBe($sessionToken);
    Http::assertSentCount(1);
});

test('backend unauthorized errors replace the stale token and restart onboarding without retrying the contact update', function () {
    $staleSessionToken = str_repeat('1', 64);
    $freshSessionToken = str_repeat('5', 64);
    storeTelegramContactSession($staleSessionToken);

    Http::fake([
        'ordering-backend.test/api/sessions/current/contact' => Http::response([
            'message' => 'Unauthenticated.',
        ], 401),
        'ordering-backend.test/api/sessions' => Http::response([
            'data' => [
                'session_token' => $freshSessionToken,
            ],
        ], 201),
    ]);

    telegramContactBot()
        ->hearMessage(telegramContactMessage(userId: 654321))
        ->reply()
        ->assertReplyMessage([
            'text' => 'Сессия истекла. Пожалуйста, снова поделитесь контактом.',
            'reply_markup' => contactRequestKeyboard(),
        ]);

    expect(app(TelegramSessionStore::class)->get('telegram-chat-123456'))
        ->toBe($freshSessionToken)
        ->not->toBe($staleSessionToken);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === 'http://ordering-backend.test/api/sessions/current/contact'
        && $request->hasHeader('X-Session-Token', $staleSessionToken));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://ordering-backend.test/api/sessions'
        && ! $request->hasHeader('X-Session-Token'));
    Http::assertSentCount(2);
});

test('backend unauthorized errors forget the stale token before a failed session recreation', function () {
    storeTelegramContactSession(str_repeat('6', 64));

    Http::fake([
        'ordering-backend.test/api/sessions/current/contact' => Http::response([
            'message' => 'Unauthenticated.',
        ], 401),
        'ordering-backend.test/api/sessions' => Http::response([
            'message' => 'Session service unavailable.',
        ], 503),
    ]);

    telegramContactBot()
        ->hearMessage(telegramContactMessage(userId: 654321))
        ->reply()
        ->assertReplyMessage([
            'text' => 'Сервис временно недоступен. Попробуйте снова позже.',
            'reply_markup' => contactRequestKeyboard(),
        ]);

    expect(app(TelegramSessionStore::class)->get('telegram-chat-123456'))->toBeNull();
    Http::assertSentCount(2);
});

test('backend connection failures produce a safe temporary failure response', function () {
    $sessionToken = str_repeat('2', 64);
    storeTelegramContactSession($sessionToken);

    Http::fake([
        'ordering-backend.test/api/sessions/current/contact' => Http::failedConnection(),
    ]);

    telegramContactBot()
        ->hearMessage(telegramContactMessage(userId: 654321))
        ->reply()
        ->assertReplyMessage([
            'text' => 'Сервис временно недоступен. Попробуйте снова позже.',
            'reply_markup' => contactRequestKeyboard(),
        ]);

    expect(app(TelegramSessionStore::class)->get('telegram-chat-123456'))->toBe($sessionToken);
    Http::assertSentCount(1);
});

test('malformed backend responses produce a safe temporary failure response', function () {
    $sessionToken = str_repeat('3', 64);
    storeTelegramContactSession($sessionToken);

    Http::fake([
        'ordering-backend.test/api/sessions/current/contact' => Http::response([
            'data' => ['unexpected' => true],
        ]),
    ]);

    telegramContactBot()
        ->hearMessage(telegramContactMessage(userId: 654321))
        ->reply()
        ->assertReplyMessage([
            'text' => 'Сервис временно недоступен. Попробуйте снова позже.',
            'reply_markup' => contactRequestKeyboard(),
        ]);

    expect(app(TelegramSessionStore::class)->get('telegram-chat-123456'))->toBe($sessionToken);
    Http::assertSentCount(1);
});

function telegramContactBot(
    string $firstName = 'Test',
    ?string $lastName = null,
): FakeNutgram {
    /** @var FakeNutgram $bot */
    $bot = app(Nutgram::class);

    return $bot
        ->setCommonChat(Chat::make(id: 123456, type: ChatType::PRIVATE))
        ->setCommonUser(User::make(
            id: 654321,
            is_bot: false,
            first_name: $firstName,
            last_name: $lastName,
        ));
}

/** @return array{contact: array{phone_number: string, first_name: string, user_id: int}} */
function telegramContactMessage(int $userId, string $phone = '+380931234567'): array
{
    return [
        'contact' => [
            'phone_number' => $phone,
            'first_name' => 'Shared contact',
            'user_id' => $userId,
        ],
    ];
}

function storeTelegramContactSession(string $sessionToken): void
{
    app(TelegramSessionStore::class)->put('telegram-chat-123456', $sessionToken);
}

/** @return array{data: array{session_id: string, contact: array{name: string, phone: string, phone_verified: bool}}} */
function telegramContactBackendResponse(): array
{
    return [
        'data' => [
            'session_id' => '01K23456789ABCDEFGHJKMNPQRS',
            'contact' => [
                'name' => 'Ada Lovelace',
                'phone' => '+380931234567',
                'phone_verified' => false,
            ],
        ],
    ];
}

/** @return array{keyboard: array<int, array<int, array{text: string, request_contact: bool}>>, resize_keyboard: bool, one_time_keyboard: bool} */
function contactRequestKeyboard(): array
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

/** @return array{inline_keyboard: array<int, array<int, array{text: string, callback_data: string}>>} */
function mainMenuKeyboard(): array
{
    return [
        'inline_keyboard' => [
            [[
                'text' => '🍕 Каталог',
                'callback_data' => 'catalog',
            ]],
            [[
                'text' => '🛒 Корзина',
                'callback_data' => 'menu:cart',
            ]],
        ],
    ];
}
