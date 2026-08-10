<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Restaurant;
use Illuminate\Testing\TestResponse;

it('returns not found when the restaurant is missing', function () {
    config()->set('services.internal.token', 'test-internal-token');

    authorizedProductRequest('missing-restaurant', 1)->assertNotFound();
});

it('returns not found when the restaurant is inactive', function () {
    config()->set('services.internal.token', 'test-internal-token');

    $restaurant = Restaurant::factory()->create([
        'slug' => 'inactive-product-restaurant',
        'is_active' => false,
    ]);
    $product = Product::factory()->create(['restaurant_id' => $restaurant->id]);

    authorizedProductRequest($restaurant->slug, $product->id)->assertNotFound();
});

it('returns not found when the product is missing', function () {
    config()->set('services.internal.token', 'test-internal-token');

    $restaurant = Restaurant::factory()->create(['slug' => 'missing-product-restaurant']);

    authorizedProductRequest($restaurant->slug, 999999)->assertNotFound();
});

it('returns not found when the product belongs to another restaurant', function () {
    config()->set('services.internal.token', 'test-internal-token');

    $restaurant = Restaurant::factory()->create(['slug' => 'target-product-restaurant']);
    $otherRestaurant = Restaurant::factory()->create(['slug' => 'other-product-restaurant']);
    $otherProduct = Product::factory()->create(['restaurant_id' => $otherRestaurant->id]);

    authorizedProductRequest($restaurant->slug, $otherProduct->id)->assertNotFound();
});

it('returns ok for an unavailable product and exposes is available as false', function () {
    config()->set('services.internal.token', 'test-internal-token');

    [$restaurant, $product] = restaurantAndProduct('unavailable-product-restaurant', [
        'is_available' => false,
    ]);

    authorizedProductRequest($restaurant->slug, $product->id)
        ->assertOk()
        ->assertJsonPath('data.id', $product->id)
        ->assertJsonPath('data.is_available', false);
});

it('retrieves a product without category attachments through its restaurant', function () {
    config()->set('services.internal.token', 'test-internal-token');

    [$restaurant, $product] = restaurantAndProduct('unattached-product-restaurant');

    expect($product->categories()->count())->toBe(0);

    authorizedProductRequest($restaurant->slug, $product->id)
        ->assertOk()
        ->assertJsonPath('data.id', $product->id);
});

it('returns the exact product resource structure', function () {
    config()->set('services.internal.token', 'test-internal-token');

    [$restaurant, $product] = restaurantAndProduct('shape-product-restaurant', [
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

    $response = authorizedProductRequest($restaurant->slug, $product->id)
        ->assertOk()
        ->assertExactJson([
            'data' => [
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
        ]);

    $productData = $response->json('data');

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

it('does not expose hidden database fields relationships pivot data tokens or dots credentials', function () {
    config()->set('services.internal.token', 'test-internal-token');
    config()->set('services.dots.token', 'dots-public-token');
    config()->set('services.dots.account_token', 'dots-account-token');
    config()->set('services.dots.auth_token', 'dots-auth-token');

    [$restaurant, $product] = restaurantAndProduct('safe-product-restaurant', [
        'original_payload' => [
            'secret' => 'original-payload-secret',
        ],
    ]);
    $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);
    $category->products()->attach($product);

    $response = authorizedProductRequest($restaurant->slug, $product->id)
        ->assertOk();

    expect($response->getContent())
        ->not->toContain('restaurant_id')
        ->not->toContain('original_payload')
        ->not->toContain('original-payload-secret')
        ->not->toContain('created_at')
        ->not->toContain('updated_at')
        ->not->toContain('categories')
        ->not->toContain('pivot')
        ->not->toContain('test-internal-token')
        ->not->toContain('dots-public-token')
        ->not->toContain('dots-account-token')
        ->not->toContain('dots-auth-token');
});

function restaurantAndProduct(string $slug, array $productAttributes = []): array
{
    $restaurant = Restaurant::factory()->create(['slug' => $slug]);

    return [
        $restaurant,
        Product::factory()->create(array_merge([
            'restaurant_id' => $restaurant->id,
        ], $productAttributes)),
    ];
}

function authorizedProductRequest(string $restaurantSlug, int $productId): TestResponse
{
    return test()->withHeader('X-Internal-Api-Token', 'test-internal-token')
        ->getJson(route('internal.restaurants.products.show', [
            'restaurant' => $restaurantSlug,
            'product' => $productId,
        ]));
}
