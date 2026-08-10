<?php

namespace App\Services\Reconcilers;

use App\Models\Restaurant;
use App\Services\Repositories\ProductRepository;

class ProductAvailabilityReconciler
{
    public function __construct(
        private readonly ProductRepository $products,
    ) {}

    /**
     * @param  array<int, array{id?: string, items?: array<int, array{id?: string}>}>  $categories
     */
    public function deactivateMissing(Restaurant $restaurant, array $categories): int
    {
        $presentProductIds = collect($categories)
            ->flatMap(fn (array $category): array => $category['items'] ?? [])
            ->pluck('id')
            ->filter()
            ->values()
            ->all();

        return $this->products->deactivateMissingForRestaurant($restaurant, $presentProductIds);
    }
}
