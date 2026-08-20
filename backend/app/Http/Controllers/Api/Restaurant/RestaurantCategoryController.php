<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Services\Handlers\Restaurant\ListRestaurantCategoriesHandler;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RestaurantCategoryController extends Controller
{
    public function index(
        ListRestaurantCategoriesHandler $listRestaurantCategoriesHandler,
        string $restaurantSlug,
    ): AnonymousResourceCollection {
        return CategoryResource::collection(
            $listRestaurantCategoriesHandler->handle($restaurantSlug),
        );
    }
}
