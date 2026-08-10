<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Restaurant;
use App\Services\Handlers\Restaurant\ProductSearchHandler;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

it('matches product names case insensitively', function () {
    $restaurant = Restaurant::factory()->create();
    $product = Product::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'Sushi Set']);
    Product::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'Burger']);

    $results = searchProducts($restaurant, 'sUsHi');

    expect($results)->toHaveCount(1)
        ->and($results->first()->is($product))->toBeTrue();
});

it('matches product descriptions', function () {
    $restaurant = Restaurant::factory()->create();
    $product = Product::factory()->create(['restaurant_id' => $restaurant->id, 'description' => 'Salmon and avocado']);

    expect(searchProducts($restaurant, 'avocado')->first()->is($product))->toBeTrue();
});

it('matches active category names', function () {
    [$restaurant, $category] = searchRestaurantAndCategory('Rolls');
    $product = Product::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'Sushi Set']);
    $category->products()->attach($product);

    expect(searchProducts($restaurant, 'roll')->first()->is($product))->toBeTrue();
});

it('supports cyrillic queries', function () {
    $restaurant = Restaurant::factory()->create();
    $product = Product::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'Борщ український']);

    expect(searchProducts($restaurant, 'борщ')->first()->is($product))->toBeTrue();
});

it('handles nullable product descriptions', function () {
    $restaurant = Restaurant::factory()->create();
    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Sushi Set',
        'description' => null,
    ]);

    expect(searchProducts($restaurant, 'sushi')->first()->is($product))->toBeTrue();
});

it('excludes products belonging to another restaurant', function () {
    $restaurant = Restaurant::factory()->create();
    $otherRestaurant = Restaurant::factory()->create();
    Product::factory()->create(['restaurant_id' => $otherRestaurant->id, 'name' => 'Sushi Set']);

    expect(searchProducts($restaurant, 'sushi'))->toHaveCount(0);
});

it('excludes unavailable products', function () {
    $restaurant = Restaurant::factory()->create();
    Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Sushi Set',
        'is_available' => false,
    ]);

    expect(searchProducts($restaurant, 'sushi'))->toHaveCount(0);
});

it('does not match inactive categories', function () {
    [$restaurant, $category] = searchRestaurantAndCategory('Rolls', ['is_active' => false]);
    $product = Product::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'Sushi Set']);
    $category->products()->attach($product);

    expect(searchProducts($restaurant, 'rolls'))->toHaveCount(0);
});

it('does not match unattached categories', function () {
    [$restaurant] = searchRestaurantAndCategory('Rolls');
    Product::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'Sushi Set']);

    expect(searchProducts($restaurant, 'rolls'))->toHaveCount(0);
});

it('does not match categories from another restaurant', function () {
    $restaurant = Restaurant::factory()->create();
    $otherRestaurant = Restaurant::factory()->create();
    $otherCategory = Category::factory()->create(['restaurant_id' => $otherRestaurant->id, 'name' => 'Rolls']);
    $product = Product::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'Sushi Set']);
    $otherCategory->products()->attach($product);

    expect(searchProducts($restaurant, 'rolls'))->toHaveCount(0);
});

it('does not leak products through malformed cross restaurant pivots', function () {
    $restaurant = Restaurant::factory()->create();
    $otherRestaurant = Restaurant::factory()->create();
    $category = Category::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'Rolls']);
    $otherProduct = Product::factory()->create(['restaurant_id' => $otherRestaurant->id, 'name' => 'Sushi Set']);
    $category->products()->attach($otherProduct);

    expect(searchProducts($restaurant, 'rolls'))->toHaveCount(0);
});

it('does not duplicate products with multiple matching categories', function () {
    $restaurant = Restaurant::factory()->create();
    $firstCategory = Category::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'Rolls']);
    $secondCategory = Category::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'Hot Rolls']);
    $product = Product::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'Sushi Set']);
    $product->categories()->attach([$firstCategory->id, $secondCategory->id]);

    $results = searchProducts($restaurant, 'rolls');

    expect($results)->toHaveCount(1)
        ->and($results->first()->is($product))->toBeTrue();
});

