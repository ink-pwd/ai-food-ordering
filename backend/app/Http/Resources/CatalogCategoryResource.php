<?php

namespace App\Http\Resources;

use App\Models\Category;
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
        /** @var Category $category */
        $category = $this->resource;

        return [
            ...parent::toArray($request),
            'products' => ProductResource::collection($category->getRelation('products')),
        ];
    }
}
