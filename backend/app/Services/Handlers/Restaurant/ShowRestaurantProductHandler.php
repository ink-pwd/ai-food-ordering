<?php

namespace App\Services\Handlers\Restaurant;

use App\Models\Product;
use App\Services\Repositories\ProductRepository;
use App\Services\Repositories\RestaurantRepository;

readonly class ShowRestaurantProductHandler
{
    public function __construct(
        private RestaurantRepository $restaurants,
        private ProductRepository $products,
    ) {
    }

    public function handle(
        string $restaurantSlug,
        string $productId,
    ): Product {
        $restaurant = $this->restaurants->findActiveBySlugOrFail($restaurantSlug);

        return $this->products->findForRestaurantByIdOrFail(
            $restaurant,
            $productId,
        );
    }
}
