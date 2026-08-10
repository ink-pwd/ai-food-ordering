<?php

namespace App\Services\Repositories;

use App\Enums\CatalogSyncStatus;
use App\Models\CatalogSyncLog;
use App\Models\Restaurant;

class CatalogSyncLogRepository
{
    public function createRunning(Restaurant $restaurant): CatalogSyncLog
    {
        return CatalogSyncLog::query()->create([
            'restaurant_id' => $restaurant->id,
            'status' => CatalogSyncStatus::Running,
            'started_at' => now(),
            'finished_at' => null,
            'summary' => null,
            'error_message' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public function markSucceeded(CatalogSyncLog $log, array $summary): CatalogSyncLog
    {
        $log->fill([
            'status' => CatalogSyncStatus::Succeeded,
            'finished_at' => now(),
            'summary' => $summary,
            'error_message' => null,
        ])->save();

        return $log->fresh();
    }

    public function markFailed(CatalogSyncLog $log, string $safeErrorMessage): CatalogSyncLog
    {
        $log->forceFill([
            'status' => CatalogSyncStatus::Failed,
            'finished_at' => now(),
            'summary' => null,
            'error_message' => $safeErrorMessage,
        ])->save();

        return $log->fresh();
    }
}
