<?php

use GuzzleHttp\Psr7\Request as TelegramRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;

beforeEach(function () {
    config()->set('services.ordering_backend.url', 'http://ordering-backend.test');
    config()->set('services.ordering_backend.token', 'internal-api-secret');
    config()->set('services.ordering_backend.restaurant_slug', 'test-restaurant');
    config()->set('services.ordering_backend.timeout', 7);

    Http::preventStrayRequests();
});

test('the catalog callback requests and renders backend categories', function () {
    Http::fake([
        'ordering-backend.test/api/restaurants/test-restaurant/categories' => Http::response([
            'data' => [
                [
                    'id' => 37,
                    'name' => 'Пицца',
                ],
                [
                    'id' => 41,
                    'name' => 'Напитки',
                ],
            ],
        ]),
    ]);

    catalogTelegramBot()
        ->hearCallbackQueryData('catalog')
        ->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertCalled('editMessageText')
        ->assertReplyMessage([
            'text' => 'Категории',
            'reply_markup' => catalogCategoriesKeyboard(),
        ], index: 1, forceMethod: 'editMessageText')
        ->assertRaw(function (TelegramRequest $request): bool {
            expect((string) $request->getBody())
                ->not->toContain('internal-api-secret')
                ->not->toContain('X-Session-Token');

            return true;
        }, index: 1);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'http://ordering-backend.test/api/restaurants/test-restaurant/categories'
        && $request->hasHeader('X-Internal-Api-Token', 'internal-api-secret')
        && ! $request->hasHeader('X-Session-Token'));
    Http::assertSentCount(1);
});

test('an empty category list renders safe navigation', function () {
    Http::fake([
        'ordering-backend.test/api/restaurants/test-restaurant/categories' => Http::response([
            'data' => [],
        ]),
    ]);

    catalogTelegramBot()
        ->hearCallbackQueryData('catalog')
        ->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertReplyMessage([
            'text' => 'Категории пока недоступны.',
            'reply_markup' => [
                'inline_keyboard' => [
                    [[
                        'text' => '⬅️ Главное меню',
                        'callback_data' => 'main_menu',
                    ]],
                ],
            ],
        ], index: 1, forceMethod: 'editMessageText');
});

test('a category callback uses its local id and renders backend prices', function () {
    Http::fake([
        'ordering-backend.test/api/restaurants/test-restaurant/categories/37/products' => Http::response([
            'data' => [
                catalogTelegramProduct([
                    'id' => 501,
                    'name' => 'Pepperoni',
                    'promotion_price' => null,
                ]),
                catalogTelegramProduct(),
            ],
        ]),
    ]);

    catalogTelegramBot()
        ->hearCallbackQueryData('category:37')
        ->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertReplyMessage([
            'text' => 'Товары категории',
            'reply_markup' => [
                'inline_keyboard' => [
                    [[
                        'text' => 'Pepperoni — 220.00 UAH',
                        'callback_data' => 'product:37:501',
                    ]],
                    [[
                        'text' => 'Margherita — 190.00 UAH',
                        'callback_data' => 'product:37:502',
                    ]],
                    [[
                        'text' => '⬅️ Категории',
                        'callback_data' => 'catalog',
                    ]],
                ],
            ],
        ], index: 1, forceMethod: 'editMessageText');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'http://ordering-backend.test/api/restaurants/test-restaurant/categories/37/products'
        && $request->hasHeader('X-Internal-Api-Token', 'internal-api-secret')
        && ! $request->hasHeader('X-Session-Token'));
    Http::assertSentCount(1);
});

test('an empty product list renders a categories back button', function () {
    Http::fake([
        'ordering-backend.test/api/restaurants/test-restaurant/categories/37/products' => Http::response([
            'data' => [],
        ]),
    ]);

    catalogTelegramBot()
        ->hearCallbackQueryData('category:37')
        ->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertReplyMessage([
            'text' => 'В этой категории пока нет товаров.',
            'reply_markup' => [
                'inline_keyboard' => [
                    [[
                        'text' => '⬅️ Категории',
                        'callback_data' => 'catalog',
                    ]],
                ],
            ],
        ], index: 1, forceMethod: 'editMessageText');
});

