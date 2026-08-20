<?php

namespace App\Services\Repositories;

use App\Models\Category;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Collection;

class CategoryRepository
{
    /**
     * @return Collection<int, Category>
     */
    public function catalogForRestaurant(Restaurant $restaurant): Collection
    {
        return Category::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('is_active', true)
            ->with(['products' => function ($query) use ($restaurant): void {
                $query->where('products.restaurant_id', $restaurant->id)
                    ->where('products.is_available', true)
                    ->orderBy('products.sort_order')
                    ->orderBy('products.id');
            }])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, Category>
     */
    public function activeForRestaurant(Restaurant $restaurant): Collection
    {
        return Category::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function findActiveForRestaurantByIdOrFail(
        Restaurant $restaurant,
        string $categoryId,
    ): Category {
        return Category::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereKey($categoryId)
            ->where('is_active', true)
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{category: Category, state: 'created'|'updated'|'unchanged'}
     */
    public function upsertForRestaurant(Restaurant $restaurant, string $externalId, array $attributes): array
    {
        $category = Category::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('external_id', $externalId)
            ->first();

        if (! $category) {
            return [
                'category' => Category::query()->create(array_merge($attributes, [
                    'restaurant_id' => $restaurant->id,
                    'external_id' => $externalId,
                ])),
                'state' => 'created',
            ];
        }

        $category->fill($attributes);

        if ($category->isDirty()) {
            $category->save();

            return [
                'category' => $category,
                'state' => 'updated',
            ];
        }

        return [
            'category' => $category,
            'state' => 'unchanged',
        ];
    }
}
