<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Restaurant;
use App\Services\Handlers\Synchronization\ProductSynchronizationHandler;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

it('creates products from nested category items', function () {
    [$restaurant, $category] = restaurantWithCategory();

    $result = syncProducts($restaurant, [
        dotsCategoryWithProducts($category->external_id, [dotsProduct('22222222-2222-2222-2222-222222222222', $category->external_id)]),
    ]);

    expect($result['created'])->toBe(1)
        ->and(Product::query()->whereBelongsTo($restaurant)->count())->toBe(1);
});

it('maps all supported fields', function () {
    [$restaurant, $category] = restaurantWithCategory();
    $productData = dotsProduct('22222222-2222-2222-2222-222222222222', $category->external_id, [
        'name' => 'Big Boss',
        'description' => 'Grilled pork cutlet',
        'price' => 105,
        'promotionPrice' => '95.50',
        'image' => 'https://assets.dots.live/example.png',
        'isAvailableToOrder' => false,
    ]);

    syncProducts($restaurant, [dotsCategoryWithProducts($category->external_id, [$productData])]);

    $product = Product::query()->whereBelongsTo($restaurant)->sole();

    expect($product->external_id)->toBe('22222222-2222-2222-2222-222222222222')
        ->and($product->name)->toBe('Big Boss')
        ->and($product->description)->toBe('Grilled pork cutlet')
        ->and($product->price)->toBe('105.00')
        ->and($product->promotion_price)->toBe('95.50')
        ->and($product->image_url)->toBe('https://assets.dots.live/example.png')
        ->and($product->is_available)->toBeFalse();
});

it('takes currency from the restaurant', function () {
    [$restaurant, $category] = restaurantWithCategory(['currency' => 'EUR']);

    syncProducts($restaurant, [
        dotsCategoryWithProducts($category->external_id, [dotsProduct('22222222-2222-2222-2222-222222222222', $category->external_id)]),
    ]);

    expect(Product::query()->whereBelongsTo($restaurant)->sole()->currency)->toBe('EUR');
});

it('preserves decimal prices without float casts', function () {
    [$restaurant, $category] = restaurantWithCategory();

    syncProducts($restaurant, [
        dotsCategoryWithProducts($category->external_id, [
            dotsProduct('22222222-2222-2222-2222-222222222222', $category->external_id, [
                'price' => '105',
                'promotionPrice' => '95.5',
            ]),
        ]),
    ]);

    $product = Product::query()->whereBelongsTo($restaurant)->sole();

    expect($product->price)->toBe('105.00')
        ->and($product->promotion_price)->toBe('95.50');
});

it('preserves the complete product object in original payload', function () {
    [$restaurant, $category] = restaurantWithCategory();
    $productData = dotsProduct('22222222-2222-2222-2222-222222222222', $category->external_id, [
        'fullDescription' => 'Extended description',
        'measureText' => '320 g',
        'packagePrice' => 0,
        'modifiers' => [['id' => 'modifier-id']],
        'unknownField' => ['nested' => true],
    ]);

    syncProducts($restaurant, [dotsCategoryWithProducts($category->external_id, [$productData])]);

    expect(Product::query()->whereBelongsTo($restaurant)->sole()->original_payload)->toBe($productData);
});

it('assigns sort order from the position inside items', function () {
    [$restaurant, $category] = restaurantWithCategory();

    syncProducts($restaurant, [
        dotsCategoryWithProducts($category->external_id, [
            dotsProduct('22222222-2222-2222-2222-222222222222', $category->external_id),
            dotsProduct('33333333-3333-3333-3333-333333333333', $category->external_id),
        ]),
    ]);

    expect(Product::query()->whereBelongsTo($restaurant)->orderBy('sort_order')->pluck('sort_order')->all())
        ->toBe([0, 1]);
});

