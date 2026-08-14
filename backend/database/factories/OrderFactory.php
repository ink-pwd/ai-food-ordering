<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\ReceivingType;
use App\Enums\SessionChannel;
use App\Models\Cart;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'cart_id' => Cart::factory(),

            'restaurant_id' => function (array $attributes): int {
                return Cart::query()
                    ->findOrFail($attributes['cart_id'])
                    ->restaurant_id;
            },

            'session_id' => function (array $attributes): string {
                return Cart::query()
                    ->findOrFail($attributes['cart_id'])
                    ->session_id;
            },

            'idempotency_key' => 'checkout_'.fake()->unique()->uuid(),

            'external_order_id' => null,

            'channel' => SessionChannel::ChatGPT->value,
            'status' => OrderStatus::Draft->value,
            'receiving_type' => ReceivingType::Pickup->value,
            'payment_type' => 2,
            'payment_checkout_url' => null,
            'payment_snapshot' => null,
            'payment_received_at' => null,
            'payment_qr_path' => null,
            'payment_qr_fingerprint' => null,

            'customer_name' => fake()->name(),
            'customer_phone' => '+380'.fake()->numerify('#########'),

            'total' => function (array $attributes): string {
                return Cart::query()
                    ->findOrFail($attributes['cart_id'])
                    ->total;
            },

            'currency' => function (array $attributes): string {
                return Cart::query()
                    ->findOrFail($attributes['cart_id'])
                    ->currency;
            },

            'request_payload' => null,
            'fulfillment_snapshot' => null,
            'response_payload' => null,
            'failure_message' => null,
        ];
    }
}
