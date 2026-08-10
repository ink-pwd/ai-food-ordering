<?php

namespace App\Services\Repositories;

use App\Models\Category;
use App\Models\Product;
use App\Models\Restaurant;

class ProductRepository
{
    /**
     * @param  array<int, string>  $externalIds
     * @return array<string, int>
     */
    public function categoryIdsByExternalId(Restaurant $restaurant, array $externalIds): array
    {
        return Category::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereIn('external_id', $externalIds)
            ->pluck('id', 'external_id')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{product: Product, state: 'created'|'updated'|'unchanged'}
     */
    public function upsertForRestaurant(Restaurant $restaurant, string $externalId, array $attributes): array
    {
        $product = Product::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('external_id', $externalId)
            ->first();

        if (! $product) {
            return [
                'product' => Product::query()->create(array_merge($attributes, [
                    'restaurant_id' => $restaurant->id,
                    'external_id' => $externalId,
                ])),
                'state' => 'created',
            ];
        }

        $product->fill($attributes);

        if ($product->isDirty()) {
            $product->save();

            return [
                'product' => $product,
                'state' => 'updated',
            ];
        }

        return [
            'product' => $product,
            'state' => 'unchanged',
        ];
    }

    /**
     * @return array{attached: int, detached: int}
     */
    public function syncCategoryRelation(Product $product, int $categoryId, Restaurant $restaurant): array
    {
        if ($product->restaurant_id !== $restaurant->id) {
            return ['attached' => 0, 'detached' => 0];
        }

        $targetCategoryExists = Category::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereKey($categoryId)
            ->exists();

        if (! $targetCategoryExists) {
            return ['attached' => 0, 'detached' => 0];
        }

        $currentCategoryIds = $product->categories()
            ->where('categories.restaurant_id', $restaurant->id)
            ->pluck('categories.id');
        $categoryIdsToDetach = $currentCategoryIds->reject(fn (int $currentCategoryId): bool => $currentCategoryId === $categoryId)->values();
        $detached = 0;
        $attached = 0;

        if ($categoryIdsToDetach->isNotEmpty()) {
            $product->categories()->detach($categoryIdsToDetach->all());
            $detached = $categoryIdsToDetach->count();
        }

        if (! $currentCategoryIds->contains($categoryId)) {
            $product->categories()->attach($categoryId);
            $attached = 1;
        }

        return [
            'attached' => $attached,
            'detached' => $detached,
        ];
    }

    /**
     * @param  array<int, string>  $presentExternalIds
     */
    public function deactivateMissingForRestaurant(Restaurant $restaurant, array $presentExternalIds): int
    {
        $query = Product::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('is_available', true);

        if ($presentExternalIds !== []) {
            $query->whereNotIn('external_id', $presentExternalIds);
        }

        return $query->update([
            'is_available' => false,
            'updated_at' => now(),
        ]);
    }

    public function findForRestaurantById(Restaurant $restaurant, int $productId): ?Product
    {
        return Product::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereKey($productId)
            ->first();
    }
}
