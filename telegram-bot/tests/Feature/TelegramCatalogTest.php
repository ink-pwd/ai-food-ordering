<?php

use App\Telegram\Session\TelegramSessionStore;
use App\Telegram\Support\RestaurantNavigationContext;
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

test('catalog resolves restaurant id through current session restaurants and renders categories', function () {
    $sessionToken = str_repeat('c', 64);
    storeCatalogSession($sessionToken);
    $context = catalogContext($sessionToken);

    Http::fake([
        'ordering-backend.test/api/sessions/current/restaurants' => Http::response(['data' => [catalogRestaurant()]]),
        'ordering-backend.test/api/restaurants/pizza-house/categories' => Http::response([
            'data' => [['id' => 7, 'name' => 'Піца']],
        ]),
    ]);

    catalogBot()
        ->hearCallbackQueryData("catalog:{$context}")
        ->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertReplyMessage([
            'text' => 'Категорії',
            'reply_markup' => [
                'inline_keyboard' => [
                    [[
                        'text' => '📂 Піца',
                        'callback_data' => "category:7:{$context}",
                    ]],
                    [[
                        'text' => '⬅️ Головне меню',
                        'callback_data' => "main_menu:{$context}",
                    ]],
                ],
            ],
        ], index: 1, forceMethod: 'editMessageText');

    Http::assertSentInOrder([
        fn (Request $request): bool => $request->url() === 'http://ordering-backend.test/api/sessions/current/restaurants'
            && $request->hasHeader('X-Session-Token', $sessionToken),
        fn (Request $request): bool => $request->url() === 'http://ordering-backend.test/api/restaurants/pizza-house/categories',
    ]);
    Http::assertSentCount(2);
});

test('category and product callbacks preserve restaurant context and use backend returned slug', function () {
    $sessionToken = str_repeat('p', 64);
    storeCatalogSession($sessionToken);
    $context = catalogContext($sessionToken);

    Http::fake([
        'ordering-backend.test/api/sessions/current/restaurants' => Http::response(['data' => [catalogRestaurant()]]),
        'ordering-backend.test/api/restaurants/pizza-house/categories/7/products' => Http::response([
            'data' => [[
                'id' => 502,
                'name' => 'Маргарита',
                'price' => '190.00',
                'promotion_price' => null,
                'currency' => 'UAH',
            ]],
        ]),
        'ordering-backend.test/api/restaurants/pizza-house/products/502' => Http::response([
            'data' => [
                'id' => 502,
                'name' => 'Маргарита',
                'description' => 'Сир і томати',
                'price' => '190.00',
                'promotion_price' => null,
                'currency' => 'UAH',
                'is_available' => true,
            ],
        ]),
    ]);

    catalogBot()
        ->hearCallbackQueryData("category:7:{$context}")
        ->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertReplyMessage([
            'text' => 'Товари категорії',
            'reply_markup' => [
                'inline_keyboard' => [
                    [[
                        'text' => '🍽 Маргарита — 190.00 UAH',
                        'callback_data' => "product:7:502:{$context}",
                    ]],
                    [[
                        'text' => '⬅️ Категорії',
                        'callback_data' => "catalog:{$context}",
                    ]],
                ],
            ],
        ], index: 1, forceMethod: 'editMessageText');

    catalogBot()
        ->hearCallbackQueryData("product:7:502:{$context}")
        ->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertReplyMessage([
            'text' => "Маргарита\n\nСир і томати\n\nЗвичайна ціна: 190.00 UAH\nНаявність: У наявності",
            'reply_markup' => [
                'inline_keyboard' => [
                    [[
                        'text' => '🛒 Додати до кошика',
                        'callback_data' => "cart:add:502:{$context}",
                    ]],
                    [[
                        'text' => '⬅️ Назад',
                        'callback_data' => "category:7:{$context}",
                    ]],
                ],
            ],
        ], index: 1, forceMethod: 'editMessageText');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://ordering-backend.test/api/restaurants/pizza-house/categories/7/products');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://ordering-backend.test/api/restaurants/pizza-house/products/502');
    Http::assertSentCount(4);
});

test('main menu callback validates context and renders context-aware Ukrainian buttons', function () {
    $sessionToken = str_repeat('m', 64);
    storeCatalogSession($sessionToken);
    $context = catalogContext($sessionToken);

    catalogBot()
        ->hearCallbackQueryData("main_menu:{$context}")
        ->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertReplyMessage([
            'text' => 'Вітаємо! Оберіть дію:',
            'reply_markup' => mainMenuKeyboard($context),
        ], index: 1, forceMethod: 'editMessageText');

    Http::assertNothingSent();
});

test('stale and unknown restaurant callbacks do not call catalog endpoints', function (string $case) {
    $oldToken = str_repeat('o', 64);
    $newToken = str_repeat('n', 64);
    $restaurantId = $case === 'unknown' ? 999 : 10;
    $context = $case === 'unknown'
        ? app(RestaurantNavigationContext::class)->encode($restaurantId, $newToken)
        : app(RestaurantNavigationContext::class)->encode($restaurantId, $oldToken);
    storeCatalogSession($newToken);

    Http::fake([
        'ordering-backend.test/api/sessions/current/restaurants' => Http::response(['data' => [catalogRestaurant()]]),
    ]);

    catalogBot()
        ->hearCallbackQueryData("catalog:{$context}")
        ->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertReplyMessage([
            'text' => RestaurantNavigationContext::STALE_MESSAGE,
        ], index: 1, forceMethod: 'editMessageText');

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/restaurants/pizza-house/categories'));

    if ($case === 'stale') {
        Http::assertNothingSent();
    } else {
        Http::assertSentCount(1);
    }
})->with(['stale', 'unknown']);

function catalogBot(): FakeNutgram
{
    /** @var FakeNutgram $bot */
    $bot = app(Nutgram::class);

    return $bot
        ->setCommonChat(Chat::make(id: 123456, type: ChatType::PRIVATE))
        ->setCommonUser(User::make(id: 654321, is_bot: false, first_name: 'Test'));
}

function storeCatalogSession(string $sessionToken): void
{
    app(TelegramSessionStore::class)->put('telegram-chat-123456', $sessionToken);
}

function catalogContext(string $sessionToken, int $restaurantId = 10): string
{
    return app(RestaurantNavigationContext::class)->encode($restaurantId, $sessionToken);
}

/** @return array<string, mixed> */
function catalogRestaurant(array $overrides = []): array
{
    return array_replace([
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
    ], $overrides);
}

/** @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>} */
function mainMenuKeyboard(string $context): array
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