it('updates an existing product', function () {
    [$restaurant, $category] = restaurantWithCategory();
    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'external_id' => '22222222-2222-2222-2222-222222222222',
        'name' => 'Old Name',
    ]);

    $result = syncProducts($restaurant, [
        dotsCategoryWithProducts($category->external_id, [
            dotsProduct('22222222-2222-2222-2222-222222222222', $category->external_id, ['name' => 'New Name']),
        ]),
    ]);

    expect($result['updated'])->toBe(1)
        ->and($product->refresh()->name)->toBe('New Name');
});

it('updates price and promotion price', function () {
    [$restaurant, $category] = restaurantWithCategory();
    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'external_id' => '22222222-2222-2222-2222-222222222222',
        'price' => '100.00',
        'promotion_price' => null,
    ]);

    syncProducts($restaurant, [
        dotsCategoryWithProducts($category->external_id, [
            dotsProduct('22222222-2222-2222-2222-222222222222', $category->external_id, [
                'price' => '120.25',
                'promotionPrice' => '110.10',
            ]),
        ]),
    ]);

    expect($product->refresh()->price)->toBe('120.25')
        ->and($product->promotion_price)->toBe('110.10');
});

it('updates availability in both directions', function () {
    [$restaurant, $category] = restaurantWithCategory();
    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'external_id' => '22222222-2222-2222-2222-222222222222',
        'is_available' => true,
    ]);

    syncProducts($restaurant, [dotsCategoryWithProducts($category->external_id, [dotsProduct($product->external_id, $category->external_id, ['isAvailableToOrder' => false])])]);
    expect($product->refresh()->is_available)->toBeFalse();

    syncProducts($restaurant, [dotsCategoryWithProducts($category->external_id, [dotsProduct($product->external_id, $category->external_id, ['isAvailableToOrder' => true])])]);
    expect($product->refresh()->is_available)->toBeTrue();
});

it('runs the same payload twice without duplicates', function () {
    [$restaurant, $category] = restaurantWithCategory();
    $payload = [dotsCategoryWithProducts($category->external_id, [dotsProduct('22222222-2222-2222-2222-222222222222', $category->external_id)])];

    syncProducts($restaurant, $payload);
    $result = syncProducts($restaurant, $payload);

    expect($result['unchanged'])->toBe(1)
        ->and(Product::query()->whereBelongsTo($restaurant)->count())->toBe(1)
        ->and(DB::table('category_product')->count())->toBe(1);
});

it('does not change updated at for an unchanged product', function () {
    [$restaurant, $category] = restaurantWithCategory();
    $productData = dotsProduct('22222222-2222-2222-2222-222222222222', $category->external_id);

    syncProducts($restaurant, [dotsCategoryWithProducts($category->external_id, [$productData])]);

    $product = Product::query()->whereBelongsTo($restaurant)->sole();
    $timestamp = Carbon::parse('2026-01-01 12:00:00');
    $product->forceFill(['updated_at' => $timestamp])->save();

    syncProducts($restaurant, [dotsCategoryWithProducts($category->external_id, [$productData])]);

    expect($product->refresh()->updated_at->equalTo($timestamp))->toBeTrue();
});

it('attaches a product to its local category', function () {
    [$restaurant, $category] = restaurantWithCategory();

    $result = syncProducts($restaurant, [dotsCategoryWithProducts($category->external_id, [dotsProduct('22222222-2222-2222-2222-222222222222', $category->external_id)])]);

    $product = Product::query()->whereBelongsTo($restaurant)->sole();

    expect($result['relations_attached'])->toBe(1)
        ->and($category->products->first()->is($product))->toBeTrue();
});

it('does not duplicate an existing pivot relation', function () {
    [$restaurant, $category] = restaurantWithCategory();
    $product = Product::factory()->create(['restaurant_id' => $restaurant->id, 'external_id' => '22222222-2222-2222-2222-222222222222']);
    $category->products()->attach($product);

    $result = syncProducts($restaurant, [dotsCategoryWithProducts($category->external_id, [dotsProduct($product->external_id, $category->external_id)])]);

    expect($result['relations_attached'])->toBe(0)
        ->and(DB::table('category_product')->count())->toBe(1);
});

