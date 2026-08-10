<?php

namespace App\Services\Orchestrators;

use App\Integrations\Dots\CatalogApi;
use App\Models\CatalogSyncLog;
use App\Models\Restaurant;
use App\Services\Handlers\Synchronization\CategorySynchronizationHandler;
use App\Services\Handlers\Synchronization\ProductSynchronizationHandler;
use App\Services\Reconcilers\ProductAvailabilityReconciler;
use App\Services\Repositories\CatalogSyncLogRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class CatalogSynchronizationOrchestrator
{
    public function __construct(
        private readonly CatalogApi $catalogApi,
        private readonly CategorySynchronizationHandler $categorySynchronizationHandler,
        private readonly ProductSynchronizationHandler $productSynchronizationHandler,
        private readonly ProductAvailabilityReconciler $productAvailabilityReconciler,
        private readonly CatalogSyncLogRepository $logs,
    ) {}

    public function sync(Restaurant $restaurant): CatalogSyncLog
    {
        $log = $this->logs->createRunning($restaurant);

        try {
            $catalog = $this->catalogApi->refreshCompanyCatalog($restaurant->external_company_id);

            $this->validateCatalog($catalog);

            DB::transaction(function () use ($restaurant, $catalog, $log): void {
                $categoryResult = $this->categorySynchronizationHandler->sync($restaurant, $catalog['items']);
                $productResult = $this->productSynchronizationHandler->sync($restaurant, $catalog['items']);
                $deactivatedCount = $this->productAvailabilityReconciler->deactivateMissing($restaurant, $catalog['items']);

                $this->logs->markSucceeded($log, [
                    'categories' => $categoryResult,
                    'products' => array_merge($productResult, [
                        'deactivated' => $deactivatedCount,
                    ]),
                ]);
            });

            return $log->fresh();
        } catch (Throwable $throwable) {
            try {
                $this->logs->markFailed($log, $this->safeErrorMessage($throwable));
            } catch (Throwable) {
                // Failure logging is best effort; preserve the original synchronization exception.
            }

            throw $throwable;
        }
    }

    /**
     * @param  array<string, mixed>  $catalog
     */
    private function validateCatalog(array $catalog): void
    {
        $validator = Validator::make($catalog, [
            'items' => ['present', 'array', 'list'],
            'hasNext' => ['required', 'boolean'],
        ]);

        $validator->after(function ($validator) use ($catalog): void {
            if (($catalog['hasNext'] ?? null) !== false) {
                $validator->errors()->add('hasNext', 'The catalog response must be complete.');
            }
        });

        $validator->validate();
    }

    private function safeErrorMessage(Throwable $throwable): string
    {
        $message = $throwable->getMessage() !== ''
            ? $throwable->getMessage()
            : $throwable::class;

        foreach ([
            config('services.dots.token'),
            config('services.dots.account_token'),
            config('services.dots.auth_token'),
        ] as $secret) {
            if (is_string($secret) && $secret !== '') {
                $message = str_replace($secret, '[REDACTED]', $message);
            }
        }

        return Str::limit($message, 2000, '');
    }
}
