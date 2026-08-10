<?php

use App\Enums\CatalogSyncStatus;
use App\Integrations\Dots\CatalogApi;
use App\Models\CatalogSyncLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\Restaurant;
use App\Services\Handlers\Synchronization\CategorySynchronizationHandler;
use App\Services\Handlers\Synchronization\ProductSynchronizationHandler;
use App\Services\Orchestrators\CatalogSynchronizationOrchestrator;
use App\Services\Reconcilers\ProductAvailabilityReconciler;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

afterEach(function () {
    Carbon::setTestNow();
});

it('creates a running log before requesting the catalog', function () {
    $restaurant = Restaurant::factory()->create();

    $this->mock(CatalogApi::class, function ($mock) use ($restaurant) {
        $mock->shouldReceive('refreshCompanyCatalog')
            ->once()
            ->with($restaurant->external_company_id)
            ->andReturnUsing(function () use ($restaurant): array {
                expect(CatalogSyncLog::query()->whereBelongsTo($restaurant)->sole()->status)
                    ->toBe(CatalogSyncStatus::Running);

                return catalogSyncCompleteCatalog();
            });
    });

    app(CatalogSynchronizationOrchestrator::class)->sync($restaurant);
});

it('passes restaurant external company id to refresh company catalog', function () {
    $restaurant = Restaurant::factory()->create(['external_company_id' => '99999999-9999-9999-9999-999999999999']);

    mockCatalogRefresh($restaurant, catalogSyncCompleteCatalog());

    app(CatalogSynchronizationOrchestrator::class)->sync($restaurant);
});

it('uses refresh company catalog rather than cached read method', function () {
    $restaurant = Restaurant::factory()->create();

    $this->mock(CatalogApi::class, function ($mock) use ($restaurant) {
        $mock->shouldReceive('getCompanyCatalog')->never();
        $mock->shouldReceive('refreshCompanyCatalog')
            ->once()
            ->with($restaurant->external_company_id)
            ->andReturn(catalogSyncCompleteCatalog());
    });

    app(CatalogSynchronizationOrchestrator::class)->sync($restaurant);
});

it('runs category synchronization before product synchronization', function () {
    $restaurant = Restaurant::factory()->create();
    $catalog = catalogSyncCompleteCatalog();

    mockCatalogRefresh($restaurant, $catalog);

    $this->mock(CategorySynchronizationHandler::class, function ($mock) use ($restaurant, $catalog) {
        $mock->shouldReceive('sync')
            ->once()
            ->ordered()
            ->with($restaurant, $catalog['items'])
            ->andReturn(['created' => 1, 'updated' => 0, 'unchanged' => 0]);
    });

    $this->mock(ProductSynchronizationHandler::class, function ($mock) use ($restaurant, $catalog) {
        $mock->shouldReceive('sync')
            ->once()
            ->ordered()
            ->with($restaurant, $catalog['items'])
            ->andReturn(['created' => 1, 'updated' => 0, 'unchanged' => 0, 'relations_attached' => 1, 'relations_detached' => 0]);
    });

    $this->mock(ProductAvailabilityReconciler::class, function ($mock) use ($restaurant, $catalog) {
        $mock->shouldReceive('deactivateMissing')
            ->once()
            ->ordered()
            ->with($restaurant, $catalog['items'])
            ->andReturn(0);
    });

    app(CatalogSynchronizationOrchestrator::class)->sync($restaurant);
});

it('creates categories and products from a valid response', function () {
    $restaurant = Restaurant::factory()->create();

    mockCatalogRefresh($restaurant, catalogSyncCompleteCatalog());

    app(CatalogSynchronizationOrchestrator::class)->sync($restaurant);

    expect(Category::query()->whereBelongsTo($restaurant)->count())->toBe(1)
        ->and(Product::query()->whereBelongsTo($restaurant)->count())->toBe(1);
});

it('creates pivot relations', function () {
    $restaurant = Restaurant::factory()->create();

    mockCatalogRefresh($restaurant, catalogSyncCompleteCatalog());

    app(CatalogSynchronizationOrchestrator::class)->sync($restaurant);

    $category = Category::query()->whereBelongsTo($restaurant)->sole();
    $product = Product::query()->whereBelongsTo($restaurant)->sole();

    expect($category->products()->whereKey($product->id)->exists())->toBeTrue();
});

