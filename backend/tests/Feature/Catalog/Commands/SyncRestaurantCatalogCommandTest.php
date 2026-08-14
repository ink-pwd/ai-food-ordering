<?php

use App\Jobs\SyncDotsTopology;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Queue;

it('queues Dots topology synchronization without a restaurant slug', function () {
    Queue::fake();
    $job = new SyncDotsTopology;

    try {
        $this->artisan('catalog:sync')
            ->expectsOutput('Catalog topology synchronization queued.')
            ->assertSuccessful();

        Queue::assertPushed(SyncDotsTopology::class, 1);
        Queue::assertPushed(SyncDotsTopology::class, function (SyncDotsTopology $job): bool {
            return $job instanceof ShouldBeUnique;
        });
    } finally {
        releaseCommandTestTopologyUniqueLockFor($job);
    }
});

it('does not expose configured Dots tokens or credentials in output', function () {
    Queue::fake();
    $job = new SyncDotsTopology;

    config()->set('services.dots.token', 'fake-public-token');
    config()->set('services.dots.account_token', 'fake-account-token');
    config()->set('services.dots.auth_token', 'fake-auth-token');

    try {
        $this->artisan('catalog:sync')
            ->expectsOutput('Catalog topology synchronization queued.')
            ->doesntExpectOutputToContain('fake-public-token')
            ->doesntExpectOutputToContain('fake-account-token')
            ->doesntExpectOutputToContain('fake-auth-token')
            ->assertSuccessful();
    } finally {
        releaseCommandTestTopologyUniqueLockFor($job);
    }
});

it('does not queue duplicate topology jobs for repeated command invocations while the unique lock is held', function () {
    Queue::fake();
    $job = new SyncDotsTopology;

    try {
        $this->artisan('catalog:sync')->assertSuccessful();
        $this->artisan('catalog:sync')->assertSuccessful();

        Queue::assertPushed(SyncDotsTopology::class, 1);
    } finally {
        releaseCommandTestTopologyUniqueLockFor($job);
    }
});

function releaseCommandTestTopologyUniqueLockFor(SyncDotsTopology $job): void
{
    (new UniqueLock(app(Repository::class)))->release($job);
}
