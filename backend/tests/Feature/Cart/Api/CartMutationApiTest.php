<?php

use App\Enums\CartStatus;
use App\Enums\SessionChannel;
use App\Enums\SessionStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Restaurant;
use App\Services\Repositories\SessionRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config()->set('services.internal.token', 'test-internal-token');
    config()->set('services.internal.session_store', 'array');
    config()->set('services.internal.session_ttl_seconds', 60);
    config()->set('services.internal.session_key_prefix', 'test-cart-mutation-session');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('registers all mutation routes with the expected middleware order', function () {
    $routes = [
        'internal.carts.items.update' => ['PATCH', 'api/carts/current/items/{item}'],
        'internal.carts.items.destroy' => ['DELETE', 'api/carts/current/items/{item}'],
        'internal.carts.items.clear' => ['DELETE', 'api/carts/current/items'],
    ];

    foreach ($routes as $name => [$method, $uri]) {
        $route = Route::getRoutes()->getByName($name);

        expect($route)->not->toBeNull()
            ->and($route->uri())->toBe($uri)
            ->and($route->methods())->toContain($method)
            ->and(array_values(array_intersect(
                $route->gatherMiddleware(),
                ['internal.api', 'internal.session'],
            )))->toBe([
                'internal.api',
                'internal.session',
            ]);
    }
});

it('updates item quantity through the api', function () {
    [$restaurant, $cart, $product, $item] = cartMutationApiContext([
        'price' => '99.50',
        'promotion_price' => null,
    ], [
        'quantity' => 1,
        'unit_price' => '99.50',
        'total' => '99.50',
    ]);

    $cart->update([
        'subtotal' => '99.50',
        'total' => '99.50',
    ]);

    authorizedCartMutationPatch($item->id, [
        'quantity' => 3,
    ])->assertOk()
        ->assertJsonPath('data.id', $cart->id)
        ->assertJsonPath('data.items.0.product_id', $product->id)
        ->assertJsonPath('data.items.0.quantity', 3)
        ->assertJsonPath('data.items.0.unit_price', '99.50')
        ->assertJsonPath('data.items.0.total', '298.50')
        ->assertJsonPath('data.subtotal', '298.50')
        ->assertJsonPath('data.total', '298.50');
});

it('uses server price when updating an item', function () {
    [, $cart, $product, $item] = cartMutationApiContext([
        'price' => '150.00',
        'promotion_price' => '125.25',
    ]);

    authorizedCartMutationPatch($item->id, [
        'quantity' => 2,
    ])->assertOk()
        ->assertJsonPath('data.items.0.unit_price', '125.25')
        ->assertJsonPath('data.items.0.total', '250.50')
        ->assertJsonPath('data.total', '250.50');

    expect($cart->fresh()->total)->toBe('250.50');
});

it('validates update quantity', function (array $payload) {
    [, , , $item] = cartMutationApiContext();

    authorizedCartMutationPatch(
        $item->id,
        $payload,
    )->assertUnprocessable()
        ->assertJsonValidationErrors(['quantity']);
})->with([
    'missing' => [[]],
    'zero' => [['quantity' => 0]],
    'negative' => [['quantity' => -1]],
    'string' => [['quantity' => 'three']],
]);