it('marks the log as succeeded', function () {
    $restaurant = Restaurant::factory()->create();

    mockCatalogRefresh($restaurant, catalogSyncCompleteCatalog());

    $log = app(CatalogSynchronizationOrchestrator::class)->sync($restaurant);

    expect($log->status)->toBe(CatalogSyncStatus::Succeeded);
});

it('sets finished at on success', function () {
    $restaurant = Restaurant::factory()->create();

    Carbon::setTestNow(Carbon::parse('2026-08-06 12:00:00'));
    mockCatalogRefresh($restaurant, catalogSyncCompleteCatalog());

    $log = app(CatalogSynchronizationOrchestrator::class)->sync($restaurant);

    expect($log->finished_at)->not->toBeNull()
        ->and($log->finished_at->greaterThanOrEqualTo($log->started_at))->toBeTrue();
});

it('stores the exact category and product counter summary', function () {
    $restaurant = Restaurant::factory()->create();

    mockCatalogRefresh($restaurant, catalogSyncCompleteCatalog());

    $log = app(CatalogSynchronizationOrchestrator::class)->sync($restaurant);

    expect($log->summary)->toBe([
        'categories' => [
            'created' => 1,
            'updated' => 0,
            'unchanged' => 0,
        ],
        'products' => [
            'created' => 1,
            'updated' => 0,
            'unchanged' => 0,
            'relations_attached' => 1,
            'relations_detached' => 0,
            'deactivated' => 0,
        ],
    ]);
});

it('returns the completed catalog sync log', function () {
    $restaurant = Restaurant::factory()->create();

    mockCatalogRefresh($restaurant, catalogSyncCompleteCatalog());

    $log = app(CatalogSynchronizationOrchestrator::class)->sync($restaurant);

    expect($log)->toBeInstanceOf(CatalogSyncLog::class)
        ->and($log->exists)->toBeTrue()
        ->and($log->status)->toBe(CatalogSyncStatus::Succeeded)
        ->and($log->wasRecentlyCreated)->toBeFalse();
});

it('marks missing products unavailable during a successful complete synchronization', function () {
    $restaurant = Restaurant::factory()->create();
    $missingProduct = Product::factory()->create(['restaurant_id' => $restaurant->id, 'is_available' => true]);

    mockCatalogRefresh($restaurant, catalogSyncCompleteCatalog());

    app(CatalogSynchronizationOrchestrator::class)->sync($restaurant);

    expect($missingProduct->refresh()->is_available)->toBeFalse();
});

it('deactivates all available products for an empty complete catalog while preserving rows and pivots', function () {
    $restaurant = Restaurant::factory()->create();
    $category = Category::factory()->create(['restaurant_id' => $restaurant->id, 'is_active' => true]);
    $product = Product::factory()->create(['restaurant_id' => $restaurant->id, 'is_available' => true]);
    $category->products()->attach($product);

    mockCatalogRefresh($restaurant, ['items' => [], 'hasNext' => false]);

    $log = app(CatalogSynchronizationOrchestrator::class)->sync($restaurant);

    expect($log->status)->toBe(CatalogSyncStatus::Succeeded)
        ->and($log->summary['products']['deactivated'])->toBe(1)
        ->and(Category::query()->whereBelongsTo($restaurant)->count())->toBe(1)
        ->and(Product::query()->whereBelongsTo($restaurant)->count())->toBe(1)
        ->and($product->refresh()->is_available)->toBeFalse()
        ->and($product->categories()->count())->toBe(1);
});

it('rejects a missing items field', function () {
    $restaurant = Restaurant::factory()->create();

    mockCatalogRefresh($restaurant, ['hasNext' => false]);

    app(CatalogSynchronizationOrchestrator::class)->sync($restaurant);
})->throws(ValidationException::class);

it('rejects a non list items value', function () {
    $restaurant = Restaurant::factory()->create();

    mockCatalogRefresh($restaurant, ['items' => ['not' => 'a-list'], 'hasNext' => false]);

    app(CatalogSynchronizationOrchestrator::class)->sync($restaurant);
})->throws(ValidationException::class);

it('rejects a missing has next field', function () {
    $restaurant = Restaurant::factory()->create();

    mockCatalogRefresh($restaurant, ['items' => []]);

    app(CatalogSynchronizationOrchestrator::class)->sync($restaurant);
})->throws(ValidationException::class);

