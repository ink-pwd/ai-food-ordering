<?php

use App\Models\Product;
use App\Models\Restaurant;
use Illuminate\Testing\TestResponse;

it('returns not found when the restaurant is missing', function () {
    config()->set('services.internal.token', 'test-internal-token');

    authorizedSearchRequest('missing-restaurant', ['q' => 'sushi'])->assertNotFound();
});

it('returns not found when the restaurant is inactive', function () {
    config()->set('services.internal.token', 'test-internal-token');

    $restaurant = Restaurant::factory()->create([
        'slug' => 'inactive-search-restaurant',
        'is_active' => false,
    ]);

    authorizedSearchRequest($restaurant->slug, ['q' => 'sushi'])->assertNotFound();
});

it('validates required q', function () {
    config()->set('services.internal.token', 'test-internal-token');

    $restaurant = Restaurant::factory()->create(['slug' => 'missing-q-search-restaurant']);

    authorizedSearchRequest($restaurant->slug, [])->assertUnprocessable();
});

it('validates q maximum length', function () {
    config()->set('services.internal.token', 'test-internal-token');

    $restaurant = Restaurant::factory()->create(['slug' => 'long-q-search-restaurant']);

    authorizedSearchRequest($restaurant->slug, ['q' => str_repeat('a', 101)])->assertUnprocessable();
});

it('validates limit minimum maximum and integer type', function (mixed $limit) {
    config()->set('services.internal.token', 'test-internal-token');

    $restaurant = Restaurant::factory()->create(['slug' => 'invalid-limit-search-restaurant'.str_replace('.', '-', (string) $limit)]);

    authorizedSearchRequest($restaurant->slug, ['q' => 'sushi', 'limit' => $limit])->assertUnprocessable();
})->with([
    'zero' => [0],
    'over max' => [51],
    'non integer' => ['abc'],
]);

it('respects a valid custom limit', function () {
    config()->set('services.internal.token', 'test-internal-token');

    $restaurant = Restaurant::factory()->create(['slug' => 'custom-limit-search-restaurant']);
    Product::factory()->count(3)->create(['restaurant_id' => $restaurant->id, 'name' => 'Sushi Set']);

    authorizedSearchRequest($restaurant->slug, ['q' => 'sushi', 'limit' => 2])
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('returns successful results through the product search response and product resource', function () {
    config()->set('services.internal.token', 'test-internal-token');

    $restaurant = Restaurant::factory()->create(['slug' => 'response-search-restaurant']);
    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'external_id' => '11111111-1111-1111-1111-111111111111',
        'name' => 'Sushi Set',
        'description' => 'Salmon and avocado',
        'price' => '220',
        'promotion_price' => null,
        'currency' => 'UAH',
        'image_url' => null,
        'is_available' => true,
        'sort_order' => 10,
    ]);

    authorizedSearchRequest($restaurant->slug, ['q' => 'sushi'])
        ->assertOk()
        ->assertExactJson([
            'data' => [
                [
                    'id' => $product->id,
                    'external_id' => '11111111-1111-1111-1111-111111111111',
                    'name' => 'Sushi Set',
                    'description' => 'Salmon and avocado',
                    'price' => '220.00',
                    'promotion_price' => null,
                    'currency' => 'UAH',
                    'image_url' => null,
                    'is_available' => true,
                    'sort_order' => 10,
                ],
            ],
        ]);
});

it('does not expose hidden data or credentials', function () {
    config()->set('services.internal.token', 'test-internal-token');
    config()->set('services.dots.token', 'dots-public-token');
    config()->set('services.dots.account_token', 'dots-account-token');
    config()->set('services.dots.auth_token', 'dots-auth-token');

    $restaurant = Restaurant::factory()->create(['slug' => 'safe-search-restaurant']);
    Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Sushi Set',
        'original_payload' => ['secret' => 'original-payload-secret'],
    ]);

    $response = authorizedSearchRequest($restaurant->slug, ['q' => 'sushi'])->assertOk();

    expect($response->getContent())
        ->not->toContain('restaurant_id')
        ->not->toContain('categories')
        ->not->toContain('pivot')
        ->not->toContain('original_payload')
        ->not->toContain('original-payload-secret')
        ->not->toContain('created_at')
        ->not->toContain('updated_at')
        ->not->toContain('test-internal-token')
        ->not->toContain('dots-public-token')
        ->not->toContain('dots-account-token')
        ->not->toContain('dots-auth-token');
});

function authorizedSearchRequest(string $restaurantSlug, array $query): TestResponse
{
    return test()->withHeader('X-Internal-Api-Token', 'test-internal-token')
        ->getJson(route('internal.restaurants.products.search', ['restaurant' => $restaurantSlug]).'?'.http_build_query($query));
}
