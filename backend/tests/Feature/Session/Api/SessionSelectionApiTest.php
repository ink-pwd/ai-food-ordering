<?php

use App\Enums\CartStatus;
use App\Enums\SessionChannel;
use App\Enums\SessionStatus;
use App\Models\Cart;
use App\Models\City;
use App\Models\Restaurant;
use App\Services\Repositories\SessionRepository;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Carbon::setTestNow('2026-08-14 12:00:00');
    config()->set('services.internal.token', 'test-internal-token');
    config()->set('services.internal.session_store', 'array');
    config()->set('services.internal.session_ttl_seconds', 60);
    config()->set('services.internal.session_key_prefix', 'test-session-selection');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('lists only active cities', function () {
    $activeCity = City::factory()->create(['name' => 'Chernihiv', 'is_active' => true]);
    City::factory()->create(['name' => 'Inactive City', 'is_active' => false]);

    $response = internalGet(route('internal.cities.index'))->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($activeCity->id)
        ->and($response->json('data.0.name'))->toBe('Chernihiv');
});

it('authenticates active sessions without selected city or restaurant', function () {
    storeSelectionSession(selectionToken(), selectionSession([
        'city_id' => null,
        'restaurant_id' => null,
        'metadata' => ['contact' => contactMetadata()],
    ]));

    internalPut(route('internal.sessions.city.update'), ['city_id' => City::factory()->create()->id])
        ->assertOk();
});

it('selects an active city after contact exists and cannot replace it', function () {
    $city = City::factory()->create(['is_active' => true]);
    $otherCity = City::factory()->create(['is_active' => true]);
    storeSelectionSession(selectionToken(), selectionSession([
        'metadata' => ['contact' => contactMetadata()],
    ]));

    internalPut(route('internal.sessions.city.update'), ['city_id' => $city->id])
        ->assertOk()
        ->assertJsonPath('data.city.id', $city->id);

    expect(app(SessionRepository::class)->findByToken(selectionToken())['city_id'])->toBe($city->id);

    internalPut(route('internal.sessions.city.update'), ['city_id' => $city->id])->assertConflict();
    internalPut(route('internal.sessions.city.update'), ['city_id' => $otherCity->id])->assertConflict();

    expect(app(SessionRepository::class)->findByToken(selectionToken())['city_id'])->toBe($city->id);
});

it('rejects city selection before contact exists', function () {
    $city = City::factory()->create(['is_active' => true]);
    storeSelectionSession(selectionToken(), selectionSession([
        'metadata' => ['contact' => array_merge(contactMetadata(), ['phone_verified' => false])],
    ]));

    internalPut(route('internal.sessions.city.update'), ['city_id' => $city->id])->assertConflict();
});

it('lists restaurants only for the selected city', function () {
    $city = City::factory()->create();
    $selectedRestaurant = Restaurant::factory()->for($city)->create([
        'name' => 'Selected City Restaurant',
        'is_active' => true,
        'available_payment_types' => [1, 2, 3],
    ]);
    Restaurant::factory()->for($city)->create(['name' => 'Inactive Restaurant', 'is_active' => false]);
    Restaurant::factory()->create(['name' => 'Other City Restaurant', 'is_active' => true]);
    storeSelectionSession(selectionToken(), selectionSession([
        'city_id' => $city->id,
        'metadata' => ['contact' => contactMetadata()],
    ]));

    $response = internalGet(route('internal.sessions.restaurants.index'))->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($selectedRestaurant->id)
        ->and($response->json('data.0.available_payment_types'))->toBe([1, 2, 3]);
});

it('selects only active restaurants from the selected city and cannot replace selection', function () {
    $city = City::factory()->create();
    $restaurant = Restaurant::factory()->for($city)->create(['is_active' => true]);
    $replacement = Restaurant::factory()->for($city)->create(['is_active' => true]);
    $otherCityRestaurant = Restaurant::factory()->create(['is_active' => true]);
    storeSelectionSession(selectionToken(), selectionSession([
        'city_id' => $city->id,
        'metadata' => ['contact' => contactMetadata()],
    ]));

    internalPut(route('internal.sessions.restaurant.update'), ['restaurant_id' => $otherCityRestaurant->id])
        ->assertNotFound();

    internalPut(route('internal.sessions.restaurant.update'), ['restaurant_id' => $restaurant->id])
        ->assertOk()
        ->assertJsonPath('data.restaurant.id', $restaurant->id);

    expect(app(SessionRepository::class)->findByToken(selectionToken())['restaurant_id'])->toBe($restaurant->id);

    internalPut(route('internal.sessions.restaurant.update'), ['restaurant_id' => $restaurant->id])->assertConflict();
    internalPut(route('internal.sessions.restaurant.update'), ['restaurant_id' => $replacement->id])->assertConflict();

    expect(app(SessionRepository::class)->findByToken(selectionToken())['restaurant_id'])->toBe($restaurant->id);
});

it('exits by abandoning active cart and closing the session without changing checked out carts', function () {
    $restaurant = Restaurant::factory()->create();
    $sessionId = selectionSession()['id'];
    storeSelectionSession(selectionToken(), selectionSession([
        'city_id' => $restaurant->city_id,
        'restaurant_id' => $restaurant->id,
        'metadata' => ['contact' => contactMetadata()],
    ]));
    $activeCart = Cart::factory()->for($restaurant)->create([
        'session_id' => $sessionId,
        'status' => CartStatus::Active,
    ]);
    $checkedOutCart = Cart::factory()->for($restaurant)->create([
        'session_id' => $sessionId,
        'status' => CartStatus::CheckedOut,
    ]);

    internalDelete(route('internal.sessions.current.destroy'))
        ->assertOk()
        ->assertJsonPath('data.status', SessionStatus::Closed->value);

    expect($activeCart->refresh()->status)->toBe(CartStatus::Abandoned)
        ->and($checkedOutCart->refresh()->status)->toBe(CartStatus::CheckedOut);

    internalGet(route('internal.sessions.restaurants.index'))->assertUnauthorized();
});

function internalGet(string $uri): TestResponse
{
    return test()->withHeaders(selectionHeaders())->getJson($uri);
}

function internalPut(string $uri, array $payload): TestResponse
{
    return test()->withHeaders(selectionHeaders())->putJson($uri, $payload);
}

function internalDelete(string $uri): TestResponse
{
    return test()->withHeaders(selectionHeaders())->deleteJson($uri);
}

function selectionHeaders(): array
{
    return [
        'X-Internal-Api-Token' => 'test-internal-token',
        'X-Session-Token' => selectionToken(),
    ];
}

function storeSelectionSession(string $plainToken, array $session): void
{
    app(SessionRepository::class)->put($plainToken, $session);
}

function selectionSession(array $overrides = []): array
{
    return array_replace([
        'id' => '01JZXYZSESSION000000000999',
        'city_id' => null,
        'restaurant_id' => null,
        'channel' => SessionChannel::ChatGPT->value,
        'external_session_id' => 'external-conversation-id',
        'status' => SessionStatus::Active->value,
        'metadata' => [],
        'created_at' => '2026-08-14T12:00:00+00:00',
        'expires_at' => '2026-08-14T13:00:00+00:00',
    ], $overrides);
}

function contactMetadata(): array
{
    return [
        'name' => 'Yehor',
        'phone' => '+380931234567',
        'phone_verified' => true,
    ];
}

function selectionToken(): string
{
    return str_repeat('f', 64);
}
