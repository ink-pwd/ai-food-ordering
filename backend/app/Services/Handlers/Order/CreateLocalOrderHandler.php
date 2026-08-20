<?php

namespace App\Services\Handlers\Order;

use App\DTO\OrderCheckoutData;
use App\DTO\OrderPricingData;
use App\DTO\SessionData;
use App\Enums\OrderStatus;
use App\Enums\SessionChannel;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Services\Repositories\CartRepository;
use App\Services\Repositories\OrderRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

readonly class CreateLocalOrderHandler
{
    public function __construct(
        private CartRepository $carts,
        private OrderRepository $orders,
        private ValidateOrderCartHandler $validateCart,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{order: Order, created: bool}
     */
    public function handle(
        OrderCheckoutData $checkout,
        SessionData $session,
        string $idempotencyKey,
        array $payload,
        OrderPricingData $pricing,
    ): array {
        $cartSignature = $this->cartSignature(
            $checkout->cart,
        );

        try {
            // Row locks plus order/item persistence must stay in one atomic checkout transaction.
            return DB::transaction(
                fn (): array => $this->createWithinTransaction(
                    $checkout,
                    $session,
                    $idempotencyKey,
                    $payload,
                    $pricing,
                    $cartSignature,
                ),
            );
        } catch (QueryException $exception) {
            $existing = $this->orders->findByIdempotencyKey(
                $idempotencyKey,
            );

            if ($existing === null) {
                throw $exception;
            }

            $this->assertSameSession(
                $existing,
                $session->id,
            );

            return [
                'order' => $existing,
                'created' => false,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{order: Order, created: bool}
     */
    private function createWithinTransaction(
        OrderCheckoutData $checkout,
        SessionData $session,
        string $idempotencyKey,
        array $payload,
        OrderPricingData $pricing,
        string $cartSignature,
    ): array {
        $existingByKey = $this->orders
            ->findByIdempotencyKeyForUpdate(
                $idempotencyKey,
            );

        if ($existingByKey !== null) {
            $this->assertSameSession(
                $existingByKey,
                $session->id,
            );

            return [
                'order' => $existingByKey,
                'created' => false,
            ];
        }

        $cart = $this->carts->findForSessionForUpdate(
            $checkout->restaurant,
            $session->id,
        );

        if ($cart === null) {
            throw new NotFoundHttpException(
                'Active cart was not found.',
            );
        }

        $cart = $this->carts->loadItemsWithProducts($cart);

        $this->validateCart->handle($cart);

        if (
            $this->cartSignature($cart)
            !== $cartSignature
        ) {
            throw new ConflictHttpException(
                'Cart changed during checkout. Please confirm the cart again.',
            );
        }

        $existingForCart =
            $this->orders->findForCartForUpdate(
                $cart->id,
            );

        if ($existingForCart !== null) {
            if (
                $existingForCart->idempotency_key
                !== $idempotencyKey
            ) {
                throw new ConflictHttpException(
                    'An order has already been created for this cart.',
                );
            }

            return [
                'order' => $existingForCart,
                'created' => false,
            ];
        }

        $order = $this->orders->create(
            restaurantId: $checkout->restaurant->id,
            cartId: $cart->id,
            sessionId: $session->id,
            idempotencyKey: $idempotencyKey,
            channel: SessionChannel::from(
                $session->channel,
            ),
            status: OrderStatus::Creating,
            receivingType: $checkout->receivingType,
            paymentType: $checkout->paymentType->value,
            customerName: $checkout->customerName,
            customerPhone: $checkout->customerPhone,
            total: $pricing->validatedTotal,
            currency: $cart->currency,
            fulfillmentSnapshot:
            $pricing->fulfillmentSnapshot->toArray(),
            requestPayload: $payload,
        );

        /** @var Collection<int, CartItem> $items */
        $items = $cart->items;

        foreach ($items as $item) {
            /** @var Product|null $product */
            $product = $item->product;

            $this->orders->createItem(
                $order,
                $item->product_id,
                $item->external_product_id,
                // @phpstan-ignore nullsafe.neverNull
                $product?->name ?? 'Unknown product',
                $item->quantity,
                (string) $item->unit_price,
                (string) $item->total,
            );
        }

        return [
            'order' => $order,
            'created' => true,
        ];
    }

    private function cartSignature(Cart $cart): string
    {
        /** @var Collection<int, CartItem> $items */
        $items = $cart->items;

        return hash(
            'sha256',
            json_encode(
                $items
                    ->map(
                        static function (
                            CartItem $item,
                        ): array {
                            /** @var Product|null $product */
                            $product = $item->product;

                            return [
                                'id' => $item->id,
                                'product_id' => $item->product_id,
                                'external_product_id' => $item->external_product_id,
                                'quantity' => $item->quantity,
                                'unit_price' => (string) $item->unit_price,
                                'total' => (string) $item->total,
                                'available' => (bool) (
                                    // @phpstan-ignore nullsafe.neverNull
                                    $product?->is_available
                                    ?? false
                                ),
                            ];
                        },
                    )
                    ->values()
                    ->all(),
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    private function assertSameSession(
        Order $order,
        string $sessionId,
    ): void {
        if ($order->session_id !== $sessionId) {
            throw new ConflictHttpException(
                'Idempotency key is already in use.',
            );
        }
    }
}
