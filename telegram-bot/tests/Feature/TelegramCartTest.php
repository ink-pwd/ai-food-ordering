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

test('cart menu validates restaurant context and renders authoritative current cart', function () {
    $sessionToken = str_repeat('7', 64);
    storeCartSession($sessionToken);
    $context = cartContext($sessionToken);

    Http::fake(cartHttpFakes([
        'ordering-backend.test/api/carts' => Http::response(cartResponse(), 201),
        'ordering-backend.test/api/carts/current' => Http::response(cartResponse()),
    ]));

    cartBot()
        ->hearCallbackQueryData("menu:cart:{$context}")
        ->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertReplyMessage([
            'text' => "🛒 Кошик\n\nКошик порожній.\n\nПроміжний підсумок: 0.00 UAH\nРазом: 0.00 UAH",
            'reply_markup' => cartKeyboard([], $context),
        ], index: 1, forceMethod: 'editMessageText');

    Http::assertSentInOrder([
        fn (Request $request): bool => $request->url() === 'http://ordering-backend.test/api/sessions/current/restaurants',
        fn (Request $request): bool => $request->method() === 'POST' && $request->url() === 'http://ordering-backend.test/api/carts',
        fn (Request $request): bool => $request->method() === 'GET' && $request->url() === 'http://ordering-backend.test/api/carts/current',
    ]);
    Http::assertSentCount(3);
});

test('add to cart mutates once and retains context', function () {
    $sessionToken = str_repeat('8', 64);
    storeCartSession($sessionToken);
    $context = cartContext($sessionToken);

    Http::fake(cartHttpFakes([
        'ordering-backend.test/api/carts' => Http::response(cartResponse()),
        'ordering-backend.test/api/carts/current' => Http::response(cartResponse()),
        'ordering-backend.test/api/carts/current/items' => Http::response(cartResponse(['items' => [cartItem()]], '190.00'), 201),
    ]));

    cartBot()
        ->hearCallbackQueryData("cart:add:502:{$context}")
        ->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertReplyMessage(['reply_markup' => cartKeyboard([cartItem()], $context)], index: 1, forceMethod: 'editMessageText');

    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://ordering-backend.test/api/carts/current/items'
        && $request->data() === ['product_id' => 502, 'quantity' => 1]))->toHaveCount(1);
});

test('increment decrement and remove mutate once from authoritative backend cart and retain context', function () {
    $sessionToken = str_repeat('8', 64);
    storeCartSession($sessionToken);
    $context = cartContext($sessionToken);

    Http::fake(cartHttpFakes([
        'ordering-backend.test/api/carts/current' => Http::sequence()
            ->push(cartResponse(['items' => [cartItem(['quantity' => 1])]], '190.00'))
            ->push(cartResponse(['items' => [cartItem(['quantity' => 2, 'total' => '380.00'])]], '380.00'))
            ->push(cartResponse(['items' => [cartItem()]], '190.00'))
            ->push(cartResponse()),
        'ordering-backend.test/api/carts/current/items/701' => Http::sequence()
            ->push(cartResponse(['items' => [cartItem(['quantity' => 2, 'total' => '380.00'])]], '380.00'))
            ->push(cartResponse(['items' => [cartItem(['quantity' => 1])]], '190.00'))
            ->push(cartResponse(['items' => [cartItem()]], '190.00')),
    ]));

    cartBot()->hearCallbackQueryData("cart:inc:701:{$context}")->reply()->assertCalled('answerCallbackQuery', 1);
    cartBot()->hearCallbackQueryData("cart:dec:701:{$context}")->reply()->assertCalled('answerCallbackQuery', 1);
    cartBot()->hearCallbackQueryData("cart:remove:701:{$context}")->reply()->assertCalled('answerCallbackQuery', 1);

    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'PATCH'
        && $request->url() === 'http://ordering-backend.test/api/carts/current/items/701'
        && $request->data() === ['quantity' => 2]))->toHaveCount(1);
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'PATCH'
        && $request->url() === 'http://ordering-backend.test/api/carts/current/items/701'
        && $request->data() === ['quantity' => 1]))->toHaveCount(1);
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'http://ordering-backend.test/api/carts/current/items/701'))->toHaveCount(1);
});