it('moves a product from an old category to the current Dots category', function () {
    $restaurant = Restaurant::factory()->create();
    $oldCategory = Category::factory()->create(['restaurant_id' => $restaurant->id, 'external_id' => '11111111-1111-1111-1111-111111111111']);
    $newCategory = Category::factory()->create(['restaurant_id' => $restaurant->id, 'external_id' => '22222222-2222-2222-2222-222222222222']);
    $product = Product::factory()->create(['restaurant_id' => $restaurant->id, 'external_id' => '33333333-3333-3333-3333-333333333333']);
    $oldCategory->products()->attach($product);

    $result = syncProducts($restaurant, [dotsCategoryWithProducts($newCategory->external_id, [dotsProduct($product->external_id, $newCategory->external_id)])]);

    expect($result['relations_attached'])->toBe(1)
        ->and($result['relations_detached'])->toBe(1)
        ->and($product->categories()->pluck('categories.id')->all())->toBe([$newCategory->id]);
});

it('returns all counters correctly', function () {
    $restaurant = Restaurant::factory()->create();
    $category = Category::factory()->create(['restaurant_id' => $restaurant->id, 'external_id' => '11111111-1111-1111-1111-111111111111']);
    $oldCategory = Category::factory()->create(['restaurant_id' => $restaurant->id, 'external_id' => '22222222-2222-2222-2222-222222222222']);
    $updated = Product::factory()->create(['restaurant_id' => $restaurant->id, 'external_id' => '33333333-3333-3333-3333-333333333333', 'name' => 'Old']);
    $unchangedData = dotsProduct('44444444-4444-4444-4444-444444444444', $category->external_id);

    Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'external_id' => $unchangedData['id'],
        'name' => $unchangedData['name'],
        'description' => $unchangedData['description'],
        'price' => $unchangedData['price'],
        'promotion_price' => $unchangedData['promotionPrice'],
        'currency' => $restaurant->currency,
        'image_url' => $unchangedData['image'],
        'is_available' => $unchangedData['isAvailableToOrder'],
        'sort_order' => 1,
        'original_payload' => $unchangedData,
    ])->categories()->attach($category);
    $oldCategory->products()->attach($updated);

    $result = syncProducts($restaurant, [
        dotsCategoryWithProducts($category->external_id, [
            dotsProduct($updated->external_id, $category->external_id, ['name' => 'New']),
            $unchangedData,
            dotsProduct('55555555-5555-5555-5555-555555555555', $category->external_id),
        ]),
    ]);

    expect($result)->toBe([
        'created' => 1,
        'updated' => 1,
        'unchanged' => 1,
        'relations_attached' => 2,
        'relations_detached' => 1,
    ]);
});

it('scopes identical product ids by restaurant', function () {
    [$firstRestaurant, $firstCategory] = restaurantWithCategory();
    [$secondRestaurant, $secondCategory] = restaurantWithCategory();
    $productId = '22222222-2222-2222-2222-222222222222';

    syncProducts($firstRestaurant, [dotsCategoryWithProducts($firstCategory->external_id, [dotsProduct($productId, $firstCategory->external_id, ['name' => 'First'])])]);
    syncProducts($secondRestaurant, [dotsCategoryWithProducts($secondCategory->external_id, [dotsProduct($productId, $secondCategory->external_id, ['name' => 'Second'])])]);

    expect(Product::query()->count())->toBe(2)
        ->and(Product::query()->whereBelongsTo($firstRestaurant)->sole()->name)->toBe('First')
        ->and(Product::query()->whereBelongsTo($secondRestaurant)->sole()->name)->toBe('Second');
});

