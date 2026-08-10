<?php

namespace App\Jobs;

use App\Models\Restaurant;
use App\Services\Orchestrators\CatalogSynchronizationOrchestrator;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Facades\Cache;

#[UniqueFor(3600)]
class SyncRestaurantCatalog implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public function __construct(public int $restaurantId)
    {
        $this->onConnection('rabbitmq');

        $this->onQueue(
            (string) config('queue.queues.catalog_sync'),
        );
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30];
    }

    public function uniqueId(): string
    {
        return (string) $this->restaurantId;
    }

    public function uniqueVia(): Repository
    {
        return Cache::store('redis');
    }

    public function handle(CatalogSynchronizationOrchestrator $catalogSynchronizationOrchestrator): void
    {
        $restaurant = Restaurant::query()->find($this->restaurantId);

        if ($restaurant === null) {
            return;
        }

        if (! $restaurant->is_active) {
            return;
        }

        $catalogSynchronizationOrchestrator->sync($restaurant);
    }
}
