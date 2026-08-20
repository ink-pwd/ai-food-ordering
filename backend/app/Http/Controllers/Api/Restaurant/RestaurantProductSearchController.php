<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use App\Http\Requests\RestaurantProductSearchRequest;
use App\Http\Responses\RestaurantProductSearchResponse;
use App\Services\Handlers\Restaurant\ProductSearchHandler;

class RestaurantProductSearchController extends Controller
{
    public function index(
        RestaurantProductSearchRequest $request,
        ProductSearchHandler $productSearchHandler,
        string $restaurantSlug,
    ): RestaurantProductSearchResponse {
        $products = $productSearchHandler->handle(
            $restaurantSlug,
            $request->searchQuery(),
            $request->resultLimit(),
        );

        return new RestaurantProductSearchResponse($products);
    }
}
