<?php

namespace App\Integrations\OrderingBackend;

use App\DTO\OrderingBackend\CartData;
use App\DTO\OrderingBackend\CartItemData;
use Illuminate\Http\Client\Response;

final readonly class CartOrderingBackendClient
{
    public function __construct(
        private OrderingBackendTransport $transport,
    ) {
    }

    public function getOrCreateCurrentCart(
        string $sessionToken,
    ): CartData {
        $this->ensureCurrentCart($sessionToken);

        return $this->currentCart($sessionToken);
    }

    public function ensureCurrentCart(
        string $sessionToken,
    ): CartData {
        $response = $this->transport->sessionBoundPost(
            sessionToken: $sessionToken,
            path: 'api/carts',
            operation: 'ensure_current_cart',
            message: 'Unable to get or create the current ordering backend cart.',
        );

        return $this->cartFromResponse(
            $response,
            'ensure_current_cart',
        );
    }

    public function currentCart(
        string $sessionToken,
    ): CartData {
        $response = $this->transport->sessionBoundGet(
            sessionToken: $sessionToken,
            path: 'api/carts/current',
            operation: 'get_current_cart',
            message: 'Unable to retrieve the current ordering backend cart.',
        );

        return $this->cartFromResponse(
            $response,
            'get_current_cart',
        );
    }

    public function addCurrentCartItem(
        string $sessionToken,
        int $productId,
        int $quantity,
    ): CartData {
        $response = $this->transport->sessionBoundPost(
            sessionToken: $sessionToken,
            path: 'api/carts/current/items',
            operation: 'add_current_cart_item',
            message: 'Unable to add an item to the current ordering backend cart.',
            data: [
                'product_id' => $productId,
                'quantity' => $quantity,
            ],
        );

        return $this->cartFromResponse(
            $response,
            'add_current_cart_item',
        );
    }

    public function updateCurrentCartItem(
        string $sessionToken,
        int $itemId,
        int $quantity,
    ): CartData {
        $response = $this->transport->sessionBoundPatch(
            sessionToken: $sessionToken,
            path: "api/carts/current/items/{$itemId}",
            operation: 'update_current_cart_item',
            message: 'Unable to update an item in the current ordering backend cart.',
            data: [
                'quantity' => $quantity,
            ],
        );

        return $this->cartFromResponse(
            $response,
            'update_current_cart_item',
        );
    }

    public function removeCurrentCartItem(
        int $itemId,
        string $sessionToken,
    ): CartData {
        $this->transport->sessionBoundDelete(
            sessionToken: $sessionToken,
            path: "api/carts/current/items/{$itemId}",
            operation: 'remove_current_cart_item',
            message: 'Unable to remove an item from the current ordering backend cart.',
        );

        return $this->currentCart($sessionToken);
    }

    public function clearCurrentCart(
        string $sessionToken,
    ): CartData {
        $this->transport->sessionBoundDelete(
            sessionToken: $sessionToken,
            path: 'api/carts/current/items',
            operation: 'clear_current_cart',
            message: 'Unable to clear the current ordering backend cart.',
        );

        return $this->currentCart($sessionToken);
    }

    private function isValidCart(mixed $cart): bool
    {
        return is_array($cart)
            && $this->transport->isPositiveInteger(
                $cart['id'] ?? null,
            )
            && $this->transport->isNonEmptyString(
                $cart['status'] ?? null,
            )
            && $this->transport->isNonEmptyString(
                $cart['currency'] ?? null,
            )
            && $this->transport->isNonEmptyString(
                $cart['subtotal'] ?? null,
            )
            && $this->transport->isNonEmptyString(
                $cart['total'] ?? null,
            )
            && $this->transport->isNonEmptyString(
                $cart['expires_at'] ?? null,
            )
            && is_array($cart['items'] ?? null)
            && array_is_list($cart['items']);
    }

    private function isValidCartItem(mixed $item): bool
    {
        return is_array($item)
            && $this->transport->isPositiveInteger(
                $item['id'] ?? null,
            )
            && $this->transport->isPositiveInteger(
                $item['product_id'] ?? null,
            )
            && $this->transport->isNonEmptyString(
                $item['external_product_id'] ?? null,
            )
            && $this->transport->isNonEmptyString(
                $item['name'] ?? null,
            )
            && $this->transport->isPositiveInteger(
                $item['quantity'] ?? null,
            )
            && $this->transport->isNonEmptyString(
                $item['unit_price'] ?? null,
            )
            && $this->transport->isNonEmptyString(
                $item['total'] ?? null,
            );
    }

    private function cartFromResponse(
        Response $response,
        string $operation,
    ): CartData {
        $invalidMessage =
            'Ordering backend returned malformed cart data.';

        $cart = $this->transport->responseData(
            $response,
            $operation,
            $invalidMessage,
        );

        if (! $this->isValidCart($cart)) {
            throw $this->transport->invalidResponse(
                $response,
                $operation,
                $invalidMessage,
            );
        }

        /** @var array{id: int, status: string, currency: string, subtotal: string, total: string, expires_at: string, items: list<mixed>} $cart */
        $items = array_map(
            function (mixed $item) use (
                $response,
                $operation,
                $invalidMessage,
            ): CartItemData {
                if (! $this->isValidCartItem($item)) {
                    throw $this->transport->invalidResponse(
                        $response,
                        $operation,
                        $invalidMessage,
                    );
                }

                /** @var array{id: int, product_id: int, external_product_id: string, name: string, quantity: int, unit_price: string, total: string} $item */
                return new CartItemData(
                    id: $item['id'],
                    productId: $item['product_id'],
                    externalProductId: $item['external_product_id'],
                    name: $item['name'],
                    quantity: $item['quantity'],
                    unitPrice: $item['unit_price'],
                    total: $item['total'],
                );
            },
            $cart['items'],
        );

        return new CartData(
            id: $cart['id'],
            status: $cart['status'],
            currency: $cart['currency'],
            subtotal: $cart['subtotal'],
            total: $cart['total'],
            expiresAt: $cart['expires_at'],
            items: $items,
        );
    }
}
