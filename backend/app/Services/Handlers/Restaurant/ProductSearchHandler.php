<?php

namespace App\Services\Handlers\Restaurant;

use App\Models\Product;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Collection;

class ProductSearchHandler
{
    /**
     * @return Collection<int, Product>
     */
    public function handle(Restaurant $restaurant, string $query, int $limit): Collection
    {
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
}
