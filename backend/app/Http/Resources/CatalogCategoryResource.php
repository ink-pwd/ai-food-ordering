<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class CatalogCategoryResource extends CategoryResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'products' => ProductResource::collection($this->resource->getRelation('products')),
        ];
    }
}
