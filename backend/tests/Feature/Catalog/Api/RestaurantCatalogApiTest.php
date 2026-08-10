<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Restaurant;
use Illuminate\Testing\TestResponse;

it('returns not found when the restaurant is missing', function () {
    config()->set('services.internal.token', 'test-internal-token');

    authorizedCatalogRequest('missing-restaurant')->assertNotFound();
});

it('returns not found when the restaurant is inactive', function () {
    config()->set('services.internal.token', 'test-internal-token');

    $restaurant = Restaurant::factory()->create([
        'slug' => 'inactive-catalog-restaurant',
        'is_active' => false,
    ]);

    authorizedCatalogRequest($restaurant->slug)->assertNotFound();
});

it('returns only active categories', function () {
    config()->set('services.internal.token', 'test-internal-token');

    $restaurant = Restaurant::factory()->create(['slug' => 'active-categories-catalog-restaurant']);
    $activeCategory = Category::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_active' => true,
    ]);
    Category::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_active' => false,
    ]);

    authorizedCatalogRequest($restaurant->slug)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $activeCategory->id);
});

it('excludes categories belonging to another restaurant', function () {
    config()->set('services.internal.token', 'test-internal-token');

    $restaurant = Restaurant::factory()->create(['slug' => 'target-catalog-restaurant']);
    $otherRestaurant = Restaurant::factory()->create(['slug' => 'other-catalog-restaurant']);
    $targetCategory = Category::factory()->create(['restaurant_id' => $restaurant->id]);
    $otherCategory = Category::factory()->create(['restaurant_id' => $otherRestaurant->id]);

    $response = authorizedCatalogRequest($restaurant->slug)
        ->assertOk()
        ->assertJsonCount(1, 'data');

    expect($response->json('data.0.id'))->toBe($targetCategory->id)
        ->and($response->json('data.0.id'))->not->toBe($otherCategory->id);
});

it('keeps active categories with no available products in the response', function () {
    config()->set('services.internal.token', 'test-internal-token');

    [$restaurant, $category] = catalogRestaurantAndCategory('empty-category-catalog-restaurant');
    $unavailable = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_available' => false,
    ]);
    $category->products()->attach($unavailable);

    authorizedCatalogRequest($restaurant->slug)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $category->id)
        ->assertJsonPath('data.0.products', []);
});

it('returns only products attached to each category', function () {
    config()->set('services.internal.token', 'test-internal-token');

    [$restaurant, $category] = catalogRestaurantAndCategory('attached-catalog-restaurant');
    $included = Product::factory()->create(['restaurant_id' => $restaurant->id]);
    $notAttached = Product::factory()->create(['restaurant_id' => $restaurant->id]);
    $category->products()->attach($included);

    $response = authorizedCatalogRequest($restaurant->slug)
        ->assertOk()
        ->assertJsonCount(1, 'data.0.products');

    expect($response->json('data.0.products.0.id'))->toBe($included->id)
        ->and($response->json('data.0.products.0.id'))->not->toBe($notAttached->id);
});

it('excludes unavailable products from categories', function () {
    config()->set('services.internal.token', 'test-internal-token');

    [$restaurant, $category] = catalogRestaurantAndCategory('available-catalog-restaurant');
    $available = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_available' => true,
    ]);
    $unavailable = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_available' => false,
    ]);
    $category->products()->attach([$available->id, $unavailable->id]);

    authorizedCatalogRequest($restaurant->slug)
        ->assertOk()
        ->assertJsonCount(1, 'data.0.products')
        ->assertJsonPath('data.0.products.0.id', $available->id);
});

it('excludes products from another restaurant even when a malformed pivot attaches them', function () {
    config()->set('services.internal.token', 'test-internal-token');

    [$restaurant, $category] = catalogRestaurantAndCategory('tenant-safe-catalog-restaurant');
    $otherRestaurant = Restaurant::factory()->create(['slug' => 'leaking-catalog-restaurant']);
    $included = Product::factory()->create(['restaurant_id' => $restaurant->id]);
    $leakingProduct = Product::factory()->create(['restaurant_id' => $otherRestaurant->id]);
    $category->products()->attach([$included->id, $leakingProduct->id]);

    authorizedCatalogRequest($restaurant->slug)
        ->assertOk()
        ->assertJsonCount(1, 'data.0.products')
        ->assertJsonPath('data.0.products.0.id', $included->id);
});

it('orders categories by sort order then local id', function () {
    config()->set('services.internal.token', 'test-internal-token');

    $restaurant = Restaurant::factory()->create(['slug' => 'ordered-categories-catalog-restaurant']);
    $third = Category::factory()->create(['restaurant_id' => $restaurant->id, 'sort_order' => 20]);
    $first = Category::factory()->create(['restaurant_id' => $restaurant->id, 'sort_order' => 10]);
    $second = Category::factory()->create(['restaurant_id' => $restaurant->id, 'sort_order' => 10]);

    authorizedCatalogRequest($restaurant->slug)
        ->assertOk()
        ->assertJsonPath('data.0.id', $first->id)
        ->assertJsonPath('data.1.id', $second->id)
        ->assertJsonPath('data.2.id', $third->id);
});

