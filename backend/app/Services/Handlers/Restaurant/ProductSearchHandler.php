<?php

namespace App\Services\Handlers\Restaurant;

use App\Models\Product;
use App\Services\Repositories\ProductRepository;
use App\Services\Repositories\RestaurantRepository;
use Illuminate\Database\Eloquent\Collection;

readonly class ProductSearchHandler
{
    public function __construct(
        private RestaurantRepository $restaurants,
        private ProductRepository $products,
    ) {
    }

    /**
     * @return Collection<int, Product>
     */
    public function handle(
        string $restaurantSlug,
        string $query,
        int $limit,
    ): Collection {
        $restaurant = $this->restaurants->findActiveBySlugOrFail($restaurantSlug);

        return $this->products->searchAvailableForRestaurant(
            $restaurant,
            $query,
            $limit,
        );
    }
}
