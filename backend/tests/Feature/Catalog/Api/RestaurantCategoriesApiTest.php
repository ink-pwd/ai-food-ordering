<?php

use App\Models\Category;
use App\Models\Restaurant;
use Illuminate\Testing\TestResponse;

it('returns not found when the restaurant is missing', function () {
    config()->set('services.internal.token', 'test-internal-token');

    authorizedCategoriesRequest('missing-restaurant')->assertNotFound();
});

it('returns not found when the restaurant is inactive', function () {
    config()->set('services.internal.token', 'test-internal-token');

    $restaurant = Restaurant::factory()->create([
        'slug' => 'inactive-restaurant',
        'is_active' => false,
    ]);

    authorizedCategoriesRequest($restaurant->slug)->assertNotFound();
});

it('returns only active categories', function () {
    config()->set('services.internal.token', 'test-internal-token');

    $restaurant = Restaurant::factory()->create(['slug' => 'category-filter-restaurant']);
    $activeCategory = Category::factory()->create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Pizza',
        'slug' => 'pizza',
        'is_active' => true,
    ]);
    Category::factory()->create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Hidden',
        'slug' => 'hidden',
        'is_active' => false,
    ]);

    authorizedCategoriesRequest($restaurant->slug)
        ->assertOk()
        ->assertJsonPath('data.0.id', $activeCategory->id)
        ->assertJsonCount(1, 'data');
});

it('never returns categories from another restaurant', function () {
    config()->set('services.internal.token', 'test-internal-token');

    $restaurant = Restaurant::factory()->create(['slug' => 'target-restaurant']);
    $otherRestaurant = Restaurant::factory()->create(['slug' => 'other-restaurant']);
    $targetCategory = Category::factory()->create(['restaurant_id' => $restaurant->id]);
    $otherCategory = Category::factory()->create(['restaurant_id' => $otherRestaurant->id]);

    $response = authorizedCategoriesRequest($restaurant->slug)
        ->assertOk()
        ->assertJsonCount(1, 'data');

    expect($response->json('data.0.id'))->toBe($targetCategory->id)
        ->and($response->json('data.0.id'))->not->toBe($otherCategory->id);
});

it('orders categories by sort order then local id', function () {
    config()->set('services.internal.token', 'test-internal-token');

    $restaurant = Restaurant::factory()->create(['slug' => 'ordered-restaurant']);
    $third = Category::factory()->create(['restaurant_id' => $restaurant->id, 'sort_order' => 20]);
    $first = Category::factory()->create(['restaurant_id' => $restaurant->id, 'sort_order' => 10]);
    $second = Category::factory()->create(['restaurant_id' => $restaurant->id, 'sort_order' => 10]);

    authorizedCategoriesRequest($restaurant->slug)
        ->assertOk()
        ->assertJsonPath('data.0.id', $first->id)
        ->assertJsonPath('data.1.id', $second->id)
        ->assertJsonPath('data.2.id', $third->id);
});

it('returns category objects with exact expected fields and types', function () {
    config()->set('services.internal.token', 'test-internal-token');

    $restaurant = Restaurant::factory()->create(['slug' => 'shape-restaurant']);
    $category = Category::factory()->create([
        'restaurant_id' => $restaurant->id,
        'external_id' => '11111111-1111-1111-1111-111111111111',
        'name' => 'Pizza',
        'slug' => 'pizza',
        'sort_order' => 10,
    ]);

    $response = authorizedCategoriesRequest($restaurant->slug)
        ->assertOk()
        ->assertExactJson([
            'data' => [
                [
                    'id' => $category->id,
                    'external_id' => '11111111-1111-1111-1111-111111111111',
                    'name' => 'Pizza',
                    'slug' => 'pizza',
                    'sort_order' => 10,
                ],
            ],
        ]);

    $categoryData = $response->json('data.0');

    expect(array_keys($categoryData))->toBe(['id', 'external_id', 'name', 'slug', 'sort_order'])
        ->and($categoryData['id'])->toBeInt()
        ->and($categoryData['external_id'])->toBeString()
        ->and($categoryData['name'])->toBeString()
        ->and($categoryData['slug'])->toBeString()
        ->and($categoryData['sort_order'])->toBeInt();
});

it('does not expose hidden database fields tokens or dots credentials', function () {
    config()->set('services.internal.token', 'test-internal-token');
    config()->set('services.dots.token', 'dots-public-token');
    config()->set('services.dots.account_token', 'dots-account-token');
    config()->set('services.dots.auth_token', 'dots-auth-token');

    $restaurant = Restaurant::factory()->create(['slug' => 'safe-response-restaurant']);
    Category::factory()->create(['restaurant_id' => $restaurant->id]);

    $response = authorizedCategoriesRequest($restaurant->slug)
        ->assertOk();

    expect($response->getContent())
        ->not->toContain('restaurant_id')
        ->not->toContain('is_active')
        ->not->toContain('created_at')
        ->not->toContain('updated_at')
        ->not->toContain('pivot')
        ->not->toContain('products')
        ->not->toContain('original_payload')
        ->not->toContain('test-internal-token')
        ->not->toContain('dots-public-token')
        ->not->toContain('dots-account-token')
        ->not->toContain('dots-auth-token');
});

function authorizedCategoriesRequest(string $restaurantSlug): TestResponse
{
    return test()->withHeader('X-Internal-Api-Token', 'test-internal-token')
        ->getJson(route('internal.restaurants.categories.index', ['restaurant' => $restaurantSlug]));
}
