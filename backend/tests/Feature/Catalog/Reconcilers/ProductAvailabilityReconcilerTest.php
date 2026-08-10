<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Restaurant;
use App\Services\Reconcilers\ProductAvailabilityReconciler;
use Illuminate\Support\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

it('deactivates an available product missing from the payload', function () {
    $restaurant = Restaurant::factory()->create();
    $product = Product::factory()->create(['restaurant_id' => $restaurant->id, 'is_available' => true]);

    $count = reconcileProducts($restaurant, []);

    expect($count)->toBe(1)
        ->and($product->refresh()->is_available)->toBeFalse();
});

it('returns the number of changed products', function () {
    $restaurant = Restaurant::factory()->create();
    Product::factory()->count(2)->create(['restaurant_id' => $restaurant->id, 'is_available' => true]);
    Product::factory()->create(['restaurant_id' => $restaurant->id, 'is_available' => false]);

    expect(reconcileProducts($restaurant, []))->toBe(2);
});

it('leaves a present product unchanged', function () {
    $restaurant = Restaurant::factory()->create();
    $product = Product::factory()->create(['restaurant_id' => $restaurant->id, 'is_available' => true]);

    $count = reconcileProducts($restaurant, [reconcileCategory('11111111-1111-1111-1111-111111111111', [reconcileProduct($product->external_id)])]);

    expect($count)->toBe(0)
        ->and($product->refresh()->is_available)->toBeTrue();
});

it('preserves availability supplied by product synchronizer for present products', function () {
    $restaurant = Restaurant::factory()->create();
    $product = Product::factory()->create(['restaurant_id' => $restaurant->id, 'is_available' => false]);

    reconcileProducts($restaurant, [reconcileCategory('11111111-1111-1111-1111-111111111111', [reconcileProduct($product->external_id)])]);

    expect($product->refresh()->is_available)->toBeFalse();
});

it('does not update an already unavailable missing product', function () {
    $restaurant = Restaurant::factory()->create();
    Product::factory()->create(['restaurant_id' => $restaurant->id, 'is_available' => false]);

    expect(reconcileProducts($restaurant, []))->toBe(0);
});

it('does not change updated at when no update is needed', function () {
    $restaurant = Restaurant::factory()->create();
    $timestamp = Carbon::parse('2026-01-01 12:00:00');
    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_available' => true,
        'updated_at' => $timestamp,
    ]);

    reconcileProducts($restaurant, [reconcileCategory('11111111-1111-1111-1111-111111111111', [reconcileProduct($product->external_id)])]);

    expect($product->refresh()->updated_at->equalTo($timestamp))->toBeTrue();
});

it('updates updated at when a product is newly deactivated', function () {
    $restaurant = Restaurant::factory()->create();
    $oldTimestamp = Carbon::parse('2026-01-01 12:00:00');
    Carbon::setTestNow(Carbon::parse('2026-01-02 12:00:00'));
    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_available' => true,
        'updated_at' => $oldTimestamp,
    ]);

    reconcileProducts($restaurant, []);

    expect($product->refresh()->updated_at->greaterThan($oldTimestamp))->toBeTrue();
});

it('deactivates all available products for an empty complete product list', function () {
    $restaurant = Restaurant::factory()->create();
    Product::factory()->count(3)->create(['restaurant_id' => $restaurant->id, 'is_available' => true]);

    reconcileProducts($restaurant, []);

    expect(Product::query()->whereBelongsTo($restaurant)->where('is_available', true)->count())->toBe(0);
});

it('does not affect another restaurant', function () {
    $restaurant = Restaurant::factory()->create();
    $otherRestaurant = Restaurant::factory()->create();
    Product::factory()->create(['restaurant_id' => $restaurant->id, 'is_available' => true]);
    $otherProduct = Product::factory()->create(['restaurant_id' => $otherRestaurant->id, 'is_available' => true]);

    reconcileProducts($restaurant, []);

    expect($otherProduct->refresh()->is_available)->toBeTrue();
});

it('does not delete products', function () {
    $restaurant = Restaurant::factory()->create();
    Product::factory()->count(2)->create(['restaurant_id' => $restaurant->id, 'is_available' => true]);

    reconcileProducts($restaurant, []);

    expect(Product::query()->whereBelongsTo($restaurant)->count())->toBe(2);
});

it('does not delete categories', function () {
    $restaurant = Restaurant::factory()->create();
    Category::factory()->create(['restaurant_id' => $restaurant->id]);
    Product::factory()->create(['restaurant_id' => $restaurant->id, 'is_available' => true]);

    reconcileProducts($restaurant, []);

    expect(Category::query()->whereBelongsTo($restaurant)->count())->toBe(1);
});

it('does not delete or detach pivot relations', function () {
    $restaurant = Restaurant::factory()->create();
    $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);
    $product = Product::factory()->create(['restaurant_id' => $restaurant->id, 'is_available' => true]);
    $category->products()->attach($product);

    reconcileProducts($restaurant, []);

    expect($product->categories()->count())->toBe(1);
});

it('is idempotent on repeated reconciliation', function () {
    $restaurant = Restaurant::factory()->create();
    Product::factory()->create(['restaurant_id' => $restaurant->id, 'is_available' => true]);

    expect(reconcileProducts($restaurant, []))->toBe(1)
        ->and(reconcileProducts($restaurant, []))->toBe(0);
});

it('handles multiple categories and products', function () {
    $restaurant = Restaurant::factory()->create();
    $presentOne = Product::factory()->create(['restaurant_id' => $restaurant->id, 'is_available' => true]);
    $presentTwo = Product::factory()->create(['restaurant_id' => $restaurant->id, 'is_available' => true]);
    $missing = Product::factory()->create(['restaurant_id' => $restaurant->id, 'is_available' => true]);

    $count = reconcileProducts($restaurant, [
        reconcileCategory('11111111-1111-1111-1111-111111111111', [reconcileProduct($presentOne->external_id)]),
        reconcileCategory('22222222-2222-2222-2222-222222222222', [reconcileProduct($presentTwo->external_id)]),
    ]);

    expect($count)->toBe(1)
        ->and($presentOne->refresh()->is_available)->toBeTrue()
        ->and($presentTwo->refresh()->is_available)->toBeTrue()
        ->and($missing->refresh()->is_available)->toBeFalse();
});

it('handles categories with empty items lists', function () {
    $restaurant = Restaurant::factory()->create();
    $product = Product::factory()->create(['restaurant_id' => $restaurant->id, 'is_available' => true]);

    reconcileProducts($restaurant, [reconcileCategory('11111111-1111-1111-1111-111111111111', [])]);

    expect($product->refresh()->is_available)->toBeFalse();
});

function reconcileProducts(Restaurant $restaurant, array $categories): int
{
    return app(ProductAvailabilityReconciler::class)->deactivateMissing($restaurant, $categories);
}

function reconcileCategory(string $id, array $products): array
{
    return [
        'id' => $id,
        'items' => $products,
    ];
}

function reconcileProduct(string $id): array
{
    return ['id' => $id];
}
