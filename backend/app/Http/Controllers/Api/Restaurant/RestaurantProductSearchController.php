<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use App\Http\Requests\RestaurantProductSearchRequest;
use App\Http\Responses\RestaurantProductSearchResponse;
use App\Models\Restaurant;
use App\Services\Handlers\Restaurant\ProductSearchHandler;

class RestaurantProductSearchController extends Controller
{
    public function index(
        RestaurantProductSearchRequest $request,
        ProductSearchHandler $productSearchHandler,
        string $restaurant,
    ): RestaurantProductSearchResponse {
        $resolvedRestaurant = Restaurant::query()
            ->where('slug', $restaurant)
            ->where('is_active', true)
            ->firstOrFail();

        $products = $productSearchHandler->handle(
            $resolvedRestaurant,
            $request->searchQuery(),
            $request->resultLimit(),
        );

        return new RestaurantProductSearchResponse($products);
    }
}