it('rejects client controlled values during update', function (string $field, mixed $value) {
    [, , , $item] = cartMutationApiContext();

    authorizedCartMutationPatch($item->id, [
        'quantity' => 2,
        $field => $value,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'product id' => ['product_id', 999],
    'cart id' => ['cart_id', 999],
    'restaurant id' => ['restaurant_id', 999],
    'session id' => ['session_id', 'client-session'],
    'external product id' => [
        'external_product_id',
        '11111111-1111-1111-1111-111111111111',
    ],
    'unit price' => ['unit_price', '0.01'],
    'price' => ['price', '0.01'],
    'total' => ['total', '0.01'],
    'subtotal' => ['subtotal', '0.01'],
    'currency' => ['currency', 'USD'],
    'status' => ['status', 'checked_out'],
]);

it('returns not found when updating an item outside the current cart', function () {
    cartMutationApiContext();

    $foreignCart = Cart::factory()->create();

    $foreignProduct = Product::factory()->create([
        'restaurant_id' => $foreignCart->restaurant_id,
    ]);

    $foreignItem = CartItem::factory()->create([
        'cart_id' => $foreignCart->id,
        'product_id' => $foreignProduct->id,
        'external_product_id' => $foreignProduct->external_id,
        'quantity' => 1,
    ]);

    authorizedCartMutationPatch($foreignItem->id, [
        'quantity' => 2,
    ])->assertNotFound();

    expect($foreignItem->fresh()->quantity)->toBe(1);
});

it('removes one item through the api and recalculates totals', function () {
    [$restaurant, $cart, $product, $item] = cartMutationApiContext([
        'price' => '100.00',
    ], [
        'quantity' => 1,
        'unit_price' => '100.00',
        'total' => '100.00',
    ]);

    $secondProduct = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'price' => '50.00',
        'is_available' => true,
    ]);

    $secondItem = CartItem::factory()->create([
        'cart_id' => $cart->id,
        'product_id' => $secondProduct->id,
        'external_product_id' => $secondProduct->external_id,
        'quantity' => 1,
        'unit_price' => '50.00',
        'total' => '50.00',
    ]);

    $cart->update([
        'subtotal' => '150.00',
        'total' => '150.00',
    ]);

    authorizedCartMutationDeleteItem($item->id)
        ->assertOk()
        ->assertJsonPath('data.subtotal', '50.00')
        ->assertJsonPath('data.total', '50.00')
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.id', $secondItem->id);

    expect(CartItem::query()->whereKey($item->id)->exists())->toBeFalse();
});

it('clears the current cart through the api', function () {
    [$restaurant, $cart] = cartMutationApiContext();

    $secondProduct = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
    ]);

    CartItem::factory()->create([
        'cart_id' => $cart->id,
        'product_id' => $secondProduct->id,
        'external_product_id' => $secondProduct->external_id,
    ]);

    $cart->update([
        'subtotal' => '200.00',
        'total' => '200.00',
    ]);

    authorizedCartMutationClear()
        ->assertOk()
        ->assertJsonPath('data.items', [])
        ->assertJsonPath('data.subtotal', '0.00')
        ->assertJsonPath('data.total', '0.00');

    expect($cart->items()->count())->toBe(0);
});

it('allows clearing an already empty cart', function () {
    [, $cart] = cartMutationApiContext();

    $cart->items()->delete();

    authorizedCartMutationClear()
        ->assertOk()
        ->assertJsonPath('data.items', [])
        ->assertJsonPath('data.total', '0.00');
});

it('rejects mutations for a checked out cart', function () {
    [, $cart, , $item] = cartMutationApiContext();

    $cart->update([
        'status' => CartStatus::CheckedOut,
    ]);

    authorizedCartMutationPatch($item->id, [
        'quantity' => 2,
    ])->assertConflict();

    authorizedCartMutationDeleteItem(
        $item->id,
    )->assertConflict();

    authorizedCartMutationClear()
        ->assertConflict();

    expect($item->fresh())->not->toBeNull();
});

it('requires the internal api token for cart mutations', function () {
    [, , , $item] = cartMutationApiContext();

    $this->withHeader(
        'X-Session-Token',
        cartMutationApiToken(),
    )->patchJson(
        route('internal.carts.items.update', [
            'item' => $item->id,
        ]),
        ['quantity' => 2],
    )->assertUnauthorized()
        ->assertExactJson([
            'message' => 'Unauthenticated.',
        ]);
});

it('requires the session token for cart mutations', function () {
    [, , , $item] = cartMutationApiContext();

    $this->withHeader(
        'X-Internal-Api-Token',
        'test-internal-token',
    )->patchJson(
        route('internal.carts.items.update', [
            'item' => $item->id,
        ]),
        ['quantity' => 2],
    )->assertUnauthorized()
        ->assertExactJson([
            'message' => 'Unauthenticated.',
        ]);
});

