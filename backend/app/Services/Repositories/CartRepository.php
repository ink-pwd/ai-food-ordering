<?php

namespace App\Services\Repositories;

use App\Enums\CartStatus;
use App\Models\Cart;
use App\Models\CartItem;
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
            ],
            [
                'status' => CartStatus::Active,
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
            ->map(static fn ($total): string => (string) $total)
            ->all();
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
        return CartItem::query()
            ->where('cart_id', $cart->id)
            ->delete();
    }
}
