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

readonly class CatalogSynchronizationOrchestrator
{
    public function __construct(
        private CatalogApi $catalogApi,
        private CategorySynchronizationHandler $categorySynchronizationHandler,
        private ProductSynchronizationHandler $productSynchronizationHandler,
        private ProductAvailabilityReconciler $productAvailabilityReconciler,
        private CatalogSyncLogRepository $logs,
    ) {
    }

    public function sync(
        Restaurant $restaurant,
    ): CatalogSyncLog {
        $log = $this->logs->createRunning(
            $restaurant,
        );

        try {
            $catalog =
                $this->catalogApi
                    ->refreshCompanyCatalog(
                        $restaurant
                            ->external_company_id,
                    );

            $this->validateCatalog(
                $catalog,
            );

            // Categories, products, availability reconciliation, and the success log form one catalog snapshot.
            DB::transaction(
                fn (): null => $this
                    ->syncCatalogWithinTransaction(
                        $restaurant,
                        $catalog,
                        $log,
                    ),
            );

            return $this->logs->refresh(
                $log,
            );
        } catch (Throwable $throwable) {
            try {
                $this->logs->markFailed(
                    $log,
                    $this->safeErrorMessage(
                        $throwable,
                    ),
                );
            } catch (Throwable) {
                // Failure logging is best effort; preserve the original synchronization exception.
            }

            throw $throwable;
        }
    }

    /**
     * @param  array<string, mixed>  $catalog
     */
    private function syncCatalogWithinTransaction(
        Restaurant $restaurant,
        array $catalog,
        CatalogSyncLog $log,
    ): null {
        /** @var array<int, array<string, mixed>> $catalogItems */
        $catalogItems = $catalog['items'];

        $categoryResult =
            $this
                ->categorySynchronizationHandler
                ->sync(
                    $restaurant,
                    $catalogItems,
                );

        $productResult =
            $this
                ->productSynchronizationHandler
                ->sync(
                    $restaurant,
                    $catalogItems,
                );

        $deactivatedCount =
            $this
                ->productAvailabilityReconciler
                ->deactivateMissing(
                    $restaurant,
                    $catalogItems,
                );

        $this->logs->markSucceeded(
            $log,
            [
                'categories' => $categoryResult,

                'products' => array_merge(
                    $productResult->toArray(),
                    [
                        'deactivated' => $deactivatedCount,
                    ],
                ),
            ],
        );

        return null;
    }

    /**
     * @param  array<string, mixed>  $catalog
     */
    private function validateCatalog(
        array $catalog,
    ): void {
        $validator = Validator::make(
            $catalog,
            [
                'items' => [
                    'present',
                    'array',
                    'list',
                ],

                'hasNext' => [
                    'required',
                    'boolean',
                ],
            ],
        );

        $validator->after(
            function (
                $validator,
            ) use ($catalog): void {
                if (
                    ($catalog['hasNext'] ?? null)
                    !== false
                ) {
                    $validator
                        ->errors()
                        ->add(
                            'hasNext',
                            'The catalog response must be complete.',
                        );
                }
            },
        );

        $validator->validate();
    }

    private function safeErrorMessage(
        Throwable $throwable,
    ): string {
        $message =
            $throwable->getMessage() !== ''
                ? $throwable->getMessage()
                : $throwable::class;

        foreach (
            [
                config(
                    'services.dots.token',
                ),
                config(
                    'services.dots.account_token',
                ),
                config(
                    'services.dots.auth_token',
                ),
            ]
            as $secret
        ) {
            if (
                is_string($secret)
                && $secret !== ''
            ) {
                $message = str_replace(
                    $secret,
                    '[REDACTED]',
                    $message,
                );
            }
        }

        return Str::limit(
            $message,
            2000,
            '',
        );
    }
}
