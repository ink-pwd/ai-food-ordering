<?php

namespace App\Jobs;

use App\Services\Orchestrators\DotsTopologySynchronizationOrchestrator;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Facades\Cache;

#[UniqueFor(3600)]
class SyncDotsTopology implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public function __construct()
    {
        $this->onConnection('rabbitmq');

        /** @var string $queue */
        $queue = config('queue.queues.catalog_sync');

        $this->onQueue(
            (string) $queue,
        );
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function uniqueId(): string
    {
        return 'dots-topology';
    }

    public function uniqueVia(): Repository
    {
        return Cache::store('redis');
    }

    public function handle(DotsTopologySynchronizationOrchestrator $orchestrator): void
    {
        $orchestrator->sync();
    }
}