test('a product callback renders backend details and stateless navigation ids', function () {
    Http::fake([
        'ordering-backend.test/api/restaurants/test-restaurant/products/502' => Http::response([
            'data' => catalogTelegramProduct(),
        ]),
    ]);

    catalogTelegramBot()
        ->hearCallbackQueryData('product:37:502')
        ->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertReplyMessage([
            'text' => "Margherita\n\nTomato, mozzarella and basil\n\nОбычная цена: 220.00 UAH\nАкционная цена: 190.00 UAH\nДоступность: В наличии",
            'reply_markup' => [
                'inline_keyboard' => [
                    [[
                        'text' => '🛒 Добавить в корзину',
                        'callback_data' => 'cart:add:502',
                    ]],
                    [[
                        'text' => '⬅️ Назад',
                        'callback_data' => 'category:37',
                    ]],
                ],
            ],
        ], index: 1, forceMethod: 'editMessageText');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'http://ordering-backend.test/api/restaurants/test-restaurant/products/502'
        && $request->hasHeader('X-Internal-Api-Token', 'internal-api-secret')
        && ! $request->hasHeader('X-Session-Token'));
    Http::assertSentCount(1);
});

test('main menu navigation reuses the existing keyboard without a backend request', function () {
    catalogTelegramBot()
        ->hearCallbackQueryData('main_menu')
        ->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertReplyMessage([
            'text' => 'Приветствую! Выберите действие:',
            'reply_markup' => [
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
            ],
        ], index: 1, forceMethod: 'editMessageText');

    Http::assertNothingSent();
});

test('backend catalog failures render safe Telegram messages', function (string $failure, string $expectedMessage) {
    $url = 'ordering-backend.test/api/restaurants/test-restaurant/categories';

    if ($failure === 'connection') {
        Http::fake([$url => Http::failedConnection()]);
    } elseif ($failure === 'malformed') {
        Http::fake([$url => Http::response(['data' => ['raw' => 'malformed-backend-detail']])]);
    } else {
        Http::fake([$url => Http::response([
            'message' => 'raw-backend-error-detail',
        ], (int) $failure)]);
    }

    catalogTelegramBot()
        ->hearCallbackQueryData('catalog')
        ->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertReplyMessage([
            'text' => $expectedMessage,
        ], index: 1, forceMethod: 'editMessageText')
        ->assertRaw(function (TelegramRequest $request): bool {
            expect((string) $request->getBody())
                ->not->toContain('raw-backend-error-detail')
                ->not->toContain('malformed-backend-detail')
                ->not->toContain('internal-api-secret');

            return true;
        }, index: 1);
})->with([
    'unauthorized' => ['401', 'Сервис каталога временно недоступен. Попробуйте снова позже.'],
    'not found' => ['404', 'Каталог не найден.'],
    'unavailable' => ['connection', 'Сервис каталога временно недоступен. Попробуйте снова позже.'],
    'malformed response' => ['malformed', 'Сервис каталога временно недоступен. Попробуйте снова позже.'],
]);

function catalogTelegramBot(): FakeNutgram
{
    /** @var FakeNutgram $bot */
    $bot = app(Nutgram::class);

    return $bot
        ->setCommonChat(Chat::make(id: 123456, type: ChatType::PRIVATE))
        ->setCommonUser(User::make(id: 654321, is_bot: false, first_name: 'Test'));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array{id: int, external_id: string, name: string, description: string, price: string, promotion_price: ?string, currency: string, image_url: string, is_available: bool, sort_order: int}
 */
function catalogTelegramProduct(array $overrides = []): array
{
    return array_replace([
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
    ], $overrides);
}

/** @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>} */
function catalogCategoriesKeyboard(): array
{
    return [
        'inline_keyboard' => [
            [[
                'text' => 'Пицца',
                'callback_data' => 'category:37',
            ]],
            [[
                'text' => 'Напитки',
                'callback_data' => 'category:41',
            ]],
            [[
                'text' => '⬅️ Главное меню',
                'callback_data' => 'main_menu',
            ]],
        ],
    ];
}
