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
    config()->set('services.internal.session_key_prefix', 'test-cart-item-session');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('registers the cart item store route with exact method uri name and middleware order', function () {
    $route = Route::getRoutes()->getByName('internal.carts.items.store');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('api/carts/current/items')
        ->and($route->methods())->toContain('POST')
        ->and(array_values(array_intersect(
            $route->gatherMiddleware(),
            ['internal.api', 'internal.session'],
        )))->toBe(['internal.api', 'internal.session']);
});

it('adds an available product to the current cart', function () {
    [$restaurant, $cart, $product] = cartItemApiContext([
        'price' => '99.00',
        'promotion_price' => null,
        'name' => 'Pepperoni Pizza',
    ]);

    authorizedCartItemRequest([
        'product_id' => $product->id,
        'quantity' => 2,
    ])->assertCreated()
        ->assertJsonPath('data.id', $cart->id)
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.currency', $restaurant->currency)
        ->assertJsonPath('data.subtotal', '198.00')
        ->assertJsonPath('data.total', '198.00')
        ->assertJsonPath('data.items.0.product_id', $product->id)
        ->assertJsonPath('data.items.0.external_product_id', $product->external_id)
        ->assertJsonPath('data.items.0.name', 'Pepperoni Pizza')
        ->assertJsonPath('data.items.0.quantity', 2)
        ->assertJsonPath('data.items.0.unit_price', '99.00')
        ->assertJsonPath('data.items.0.total', '198.00');

    expect(CartItem::query()->count())->toBe(1);
});

it('uses promotion price instead of regular price', function () {
    [, , $product] = cartItemApiContext([
        'price' => '150.00',
        'promotion_price' => '119.50',
    ]);

    authorizedCartItemRequest([
        'product_id' => $product->id,
        'quantity' => 2,
    ])->assertCreated()
        ->assertJsonPath('data.items.0.unit_price', '119.50')
        ->assertJsonPath('data.items.0.total', '239.00')
        ->assertJsonPath('data.subtotal', '239.00')
        ->assertJsonPath('data.total', '239.00');
});

it('recalculates cart totals after adding multiple products', function () {
    [$restaurant, $cart, $firstProduct] = cartItemApiContext([
        'price' => '100.25',
        'promotion_price' => null,
    ]);

    $secondProduct = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'price' => '49.75',
        'promotion_price' => null,
        'is_available' => true,
    ]);

    authorizedCartItemRequest([
        'product_id' => $firstProduct->id,
        'quantity' => 2,
    ])->assertCreated();

    authorizedCartItemRequest([
        'product_id' => $secondProduct->id,
        'quantity' => 1,
    ])->assertCreated()
        ->assertJsonPath('data.subtotal', '250.25')
        ->assertJsonPath('data.total', '250.25');

    expect($cart->fresh()->subtotal)->toBe('250.25')
        ->and($cart->fresh()->total)->toBe('250.25')
        ->and($cart->items()->count())->toBe(2);
});

it('rejects a duplicate product without changing the existing item', function () {
    [, $cart, $product] = cartItemApiContext([
        'price' => '100.00',
        'promotion_price' => null,
    ]);

    authorizedCartItemRequest([
        'product_id' => $product->id,
        'quantity' => 2,
    ])->assertCreated();

    authorizedCartItemRequest([
        'product_id' => $product->id,
        'quantity' => 5,
    ])->assertConflict();

    $item = CartItem::query()->sole();

    expect($item->quantity)->toBe(2)
        ->and($item->unit_price)->toBe('100.00')
        ->and($item->total)->toBe('200.00')
        ->and($cart->fresh()->subtotal)->toBe('200.00')
        ->and($cart->fresh()->total)->toBe('200.00');
});

it('rejects unavailable products', function () {
    [, $cart, $product] = cartItemApiContext([
        'is_available' => false,
    ]);

    authorizedCartItemRequest([
        'product_id' => $product->id,
        'quantity' => 1,
    ])->assertNotFound();

    expect($cart->items()->count())->toBe(0);
});

it('rejects a product belonging to another restaurant', function () {
    [, $cart] = cartItemApiContext();

    $otherRestaurant = Restaurant::factory()->create();

    $foreignProduct = Product::factory()->create([
        'restaurant_id' => $otherRestaurant->id,
        'is_available' => true,
    ]);

    authorizedCartItemRequest([
        'product_id' => $foreignProduct->id,
        'quantity' => 1,
    ])->assertNotFound();

    expect($cart->items()->count())->toBe(0);
});