it('does not change another restaurants product or category', function () {
    [$firstRestaurant, $firstCategory] = restaurantWithCategory();
    [$secondRestaurant, $secondCategory] = restaurantWithCategory();
    $otherProduct = Product::factory()->create(['restaurant_id' => $secondRestaurant->id, 'external_id' => '22222222-2222-2222-2222-222222222222', 'name' => 'Other']);
    $secondCategory->products()->attach($otherProduct);

    syncProducts($firstRestaurant, [dotsCategoryWithProducts($firstCategory->external_id, [dotsProduct($otherProduct->external_id, $firstCategory->external_id, ['name' => 'First'])])]);

    expect($otherProduct->refresh()->name)->toBe('Other')
        ->and($otherProduct->categories()->pluck('categories.id')->all())->toBe([$secondCategory->id]);
});

it('rejects a missing local category without database changes', function () {
    $restaurant = Restaurant::factory()->create();
    $categoryId = '11111111-1111-1111-1111-111111111111';

    try {
        syncProducts($restaurant, [dotsCategoryWithProducts($categoryId, [dotsProduct('22222222-2222-2222-2222-222222222222', $categoryId)])]);
    } catch (ValidationException) {
        expect(Product::query()->count())->toBe(0)
            ->and(DB::table('category_product')->count())->toBe(0);

        return;
    }

    $this->fail('Expected missing local category validation to fail.');
});

it('rejects a mismatched company category id', function () {
    [$restaurant, $category] = restaurantWithCategory();

    syncProducts($restaurant, [
        dotsCategoryWithProducts($category->external_id, [
            dotsProduct('22222222-2222-2222-2222-222222222222', '33333333-3333-3333-3333-333333333333'),
        ]),
    ]);
})->throws(ValidationException::class);

it('rejects duplicate product ids across categories', function () {
    $restaurant = Restaurant::factory()->create();
    $firstCategory = Category::factory()->create(['restaurant_id' => $restaurant->id, 'external_id' => '11111111-1111-1111-1111-111111111111']);
    $secondCategory = Category::factory()->create(['restaurant_id' => $restaurant->id, 'external_id' => '22222222-2222-2222-2222-222222222222']);
    $productId = '33333333-3333-3333-3333-333333333333';

    syncProducts($restaurant, [
        dotsCategoryWithProducts($firstCategory->external_id, [dotsProduct($productId, $firstCategory->external_id)]),
        dotsCategoryWithProducts($secondCategory->external_id, [dotsProduct($productId, $secondCategory->external_id)]),
    ]);
})->throws(ValidationException::class);

it('rejects invalid ids', function () {
    $restaurant = Restaurant::factory()->create();

    syncProducts($restaurant, [
        dotsCategoryWithProducts('not-a-uuid', [dotsProduct('also-invalid', 'not-a-uuid')]),
    ]);
})->throws(ValidationException::class);

it('rejects missing required product fields', function (array $productData) {
    [$restaurant, $category] = restaurantWithCategory();

    syncProducts($restaurant, [dotsCategoryWithProducts($category->external_id, [$productData])]);
})->with([
    'missing id' => [[
        'companyCategoryId' => '11111111-1111-1111-1111-111111111111',
        'name' => 'Big Boss',
        'price' => 105,
        'isAvailableToOrder' => true,
    ]],
    'missing name' => [[
        'id' => '22222222-2222-2222-2222-222222222222',
        'companyCategoryId' => '11111111-1111-1111-1111-111111111111',
        'price' => 105,
        'isAvailableToOrder' => true,
    ]],
    'missing price' => [[
        'id' => '22222222-2222-2222-2222-222222222222',
        'companyCategoryId' => '11111111-1111-1111-1111-111111111111',
        'name' => 'Big Boss',
        'isAvailableToOrder' => true,
    ]],
    'missing availability' => [[
        'id' => '22222222-2222-2222-2222-222222222222',
        'companyCategoryId' => '11111111-1111-1111-1111-111111111111',
        'name' => 'Big Boss',
        'price' => 105,
    ]],
])->throws(ValidationException::class);

