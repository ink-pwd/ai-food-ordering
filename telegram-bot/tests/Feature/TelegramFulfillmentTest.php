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

test('delivery fulfillment preserves restaurant context through type prompt force reply and main menu', function () {
    $sessionToken = str_repeat('d', 64);
    storeFulfillmentPrompt4Session($sessionToken);
    $context = fulfillmentPrompt4Context($sessionToken);

    Http::fake(fulfillmentPrompt4Fakes([
        'ordering-backend.test/api/sessions/current/fulfillment' => Http::response(fulfillmentPrompt4State(['type' => 'delivery'])),
        'ordering-backend.test/api/sessions/current/delivery-address' => Http::response([
            'data' => fulfillmentPrompt4DeliveryResult([
                'delivery_available' => true,
                'delivery_price' => '80.00 UAH',
                'fulfillment' => ['type' => 'delivery', 'delivery_address' => ['street' => 'Шевченка', 'house' => '11А', 'flat' => '7']],
            ]),
        ]),
    ]));

    fulfillmentPrompt4Bot()
        ->hearCallbackQueryData("fulfillment:delivery:{$context}")
        ->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertReplyMessage([
            'text' => '📍 Оберіть тип адреси доставки:',
            'reply_markup' => fulfillmentPrompt4DeliveryTypeKeyboard($context),
        ], index: 1, forceMethod: 'sendMessage');

    fulfillmentPrompt4Bot()
        ->hearCallbackQueryData("delivery:type:0:{$context}")
        ->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertReplyMessage([
            'text' => fulfillmentPrompt4DeliveryPrompt(0, $sessionToken),
            'reply_markup' => ['force_reply' => true, 'input_field_placeholder' => 'Вулиця, будинок, квартира', 'selective' => true],
        ], index: 1, forceMethod: 'sendMessage');

    fulfillmentPrompt4Bot()
        ->hearMessage(fulfillmentPrompt4DeliveryAddressReply('Шевченка, 11А, 7', fulfillmentPrompt4DeliveryPrompt(0, $sessionToken)))
        ->reply()
        ->assertReplyMessage([
            'text' => "✅ Доставка доступна\n📍 Адреса: Шевченка, 11А, 7\n💰 Вартість доставки: 80.00 UAH",
            'reply_markup' => fulfillmentPrompt4MainMenuKeyboard($context),
        ])
        ->assertRaw(function (TelegramRequest $request) use ($sessionToken): bool {
            expect((string) $request->getBody())->not->toContain($sessionToken)->not->toContain('internal-api-secret');

            return true;
        });

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === 'http://ordering-backend.test/api/sessions/current/fulfillment'
        && $request->data() === ['type' => 'delivery']);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://ordering-backend.test/api/sessions/current/delivery-address'
        && $request->data() === ['type' => 0, 'street' => 'Шевченка', 'house' => '11А', 'flat' => '7']);
});

test('address types map to backend payload types and retry keeps context', function (int $type) {
    $sessionToken = str_repeat((string) ($type + 1), 64);
    storeFulfillmentPrompt4Session($sessionToken);
    $context = fulfillmentPrompt4Context($sessionToken);

    Http::fake(fulfillmentPrompt4Fakes([
        'ordering-backend.test/api/sessions/current/delivery-address' => Http::response(['data' => fulfillmentPrompt4DeliveryResult()]),
    ]));

    fulfillmentPrompt4Bot()->hearCallbackQueryData("delivery:retry:{$context}")->reply()->assertCalled('answerCallbackQuery', 1)
        ->assertReplyMessage(['reply_markup' => fulfillmentPrompt4DeliveryTypeKeyboard($context)], index: 1, forceMethod: 'sendMessage');

    fulfillmentPrompt4Bot()->hearMessage(fulfillmentPrompt4DeliveryAddressReply('Шевченка, 11А', fulfillmentPrompt4DeliveryPrompt($type, $sessionToken)))->reply();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://ordering-backend.test/api/sessions/current/delivery-address'
        && $request->data()['type'] === $type
        && $request->data()['street'] === 'Шевченка'
        && $request->data()['house'] === '11А');
})->with([0, 1, 2, 3]);