test('clear confirmation cancel and confirm preserve context and clear only valid current session', function () {
    $sessionToken = str_repeat('9', 64);
    storeCartSession($sessionToken);
    $context = cartContext($sessionToken);
    $item = cartItem();

    Http::fake(cartHttpFakes([
        'ordering-backend.test/api/carts/current' => Http::response(cartResponse(['items' => [$item]], '190.00')),
        'ordering-backend.test/api/carts/current/items' => Http::response(cartResponse()),
    ]));

    cartBot()
        ->hearCallbackQueryData("cart:clear:{$context}")
        ->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertReplyMessage([
            'text' => 'Очистити весь кошик?',
            'reply_markup' => [
                'inline_keyboard' => [
                    [['text' => '✅ Так, очистити', 'callback_data' => "cart:clear:confirm:{$context}"]],
                    [['text' => '❌ Скасувати', 'callback_data' => "cart:clear:cancel:{$context}"]],
                ],
            ],
        ], index: 1, forceMethod: 'editMessageText');

    cartBot()->hearCallbackQueryData("cart:clear:cancel:{$context}")->reply();
    cartBot()->hearCallbackQueryData("cart:clear:confirm:{$context}")->reply();

    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'http://ordering-backend.test/api/carts/current/items'))->toHaveCount(1);
});

test('stale clear confirmation cannot clear a new session cart', function () {
    $oldToken = str_repeat('o', 64);
    $newToken = str_repeat('n', 64);
    $context = cartContext($oldToken);
    storeCartSession($newToken);

    cartBot()
        ->hearCallbackQueryData("cart:clear:confirm:{$context}")
        ->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertReplyMessage([
            'text' => RestaurantNavigationContext::STALE_MESSAGE,
        ], index: 1, forceMethod: 'editMessageText');

    Http::assertNothingSent();
});

test('cart callbacks are compact and contain no secrets or slug data', function () {
    $sessionToken = str_repeat('s', 64);
    $context = cartContext($sessionToken, 123456789);
    $callbacks = [
        "menu:cart:{$context}",
        "cart:add:987654321:{$context}",
        "cart:inc:987654321:{$context}",
        "cart:dec:987654321:{$context}",
        "cart:remove:987654321:{$context}",
        "cart:clear:{$context}",
        "cart:clear:confirm:{$context}",
        "cart:clear:cancel:{$context}",
        "checkout:{$context}",
    ];

    foreach ($callbacks as $callback) {
        expect(strlen($callback))->toBeLessThanOrEqual(64)
            ->and($callback)->not->toContain($sessionToken)
            ->and($callback)->not->toContain('internal-api-secret')
            ->and($callback)->not->toContain('pizza-house')
            ->and($callback)->not->toContain(hash('sha256', $sessionToken));
    }
});

function cartBot(): FakeNutgram
{
    /** @var FakeNutgram $bot */
    $bot = app(Nutgram::class);

    return $bot
        ->setCommonChat(Chat::make(id: 123456, type: ChatType::PRIVATE))
        ->setCommonUser(User::make(id: 654321, is_bot: false, first_name: 'Test'));
}

function storeCartSession(string $sessionToken): void
{
    app(TelegramSessionStore::class)->put('telegram-chat-123456', $sessionToken);
}

function cartContext(string $sessionToken, int $restaurantId = 10): string
{
    return app(RestaurantNavigationContext::class)->encode($restaurantId, $sessionToken);
}

/** @param array<string, mixed> $fakes */
function cartHttpFakes(array $fakes): array
{
    return ['ordering-backend.test/api/sessions/current/restaurants' => Http::response(['data' => [cartRestaurant()]])] + $fakes;
}

/** @return array<string, mixed> */
function cartRestaurant(): array
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
function cartResponse(array $overrides = [], string $total = '0.00'): array
{
    return [
        'data' => array_replace([
            'id' => 91,
            'status' => 'active',
            'currency' => 'UAH',
            'subtotal' => $total,
            'total' => $total,
            'expires_at' => '2026-08-12T12:00:00+00:00',
            'items' => [],
        ], $overrides),
    ];
}

/** @param array<string, mixed> $overrides */
function cartItem(array $overrides = []): array
{
    return array_replace([
        'id' => 701,
        'product_id' => 502,
        'external_product_id' => 'external-product-id',
        'name' => 'Маргарита',
        'quantity' => 1,
        'unit_price' => '190.00',
        'total' => '190.00',
    ], $overrides);
}

/** @param list<array<string, mixed>> $items */
function cartKeyboard(array $items, string $context): array
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