it('rejects a missing cart', function () {
    $restaurant = Restaurant::factory()->create([
        'is_active' => true,
    ]);

    storeCartItemApiSession([
        'restaurant_id' => $restaurant->id,
    ]);

    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_available' => true,
    ]);

    authorizedCartItemRequest([
        'product_id' => $product->id,
        'quantity' => 1,
    ])->assertNotFound();

    expect(CartItem::query()->count())->toBe(0);
});

it('does not add items to a checked out historical cart', function () {
    [, $cart, $product] = cartItemApiContext();

    $cart->update([
        'status' => CartStatus::CheckedOut,
    ]);

    authorizedCartItemRequest([
        'product_id' => $product->id,
        'quantity' => 1,
    ])->assertNotFound();

    expect($cart->items()->count())->toBe(0);
});

it('rejects an expired cart', function () {
    [, $cart, $product] = cartItemApiContext();

    $cart->update([
        'expires_at' => now()->subSecond(),
    ]);

    authorizedCartItemRequest([
        'product_id' => $product->id,
        'quantity' => 1,
    ])->assertConflict();

    expect($cart->items()->count())->toBe(0);
});

it('validates product id and quantity', function (array $payload, array $errors) {
    cartItemApiContext();

    authorizedCartItemRequest($payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errors);

    expect(CartItem::query()->count())->toBe(0);
})->with([
    'missing product id' => [
        ['quantity' => 1],
        ['product_id'],
    ],
    'missing quantity' => [
        ['product_id' => 1],
        ['quantity'],
    ],
    'zero product id' => [
        ['product_id' => 0, 'quantity' => 1],
        ['product_id'],
    ],
    'zero quantity' => [
        ['product_id' => 1, 'quantity' => 0],
        ['quantity'],
    ],
    'negative quantity' => [
        ['product_id' => 1, 'quantity' => -1],
        ['quantity'],
    ],
    'non integer quantity' => [
        ['product_id' => 1, 'quantity' => 'two'],
        ['quantity'],
    ],
]);

it('rejects client controlled cart and pricing fields', function (string $field, mixed $value) {
    [, $cart, $product] = cartItemApiContext();

    authorizedCartItemRequest([
        'product_id' => $product->id,
        'quantity' => 1,
        $field => $value,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect($cart->items()->count())->toBe(0)
        ->and($cart->fresh()->subtotal)->toBe('0.00')
        ->and($cart->fresh()->total)->toBe('0.00');
})->with([
    'cart id' => ['cart_id', 999],
    'restaurant id' => ['restaurant_id', 999],
    'session id' => ['session_id', 'client-session'],
    'external product id' => ['external_product_id', '11111111-1111-1111-1111-111111111111'],
    'unit price' => ['unit_price', '0.01'],
    'price' => ['price', '0.01'],
    'total' => ['total', '0.01'],
    'subtotal' => ['subtotal', '0.01'],
    'currency' => ['currency', 'USD'],
    'status' => ['status', 'checked_out'],
]);

it('requires the internal api middleware', function () {
    [, , $product] = cartItemApiContext();

    $this->withHeader('X-Session-Token', cartItemApiToken())
        ->postJson(route('internal.carts.items.store'), [
            'product_id' => $product->id,
            'quantity' => 1,
        ])
        ->assertUnauthorized()
        ->assertExactJson([
            'message' => 'Unauthenticated.',
        ]);

    expect(CartItem::query()->count())->toBe(0);
});

it('requires the internal session middleware', function () {
    $restaurant = Restaurant::factory()->create();

    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_available' => true,
    ]);

    $this->withHeader('X-Internal-Api-Token', 'test-internal-token')
        ->postJson(route('internal.carts.items.store'), [
            'product_id' => $product->id,
            'quantity' => 1,
        ])
        ->assertUnauthorized()
        ->assertExactJson([
            'message' => 'Unauthenticated.',
        ]);

    expect(CartItem::query()->count())->toBe(0);
});

it('runs internal api before internal session', function () {
    $this->mock(SessionRepository::class, function ($mock): void {
        $mock->shouldReceive('findByToken')->never();
    });

    $this->withHeaders([
        'X-Internal-Api-Token' => 'wrong-token',
        'X-Session-Token' => cartItemApiToken(),
    ])->postJson(route('internal.carts.items.store'), [
        'product_id' => 1,
        'quantity' => 1,
    ])->assertUnauthorized()
        ->assertExactJson([
            'message' => 'Unauthenticated.',
        ]);
});

