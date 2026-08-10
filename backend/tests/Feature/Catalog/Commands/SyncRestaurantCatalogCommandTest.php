<?php

use App\Jobs\SyncRestaurantCatalog;
use App\Models\Restaurant;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Queue;

it('queues catalog synchronization for an active restaurant selected by slug', function () {
    Queue::fake();

    $restaurant = Restaurant::factory()->create(['slug' => 'active-restaurant']);
    $job = new SyncRestaurantCatalog($restaurant->id);

    try {
        $this->artisan('catalog:sync', ['restaurant' => 'active-restaurant'])
            ->expectsOutput("Catalog synchronization queued: id={$restaurant->id} slug=active-restaurant")
            ->assertSuccessful();

        Queue::assertPushed(SyncRestaurantCatalog::class, 1);
        Queue::assertPushed(SyncRestaurantCatalog::class, function (SyncRestaurantCatalog $job) use ($restaurant): bool {
            return $job->restaurantId === $restaurant->id
                && $job instanceof ShouldBeUnique;
        });
    } finally {
        releaseCommandTestUniqueLockFor($job);
    }
});

it('fails for a missing restaurant and dispatches nothing', function () {
    Queue::fake();

    $this->artisan('catalog:sync', ['restaurant' => 'missing-restaurant'])
        ->expectsOutput('Restaurant not found: slug=missing-restaurant')
        ->assertFailed();

    Queue::assertNotPushed(SyncRestaurantCatalog::class);
});

it('fails for an inactive restaurant and dispatches nothing', function () {
    Queue::fake();

    $restaurant = Restaurant::factory()->create([
        'slug' => 'inactive-restaurant',
        'is_active' => false,
    ]);

    $this->artisan('catalog:sync', ['restaurant' => 'inactive-restaurant'])
        ->expectsOutput("Restaurant is inactive: id={$restaurant->id} slug=inactive-restaurant")
        ->assertFailed();

    Queue::assertNotPushed(SyncRestaurantCatalog::class);
});

it('does not expose configured dots tokens or credentials in output', function () {
    Queue::fake();

    config()->set('services.dots.token', 'fake-public-token');
    config()->set('services.dots.account_token', 'fake-account-token');
    config()->set('services.dots.auth_token', 'fake-auth-token');

    $restaurant = Restaurant::factory()->create(['slug' => 'token-safe-restaurant']);
    $job = new SyncRestaurantCatalog($restaurant->id);

    try {
        $this->artisan('catalog:sync', ['restaurant' => 'token-safe-restaurant'])
            ->expectsOutput("Catalog synchronization queued: id={$restaurant->id} slug=token-safe-restaurant")
            ->doesntExpectOutputToContain('fake-public-token')
            ->doesntExpectOutputToContain('fake-account-token')
            ->doesntExpectOutputToContain('fake-auth-token')
            ->assertSuccessful();
    } finally {
        releaseCommandTestUniqueLockFor($job);
    }
});

it('does not queue duplicate jobs for repeated command invocations while the unique lock is held', function () {
    Queue::fake();

    $restaurant = Restaurant::factory()->create(['slug' => 'duplicate-command-restaurant']);
    $job = new SyncRestaurantCatalog($restaurant->id);

    try {
        $this->artisan('catalog:sync', ['restaurant' => 'duplicate-command-restaurant'])
            ->assertSuccessful();
        $this->artisan('catalog:sync', ['restaurant' => 'duplicate-command-restaurant'])
            ->assertSuccessful();

        Queue::assertPushed(SyncRestaurantCatalog::class, 1);
    } finally {
        releaseCommandTestUniqueLockFor($job);
    }
});

function releaseCommandTestUniqueLockFor(SyncRestaurantCatalog $job): void
{
    (new UniqueLock(app(Repository::class)))->release($job);
}