it('treats percent signs literally', function () {
    $restaurant = Restaurant::factory()->create();
    $percent = Product::factory()->create(['restaurant_id' => $restaurant->id, 'name' => '100% Tuna']);
    Product::factory()->create(['restaurant_id' => $restaurant->id, 'name' => '1000 Tuna']);

    expect(searchProducts($restaurant, '100%')->pluck('id')->all())->toBe([$percent->id]);
});

it('treats underscores literally', function () {
    $restaurant = Restaurant::factory()->create();
    $underscore = Product::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'A_B Roll']);
    Product::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'ACB Roll']);

    expect(searchProducts($restaurant, 'A_B')->pluck('id')->all())->toBe([$underscore->id]);
});

it('treats exclamation marks literally', function () {
    $restaurant = Restaurant::factory()->create();
    $exclamation = Product::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'Hot! Roll']);
    Product::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'Hot Roll']);

    expect(searchProducts($restaurant, 'Hot!')->pluck('id')->all())->toBe([$exclamation->id]);
});

it('treats backslashes literally', function () {
    $restaurant = Restaurant::factory()->create();
    $backslash = Product::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'Path \\ Roll']);
    Product::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'Path Roll']);

    expect(searchProducts($restaurant, '\\')->pluck('id')->all())->toBe([$backslash->id]);
});

it('does not let wildcard only queries match the entire catalog', function (string $query) {
    $restaurant = Restaurant::factory()->create();
    Product::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'Sushi Set']);
    Product::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'Burger']);

    expect(searchProducts($restaurant, $query))->toHaveCount(0);
})->with([
    'percent' => ['%'],
    'underscore' => ['_'],
    'percent and underscore' => ['%_'],
]);

it('does not let sql like input bypass restaurant or availability filters', function () {
    $restaurant = Restaurant::factory()->create();
    $otherRestaurant = Restaurant::factory()->create();
    Product::factory()->create(['restaurant_id' => $restaurant->id, 'name' => "%' OR true --", 'is_available' => false]);
    Product::factory()->create(['restaurant_id' => $otherRestaurant->id, 'name' => "%' OR true --", 'is_available' => true]);

    expect(searchProducts($restaurant, "%' OR true --"))->toHaveCount(0);
});

it('orders products by sort order then local id', function () {
    $restaurant = Restaurant::factory()->create();
    $third = Product::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'Sushi third', 'sort_order' => 20]);
    $first = Product::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'Sushi first', 'sort_order' => 10]);
    $second = Product::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'Sushi second', 'sort_order' => 10]);

    expect(searchProducts($restaurant, 'sushi')->pluck('id')->all())->toBe([
        $first->id,
        $second->id,
        $third->id,
    ]);
});

it('applies the requested limit', function () {
    $restaurant = Restaurant::factory()->create();
    Product::factory()->count(3)->create(['restaurant_id' => $restaurant->id, 'name' => 'Sushi Set']);

    expect(searchProducts($restaurant, 'sushi', 2))->toHaveCount(2);
});

it('performs no database mutations', function () {
    [$restaurant, $category] = searchRestaurantAndCategory('Rolls');
    $product = Product::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'Sushi Set']);
    $category->products()->attach($product);
    $originalRestaurant = $restaurant->fresh()->getRawOriginal();
    $originalCategory = $category->fresh()->getRawOriginal();
    $originalProduct = $product->fresh()->getRawOriginal();
    $originalPivotRows = DB::table('category_product')->get()->map(fn ($row) => (array) $row)->all();

    searchProducts($restaurant, 'sushi');

    expect($restaurant->fresh()->getRawOriginal())->toBe($originalRestaurant)
        ->and($category->fresh()->getRawOriginal())->toBe($originalCategory)
        ->and($product->fresh()->getRawOriginal())->toBe($originalProduct)
        ->and(DB::table('category_product')->get()->map(fn ($row) => (array) $row)->all())->toBe($originalPivotRows);
});

function searchProducts(Restaurant $restaurant, string $query, int $limit = 10): Collection
{
    return app(ProductSearchHandler::class)->handle($restaurant, $query, $limit);
}

function searchRestaurantAndCategory(string $categoryName, array $categoryAttributes = []): array
{
    $restaurant = Restaurant::factory()->create();

    return [
        $restaurant,
        Category::factory()->create(array_merge([
            'restaurant_id' => $restaurant->id,
            'name' => $categoryName,
            'is_active' => true,
        ], $categoryAttributes)),
    ];
}
