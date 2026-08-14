<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RestaurantResource extends JsonResource
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
            'name' => $this->name,
            'slug' => $this->slug,
            'image_url' => $this->image_url,
            'currency' => $this->currency,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'available_payment_types' => $this->available_payment_types ?? [],
            'available_delivery_types' => $this->available_delivery_types ?? [],
            'delivery_time_text' => $this->delivery_time_text,
            'delivery_price_text' => $this->delivery_price_text,
        ];
    }
}
