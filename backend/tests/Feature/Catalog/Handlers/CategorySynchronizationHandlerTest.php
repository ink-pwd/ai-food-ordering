<?php

use App\Models\Category;
use App\Models\Restaurant;
use App\Services\Handlers\Synchronization\CategorySynchronizationHandler;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

it('creates categories for the supplied restaurant', function () {
    $restaurant = Restaurant::factory()->create();

    $result = syncCategories($restaurant, categorySyncPayload());

    expect($result)->toBe([
        'created' => 2,
        'updated' => 0,
        'unchanged' => 0,
    ])->and(Category::query()->whereBelongsTo($restaurant)->count())->toBe(2);
});

it('maps id name and url correctly', function () {
    $restaurant = Restaurant::factory()->create();

    syncCategories($restaurant, [
        dotsCategory('11111111-1111-1111-1111-111111111111', 'Burgers', 'burgers'),
    ]);

    $category = Category::query()->whereBelongsTo($restaurant)->sole();

    expect($category->external_id)->toBe('11111111-1111-1111-1111-111111111111')
        ->and($category->name)->toBe('Burgers')
        ->and($category->slug)->toBe('burgers');
});

it('assigns sort order from array position starting at zero', function () {
    $restaurant = Restaurant::factory()->create();

    syncCategories($restaurant, categorySyncPayload());

    expect(Category::query()->whereBelongsTo($restaurant)->orderBy('sort_order')->pluck('sort_order')->all())
        ->toBe([0, 1]);
});

it('updates an existing category', function () {
    $restaurant = Restaurant::factory()->create();
    $category = Category::factory()->create([
        'restaurant_id' => $restaurant->id,
        'external_id' => '11111111-1111-1111-1111-111111111111',
        'name' => 'Old name',
        'slug' => 'old-slug',
        'sort_order' => 9,
    ]);

    $result = syncCategories($restaurant, [
        dotsCategory('11111111-1111-1111-1111-111111111111', 'New name', 'new-slug'),
    ]);

    $category->refresh();

    expect($result)->toBe([
        'created' => 0,
        'updated' => 1,
        'unchanged' => 0,
    ])->and($category->name)->toBe('New name')
        ->and($category->slug)->toBe('new-slug')
        ->and($category->sort_order)->toBe(0);
});

it('reactivates an inactive category', function () {
    $restaurant = Restaurant::factory()->create();
    $category = Category::factory()->create([
        'restaurant_id' => $restaurant->id,
        'external_id' => '11111111-1111-1111-1111-111111111111',
        'is_active' => false,
    ]);

    $result = syncCategories($restaurant, [
        dotsCategory('11111111-1111-1111-1111-111111111111', $category->name, $category->slug),
    ]);

    expect($result['updated'])->toBe(1)
        ->and($category->refresh()->is_active)->toBeTrue();
});

it('returns correct created updated and unchanged counters', function () {
    $restaurant = Restaurant::factory()->create();

    Category::factory()->create([
        'restaurant_id' => $restaurant->id,
        'external_id' => '11111111-1111-1111-1111-111111111111',
        'name' => 'Unchanged',
        'slug' => 'unchanged',
        'sort_order' => 0,
        'is_active' => true,
    ]);

    Category::factory()->create([
        'restaurant_id' => $restaurant->id,
        'external_id' => '22222222-2222-2222-2222-222222222222',
        'name' => 'Old',
        'slug' => 'old',
        'sort_order' => 5,
        'is_active' => true,
    ]);

    $result = syncCategories($restaurant, [
        dotsCategory('11111111-1111-1111-1111-111111111111', 'Unchanged', 'unchanged'),
        dotsCategory('22222222-2222-2222-2222-222222222222', 'Updated', 'updated'),
        dotsCategory('33333333-3333-3333-3333-333333333333', 'Created', 'created'),
    ]);

    expect($result)->toBe([
        'created' => 1,
        'updated' => 1,
        'unchanged' => 1,
    ]);
});

it('runs the same payload twice without creating duplicates', function () {
    $restaurant = Restaurant::factory()->create();
    $payload = categorySyncPayload();

    syncCategories($restaurant, $payload);
    $result = syncCategories($restaurant, $payload);

    expect($result)->toBe([
        'created' => 0,
        'updated' => 0,
        'unchanged' => 2,
    ])->and(Category::query()->whereBelongsTo($restaurant)->count())->toBe(2);
});

it('does not change updated at for an unchanged category', function () {
    $restaurant = Restaurant::factory()->create();
    $timestamp = Carbon::parse('2026-01-01 12:00:00');

    $category = Category::factory()->create([
        'restaurant_id' => $restaurant->id,
        'external_id' => '11111111-1111-1111-1111-111111111111',
        'name' => 'Burgers',
        'slug' => 'burgers',
        'sort_order' => 0,
        'is_active' => true,
        'updated_at' => $timestamp,
    ]);

    syncCategories($restaurant, [
        dotsCategory('11111111-1111-1111-1111-111111111111', 'Burgers', 'burgers'),
    ]);

    expect($category->refresh()->updated_at->equalTo($timestamp))->toBeTrue();
});

