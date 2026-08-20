<?php

namespace App\Models;

use Database\Factories\RestaurantAddressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $restaurant_id
 * @property string $external_address_id
 * @property string $title
 * @property string|null $latitude
 * @property string|null $longitude
 * @property array<array-key, mixed>|null $polygon
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Restaurant $restaurant
 */
#[Fillable([
    'restaurant_id',
    'external_address_id',
    'title',
    'latitude',
    'longitude',
    'polygon',
    'is_active',
])]
class RestaurantAddress extends Model
{
    /** @use HasFactory<RestaurantAddressFactory> */
    use HasFactory;

    /** @return BelongsTo<Restaurant, $this> */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'polygon' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
