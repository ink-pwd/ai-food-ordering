<?php

namespace App\Http\Responses;

use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestaurantProductSearchResponse implements Responsable
{
    /**
     * @param  Collection<int, Product>  $products
     */
    public function __construct(
        private readonly Collection $products,
    ) {}

    public function toResponse($request): Response
    {
        /** @var Request $request */
        return ProductResource::collection($this->products)->response($request);
    }
}