it('rejects has next true', function () {
    $restaurant = Restaurant::factory()->create();

    mockCatalogRefresh($restaurant, ['items' => [], 'hasNext' => true]);

    app(CatalogSynchronizationOrchestrator::class)->sync($restaurant);
})->throws(ValidationException::class);

it('makes no catalog changes for an incomplete response', function () {
    $restaurant = Restaurant::factory()->create();

    mockCatalogRefresh($restaurant, ['items' => catalogSyncCompleteCatalog()['items'], 'hasNext' => true]);

    try {
        app(CatalogSynchronizationOrchestrator::class)->sync($restaurant);
    } catch (ValidationException) {
        expect(Category::query()->whereBelongsTo($restaurant)->count())->toBe(0)
            ->and(Product::query()->whereBelongsTo($restaurant)->count())->toBe(0);

        return;
    }

    $this->fail('Expected incomplete catalog validation to fail.');
});

it('marks the log as failed when the api throws', function () {
    $restaurant = Restaurant::factory()->create();

    mockCatalogFailure($restaurant, new RuntimeException('Dots request failed.'));

    try {
        app(CatalogSynchronizationOrchestrator::class)->sync($restaurant);
    } catch (RuntimeException) {
        expect(CatalogSyncLog::query()->whereBelongsTo($restaurant)->sole()->status)
            ->toBe(CatalogSyncStatus::Failed);

        return;
    }

    $this->fail('Expected API failure.');
});

it('marks the log as failed when payload validation throws', function () {
    $restaurant = Restaurant::factory()->create();

    mockCatalogRefresh($restaurant, ['items' => [], 'hasNext' => true]);

    try {
        app(CatalogSynchronizationOrchestrator::class)->sync($restaurant);
    } catch (ValidationException) {
        expect(CatalogSyncLog::query()->whereBelongsTo($restaurant)->sole()->status)
            ->toBe(CatalogSyncStatus::Failed);

        return;
    }

    $this->fail('Expected validation failure.');
});

it('rolls back category changes when product synchronization fails', function () {
    $restaurant = Restaurant::factory()->create();
    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'external_id' => '22222222-2222-2222-2222-222222222222',
    ]);
    $catalog = catalogSyncCompleteCatalog();

    Product::saving(function (Product $savingProduct) use ($product): void {
        if ($savingProduct->is($product)) {
            throw new RuntimeException('Product persistence failed.');
        }
    });

    mockCatalogRefresh($restaurant, $catalog);

    try {
        app(CatalogSynchronizationOrchestrator::class)->sync($restaurant);
    } catch (RuntimeException) {
        expect(Category::query()->whereBelongsTo($restaurant)->count())->toBe(0)
            ->and(Product::query()->whereBelongsTo($restaurant)->count())->toBe(1)
            ->and($product->refresh()->name)->not->toBe('Big Boss');

        return;
    }

    $this->fail('Expected product synchronization to fail.');
});

it('preserves the failed log after catalog rollback', function () {
    $restaurant = Restaurant::factory()->create();

    mockCatalogRefresh($restaurant, ['items' => catalogSyncCompleteCatalog()['items'], 'hasNext' => true]);

    try {
        app(CatalogSynchronizationOrchestrator::class)->sync($restaurant);
    } catch (ValidationException) {
        expect(CatalogSyncLog::query()->whereBelongsTo($restaurant)->count())->toBe(1)
            ->and(CatalogSyncLog::query()->whereBelongsTo($restaurant)->sole()->status)->toBe(CatalogSyncStatus::Failed);

        return;
    }

    $this->fail('Expected synchronization to fail.');
});

it('sets finished at on failure', function () {
    $restaurant = Restaurant::factory()->create();

    mockCatalogFailure($restaurant, new RuntimeException('Dots request failed.'));

    try {
        app(CatalogSynchronizationOrchestrator::class)->sync($restaurant);
    } catch (RuntimeException) {
        expect(CatalogSyncLog::query()->whereBelongsTo($restaurant)->sole()->finished_at)->not->toBeNull();

        return;
    }

    $this->fail('Expected synchronization to fail.');
});

it('leaves failed log summary as null', function () {
    $restaurant = Restaurant::factory()->create();

    mockCatalogFailure($restaurant, new RuntimeException('Dots request failed.'));

    try {
        app(CatalogSynchronizationOrchestrator::class)->sync($restaurant);
    } catch (RuntimeException) {
        expect(CatalogSyncLog::query()->whereBelongsTo($restaurant)->sole()->summary)->toBeNull();

        return;
    }

    $this->fail('Expected synchronization to fail.');
});

