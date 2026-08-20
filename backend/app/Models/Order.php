<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\ReceivingType;
use App\Enums\SessionChannel;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $restaurant_id
 * @property int $cart_id
 * @property string $session_id
 * @property string $idempotency_key
 * @property string|null $external_order_id
 * @property SessionChannel $channel
 * @property OrderStatus $status
 * @property ReceivingType $receiving_type
 * @property int|null $payment_type
 * @property string|null $payment_checkout_url
 * @property array<array-key, mixed>|null $payment_snapshot
 * @property Carbon|null $payment_received_at
 * @property string|null $payment_qr_path
 * @property string|null $payment_qr_fingerprint
 * @property string $customer_name
 * @property string $customer_phone
 * @property string $total
 * @property string $currency
 * @property array<array-key, mixed>|null $fulfillment_snapshot
 * @property array<array-key, mixed>|null $request_payload
 * @property array<array-key, mixed>|null $response_payload
 * @property string|null $failure_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Restaurant $restaurant
 * @property-read Cart $cart
 * @property-read Collection<int, OrderItem> $items
 */
#[Fillable([
    'restaurant_id',
    'cart_id',
    'session_id',
    'idempotency_key',
    'external_order_id',
    'channel',
    'status',
    'receiving_type',
    'payment_type',
    'payment_checkout_url',
    'payment_snapshot',
    'payment_received_at',
    'payment_qr_path',
    'payment_qr_fingerprint',
    'customer_name',
    'customer_phone',
    'total',
    'currency',
    'fulfillment_snapshot',
    'request_payload',
    'response_payload',
    'failure_message',
])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /** @return BelongsTo<Restaurant, $this> */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /** @return BelongsTo<Cart, $this> */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /** @return HasMany<OrderItem, $this> */
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
            'payment_type' => 'integer',
            'payment_snapshot' => 'array',
            'payment_received_at' => 'datetime',
            'fulfillment_snapshot' => 'array',
            'request_payload' => 'array',
            'response_payload' => 'array',
        ];
    }
}
