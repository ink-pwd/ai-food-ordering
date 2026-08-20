<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Services\Handlers\Restaurant\ShowRestaurantProductHandler;

class RestaurantProductController extends Controller
{
    public function show(
        ShowRestaurantProductHandler $showRestaurantProductHandler,
        string $restaurantSlug,
        string $productId,
    ): ProductResource {
        return new ProductResource(
            $showRestaurantProductHandler->handle(
                $restaurantSlug,
                $productId,
            ),
        );
    }
}
