<?php

namespace App\Services\Repositories;

use App\Enums\CartStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Restaurant;
use Carbon\CarbonInterface;

class CartRepository
{
    /**
     * @return array{cart: Cart, created: bool}
     */
    public function findOrCreateForSession(Restaurant $restaurant, string $sessionId, CarbonInterface $expiresAt): array
    {
        $existingCart = Cart::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('session_id', $sessionId)
            ->where('status', CartStatus::Active)
            ->first();

        if ($existingCart) {
            return [
                'cart' => $existingCart,
                'created' => false,
            ];
        }

        $cart = Cart::query()->createOrFirst(
            [
                'restaurant_id' => $restaurant->id,
                'session_id' => $sessionId,
                'status' => CartStatus::Active,
            ],
            [
                'currency' => $restaurant->currency,
                'subtotal' => 0,
                'total' => 0,
                'expires_at' => $expiresAt,
            ],
        );

        return [
            'cart' => $cart,
            'created' => $cart->wasRecentlyCreated,
        ];
    }

    public function findForSession(Restaurant $restaurant, string $sessionId): ?Cart
    {
        return Cart::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('session_id', $sessionId)
            ->where('status', CartStatus::Active)
            ->with([
                'items' => fn ($query) => $query
                    ->orderBy('id')
                    ->with('product'),
            ])
            ->first();
    }

    public function findForSessionForUpdate(Restaurant $restaurant, string $sessionId): ?Cart
    {
        return Cart::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('session_id', $sessionId)
            ->where('status', CartStatus::Active)
            ->lockForUpdate()
            ->first();
    }

    /**
     * @return array{item: CartItem, created: bool}
     */
    public function createItem(
        Cart $cart,
        Product $product,
        int $quantity,
        string $unitPrice,
        string $total,
    ): array {
        $item = CartItem::query()->createOrFirst(
            [
                'cart_id' => $cart->id,
                'product_id' => $product->id,
            ],
            [
                'external_product_id' => $product->external_id,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total' => $total,
            ],
        );

        return [
            'item' => $item,
            'created' => $item->wasRecentlyCreated,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function itemTotals(Cart $cart): array
    {
        return CartItem::query()
            ->where('cart_id', $cart->id)
            ->pluck('total')
            ->map(static function (mixed $total): string {
                /** @var int|float|string $total */
                return (string) $total;
            })
            ->all();
    }

    public function loadItemsWithProducts(Cart $cart): Cart
    {
        return $cart->load([
            'items' => fn ($query) => $query
                ->orderBy('id')
                ->with('product'),
        ]);
    }

    public function findForOrderOrFail(Order $order): Cart
    {
        return Cart::query()->findOrFail($order->cart_id);
    }

    public function updateTotals(Cart $cart, string $subtotal, string $total): Cart
    {
        $cart->fill([
            'subtotal' => $subtotal,
            'total' => $total,
        ]);

        $cart->save();

        return $cart;
    }

    public function markCheckedOut(Cart $cart): Cart
    {
        $cart->fill([
            'status' => CartStatus::CheckedOut,
        ]);
        $cart->save();

        return $cart;
    }

    public function abandonActiveForSession(int $restaurantId, string $sessionId): int
    {
        return Cart::query()
            ->where('restaurant_id', $restaurantId)
            ->where('session_id', $sessionId)
            ->where('status', CartStatus::Active)
            ->update([
                'status' => CartStatus::Abandoned,
                'updated_at' => now(),
            ]);
    }

    public function hasNonActiveCartForSession(Restaurant $restaurant, string $sessionId): bool
    {
        return Cart::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('session_id', $sessionId)
            ->where('status', '!=', CartStatus::Active)
            ->exists();
    }

    public function refreshWithItems(Cart $cart): Cart
    {
        return Cart::query()
            ->with([
                'items' => fn ($query) => $query
                    ->orderBy('id')
                    ->with('product'),
            ])
            ->findOrFail($cart->id);
    }

    public function findItemForCartForUpdate(Cart $cart, int $itemId): ?CartItem
    {
        return CartItem::query()
            ->where('cart_id', $cart->id)
            ->whereKey($itemId)
            ->lockForUpdate()
            ->first();
    }

    public function updateItem(
        CartItem $item,
        int $quantity,
        string $unitPrice,
        string $total,
    ): CartItem {
        $item->fill([
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total' => $total,
        ]);

        $item->save();

        return $item;
    }

    public function deleteItem(CartItem $item): void
    {
        $item->delete();
    }

    public function deleteItems(Cart $cart): int
    {
        /** @var int $deleted */
        $deleted = CartItem::query()
            ->where('cart_id', $cart->id)
            ->delete();

        return $deleted;
    }
}