test('outside delivery zone offers pickup with restaurant context', function () {
    $sessionToken = str_repeat('z', 64);
    storeFulfillmentPrompt4Session($sessionToken);
    $context = fulfillmentPrompt4Context($sessionToken);

    Http::fake(fulfillmentPrompt4Fakes([
        'ordering-backend.test/api/sessions/current/delivery-address' => Http::response(['data' => fulfillmentPrompt4DeliveryResult(['delivery_available' => false, 'reason' => 'outside_delivery_zone'])]),
    ]));

    fulfillmentPrompt4Bot()
        ->hearMessage(fulfillmentPrompt4DeliveryAddressReply('Далека, 99', fulfillmentPrompt4DeliveryPrompt(0, $sessionToken)))
        ->reply()
        ->assertReplyMessage([
            'text' => '❌ Цей ресторан не доставляє за вказаною адресою.',
            'reply_markup' => [
                'inline_keyboard' => [
                    [['text' => '🏃 Перейти на самовивіз', 'callback_data' => "fulfillment:pickup:{$context}"]],
                    [['text' => '🚪 Вийти', 'callback_data' => 'exit']],
                ],
            ],
        ]);
});

test('pickup flow preserves context through address selection to main menu', function () {
    $sessionToken = str_repeat('k', 64);
    storeFulfillmentPrompt4Session($sessionToken);
    $context = fulfillmentPrompt4Context($sessionToken);

    Http::fake(fulfillmentPrompt4Fakes([
        'ordering-backend.test/api/sessions/current/fulfillment' => Http::response(fulfillmentPrompt4State(['type' => 'pickup'])),
        'ordering-backend.test/api/sessions/current/pickup-addresses' => Http::response(['data' => [fulfillmentPrompt4PickupAddress()]]),
        'ordering-backend.test/api/sessions/current/pickup-address' => Http::response(fulfillmentPrompt4State(['type' => 'pickup', 'restaurant_address_id' => 5])),
    ]));

    fulfillmentPrompt4Bot()
        ->hearCallbackQueryData("fulfillment:pickup:{$context}")
        ->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertReplyMessage([
            'text' => '📍 Оберіть точку самовивозу:',
            'reply_markup' => [
                'inline_keyboard' => [
                    [['text' => '📍 вул. Шевченка, 10', 'callback_data' => "pickup:5:{$context}"]],
                    [['text' => '🚪 Вийти', 'callback_data' => 'exit']],
                ],
            ],
        ], index: 1, forceMethod: 'editMessageText');

    fulfillmentPrompt4Bot()
        ->hearCallbackQueryData("pickup:5:{$context}")
        ->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertReplyMessage([
            'text' => "✅ Самовивіз обрано.\n📍 Адресу самовивозу збережено.",
            'reply_markup' => fulfillmentPrompt4MainMenuKeyboard($context),
        ], index: 1, forceMethod: 'editMessageText');
});

test('stale delivery reply and stale callback do not mutate the new session', function () {
    $oldToken = str_repeat('o', 64);
    $newToken = str_repeat('n', 64);
    $oldContext = fulfillmentPrompt4Context($oldToken);
    storeFulfillmentPrompt4Session($newToken);

    fulfillmentPrompt4Bot()
        ->hearMessage(fulfillmentPrompt4DeliveryAddressReply('Шевченка, 11А, 7', fulfillmentPrompt4DeliveryPrompt(0, $oldToken)))
        ->reply()
        ->assertReplyMessage(['text' => '⚠️ Це старий запит адреси. Почніть вибір доставки ще раз.']);

    fulfillmentPrompt4Bot()
        ->hearCallbackQueryData("fulfillment:delivery:{$oldContext}")
        ->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertReplyMessage(['text' => RestaurantNavigationContext::STALE_MESSAGE], index: 1, forceMethod: 'editMessageText');

    Http::assertNothingSent();
});

