<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use App\Http\Resources\CatalogCategoryResource;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RestaurantCatalogController extends Controller
{
    public function show(string $restaurant): AnonymousResourceCollection
    {
        $resolvedRestaurant = Restaurant::query()
            ->where('slug', $restaurant)
            ->where('is_active', true)
            ->firstOrFail();

        $categories = $resolvedRestaurant->categories()
            ->where('is_active', true)
            ->with(['products' => function (BelongsToMany $query) use ($resolvedRestaurant): void {
                $query->where('products.restaurant_id', $resolvedRestaurant->id)
                    ->where('products.is_available', true)
                    ->orderBy('products.sort_order')
                    ->orderBy('products.id');
            }])
            ->orderBy('categories.sort_order')
            ->orderBy('categories.id')
            ->get();

        return CatalogCategoryResource::collection($categories);
    }
}