it('rethrows the original exception', function () {
    $restaurant = Restaurant::factory()->create();
    $exception = new RuntimeException('Original exception instance.');

    mockCatalogFailure($restaurant, $exception);

    try {
        app(CatalogSynchronizationOrchestrator::class)->sync($restaurant);
    } catch (Throwable $throwable) {
        expect($throwable)->toBe($exception);

        return;
    }

    $this->fail('Expected original exception.');
});

it('rethrows the original exception when failed log persistence also fails', function () {
    $restaurant = Restaurant::factory()->create();
    $exception = new RuntimeException('Original synchronization failure.');

    mockCatalogFailure($restaurant, $exception);

    CatalogSyncLog::saving(function (CatalogSyncLog $log): void {
        if ($log->status === CatalogSyncStatus::Failed) {
            throw new RuntimeException('Failed log persistence failure.');
        }
    });

    try {
        app(CatalogSynchronizationOrchestrator::class)->sync($restaurant);
    } catch (Throwable $throwable) {
        expect($throwable)->toBe($exception);

        return;
    }

    $this->fail('Expected original synchronization exception.');
});

it('stores the exception class name when the original error message is empty', function () {
    $restaurant = Restaurant::factory()->create();

    mockCatalogFailure($restaurant, new RuntimeException);

    try {
        app(CatalogSynchronizationOrchestrator::class)->sync($restaurant);
    } catch (RuntimeException) {
        expect(CatalogSyncLog::query()->whereBelongsTo($restaurant)->sole()->error_message)
            ->toBe(RuntimeException::class);

        return;
    }

    $this->fail('Expected synchronization to fail.');
});

it('redacts configured Dots tokens from the stored error message', function () {
    $restaurant = Restaurant::factory()->create();
    config()->set('services.dots.token', 'public-secret');
    config()->set('services.dots.account_token', 'account-secret');
    config()->set('services.dots.auth_token', 'auth-secret');

    mockCatalogFailure($restaurant, new RuntimeException('Failed with public-secret account-secret auth-secret.'));

    try {
        app(CatalogSynchronizationOrchestrator::class)->sync($restaurant);
    } catch (RuntimeException) {
        $message = CatalogSyncLog::query()->whereBelongsTo($restaurant)->sole()->error_message;

        expect($message)->toBe('Failed with [REDACTED] [REDACTED] [REDACTED].')
            ->and($message)->not->toContain('public-secret')
            ->and($message)->not->toContain('account-secret')
            ->and($message)->not->toContain('auth-secret');

        return;
    }

    $this->fail('Expected synchronization to fail.');
});

it('truncates an excessively long error message', function () {
    $restaurant = Restaurant::factory()->create();

    mockCatalogFailure($restaurant, new RuntimeException(str_repeat('a', 2500)));

    try {
        app(CatalogSynchronizationOrchestrator::class)->sync($restaurant);
    } catch (RuntimeException) {
        expect(strlen(CatalogSyncLog::query()->whereBelongsTo($restaurant)->sole()->error_message))->toBe(2000);

        return;
    }

    $this->fail('Expected synchronization to fail.');
});

it('does not invoke reconciliation when api access fails', function () {
    $restaurant = Restaurant::factory()->create();

    mockCatalogFailure($restaurant, new RuntimeException('Dots request failed.'));

    $this->mock(ProductAvailabilityReconciler::class, function ($mock) {
        $mock->shouldReceive('deactivateMissing')->never();
    });

    try {
        app(CatalogSynchronizationOrchestrator::class)->sync($restaurant);
    } catch (RuntimeException) {
        expect(true)->toBeTrue();

        return;
    }

    $this->fail('Expected API failure.');
});

it('does not invoke reconciliation when top level validation fails', function () {
    $restaurant = Restaurant::factory()->create();

    mockCatalogRefresh($restaurant, ['items' => [], 'hasNext' => true]);

    $this->mock(ProductAvailabilityReconciler::class, function ($mock) {
        $mock->shouldReceive('deactivateMissing')->never();
    });

    try {
        app(CatalogSynchronizationOrchestrator::class)->sync($restaurant);
    } catch (ValidationException) {
        expect(true)->toBeTrue();

        return;
    }

    $this->fail('Expected validation failure.');
});

