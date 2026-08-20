<?php

namespace App\Services\Reconcilers;

use App\Models\Restaurant;
use App\Services\Repositories\ProductRepository;

readonly class ProductAvailabilityReconciler
{
    public function __construct(
        private ProductRepository $products,
    ) {
    }

    /**
     * @param  array<int, array<string, mixed>>  $categories
     */
    public function deactivateMissing(Restaurant $restaurant, array $categories): int
    {
        /** @var array<int, string> $presentProductIds */
        $presentProductIds = collect($categories)
            ->flatMap(static function (array $category): array {
                /** @var array<int, array<string, mixed>> $items */
                $items = $category['items'] ?? [];

                return $items;
            })
            ->pluck('id')
            ->filter()
            ->values()
            ->all();

        return $this->products->deactivateMissingForRestaurant($restaurant, $presentProductIds);
    }
}
