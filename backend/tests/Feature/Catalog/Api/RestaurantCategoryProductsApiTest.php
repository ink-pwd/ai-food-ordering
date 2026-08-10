<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Restaurant;
use Illuminate\Testing\TestResponse;

it('returns not found when the restaurant is missing', function () {
    config()->set('services.internal.token', 'test-internal-token');

    authorizedProductsRequest('missing-restaurant', 1)->assertNotFound();
});

it('returns not found when the restaurant is inactive', function () {
    config()->set('services.internal.token', 'test-internal-token');

    $restaurant = Restaurant::factory()->create([
        'slug' => 'inactive-products-restaurant',
        'is_active' => false,
    ]);
    $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);

    authorizedProductsRequest($restaurant->slug, $category->id)->assertNotFound();
});

it('returns not found when the category is missing', function () {
    config()->set('services.internal.token', 'test-internal-token');

    $restaurant = Restaurant::factory()->create(['slug' => 'missing-category-restaurant']);

    authorizedProductsRequest($restaurant->slug, 999999)->assertNotFound();
});

it('returns not found when the category is inactive', function () {
    config()->set('services.internal.token', 'test-internal-token');

    $restaurant = Restaurant::factory()->create(['slug' => 'inactive-category-restaurant']);
    $category = Category::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_active' => false,
    ]);

    authorizedProductsRequest($restaurant->slug, $category->id)->assertNotFound();
});

it('returns not found when the category belongs to another restaurant', function () {
    config()->set('services.internal.token', 'test-internal-token');

    $restaurant = Restaurant::factory()->create(['slug' => 'target-category-restaurant']);
    $otherRestaurant = Restaurant::factory()->create(['slug' => 'other-category-restaurant']);
    $otherCategory = Category::factory()->create(['restaurant_id' => $otherRestaurant->id]);

    authorizedProductsRequest($restaurant->slug, $otherCategory->id)->assertNotFound();
});

it('returns only products attached to the requested category', function () {
    config()->set('services.internal.token', 'test-internal-token');

    [$restaurant, $category] = restaurantAndCategory('attached-products-restaurant');
    $included = Product::factory()->create(['restaurant_id' => $restaurant->id]);
    $notAttached = Product::factory()->create(['restaurant_id' => $restaurant->id]);
    $category->products()->attach($included);

    $response = authorizedProductsRequest($restaurant->slug, $category->id)
        ->assertOk()
        ->assertJsonCount(1, 'data');

    expect($response->json('data.0.id'))->toBe($included->id)
        ->and($response->json('data.0.id'))->not->toBe($notAttached->id);
});

it('excludes unavailable products', function () {
    config()->set('services.internal.token', 'test-internal-token');

    [$restaurant, $category] = restaurantAndCategory('available-products-restaurant');
    $available = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_available' => true,
    ]);
    $unavailable = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_available' => false,
    ]);
    $category->products()->attach([$available->id, $unavailable->id]);

    authorizedProductsRequest($restaurant->slug, $category->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $available->id);
});

it('excludes products from another restaurant even when a malformed pivot attaches them', function () {
    config()->set('services.internal.token', 'test-internal-token');

    [$restaurant, $category] = restaurantAndCategory('tenant-safe-products-restaurant');
    $otherRestaurant = Restaurant::factory()->create(['slug' => 'leaking-products-restaurant']);
    $included = Product::factory()->create(['restaurant_id' => $restaurant->id]);
    $leakingProduct = Product::factory()->create(['restaurant_id' => $otherRestaurant->id]);
    $category->products()->attach([$included->id, $leakingProduct->id]);

    authorizedProductsRequest($restaurant->slug, $category->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $included->id);
});

it('excludes products attached only to a different category', function () {
    config()->set('services.internal.token', 'test-internal-token');

    [$restaurant, $category] = restaurantAndCategory('different-category-products-restaurant');
    $otherCategory = Category::factory()->create(['restaurant_id' => $restaurant->id]);
    $included = Product::factory()->create(['restaurant_id' => $restaurant->id]);
    $otherCategoryProduct = Product::factory()->create(['restaurant_id' => $restaurant->id]);
    $category->products()->attach($included);
    $otherCategory->products()->attach($otherCategoryProduct);

    authorizedProductsRequest($restaurant->slug, $category->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $included->id);
});

