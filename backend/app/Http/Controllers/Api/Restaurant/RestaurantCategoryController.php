<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Restaurant;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RestaurantCategoryController extends Controller
{
    public function index(string $restaurant): AnonymousResourceCollection
    {
        $resolvedRestaurant = Restaurant::query()
            ->where('slug', $restaurant)
            ->where('is_active', true)
            ->firstOrFail();

        $categories = $resolvedRestaurant->categories()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return CategoryResource::collection($categories);
    }
}
