<?php

namespace App\Models;

use App\Enums\CartStatus;
use Database\Factories\CartFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'restaurant_id',
    'session_id',
    'status',
    'currency',
    'subtotal',
    'total',
    'expires_at',
])]
class Cart extends Model
{
    /** @use HasFactory<CartFactory> */
    use HasFactory;

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function order(): HasOne
    {
        return $this->hasOne(Order::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CartStatus::class,
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
            'expires_at' => 'datetime',
        ];
    }
}
