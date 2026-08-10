<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),

            'product_id' => function (array $attributes): int {
                $order = Order::query()
                    ->findOrFail($attributes['order_id']);

                return Product::factory()->create([
                    'restaurant_id' => $order->restaurant_id,
                ])->id;
            },

            'external_product_id' => function (array $attributes): string {
                return Product::query()
                    ->findOrFail($attributes['product_id'])
                    ->external_id;
            },

            'name' => function (array $attributes): string {
                return Product::query()
                    ->findOrFail($attributes['product_id'])
                    ->name;
            },

            // Намеренно не random — factory должна быть предсказуемой.
            'quantity' => 1,

            'unit_price' => function (array $attributes): string {
                $product = Product::query()
                    ->findOrFail($attributes['product_id']);

                return $product->promotion_price ?? $product->price;
            },

            'total' => function (array $attributes): string {
                $product = Product::query()
                    ->findOrFail($attributes['product_id']);

                return $product->promotion_price ?? $product->price;
            },
        ];
    }
}
