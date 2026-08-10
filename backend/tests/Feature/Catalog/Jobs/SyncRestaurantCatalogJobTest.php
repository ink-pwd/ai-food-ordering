<?php

use App\Jobs\SyncRestaurantCatalog;
use App\Models\CatalogSyncLog;
use App\Models\Restaurant;
use App\Services\Orchestrators\CatalogSynchronizationOrchestrator;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Queue;

it('dispatches the job to the queue without publishing to rabbitmq', function () {
    Queue::fake();
    $job = new SyncRestaurantCatalog(123);

    try {
        SyncRestaurantCatalog::dispatch(123);

        Queue::assertPushed(SyncRestaurantCatalog::class, function (SyncRestaurantCatalog $job): bool {
            return $job->restaurantId === 123;
        });
    } finally {
        releaseUniqueLockFor($job);
    }
});

it('pushes only one job for duplicate restaurant dispatches while the unique lock is held', function () {
    Queue::fake();
    $job = new SyncRestaurantCatalog(123);

    try {
        SyncRestaurantCatalog::dispatch(123);
        SyncRestaurantCatalog::dispatch(123);

        Queue::assertPushed(SyncRestaurantCatalog::class, 1);
    } finally {
        releaseUniqueLockFor($job);
    }
});

it('pushes jobs for different restaurants independently', function () {
    Queue::fake();
    $firstJob = new SyncRestaurantCatalog(123);
    $secondJob = new SyncRestaurantCatalog(456);

    try {
        SyncRestaurantCatalog::dispatch(123);
        SyncRestaurantCatalog::dispatch(456);

        Queue::assertPushed(SyncRestaurantCatalog::class, 2);
    } finally {
        releaseUniqueLockFor($firstJob);
        releaseUniqueLockFor($secondJob);
    }
});

it('allows the same restaurant job to be dispatched after releasing the unique lock', function () {
    Queue::fake();
    $job = new SyncRestaurantCatalog(123);

    try {
        SyncRestaurantCatalog::dispatch(123);
        releaseUniqueLockFor($job);
        SyncRestaurantCatalog::dispatch(123);

        Queue::assertPushed(SyncRestaurantCatalog::class, 2);
    } finally {
        releaseUniqueLockFor($job);
    }
});

it('does not serialize the complete restaurant model or catalog secrets', function () {
    $restaurant = Restaurant::factory()->create([
        'external_company_id' => '99999999-9999-9999-9999-999999999999',
        'name' => 'Serialized Model Guard',
    ]);

    config()->set('services.dots.token', 'public-secret');
    config()->set('services.dots.account_token', 'account-secret');
    config()->set('services.dots.auth_token', 'auth-secret');

    $serializedJob = serialize(new SyncRestaurantCatalog($restaurant->id));

    expect($serializedJob)
        ->not->toContain(Restaurant::class)
        ->not->toContain('external_company_id')
        ->not->toContain('99999999-9999-9999-9999-999999999999')
        ->not->toContain('Serialized Model Guard')
        ->not->toContain('public-secret')
        ->not->toContain('account-secret')
        ->not->toContain('auth-secret')
        ->not->toContain('items')
        ->not->toContain('Product')
        ->not->toContain('Category')
        ->not->toContain('Closure');
});

it('passes an active restaurant to the catalog synchronizer', function () {
    $restaurant = Restaurant::factory()->create();

    $this->mock(CatalogSynchronizationOrchestrator::class, function ($mock) use ($restaurant) {
        $mock->shouldReceive('sync')
            ->once()
            ->withArgs(function (Restaurant $synchronizedRestaurant) use ($restaurant): bool {
                return $synchronizedRestaurant->is($restaurant)
                    && $synchronizedRestaurant->is_active === true;
            });
    });

    (new SyncRestaurantCatalog($restaurant->id))->handle(app(CatalogSynchronizationOrchestrator::class));
});

it('loads the restaurant fresh when the job executes', function () {
    $restaurant = Restaurant::factory()->create(['is_active' => false]);
    $job = new SyncRestaurantCatalog($restaurant->id);

    $restaurant->update(['is_active' => true]);

    $this->mock(CatalogSynchronizationOrchestrator::class, function ($mock) use ($restaurant) {
        $mock->shouldReceive('sync')
            ->once()
            ->withArgs(function (Restaurant $synchronizedRestaurant) use ($restaurant): bool {
                return $synchronizedRestaurant->is($restaurant)
                    && $synchronizedRestaurant->is_active === true;
            });
    });

    $job->handle(app(CatalogSynchronizationOrchestrator::class));
});

it('successfully no-ops when the restaurant is missing', function () {
    $this->mock(CatalogSynchronizationOrchestrator::class, function ($mock) {
        $mock->shouldReceive('sync')->never();
    });

    (new SyncRestaurantCatalog(999))->handle(app(CatalogSynchronizationOrchestrator::class));

    expect(CatalogSyncLog::query()->count())->toBe(0);
});

it('successfully no-ops when the restaurant is inactive', function () {
    $restaurant = Restaurant::factory()->create(['is_active' => false]);

    $this->mock(CatalogSynchronizationOrchestrator::class, function ($mock) {
        $mock->shouldReceive('sync')->never();
    });

    (new SyncRestaurantCatalog($restaurant->id))->handle(app(CatalogSynchronizationOrchestrator::class));

    expect(CatalogSyncLog::query()->count())->toBe(0);
});

it('rethrows catalog synchronizer exceptions unchanged', function () {
    $restaurant = Restaurant::factory()->create();
    $exception = new RuntimeException('Original synchronization exception.');

    $this->mock(CatalogSynchronizationOrchestrator::class, function ($mock) use ($exception) {
        $mock->shouldReceive('sync')
            ->once()
            ->andThrow($exception);
    });

    try {
        (new SyncRestaurantCatalog($restaurant->id))->handle(app(CatalogSynchronizationOrchestrator::class));
    } catch (Throwable $throwable) {
        expect($throwable)->toBe($exception);

        return;
    }

    $this->fail('Expected original synchronization exception.');
});

function releaseUniqueLockFor(SyncRestaurantCatalog $job): void
{
    (new UniqueLock(app(Repository::class)))->release($job);
}