it('returns only safe cart and item fields', function () {
    config()->set('services.dots.token', 'dots-public-token');
    config()->set('services.dots.account_token', 'dots-account-token');
    config()->set('services.dots.auth_token', 'dots-auth-token');

    [, , $product] = cartItemApiContext([
        'name' => 'Secret Test Pizza',
        'price' => '100.00',
        'original_payload' => [
            'secret' => 'hidden-original-payload',
        ],
    ]);

    $response = authorizedCartItemRequest([
        'product_id' => $product->id,
        'quantity' => 1,
    ])->assertCreated();

    expect(array_keys($response->json('data')))->toBe([
        'id',
        'status',
        'currency',
        'subtotal',
        'total',
        'expires_at',
        'items',
    ])->and(array_keys($response->json('data.items.0')))->toBe([
        'id',
        'product_id',
        'external_product_id',
        'name',
        'quantity',
        'unit_price',
        'total',
    ])->and($response->getContent())
        ->not->toContain(cartItemApiToken())
        ->not->toContain('session_id')
        ->not->toContain('restaurant_id')
        ->not->toContain('original_payload')
        ->not->toContain('hidden-original-payload')
        ->not->toContain('metadata')
        ->not->toContain('test-internal-token')
        ->not->toContain('dots-public-token')
        ->not->toContain('dots-account-token')
        ->not->toContain('dots-auth-token');
});

it('does not modify session metadata', function () {
    [, , $product] = cartItemApiContext(
        sessionOverrides: [
            'metadata' => [
                'contact' => [
                    'name' => 'Yehor',
                    'phone' => '+380501234567',
                ],
            ],
        ],
    );

    authorizedCartItemRequest([
        'product_id' => $product->id,
        'quantity' => 1,
    ])->assertCreated();

    expect(
        app(SessionRepository::class)
            ->findByToken(cartItemApiToken())['metadata'],
    )->toBe([
        'contact' => [
            'name' => 'Yehor',
            'phone' => '+380501234567',
        ],
    ]);
});

it('uses array sessions and performs no dots requests or queue jobs', function () {
    Http::fake();
    Queue::fake();

    [, , $product] = cartItemApiContext();

    authorizedCartItemRequest([
        'product_id' => $product->id,
        'quantity' => 1,
    ])->assertCreated();

    Http::assertNothingSent();
    Queue::assertNothingPushed();

    expect(config('services.internal.session_store'))->toBe('array');
});

/**
 * @param  array<string, mixed>  $productAttributes
 * @param  array<string, mixed>  $sessionOverrides
 * @return array{0: Restaurant, 1: Cart, 2: Product}
 */
function cartItemApiContext(
    array $productAttributes = [],
    array $sessionOverrides = [],
): array {
    $restaurant = Restaurant::factory()->create([
        'is_active' => true,
        'currency' => 'UAH',
    ]);

    $session = cartItemApiSession(array_replace([
        'restaurant_id' => $restaurant->id,
    ], $sessionOverrides));

    app(SessionRepository::class)->put(
        cartItemApiToken(),
        $session,
    );

    $cart = Cart::factory()->create([
        'restaurant_id' => $restaurant->id,
        'session_id' => $session['id'],
        'status' => CartStatus::Active,
        'currency' => 'UAH',
        'subtotal' => '0.00',
        'total' => '0.00',
        'expires_at' => now()->addHour(),
    ]);

    $product = Product::factory()->create(array_merge([
        'restaurant_id' => $restaurant->id,
        'price' => '100.00',
        'promotion_price' => null,
        'is_available' => true,
    ], $productAttributes));

    return [$restaurant, $cart, $product];
}

function authorizedCartItemRequest(array $payload): TestResponse
{
    return test()->withHeaders([
        'X-Internal-Api-Token' => 'test-internal-token',
        'X-Session-Token' => cartItemApiToken(),
    ])->postJson(
        route('internal.carts.items.store'),
        $payload,
    );
}

/**
 * @param  array<string, mixed>  $overrides
 */
function storeCartItemApiSession(array $overrides = []): void
{
    app(SessionRepository::class)->put(
        cartItemApiToken(),
        cartItemApiSession($overrides),
    );
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function cartItemApiSession(array $overrides = []): array
{
    return array_replace([
        'id' => '01JZXYZSESSION000000000301',
        'restaurant_id' => 1,
        'channel' => SessionChannel::ChatGPT->value,
        'external_session_id' => 'cart-item-external-session',
        'status' => SessionStatus::Active->value,
        'metadata' => [],
        'created_at' => now()->subMinute()->toIso8601String(),
        'expires_at' => now()->addHour()->toIso8601String(),
    ], $overrides);
}

function cartItemApiToken(): string
{
    return str_repeat('d', 64);
}
