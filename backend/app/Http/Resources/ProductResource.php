<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'external_id' => $this->external_id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'promotion_price' => $this->promotion_price,
            'currency' => $this->currency,
            'image_url' => $this->image_url,
            'is_available' => $this->is_available,
            'sort_order' => $this->sort_order,
        ];
    }
}