it('does not modify session metadata during cart mutations', function () {
    [, , , $item] = cartMutationApiContext(
        sessionOverrides: [
            'metadata' => [
                'contact' => [
                    'name' => 'Yehor',
                    'phone' => '+380501234567',
                ],
            ],
        ],
    );

    authorizedCartMutationPatch($item->id, [
        'quantity' => 2,
    ])->assertOk();

    expect(
        app(SessionRepository::class)
            ->findByToken(cartMutationApiToken())['metadata'],
    )->toBe([
        'contact' => [
            'name' => 'Yehor',
            'phone' => '+380501234567',
        ],
    ]);
});

it('performs no dots requests or queue jobs during mutations', function () {
    Http::fake();
    Queue::fake();

    [, , , $item] = cartMutationApiContext();

    authorizedCartMutationPatch($item->id, [
        'quantity' => 2,
    ])->assertOk();

    authorizedCartMutationDeleteItem(
        $item->id,
    )->assertOk();

    authorizedCartMutationClear()
        ->assertOk();

    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

/**
 * @param  array<string, mixed>  $productAttributes
 * @param  array<string, mixed>  $itemAttributes
 * @param  array<string, mixed>  $sessionOverrides
 * @return array{0: Restaurant, 1: Cart, 2: Product, 3: CartItem}
 */
function cartMutationApiContext(
    array $productAttributes = [],
    array $itemAttributes = [],
    array $sessionOverrides = [],
): array {
    $restaurant = Restaurant::factory()->create([
        'is_active' => true,
        'currency' => 'UAH',
    ]);

    $session = cartMutationApiSession(array_replace([
        'restaurant_id' => $restaurant->id,
    ], $sessionOverrides));

    app(SessionRepository::class)->put(
        cartMutationApiToken(),
        $session,
    );

    $cart = Cart::factory()->create([
        'restaurant_id' => $restaurant->id,
        'session_id' => $session['id'],
        'status' => CartStatus::Active,
        'currency' => 'UAH',
        'subtotal' => '100.00',
        'total' => '100.00',
        'expires_at' => now()->addHour(),
    ]);

    $product = Product::factory()->create(array_merge([
        'restaurant_id' => $restaurant->id,
        'price' => '100.00',
        'promotion_price' => null,
        'is_available' => true,
    ], $productAttributes));

    $item = CartItem::factory()->create(array_merge([
        'cart_id' => $cart->id,
        'product_id' => $product->id,
        'external_product_id' => $product->external_id,
        'quantity' => 1,
        'unit_price' => '100.00',
        'total' => '100.00',
    ], $itemAttributes));

    return [$restaurant, $cart, $product, $item];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function cartMutationApiSession(array $overrides = []): array
{
    return array_replace([
        'id' => '01JZXYZSESSION000000000401',
        'restaurant_id' => 1,
        'channel' => SessionChannel::ChatGPT->value,
        'external_session_id' => 'cart-mutation-api',
        'status' => SessionStatus::Active->value,
        'metadata' => [],
        'created_at' => now()->subMinute()->toIso8601String(),
        'expires_at' => now()->addHour()->toIso8601String(),
    ], $overrides);
}

function authorizedCartMutationPatch(
    int $itemId,
    array $payload,
): TestResponse {
    return test()->withHeaders(cartMutationApiHeaders())
        ->patchJson(
            route('internal.carts.items.update', [
                'item' => $itemId,
            ]),
            $payload,
        );
}

function authorizedCartMutationDeleteItem(
    int $itemId,
): TestResponse {
    return test()->withHeaders(cartMutationApiHeaders())
        ->deleteJson(
            route('internal.carts.items.destroy', [
                'item' => $itemId,
            ]),
        );
}

function authorizedCartMutationClear(): TestResponse
{
    return test()->withHeaders(cartMutationApiHeaders())
        ->deleteJson(
            route('internal.carts.items.clear'),
        );
}

/**
 * @return array<string, string>
 */
function cartMutationApiHeaders(): array
{
    return [
        'X-Internal-Api-Token' => 'test-internal-token',
        'X-Session-Token' => cartMutationApiToken(),
    ];
}

function cartMutationApiToken(): string
{
    return str_repeat('c', 64);
}
