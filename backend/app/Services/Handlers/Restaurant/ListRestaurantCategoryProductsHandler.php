<?php

namespace App\Services\Handlers\Restaurant;

use App\Models\Product;
use App\Services\Repositories\CategoryRepository;
use App\Services\Repositories\ProductRepository;
use App\Services\Repositories\RestaurantRepository;
use Illuminate\Database\Eloquent\Collection;

readonly class ListRestaurantCategoryProductsHandler
{
    public function __construct(
        private RestaurantRepository $restaurants,
        private CategoryRepository $categories,
        private ProductRepository $products,
    ) {
    }

    /**
     * @return Collection<int, Product>
     */
    public function handle(
        string $restaurantSlug,
        string $categoryId,
    ): Collection {
        $restaurant = $this->restaurants->findActiveBySlugOrFail($restaurantSlug);

        $category = $this->categories->findActiveForRestaurantByIdOrFail(
            $restaurant,
            $categoryId,
        );

        return $this->products->availableForCategory(
            $restaurant,
            $category,
        );
    }
}
