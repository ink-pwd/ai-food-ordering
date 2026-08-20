<?php

namespace App\Models;

use Database\Factories\CityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $external_city_id
 * @property string $name
 * @property string $slug
 * @property bool $is_active
 * @property string|null $center_latitude
 * @property string|null $center_longitude
 * @property string|null $currency
 * @property string|null $timezone
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Restaurant> $restaurants
 */
#[Fillable([
    'external_city_id',
    'name',
    'slug',
    'is_active',
    'center_latitude',
    'center_longitude',
    'currency',
    'timezone',
])]
class City extends Model
{
    /** @use HasFactory<CityFactory> */
    use HasFactory;

    /** @return HasMany<Restaurant, $this> */
    public function restaurants(): HasMany
    {
        return $this->hasMany(Restaurant::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'center_latitude' => 'decimal:7',
            'center_longitude' => 'decimal:7',
        ];
    }
}
