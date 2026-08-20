<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $restaurant_id
 * @property string $external_id
 * @property string $name
 * @property string|null $description
 * @property string $price
 * @property string|null $promotion_price
 * @property string $currency
 * @property string|null $image_url
 * @property bool $is_available
 * @property int $sort_order
 * @property array<array-key, mixed>|null $original_payload
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Restaurant $restaurant
 * @property-read Collection<int, Category> $categories
 * @property-read Collection<int, CartItem> $cartItems
 * @property-read Collection<int, OrderItem> $orderItems
 */
#[Fillable([
    'restaurant_id',
    'external_id',
    'name',
    'description',
    'price',
    'promotion_price',
    'currency',
    'image_url',
    'is_available',
    'sort_order',
    'original_payload',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /** @return BelongsTo<Restaurant, $this> */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /** @return BelongsToMany<Category, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class)->withTimestamps();
    }

    /** @return HasMany<CartItem, $this> */
    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    /** @return HasMany<OrderItem, $this> */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'promotion_price' => 'decimal:2',
            'is_available' => 'boolean',
            'sort_order' => 'integer',
            'original_payload' => 'array',
        ];
    }
}
