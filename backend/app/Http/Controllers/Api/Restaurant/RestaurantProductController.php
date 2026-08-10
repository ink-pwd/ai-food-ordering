<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Restaurant;

class RestaurantProductController extends Controller
{
    public function show(string $restaurant, string $product): ProductResource
    {
        $resolvedRestaurant = Restaurant::query()
            ->where('slug', $restaurant)
            ->where('is_active', true)
            ->firstOrFail();

        $resolvedProduct = $resolvedRestaurant->products()
            ->whereKey($product)
            ->firstOrFail();

        return new ProductResource($resolvedProduct);
    }
}
