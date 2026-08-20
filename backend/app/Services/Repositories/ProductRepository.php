<?php

namespace App\Services\Repositories;

use App\Models\Category;
use App\Models\Product;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Collection;

class ProductRepository
{
    public function findForRestaurantByIdOrFail(
        Restaurant $restaurant,
        string $productId,
    ): Product {
        return Product::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereKey($productId)
            ->firstOrFail();
    }

    /**
     * @return Collection<int, Product>
     */
    public function availableForCategory(
        Restaurant $restaurant,
        Category $category,
    ): Collection {
        return $category->products()
            ->where('products.restaurant_id', $restaurant->id)
            ->where('products.is_available', true)
            ->orderBy('products.sort_order')
            ->orderBy('products.id')
            ->get();
    }

    /**
     * @return Collection<int, Product>
     */
    public function searchAvailableForRestaurant(
        Restaurant $restaurant,
        string $query,
        int $limit,
    ): Collection {
        $pattern = $this->likePattern($query);

        return Product::query()
            ->where('products.restaurant_id', $restaurant->id)
            ->where('products.is_available', true)
            ->where(function ($searchQuery) use ($restaurant, $pattern): void {
                $searchQuery
                    ->whereRaw("products.name ILIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("products.description ILIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereHas('categories', function ($categoryQuery) use ($restaurant, $pattern): void {
                        $categoryQuery
                            ->where('categories.restaurant_id', $restaurant->id)
                            ->where('categories.is_active', true)
                            ->whereRaw("categories.name ILIKE ? ESCAPE '!'", [$pattern]);
                    });
            })
            ->orderBy('products.sort_order')
            ->orderBy('products.id')
            ->limit($limit)
            ->get();
    }

    private function likePattern(string $query): string
    {
        return '%'.str_replace(
            ['!', '%', '_'],
            ['!!', '!%', '!_'],
            $query,
        ).'%';
    }

    /**
     * @param  array<int, string>  $externalIds
     * @return array<string, int>
     */
    public function categoryIdsByExternalId(Restaurant $restaurant, array $externalIds): array
    {
        /** @var array<string, int> $categoryIds */
        $categoryIds = Category::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereIn('external_id', $externalIds)
            ->pluck('id', 'external_id')
            ->all();

        return $categoryIds;
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
