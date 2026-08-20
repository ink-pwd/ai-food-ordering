<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Services\Handlers\Restaurant\ListRestaurantCategoryProductsHandler;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RestaurantCategoryProductController extends Controller
{
    public function index(
        ListRestaurantCategoryProductsHandler $listRestaurantCategoryProductsHandler,
        string $restaurantSlug,
        string $categoryId,
    ): AnonymousResourceCollection {
        return ProductResource::collection(
            $listRestaurantCategoryProductsHandler->handle(
                $restaurantSlug,
                $categoryId,
            ),
        );
    }
}
