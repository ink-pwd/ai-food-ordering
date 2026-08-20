<?php

namespace App\Services\Handlers\Synchronization;

use App\Jobs\SyncRestaurantCatalog;
use App\Services\Repositories\RestaurantRepository;

readonly class DispatchRestaurantCatalogSyncJobsHandler
{
    public function __construct(
        private RestaurantRepository $restaurants,
    ) {
    }

    public function handle(): int
    {
        $dispatched = 0;

        foreach (
            $this->restaurants->activeSynchronized()
            as $restaurant
        ) {
            SyncRestaurantCatalog::dispatch(
                $restaurant->id,
            );

            $dispatched++;
        }

        return $dispatched;
    }
}