it('rejects negative or over precision prices', function (array $overrides) {
    [$restaurant, $category] = restaurantWithCategory();

    syncProducts($restaurant, [
        dotsCategoryWithProducts($category->external_id, [
            dotsProduct('22222222-2222-2222-2222-222222222222', $category->external_id, $overrides),
        ]),
    ]);
})->with([
    'negative price' => [['price' => -1]],
    'over precision price' => [['price' => '1.234']],
    'negative promotion price' => [['promotionPrice' => -1]],
    'over precision promotion price' => [['promotionPrice' => '1.234']],
])->throws(ValidationException::class);

it('treats empty input as a non destructive no-op', function () {
    $restaurant = Restaurant::factory()->create();
    Product::factory()->create(['restaurant_id' => $restaurant->id]);

    $result = syncProducts($restaurant, []);

    expect($result)->toBe([
        'created' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'relations_attached' => 0,
        'relations_detached' => 0,
    ])->and(Product::query()->whereBelongsTo($restaurant)->count())->toBe(1);
});

it('leaves products absent from the payload unchanged', function () {
    [$restaurant, $category] = restaurantWithCategory();
    $absentProduct = Product::factory()->create(['restaurant_id' => $restaurant->id, 'is_available' => true]);
    $category->products()->attach($absentProduct);

    syncProducts($restaurant, []);

    expect($absentProduct->refresh()->is_available)->toBeTrue()
        ->and($absentProduct->categories()->count())->toBe(1);
});

it('rolls back product and pivot writes when persistence fails', function () {
    [$restaurant, $category] = restaurantWithCategory();

    Product::creating(function (Product $product): void {
        if ($product->name === 'Explode') {
            throw new RuntimeException('Simulated product persistence failure.');
        }
    });

    try {
        syncProducts($restaurant, [
            dotsCategoryWithProducts($category->external_id, [
                dotsProduct('22222222-2222-2222-2222-222222222222', $category->external_id, ['name' => 'Created']),
                dotsProduct('33333333-3333-3333-3333-333333333333', $category->external_id, ['name' => 'Explode']),
            ]),
        ]);
    } catch (RuntimeException) {
        expect(Product::query()->whereBelongsTo($restaurant)->count())->toBe(0)
            ->and(DB::table('category_product')->count())->toBe(0);

        return;
    }

    $this->fail('Expected product persistence to fail.');
});

function syncProducts(Restaurant $restaurant, array $categories): array
{
    return app(ProductSynchronizationHandler::class)->sync($restaurant, $categories);
}

function restaurantWithCategory(array $restaurantAttributes = []): array
{
    $restaurant = Restaurant::factory()->create($restaurantAttributes);
    $category = Category::factory()->create([
        'restaurant_id' => $restaurant->id,
        'external_id' => '11111111-1111-1111-1111-111111111111',
    ]);

    return [$restaurant, $category];
}

function dotsCategoryWithProducts(string $categoryId, array $products): array
{
    return [
        'id' => $categoryId,
        'name' => 'Burgers',
        'url' => 'burgers',
        'items' => $products,
    ];
}

function dotsProduct(string $id, string $categoryId, array $overrides = []): array
{
    return array_replace([
        'id' => $id,
        'companyCategoryId' => $categoryId,
        'isAvailableToOrder' => true,
        'name' => 'Big Boss',
        'description' => 'Grilled pork cutlet',
        'fullDescription' => 'Extended description',
        'measureText' => '320 g',
        'price' => 105,
        'promotionPrice' => null,
        'packagePrice' => 0,
        'image' => 'https://assets.dots.live/example.png',
        'modifiers' => [],
        'nutrientsData' => [],
        'foodTypes' => [],
        'promoLabelText' => null,
    ], $overrides);
}
