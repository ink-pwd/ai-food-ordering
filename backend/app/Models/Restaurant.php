<?php

namespace App\Models;

use Database\Factories\RestaurantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $city_id
 * @property string $external_company_id
 * @property string $name
 * @property string $slug
 * @property string $currency
 * @property string $locale
 * @property string $timezone
 * @property bool $is_active
 * @property string|null $image_url
 * @property array<array-key, mixed>|null $available_payment_types
 * @property array<array-key, mixed>|null $available_delivery_types
 * @property array<array-key, mixed>|null $schedule
 * @property string|null $delivery_time_text
 * @property string|null $delivery_price_text
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read City|null $city
 * @property-read Collection<int, Category> $categories
 * @property-read Collection<int, Product> $products
 * @property-read Collection<int, CatalogSyncLog> $catalogSyncLogs
 * @property-read Collection<int, Cart> $carts
 * @property-read Collection<int, Order> $orders
 * @property-read Collection<int, RestaurantAddress> $addresses
 */
#[Fillable([
    'city_id',
    'external_company_id',
    'name',
    'slug',
    'currency',
    'locale',
    'timezone',
    'is_active',
    'image_url',
    'available_payment_types',
    'available_delivery_types',
    'schedule',
    'delivery_time_text',
    'delivery_price_text',
])]
class Restaurant extends Model
{
    /** @use HasFactory<RestaurantFactory> */
    use HasFactory;

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** @return HasMany<Category, $this> */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** @return HasMany<CatalogSyncLog, $this> */
    public function catalogSyncLogs(): HasMany
    {
        return $this->hasMany(CatalogSyncLog::class);
    }

    /** @return HasMany<Cart, $this> */
    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasMany<RestaurantAddress, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(RestaurantAddress::class);
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
            'available_payment_types' => 'array',
            'available_delivery_types' => 'array',
            'schedule' => 'array',
        ];
    }
}
