<?php

namespace App\Services\Handlers\Restaurant;

use App\Models\Category;
use App\Services\Repositories\CategoryRepository;
use App\Services\Repositories\RestaurantRepository;
use Illuminate\Database\Eloquent\Collection;

readonly class ListRestaurantCategoriesHandler
{
    public function __construct(
        private RestaurantRepository $restaurants,
        private CategoryRepository $categories,
    ) {
    }

    /**
     * @return Collection<int, Category>
     */
    public function handle(string $restaurantSlug): Collection
    {
        $restaurant = $this->restaurants->findActiveBySlugOrFail($restaurantSlug);

        return $this->categories->activeForRestaurant($restaurant);
    }
}