it('scopes identical external ids by restaurant', function () {
    $firstRestaurant = Restaurant::factory()->create();
    $secondRestaurant = Restaurant::factory()->create();
    $externalId = '11111111-1111-1111-1111-111111111111';

    syncCategories($firstRestaurant, [dotsCategory($externalId, 'First', 'first')]);
    syncCategories($secondRestaurant, [dotsCategory($externalId, 'Second', 'second')]);

    expect(Category::query()->count())->toBe(2)
        ->and(Category::query()->whereBelongsTo($firstRestaurant)->sole()->name)->toBe('First')
        ->and(Category::query()->whereBelongsTo($secondRestaurant)->sole()->name)->toBe('Second');
});

it('does not change another restaurants category', function () {
    $firstRestaurant = Restaurant::factory()->create();
    $secondRestaurant = Restaurant::factory()->create();
    $externalId = '11111111-1111-1111-1111-111111111111';

    $otherCategory = Category::factory()->create([
        'restaurant_id' => $secondRestaurant->id,
        'external_id' => $externalId,
        'name' => 'Other',
        'slug' => 'other',
    ]);

    syncCategories($firstRestaurant, [dotsCategory($externalId, 'First', 'first')]);

    expect($otherCategory->refresh()->name)->toBe('Other')
        ->and($otherCategory->slug)->toBe('other');
});

it('ignores nested product items', function () {
    $restaurant = Restaurant::factory()->create();

    syncCategories($restaurant, [
        array_merge(dotsCategory('11111111-1111-1111-1111-111111111111', 'Burgers', 'burgers'), [
            'items' => [
                ['id' => 'product-id', 'name' => 'Product'],
            ],
        ]),
    ]);

    expect(Category::query()->whereBelongsTo($restaurant)->count())->toBe(1);
});

it('treats an empty array as a non destructive no-op', function () {
    $restaurant = Restaurant::factory()->create();
    Category::factory()->create(['restaurant_id' => $restaurant->id]);

    $result = syncCategories($restaurant, []);

    expect($result)->toBe([
        'created' => 0,
        'updated' => 0,
        'unchanged' => 0,
    ])->and(Category::query()->whereBelongsTo($restaurant)->count())->toBe(1);
});

it('does not delete or deactivate categories missing from the payload', function () {
    $restaurant = Restaurant::factory()->create();
    $missingCategory = Category::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_active' => true,
    ]);

    syncCategories($restaurant, [
        dotsCategory('11111111-1111-1111-1111-111111111111', 'Burgers', 'burgers'),
    ]);

    expect($missingCategory->refresh()->exists)->toBeTrue()
        ->and($missingCategory->is_active)->toBeTrue();
});

it('rejects a missing id', function () {
    $restaurant = Restaurant::factory()->create();

    syncCategories($restaurant, [['name' => 'Burgers', 'url' => 'burgers']]);
})->throws(ValidationException::class);

it('rejects a non uuid id', function () {
    $restaurant = Restaurant::factory()->create();

    syncCategories($restaurant, [dotsCategory('not-a-uuid', 'Burgers', 'burgers')]);
})->throws(ValidationException::class);

it('rejects a missing name', function () {
    $restaurant = Restaurant::factory()->create();

    syncCategories($restaurant, [[
        'id' => '11111111-1111-1111-1111-111111111111',
        'url' => 'burgers',
    ]]);
})->throws(ValidationException::class);

it('uses url as fallback when the category name is empty', function () {
    $restaurant = Restaurant::factory()->create();

    syncCategories($restaurant, [[
        'id' => '11111111-1111-1111-1111-111111111111',
        'name' => '',
        'url' => 'burgers',
    ]]);

    expect(Category::query()
        ->where('restaurant_id', $restaurant->id)
        ->where('external_id', '11111111-1111-1111-1111-111111111111')
        ->value('name'))
        ->toBe('burgers');
});

it('makes no database changes when any item in the payload is invalid', function () {
    $restaurant = Restaurant::factory()->create();

    try {
        syncCategories($restaurant, [
            dotsCategory('11111111-1111-1111-1111-111111111111', 'Burgers', 'burgers'),
            dotsCategory('not-a-uuid', 'Invalid', 'invalid'),
        ]);
    } catch (ValidationException) {
        expect(Category::query()->whereBelongsTo($restaurant)->count())->toBe(0);

        return;
    }

    $this->fail('Expected category payload validation to fail.');
});

it('rolls back all writes if persistence fails inside the transaction', function () {
    $restaurant = Restaurant::factory()->create();

    Category::creating(function (Category $category): void {
        if ($category->name === 'Explode') {
            throw new RuntimeException('Simulated persistence failure.');
        }
    });

    try {
        syncCategories($restaurant, [
            dotsCategory('11111111-1111-1111-1111-111111111111', 'Burgers', 'burgers'),
            dotsCategory('22222222-2222-2222-2222-222222222222', 'Explode', 'explode'),
        ]);
    } catch (RuntimeException) {
        expect(Category::query()->whereBelongsTo($restaurant)->count())->toBe(0);

        return;
    }

    $this->fail('Expected category persistence to fail.');
});

function syncCategories(Restaurant $restaurant, array $categories): array
{
    return app(CategorySynchronizationHandler::class)->sync($restaurant, $categories);
}

function categorySyncPayload(): array
{
    return [
        dotsCategory('11111111-1111-1111-1111-111111111111', 'Burgers', 'burgers'),
        dotsCategory('22222222-2222-2222-2222-222222222222', 'Pizza', 'pizza'),
    ];
}

function dotsCategory(string $id, string $name, string $url): array
{
    return [
        'id' => $id,
        'name' => $name,
        'url' => $url,
        'items' => [],
    ];
}