function fulfillmentPrompt4Bot(): FakeNutgram
{
    /** @var FakeNutgram $bot */
    $bot = app(Nutgram::class);

    return $bot
        ->setCommonChat(Chat::make(id: 123456, type: ChatType::PRIVATE))
        ->setCommonUser(User::make(id: 654321, is_bot: false, first_name: 'Test'));
}

function storeFulfillmentPrompt4Session(string $sessionToken): void
{
    app(TelegramSessionStore::class)->put('telegram-chat-123456', $sessionToken);
}

function fulfillmentPrompt4Context(string $sessionToken, int $restaurantId = 10): string
{
    return app(RestaurantNavigationContext::class)->encode($restaurantId, $sessionToken);
}

/** @param array<string, mixed> $fakes */
function fulfillmentPrompt4Fakes(array $fakes): array
{
    return ['ordering-backend.test/api/sessions/current/restaurants' => Http::response(['data' => [fulfillmentPrompt4Restaurant()]])] + $fakes;
}

/** @return array<string, mixed> */
function fulfillmentPrompt4Restaurant(): array
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

/** @param array<string, mixed> $fulfillment */
function fulfillmentPrompt4State(array $fulfillment): array
{
    return ['data' => ['session_id' => '01KSESSION', 'fulfillment' => $fulfillment]];
}

/** @param array<string, mixed> $overrides */
function fulfillmentPrompt4DeliveryResult(array $overrides = []): array
{
    return array_replace([
        'session_id' => '01KSESSION',
        'delivery_available' => true,
        'reason' => null,
        'delivery_price' => null,
        'dots_delivery_type' => 1,
        'fulfillment' => ['type' => 'delivery'],
    ], $overrides);
}

/** @return array{id: int, title: string, latitude: string, longitude: string} */
function fulfillmentPrompt4PickupAddress(): array
{
    return ['id' => 5, 'title' => 'вул. Шевченка, 10', 'latitude' => '50.4501', 'longitude' => '30.5234'];
}

function fulfillmentPrompt4DeliveryPrompt(int $type, string $sessionToken): string
{
    $caption = match ($type) {
        0 => '🏢 Квартира',
        1 => '🏠 Приватний будинок',
        2 => '🏢 Офіс',
        default => '📍 Інше',
    };
    $format = $type === 0 ? 'Вулиця, будинок, квартира' : 'Вулиця, будинок';
    $example = $type === 0 ? "\n\nНаприклад:\nШевченка, 11А, 7" : '';

    return implode("\n\n", [
        $caption,
        "📍 Введіть адресу у форматі:\n{$format}{$example}",
        '#delivery-address:'.$type.':'.fulfillmentPrompt4Context($sessionToken),
    ]);
}

/** @return array{text: string, reply_to_message: array{text: string}} */
function fulfillmentPrompt4DeliveryAddressReply(string $text, string $prompt): array
{
    return ['text' => $text, 'reply_to_message' => ['text' => $prompt]];
}

/** @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>} */
function fulfillmentPrompt4DeliveryTypeKeyboard(string $context): array
{
    return [
        'inline_keyboard' => [
            [['text' => '🏢 Квартира', 'callback_data' => "delivery:type:0:{$context}"]],
            [['text' => '🏠 Приватний будинок', 'callback_data' => "delivery:type:1:{$context}"]],
            [['text' => '🏢 Офіс', 'callback_data' => "delivery:type:2:{$context}"]],
            [['text' => '📍 Інше', 'callback_data' => "delivery:type:3:{$context}"]],
            [['text' => '🚪 Вийти', 'callback_data' => 'exit']],
        ],
    ];
}

/** @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>} */
function fulfillmentPrompt4MainMenuKeyboard(string $context): array
{
    return [
        'inline_keyboard' => [
            [['text' => '🍕 Каталог', 'callback_data' => "catalog:{$context}"]],
            [['text' => '🛒 Кошик', 'callback_data' => "menu:cart:{$context}"]],
            [['text' => '🚚 Спосіб отримання', 'callback_data' => "fulfillment:menu:{$context}"]],
            [['text' => '🚪 Вийти', 'callback_data' => 'exit']],
        ],
    ];
}