it('does not invoke reconciliation when category synchronization fails', function () {
    $restaurant = Restaurant::factory()->create();
    $catalog = catalogSyncCompleteCatalog();

    mockCatalogRefresh($restaurant, $catalog);

    $this->mock(CategorySynchronizationHandler::class, function ($mock) use ($restaurant, $catalog) {
        $mock->shouldReceive('sync')
            ->once()
            ->with($restaurant, $catalog['items'])
            ->andThrow(new RuntimeException('Category synchronization failed.'));
    });

    $this->mock(ProductSynchronizationHandler::class, function ($mock) {
        $mock->shouldReceive('sync')->never();
    });

    $this->mock(ProductAvailabilityReconciler::class, function ($mock) {
        $mock->shouldReceive('deactivateMissing')->never();
    });

    try {
        app(CatalogSynchronizationOrchestrator::class)->sync($restaurant);
    } catch (RuntimeException) {
        expect(true)->toBeTrue();

        return;
    }

    $this->fail('Expected category synchronization failure.');
});

it('does not invoke reconciliation when product synchronization fails', function () {
    $restaurant = Restaurant::factory()->create();
    $catalog = catalogSyncCompleteCatalog();

    mockCatalogRefresh($restaurant, $catalog);

    $this->mock(ProductSynchronizationHandler::class, function ($mock) use ($restaurant, $catalog) {
        $mock->shouldReceive('sync')
            ->once()
            ->with($restaurant, $catalog['items'])
            ->andThrow(new RuntimeException('Product synchronization failed.'));
    });

    $this->mock(ProductAvailabilityReconciler::class, function ($mock) {
        $mock->shouldReceive('deactivateMissing')->never();
    });

    try {
        app(CatalogSynchronizationOrchestrator::class)->sync($restaurant);
    } catch (RuntimeException) {
        expect(true)->toBeTrue();

        return;
    }

    $this->fail('Expected product synchronization failure.');
});

it('rolls back previous catalog changes when reconciliation fails', function () {
    $restaurant = Restaurant::factory()->create();
    $catalog = catalogSyncCompleteCatalog();

    mockCatalogRefresh($restaurant, $catalog);

    $this->mock(ProductAvailabilityReconciler::class, function ($mock) use ($restaurant, $catalog) {
        $mock->shouldReceive('deactivateMissing')
            ->once()
            ->with($restaurant, $catalog['items'])
            ->andThrow(new RuntimeException('Reconciliation failed.'));
    });

    try {
        app(CatalogSynchronizationOrchestrator::class)->sync($restaurant);
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Reconciliation failed.')
            ->and(Category::query()->whereBelongsTo($restaurant)->count())->toBe(0)
            ->and(Product::query()->whereBelongsTo($restaurant)->count())->toBe(0);

        return;
    }

    $this->fail('Expected reconciliation failure.');
});

it('marks the log failed when reconciliation fails', function () {
    $restaurant = Restaurant::factory()->create();
    $catalog = catalogSyncCompleteCatalog();

    mockCatalogRefresh($restaurant, $catalog);

    $this->mock(ProductAvailabilityReconciler::class, function ($mock) use ($restaurant, $catalog) {
        $mock->shouldReceive('deactivateMissing')
            ->once()
            ->with($restaurant, $catalog['items'])
            ->andThrow(new RuntimeException('Reconciliation failed.'));
    });

    try {
        app(CatalogSynchronizationOrchestrator::class)->sync($restaurant);
    } catch (RuntimeException) {
        expect(CatalogSyncLog::query()->whereBelongsTo($restaurant)->sole()->status)
            ->toBe(CatalogSyncStatus::Failed);

        return;
    }

    $this->fail('Expected reconciliation failure.');
});

it('rethrows the original reconciliation exception', function () {
    $restaurant = Restaurant::factory()->create();
    $catalog = catalogSyncCompleteCatalog();
    $exception = new RuntimeException('Original reconciliation exception.');

    mockCatalogRefresh($restaurant, $catalog);

    $this->mock(ProductAvailabilityReconciler::class, function ($mock) use ($restaurant, $catalog, $exception) {
        $mock->shouldReceive('deactivateMissing')
            ->once()
            ->with($restaurant, $catalog['items'])
            ->andThrow($exception);
    });

    try {
        app(CatalogSynchronizationOrchestrator::class)->sync($restaurant);
    } catch (Throwable $throwable) {
        expect($throwable)->toBe($exception);

        return;
    }

    $this->fail('Expected reconciliation exception.');
});

