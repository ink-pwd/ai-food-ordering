<?php

use App\Enums\OrderStatus;
use App\Enums\ReceivingType;
use App\Enums\SessionChannel;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Restaurant;
use Illuminate\Database\QueryException;

it('creates an order with expected casts', function () {
    $order = Order::factory()->create([
        'status' => OrderStatus::Creating,
        'receiving_type' => ReceivingType::Pickup,
        'channel' => SessionChannel::ChatGPT,
        'total' => '125.50',
        'request_payload' => [
            'foo' => 'bar',
        ],
        'response_payload' => [
            'order_id' => 'external-order',
        ],
    ]);

    expect($order->status)
        ->toBe(OrderStatus::Creating)
        ->and($order->receiving_type)
        ->toBe(ReceivingType::Pickup)
        ->and($order->channel)
        ->toBe(SessionChannel::ChatGPT)
        ->and($order->total)
        ->toBe('125.50')
        ->and($order->request_payload)
        ->toBe([
            'foo' => 'bar',
        ])
        ->and($order->response_payload)
        ->toBe([
            'order_id' => 'external-order',
        ]);
});

it('belongs to restaurant and cart', function () {
    $order = Order::factory()->create();

    expect($order->restaurant)
        ->toBeInstanceOf(Restaurant::class)
        ->and($order->restaurant->id)
        ->toBe($order->restaurant_id)
        ->and($order->cart)
        ->toBeInstanceOf(Cart::class)
        ->and($order->cart->id)
        ->toBe($order->cart_id);
});

it('has order items', function () {
    $order = Order::factory()->create();

    OrderItem::factory()
        ->count(2)
        ->create([
            'order_id' => $order->id,
        ]);

    expect($order->items)
        ->toHaveCount(2)
        ->each
        ->toBeInstanceOf(OrderItem::class);
});

it('creates an order item snapshot', function () {
    $order = Order::factory()->create();

    $product = Product::factory()->create([
        'restaurant_id' => $order->restaurant_id,
    ]);

    $item = OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'external_product_id' => $product->external_id,
        'name' => $product->name,
        'quantity' => 1,
        'unit_price' => '100.00',
        'total' => '100.00',
    ]);

    expect($item->order->id)
        ->toBe($order->id)
        ->and($item->product->id)
        ->toBe($product->id)
        ->and($item->external_product_id)
        ->toBe($product->external_id)
        ->and($item->name)
        ->toBe($product->name)
        ->and($item->quantity)
        ->toBe(1)
        ->and($item->unit_price)
        ->toBe('100.00')
        ->and($item->total)
        ->toBe('100.00');
});

it('allows only one order per cart', function () {
    $cart = Cart::factory()->create();

    Order::factory()->create([
        'cart_id' => $cart->id,
        'restaurant_id' => $cart->restaurant_id,
        'session_id' => $cart->session_id,
    ]);

    expect(fn () => Order::factory()->create([
        'cart_id' => $cart->id,
        'restaurant_id' => $cart->restaurant_id,
        'session_id' => $cart->session_id,
    ]))->toThrow(QueryException::class);
});

it('requires a unique idempotency key', function () {
    $idempotencyKey = 'checkout_unique_test';

    Order::factory()->create([
        'idempotency_key' => $idempotencyKey,
    ]);

    expect(fn () => Order::factory()->create([
        'idempotency_key' => $idempotencyKey,
    ]))->toThrow(QueryException::class);
});

it('keeps order item snapshot when product changes', function () {
    $order = Order::factory()->create();

    $product = Product::factory()->create([
        'restaurant_id' => $order->restaurant_id,
    ]);

    $item = OrderItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'external_product_id' => $product->external_id,
        'name' => 'Original product name',
        'unit_price' => '100.00',
        'total' => '100.00',
    ]);

    $product->update([
        'name' => 'Changed product name',
    ]);

    $item->refresh();

    expect($item->name)
        ->toBe('Original product name')
        ->and($item->unit_price)
        ->toBe('100.00')
        ->and($item->total)
        ->toBe('100.00');
});
