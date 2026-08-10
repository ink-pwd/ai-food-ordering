<?php

namespace App\Services\Repositories;

use App\Models\Category;
use App\Models\Restaurant;

class CategoryRepository
{
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