it('preserves best effort failure log behavior when reconciliation fails', function () {
    $restaurant = Restaurant::factory()->create();
    $catalog = catalogSyncCompleteCatalog();
    $exception = new RuntimeException('Original reconciliation exception.');

    mockCatalogRefresh($restaurant, $catalog);

    $this->mock(ProductAvailabilityReconciler::class, function ($mock) use ($restaurant, $catalog, $exception) {
        $mock->shouldReceive('deactivateMissing')
            ->once()
            ->with($restaurant, $catalog['items'])
            ->andThrow($exception);
    });

    CatalogSyncLog::saving(function (CatalogSyncLog $log): void {
        if ($log->status === CatalogSyncStatus::Failed) {
            throw new RuntimeException('Failed log persistence failure.');
        }
    });

    try {
        app(CatalogSynchronizationOrchestrator::class)->sync($restaurant);
    } catch (Throwable $throwable) {
        expect($throwable)->toBe($exception);

        return;
    }

    $this->fail('Expected original reconciliation exception.');
});

it('does not change another restaurants catalog', function () {
    $restaurant = Restaurant::factory()->create();
    $otherRestaurant = Restaurant::factory()->create();
    $otherCategory = Category::factory()->create(['restaurant_id' => $otherRestaurant->id, 'name' => 'Other Category']);
    $otherProduct = Product::factory()->create(['restaurant_id' => $otherRestaurant->id, 'name' => 'Other Product']);
    $otherCategory->products()->attach($otherProduct);

    mockCatalogRefresh($restaurant, catalogSyncCompleteCatalog());

    app(CatalogSynchronizationOrchestrator::class)->sync($restaurant);

    expect($otherCategory->refresh()->name)->toBe('Other Category')
        ->and($otherProduct->refresh()->name)->toBe('Other Product')
        ->and($otherProduct->categories()->pluck('categories.id')->all())->toBe([$otherCategory->id]);
});

it('leaves categories and pivots but marks absent products unavailable', function () {
    $restaurant = Restaurant::factory()->create();
    $category = Category::factory()->create(['restaurant_id' => $restaurant->id, 'is_active' => true]);
    $product = Product::factory()->create(['restaurant_id' => $restaurant->id, 'is_available' => true]);
    $category->products()->attach($product);

    mockCatalogRefresh($restaurant, ['items' => [], 'hasNext' => false]);

    app(CatalogSynchronizationOrchestrator::class)->sync($restaurant);

    expect($category->refresh()->is_active)->toBeTrue()
        ->and($product->refresh()->is_available)->toBeFalse()
        ->and($product->categories()->count())->toBe(1);
});

function mockCatalogRefresh(Restaurant $restaurant, array $catalog): void
{
    test()->mock(CatalogApi::class, function ($mock) use ($restaurant, $catalog) {
        $mock->shouldReceive('refreshCompanyCatalog')
            ->once()
            ->with($restaurant->external_company_id)
            ->andReturn($catalog);
    });
}

function mockCatalogFailure(Restaurant $restaurant, Throwable $throwable): void
{
    test()->mock(CatalogApi::class, function ($mock) use ($restaurant, $throwable) {
        $mock->shouldReceive('refreshCompanyCatalog')
            ->once()
            ->with($restaurant->external_company_id)
            ->andThrow($throwable);
    });
}

function catalogSyncCompleteCatalog(array $overrides = []): array
{
    return array_replace_recursive([
        'items' => [
            catalogSyncCategory('11111111-1111-1111-1111-111111111111', [
                catalogSyncProduct('22222222-2222-2222-2222-222222222222', '11111111-1111-1111-1111-111111111111'),
            ]),
        ],
        'hasNext' => false,
        'promotions' => [],
    ], $overrides);
}

function catalogSyncCategory(string $id, array $products): array
{
    return [
        'id' => $id,
        'name' => 'Burgers',
        'url' => 'burgers',
        'items' => $products,
    ];
}

function catalogSyncProduct(string $id, string $categoryId): array
{
    return [
        'id' => $id,
        'companyCategoryId' => $categoryId,
        'isAvailableToOrder' => true,
        'name' => 'Big Boss',
        'description' => 'Grilled pork cutlet',
        'price' => 105,
        'promotionPrice' => null,
        'image' => 'https://assets.dots.live/example.png',
        'modifiers' => [],
    ];
}
