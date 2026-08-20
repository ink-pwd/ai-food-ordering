<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Controller;
use App\Http\Resources\CatalogCategoryResource;
use App\Services\Handlers\Restaurant\ShowRestaurantCatalogHandler;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RestaurantCatalogController extends Controller
{
    public function show(
        ShowRestaurantCatalogHandler $showRestaurantCatalogHandler,
        string $restaurantSlug,
    ): AnonymousResourceCollection {
        return CatalogCategoryResource::collection(
            $showRestaurantCatalogHandler->handle($restaurantSlug),
        );
    }
}
