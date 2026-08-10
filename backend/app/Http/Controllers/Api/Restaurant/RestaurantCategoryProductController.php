<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Restaurant;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RestaurantCategoryProductController extends Controller
{
    public function index(string $restaurant, string $category): AnonymousResourceCollection
    {
        $resolvedRestaurant = Restaurant::query()
            ->where('slug', $restaurant)
            ->where('is_active', true)
            ->firstOrFail();

        $resolvedCategory = $resolvedRestaurant->categories()
            ->whereKey($category)
            ->where('is_active', true)
            ->firstOrFail();

        $products = $resolvedCategory->products()
            ->where('products.restaurant_id', $resolvedRestaurant->id)
            ->where('products.is_available', true)
            ->orderBy('products.sort_order')
            ->orderBy('products.id')
            ->get();

        return ProductResource::collection($products);
    }
}
