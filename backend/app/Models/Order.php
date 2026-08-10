<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\ReceivingType;
use App\Enums\SessionChannel;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'restaurant_id',
    'cart_id',
    'session_id',
    'idempotency_key',
    'external_order_id',
    'channel',
    'status',
    'receiving_type',
    'customer_name',
    'customer_phone',
    'total',
    'currency',
    'request_payload',
    'response_payload',
    'failure_message',
])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    protected function casts(): array
    {
        return [
            'channel' => SessionChannel::class,
            'status' => OrderStatus::class,
            'receiving_type' => ReceivingType::class,
            'total' => 'decimal:2',
            'request_payload' => 'array',
            'response_payload' => 'array',
        ];
    }
}
