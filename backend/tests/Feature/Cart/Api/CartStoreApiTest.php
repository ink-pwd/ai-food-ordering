<?php

use App\Enums\CartStatus;
use App\Enums\SessionChannel;
use App\Enums\SessionStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\RestaurantAddress;
use App\Services\Repositories\SessionRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Carbon::setTestNow('2026-08-07 12:00:00');
    config()->set('services.internal.token', 'test-internal-token');
    config()->set('services.internal.session_store', 'array');
    config()->set('services.internal.session_ttl_seconds', 60);
    config()->set('services.internal.session_key_prefix', 'test-session');
});

afterEach(function () {
    Carbon::setTestNow();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('registers the cart store route with exact method uri name and middleware order', function () {
    $route = Route::getRoutes()->getByName('internal.carts.store');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('api/carts')
        ->and($route->methods())->toContain('POST')
        ->and(array_values(array_intersect($route->gatherMiddleware(), ['internal.api', 'internal.session'])))->toBe(['internal.api', 'internal.session']);
});

it('returns created for a valid api request', function () {
    $restaurant = Restaurant::factory()->create(['currency' => 'UAH']);
    storeCartApiSession(cartApiToken(), ['restaurant_id' => $restaurant->id]);

    authorizedCartRequest()->assertCreated()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.currency', 'UAH')
        ->assertJsonPath('data.items', []);
});

it('returns ok with the same cart id for a repeated valid request', function () {
    $restaurant = Restaurant::factory()->create();
    storeCartApiSession(cartApiToken(), ['restaurant_id' => $restaurant->id]);

    $first = authorizedCartRequest()->assertCreated();
    $second = authorizedCartRequest()->assertOk();

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and(Cart::query()->count())->toBe(1);
});

it('creates a fresh empty cart without changing checked out cart history', function () {
    $restaurant = Restaurant::factory()->create(['currency' => 'UAH']);
    $sessionId = cartApiSession()['id'];
    storeCartApiSession(cartApiToken(), ['restaurant_id' => $restaurant->id]);

    $historicalCart = Cart::factory()->for($restaurant)->create([
        'session_id' => $sessionId,
        'status' => CartStatus::CheckedOut,
        'subtotal' => '75.50',
        'total' => '75.50',
    ]);
    $product = Product::factory()->for($restaurant)->create();
    $historicalItem = CartItem::factory()
        ->for($historicalCart)
        ->for($product)
        ->create([
            'quantity' => 2,
            'unit_price' => '37.75',
            'total' => '75.50',
        ]);

    $response = authorizedCartRequest()
        ->assertCreated()
        ->assertJsonPath('data.status', CartStatus::Active->value)
        ->assertJsonPath('data.subtotal', '0.00')
        ->assertJsonPath('data.total', '0.00')
        ->assertJsonPath('data.items', []);

    $newCart = Cart::query()->findOrFail($response->json('data.id'));

    expect($newCart->id)->not->toBe($historicalCart->id)
        ->and($newCart->status)->toBe(CartStatus::Active)
        ->and($newCart->subtotal)->toBe('0.00')
        ->and($newCart->total)->toBe('0.00')
        ->and($newCart->items)->toBeEmpty()
        ->and($historicalCart->refresh()->status)->toBe(CartStatus::CheckedOut)
        ->and($historicalCart->subtotal)->toBe('75.50')
        ->and($historicalCart->total)->toBe('75.50')
        ->and($historicalItem->refresh()->quantity)->toBe(2)
        ->and($historicalItem->unit_price)->toBe('37.75')
        ->and($historicalItem->total)->toBe('75.50');
});

it('does not reuse an expired cart', function () {
    $restaurant = Restaurant::factory()->create();
    $sessionId = cartApiSession()['id'];
    storeCartApiSession(cartApiToken(), ['restaurant_id' => $restaurant->id]);

    $expiredCart = Cart::factory()->for($restaurant)->create([
        'session_id' => $sessionId,
        'status' => CartStatus::Expired,
        'expires_at' => now()->subMinute(),
    ]);

    $response = authorizedCartRequest()
        ->assertCreated()
        ->assertJsonPath('data.status', CartStatus::Active->value);

    expect($response->json('data.id'))->not->toBe($expiredCart->id)
        ->and($expiredCart->refresh()->status)->toBe(CartStatus::Expired)
        ->and(Cart::query()->where('session_id', $sessionId)->count())->toBe(2);
});

it('requires the internal api middleware', function () {
    $restaurant = Restaurant::factory()->create();
    storeCartApiSession(cartApiToken(), ['restaurant_id' => $restaurant->id]);

    $this->withHeader('X-Session-Token', cartApiToken())
        ->postJson(route('internal.carts.store'))
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

it('requires the internal session middleware', function () {
    Restaurant::factory()->create();

    $this->withHeader('X-Internal-Api-Token', 'test-internal-token')
        ->postJson(route('internal.carts.store'))
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

it('runs internal api before internal session', function () {
    $this->mock(SessionRepository::class, function ($mock): void {
        $mock->shouldReceive('findByToken')->never();
    });

    $this->withHeaders([
        'X-Internal-Api-Token' => 'wrong-token',
        'X-Session-Token' => cartApiToken(),
    ])->postJson(route('internal.carts.store'), ['session_id' => 'client-value'])
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

it('rejects prohibited client fields and creates no cart', function (string $field, mixed $value) {
    $restaurant = Restaurant::factory()->create();
    storeCartApiSession(cartApiToken(), ['restaurant_id' => $restaurant->id]);

    authorizedCartRequest([$field => $value])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect(Cart::query()->count())->toBe(0);
})->with([
    'session_id' => ['session_id', 'client-session'],
    'restaurant_id' => ['restaurant_id', 123],
    'restaurant_slug' => ['restaurant_slug', 'pizza-house'],
    'account_id' => ['account_id', 'account-id'],
    'status' => ['status', 'checked_out'],
    'currency' => ['currency', 'USD'],
    'subtotal' => ['subtotal', '999.99'],
    'total' => ['total', '999.99'],
    'expires_at' => [
        'expires_at',
        now()->addYear()->toIso8601String(),
    ],
    'items' => ['items', []],
]);

it('does not allow client values to override server derived cart fields', function () {
    $restaurant = Restaurant::factory()->create(['currency' => 'UAH']);
    $otherRestaurant = Restaurant::factory()->create(['currency' => 'USD']);
    storeCartApiSession(cartApiToken(), [
        'restaurant_id' => $restaurant->id,
        'expires_at' => '2026-08-08T12:00:00+00:00',
    ]);

    authorizedCartRequest()->assertCreated();
    $cart = Cart::query()->sole();

    expect($cart->restaurant_id)->toBe($restaurant->id)
        ->and($cart->restaurant_id)->not->toBe($otherRestaurant->id)
        ->and($cart->currency)->toBe('UAH')
        ->and($cart->subtotal)->toBe('0.00')
        ->and($cart->total)->toBe('0.00')
        ->and($cart->status)->toBe(CartStatus::Active)
        ->and($cart->expires_at->toIso8601String())->toBe('2026-08-08T12:00:00+00:00');
});

it('returns not found when the session restaurant is missing or inactive', function (array $restaurantAttributes, int $restaurantId) {
    if ($restaurantAttributes !== []) {
        $restaurant = Restaurant::factory()->create($restaurantAttributes);
        $restaurantId = $restaurant->id;
    }

    storeCartApiSession(cartApiToken(), ['restaurant_id' => $restaurantId]);

    authorizedCartRequest()->assertNotFound();

    expect(Cart::query()->count())->toBe(0);
})->with([
    'missing' => [[], 999999],
    'inactive' => [['is_active' => false], 0],
]);

it('fails cleanly when the session has no selected restaurant', function () {
    storeCartApiSession(cartApiToken(), ['restaurant_id' => null]);

    authorizedCartRequest()->assertConflict()
        ->assertJsonPath('message', 'Restaurant must be selected.');

    expect(Cart::query()->count())->toBe(0);
});

it('returns money values as two decimal strings', function () {
    $restaurant = Restaurant::factory()->create();
    storeCartApiSession(cartApiToken(), ['restaurant_id' => $restaurant->id]);

    $response = authorizedCartRequest()->assertCreated();

    expect($response->json('data.subtotal'))->toBe('0.00')
        ->and($response->json('data.total'))->toBe('0.00');
});

it('returns only safe cart fields', function () {
    config()->set('services.dots.token', 'dots-public-token');
    config()->set('services.dots.account_token', 'dots-account-token');
    config()->set('services.dots.auth_token', 'dots-auth-token');
    $restaurant = Restaurant::factory()->create();
    storeCartApiSession(cartApiToken(), [
        'id' => '01JZXYZSESSION000000000777',
        'restaurant_id' => $restaurant->id,
        'external_session_id' => 'secret-external-id',
        'metadata' => ['contact' => ['name' => 'Yehor']],
    ]);

    $response = authorizedCartRequest()->assertCreated();

    expect(array_keys($response->json('data')))->toBe([
        'id',
        'status',
        'currency',
        'subtotal',
        'total',
        'expires_at',
        'items',
    ])->and($response->getContent())
        ->not->toContain(cartApiToken())
        ->not->toContain('01JZXYZSESSION000000000777')
        ->not->toContain('session_id')
        ->not->toContain('restaurant_id')
        ->not->toContain('external_session_id')
        ->not->toContain('secret-external-id')
        ->not->toContain('metadata')
        ->not->toContain('contact')
        ->not->toContain('created_at')
        ->not->toContain('updated_at')
        ->not->toContain('test-internal-token')
        ->not->toContain('dots-public-token')
        ->not->toContain('dots-account-token')
        ->not->toContain('dots-auth-token');
});

it('does not modify session repository metadata', function () {
    $restaurant = Restaurant::factory()->create();
    storeCartApiSession(cartApiToken(), [
        'restaurant_id' => $restaurant->id,
        'metadata' => ['contact' => ['name' => 'Yehor']],
    ]);

    authorizedCartRequest()->assertCreated();

    expect(app(SessionRepository::class)->findByToken(cartApiToken())['metadata'])->toBe([
        'contact' => ['name' => 'Yehor'],
    ]);
});

it('uses the array session store and does not call dots http or queue jobs', function () {
    Http::fake();
    Queue::fake();
    $restaurant = Restaurant::factory()->create();
    storeCartApiSession(cartApiToken(), ['restaurant_id' => $restaurant->id]);

    authorizedCartRequest()->assertCreated();

    Http::assertNothingSent();
    Queue::assertNothingPushed();

    expect(config('services.internal.session_store'))->toBe('array');
});

function authorizedCartRequest(array $payload = []): TestResponse
{
    return test()->withHeaders([
        'X-Internal-Api-Token' => 'test-internal-token',
        'X-Session-Token' => cartApiToken(),
    ])->postJson(route('internal.carts.store'), $payload);
}

function storeCartApiSession(string $plainToken, array $overrides = []): void
{
    if (isset($overrides['restaurant_id']) && ! array_key_exists('fulfillment', $overrides)) {
        $restaurant = Restaurant::query()->find($overrides['restaurant_id']);

        if ($restaurant !== null) {
            $address = RestaurantAddress::factory()->create([
                'restaurant_id' => $restaurant->id,
                'is_active' => true,
            ]);
            $overrides['fulfillment'] = [
                'type' => 'pickup',
                'dots_delivery_type' => null,
                'delivery_price' => null,
                'delivery_address' => null,
                'restaurant_address_id' => $address->id,
                'external_address_id' => $address->external_address_id,
            ];
        }
    }

    app(SessionRepository::class)->put($plainToken, cartApiSession($overrides));
}

function cartApiSession(array $overrides = []): array
{
    return array_replace([
        'id' => '01JZXYZSESSION000000000201',
        'restaurant_id' => 1,
        'channel' => SessionChannel::ChatGPT->value,
        'external_session_id' => 'external-conversation-id',
        'status' => SessionStatus::Active->value,
        'metadata' => [],
        'created_at' => '2026-08-07T12:00:00+00:00',
        'expires_at' => '2026-08-08T12:00:00+00:00',
    ], $overrides);
}

function cartApiToken(): string
{
    return str_repeat('e', 64);
}