it('orders products within each category by sort order then local id', function () {
    config()->set('services.internal.token', 'test-internal-token');

    [$restaurant, $category] = catalogRestaurantAndCategory('ordered-products-catalog-restaurant');
    $third = Product::factory()->create(['restaurant_id' => $restaurant->id, 'sort_order' => 20]);
    $first = Product::factory()->create(['restaurant_id' => $restaurant->id, 'sort_order' => 10]);
    $second = Product::factory()->create(['restaurant_id' => $restaurant->id, 'sort_order' => 10]);
    $category->products()->attach([$third->id, $first->id, $second->id]);

    authorizedCatalogRequest($restaurant->slug)
        ->assertOk()
        ->assertJsonPath('data.0.products.0.id', $first->id)
        ->assertJsonPath('data.0.products.1.id', $second->id)
        ->assertJsonPath('data.0.products.2.id', $third->id);
});

it('returns a product under each active category it is attached to', function () {
    config()->set('services.internal.token', 'test-internal-token');

    $restaurant = Restaurant::factory()->create(['slug' => 'multi-category-catalog-restaurant']);
    $firstCategory = Category::factory()->create(['restaurant_id' => $restaurant->id, 'sort_order' => 10]);
    $secondCategory = Category::factory()->create(['restaurant_id' => $restaurant->id, 'sort_order' => 20]);
    $product = Product::factory()->create(['restaurant_id' => $restaurant->id]);
    $product->categories()->attach([$firstCategory->id, $secondCategory->id]);

    authorizedCatalogRequest($restaurant->slug)
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.products.0.id', $product->id)
        ->assertJsonPath('data.1.products.0.id', $product->id);
});

it('returns exact nested category and product fields', function () {
    config()->set('services.internal.token', 'test-internal-token');

    [$restaurant, $category] = catalogRestaurantAndCategory('shape-catalog-restaurant', [
        'external_id' => '11111111-1111-1111-1111-111111111111',
        'name' => 'Pizza',
        'slug' => 'pizza',
        'sort_order' => 10,
    ]);
    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'external_id' => '22222222-2222-2222-2222-222222222222',
        'name' => 'Margherita',
        'description' => 'Tomato, mozzarella and basil',
        'price' => '220',
        'promotion_price' => '190',
        'currency' => 'UAH',
        'image_url' => 'https://example.test/margherita.jpg',
        'is_available' => true,
        'sort_order' => 20,
    ]);
    $category->products()->attach($product);

    $response = authorizedCatalogRequest($restaurant->slug)
        ->assertOk()
        ->assertExactJson([
            'data' => [
                [
                    'id' => $category->id,
                    'external_id' => '11111111-1111-1111-1111-111111111111',
                    'name' => 'Pizza',
                    'slug' => 'pizza',
                    'sort_order' => 10,
                    'products' => [
                        [
                            'id' => $product->id,
                            'external_id' => '22222222-2222-2222-2222-222222222222',
                            'name' => 'Margherita',
                            'description' => 'Tomato, mozzarella and basil',
                            'price' => '220.00',
                            'promotion_price' => '190.00',
                            'currency' => 'UAH',
                            'image_url' => 'https://example.test/margherita.jpg',
                            'is_available' => true,
                            'sort_order' => 20,
                        ],
                    ],
                ],
            ],
        ]);

    $categoryData = $response->json('data.0');
    $productData = $response->json('data.0.products.0');

    expect(array_keys($categoryData))->toBe(['id', 'external_id', 'name', 'slug', 'sort_order', 'products'])
        ->and(array_keys($productData))->toBe([
            'id',
            'external_id',
            'name',
            'description',
            'price',
            'promotion_price',
            'currency',
            'image_url',
            'is_available',
            'sort_order',
        ]);
});

it('does not expose hidden database fields payloads pivots tokens or dots credentials', function () {
    config()->set('services.internal.token', 'test-internal-token');
    config()->set('services.dots.token', 'dots-public-token');
    config()->set('services.dots.account_token', 'dots-account-token');
    config()->set('services.dots.auth_token', 'dots-auth-token');

    [$restaurant, $category] = catalogRestaurantAndCategory('safe-catalog-restaurant');
    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'original_payload' => [
            'secret' => 'original-payload-secret',
        ],
    ]);
    $category->products()->attach($product);

    $response = authorizedCatalogRequest($restaurant->slug)
        ->assertOk();

    expect($response->getContent())
        ->not->toContain('restaurant_id')
        ->not->toContain('is_active')
        ->not->toContain('original_payload')
        ->not->toContain('original-payload-secret')
        ->not->toContain('created_at')
        ->not->toContain('updated_at')
        ->not->toContain('pivot')
        ->not->toContain('external_company_id')
        ->not->toContain('locale')
        ->not->toContain('timezone')
        ->not->toContain('test-internal-token')
        ->not->toContain('dots-public-token')
        ->not->toContain('dots-account-token')
        ->not->toContain('dots-auth-token');
});

function catalogRestaurantAndCategory(string $slug, array $categoryAttributes = []): array
{
    $restaurant = Restaurant::factory()->create(['slug' => $slug]);

    return [
        $restaurant,
        Category::factory()->create(array_merge([
            'restaurant_id' => $restaurant->id,
        ], $categoryAttributes)),
    ];
}

function authorizedCatalogRequest(string $restaurantSlug): TestResponse
{
    return test()->withHeader('X-Internal-Api-Token', 'test-internal-token')
        ->getJson(route('internal.restaurants.catalog.show', ['restaurant' => $restaurantSlug]));
}