it('orders products by sort order then local id', function () {
    config()->set('services.internal.token', 'test-internal-token');

    [$restaurant, $category] = restaurantAndCategory('ordered-products-restaurant');
    $third = Product::factory()->create(['restaurant_id' => $restaurant->id, 'sort_order' => 20]);
    $first = Product::factory()->create(['restaurant_id' => $restaurant->id, 'sort_order' => 10]);
    $second = Product::factory()->create(['restaurant_id' => $restaurant->id, 'sort_order' => 10]);
    $category->products()->attach([$third->id, $first->id, $second->id]);

    authorizedProductsRequest($restaurant->slug, $category->id)
        ->assertOk()
        ->assertJsonPath('data.0.id', $first->id)
        ->assertJsonPath('data.1.id', $second->id)
        ->assertJsonPath('data.2.id', $third->id);
});

it('returns product objects with exact expected fields and types', function () {
    config()->set('services.internal.token', 'test-internal-token');

    [$restaurant, $category] = restaurantAndCategory('shape-products-restaurant');
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
        'sort_order' => 10,
    ]);
    $category->products()->attach($product);

    $response = authorizedProductsRequest($restaurant->slug, $category->id)
        ->assertOk()
        ->assertExactJson([
            'data' => [
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
                    'sort_order' => 10,
                ],
            ],
        ]);

    $productData = $response->json('data.0');

    expect(array_keys($productData))->toBe([
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
    ])->and($productData['id'])->toBeInt()
        ->and($productData['external_id'])->toBeString()
        ->and($productData['name'])->toBeString()
        ->and($productData['description'])->toBeString()
        ->and($productData['price'])->toBeString()->toBe('220.00')
        ->and($productData['promotion_price'])->toBeString()->toBe('190.00')
        ->and($productData['currency'])->toBeString()->toHaveLength(3)
        ->and($productData['image_url'])->toBeString()
        ->and($productData['is_available'])->toBeBool()->toBeTrue()
        ->and($productData['sort_order'])->toBeInt();
});

it('does not expose hidden database fields pivot data tokens or dots credentials', function () {
    config()->set('services.internal.token', 'test-internal-token');
    config()->set('services.dots.token', 'dots-public-token');
    config()->set('services.dots.account_token', 'dots-account-token');
    config()->set('services.dots.auth_token', 'dots-auth-token');

    [$restaurant, $category] = restaurantAndCategory('safe-products-restaurant');
    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'original_payload' => [
            'secret' => 'original-payload-secret',
        ],
    ]);
    $category->products()->attach($product);

    $response = authorizedProductsRequest($restaurant->slug, $category->id)
        ->assertOk();

    expect($response->getContent())
        ->not->toContain('restaurant_id')
        ->not->toContain('original_payload')
        ->not->toContain('original-payload-secret')
        ->not->toContain('created_at')
        ->not->toContain('updated_at')
        ->not->toContain('pivot')
        ->not->toContain('categories')
        ->not->toContain('effective_price')
        ->not->toContain('test-internal-token')
        ->not->toContain('dots-public-token')
        ->not->toContain('dots-account-token')
        ->not->toContain('dots-auth-token');
});

function restaurantAndCategory(string $slug): array
{
    $restaurant = Restaurant::factory()->create(['slug' => $slug]);

    return [
        $restaurant,
        Category::factory()->create(['restaurant_id' => $restaurant->id]),
    ];
}

function authorizedProductsRequest(string $restaurantSlug, int $categoryId): TestResponse
{
    return test()->withHeader('X-Internal-Api-Token', 'test-internal-token')
        ->getJson(route('internal.restaurants.categories.products.index', [
            'restaurant' => $restaurantSlug,
            'category' => $categoryId,
        ]));
}
