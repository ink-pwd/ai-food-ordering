<?php

namespace App\Services\Handlers\Order;

use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Enums\ReceivingType;
use App\Enums\SessionChannel;
use App\Integrations\Dots\CartPricesApi;
use App\Integrations\Dots\OrdersApi;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Restaurant;
use App\Services\Repositories\CartRepository;
use App\Services\Repositories\OrderRepository;
use App\Services\Repositories\RestaurantRepository;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CreateOrderHandler
{
    public function __construct(
        private readonly RestaurantRepository $restaurants,
        private readonly CartRepository $carts,
        private readonly OrderRepository $orders,
        private readonly CartPricesApi $cartPrices,
        private readonly OrdersApi $dotsOrders,
    ) {}

    /**
     * @param  array{id: string, restaurant_id: int, channel: string, external_session_id: string, status: string, metadata: array<string, mixed>, created_at: string, expires_at: string}  $session
     * @return array{order: Order, created: bool}
     */
    public function handle(
        array $session,
        string $idempotencyKey,
        int $deliveryTime,
    ): array {
        if ($existing = $this->findExistingOrder($idempotencyKey, $session['id'])) {
            return $this->result($existing, false);
        }

        $restaurant = $this->restaurants->findActiveById($session['restaurant_id']);

        if ($restaurant === null) {
            throw new NotFoundHttpException;
        }

        $cart = $this->carts->findForSession($restaurant, $session['id']);

        if ($cart === null) {
            throw new NotFoundHttpException('Active cart was not found.');
        }

        $this->assertCartCanCheckout($cart);
        [$customerName, $customerPhone] = $this->contact($session);

        $payload = $this->buildPayload(
            $restaurant,
            $cart,
            $customerName,
            $customerPhone,
            $deliveryTime,
        );

        $localResult = $this->createLocalOrder(
            restaurant: $restaurant,
            session: $session,
            idempotencyKey: $idempotencyKey,
            payload: $payload,
            validatedTotal: $this->validatedTotal($this->validatePrices($payload)),
            customerName: $customerName,
            customerPhone: $customerPhone,
            cartSignature: $this->cartSignature($cart),
        );

        if (! $localResult['created']) {
            return $this->result($localResult['order'], false);
        }

        $this->submitToDots($localResult['order'], $payload);

        return $this->result($localResult['order'], true);
    }

    private function findExistingOrder(
        string $idempotencyKey,
        string $sessionId,
    ): ?Order {
        $order = $this->orders->findByIdempotencyKey($idempotencyKey);

        if ($order === null) {
            return null;
        }

        $this->assertSameSession($order, $sessionId);

        Log::info('Order idempotency hit.', [
            'order_id' => $order->id,
            'status' => $order->status->value,
        ]);

        return $order;
    }

    /**
     * @param  array{id: string, restaurant_id: int, channel: string, external_session_id: string, status: string, metadata: array<string, mixed>, created_at: string, expires_at: string}  $session
     * @param  array<string, mixed>  $payload
     * @return array{order: Order, created: bool}
     */
    private function createLocalOrder(
        Restaurant $restaurant,
        array $session,
        string $idempotencyKey,
        array $payload,
        string $validatedTotal,
        string $customerName,
        string $customerPhone,
        string $cartSignature,
    ): array {
        try {
            return DB::transaction(function () use (
                $restaurant,
                $session,
                $idempotencyKey,
                $payload,
                $validatedTotal,
                $customerName,
                $customerPhone,
                $cartSignature,
            ): array {
                $existingByKey = $this->orders->findByIdempotencyKeyForUpdate($idempotencyKey);

                if ($existingByKey !== null) {
                    $this->assertSameSession($existingByKey, $session['id']);

                    return [
                        'order' => $existingByKey,
                        'created' => false,
                    ];
                }

                $cart = $this->carts->findForSessionForUpdate($restaurant, $session['id']);

                if ($cart === null) {
                    throw new NotFoundHttpException('Active cart was not found.');
                }

                $cart->load([
                    'items' => fn ($query) => $query
                        ->orderBy('id')
                        ->with('product'),
                ]);

                $this->assertCartCanCheckout($cart);

                if ($this->cartSignature($cart) !== $cartSignature) {
                    throw new ConflictHttpException(
                        'Cart changed during checkout. Please confirm the cart again.',
                    );
                }

                $existingForCart = $this->orders->findForCartForUpdate($cart->id);

                if ($existingForCart !== null) {
                    if ($existingForCart->idempotency_key !== $idempotencyKey) {
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
                    restaurantId: $restaurant->id,
                    cartId: $cart->id,
                    sessionId: $session['id'],
                    idempotencyKey: $idempotencyKey,
                    channel: SessionChannel::from($session['channel']),
                    status: OrderStatus::Creating,
                    receivingType: ReceivingType::Pickup,
                    customerName: $customerName,
                    customerPhone: $customerPhone,
                    total: $validatedTotal,
                    currency: $cart->currency,
                    requestPayload: $payload,
                );

                foreach ($cart->items as $item) {
                    $this->orders->createItem(
                        order: $order,
                        productId: $item->product_id,
                        externalProductId: $item->external_product_id,
                        name: $item->product?->name ?? 'Unknown product',
                        quantity: $item->quantity,
                        unitPrice: (string) $item->unit_price,
                        total: (string) $item->total,
                    );
                }

                return [
                    'order' => $order,
                    'created' => true,
                ];
            });
        } catch (QueryException $exception) {
            $existing = $this->orders->findByIdempotencyKey($idempotencyKey);

            if ($existing === null) {
                throw $exception;
            }

            $this->assertSameSession($existing, $session['id']);

            return [
                'order' => $existing,
                'created' => false,
            ];
        }
    }

    /** @param array<string, mixed> $payload */
    private function submitToDots(Order $order, array $payload): void
    {
        try {
            $response = $this->dotsOrders->create($payload);
            $externalOrderId = $response['id'] ?? null;

            if (! is_string($externalOrderId) || trim($externalOrderId) === '') {
                throw new RuntimeException(
                    'Dots order response does not contain an order id.',
                );
            }

            DB::transaction(function () use (
                $order,
                $externalOrderId,
                $response,
            ): void {
                $this->orders->markAcceptedByDots(
                    $order,
                    $externalOrderId,
                    $response,
                );

                $this->carts->markCheckedOut(
                    $order->cart()->firstOrFail(),
                );
            });

            Log::info('Order creation accepted by Dots.', [
                'order_id' => $order->id,
                'external_order_id' => $externalOrderId,
            ]);
        } catch (RequestException $exception) {
            $body = $exception->response->json();

            $message = is_array($body)
            && is_string($body['message'] ?? null)
            && $body['message'] !== ''
                ? $body['message']
                : 'Dots rejected order creation.';

            if ($exception->response->clientError()) {
                $this->orders->markFailed(
                    $order,
                    $message,
                    is_array($body) ? $body : null,
                );

                Log::warning('Dots rejected order creation.', [
                    'order_id' => $order->id,
                    'status_code' => $exception->response->status(),
                    'message' => $message,
                ]);

                throw new HttpException(
                    422,
                    $message,
                    $exception,
                );
            }

            $this->orders->markSubmissionUnknown(
                $order,
                'Dots returned a server error while creating order.',
                is_array($body) ? $body : null,
            );

            Log::error('Dots order creation server failure.', [
                'order_id' => $order->id,
                'status_code' => $exception->response->status(),
            ]);

            throw new HttpException(
                502,
                'Ordering service is temporarily unavailable.',
                $exception,
            );
        } catch (ConnectionException $exception) {
            $this->orders->markSubmissionUnknown(
                $order,
                'Dots connection outcome is unknown.',
            );

            Log::error('Dots order creation connection failure.', [
                'order_id' => $order->id,
            ]);

            throw new HttpException(
                503,
                'Ordering service is temporarily unavailable.',
                $exception,
            );
        } catch (RuntimeException $exception) {
            $this->orders->markSubmissionUnknown(
                $order,
                $exception->getMessage(),
            );

            Log::error('Invalid Dots order response.', [
                'order_id' => $order->id,
                'message' => $exception->getMessage(),
            ]);

            throw new HttpException(
                502,
                'Ordering service returned an invalid response.',
                $exception,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validatePrices(array $payload): array
    {
        try {
            return $this->cartPrices->validate($payload);
        } catch (RequestException $exception) {
            $body = $exception->response->json();
            $message = is_array($body)
            && is_string($body['message'] ?? null)
            && $body['message'] !== ''
                ? $body['message']
                : 'Dots rejected checkout data.';

            Log::warning('Dots checkout validation failed.', [
                'status_code' => $exception->response->status(),
                'message' => $message,
            ]);

            throw new HttpException(
                $exception->response->clientError() ? 422 : 502,
                $exception->response->clientError()
                    ? $message
                    : 'Ordering service is temporarily unavailable.',
                $exception,
            );
        } catch (ConnectionException $exception) {
            throw new HttpException(
                503,
                'Ordering service is temporarily unavailable.',
                $exception,
            );
        }
    }

    private function assertCartCanCheckout(Cart $cart): void
    {
        if ($cart->status !== CartStatus::Active) {
            throw new ConflictHttpException('Cart is not active.');
        }

        if ($cart->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'cart' => ['Cart has expired.'],
            ]);
        }

        if ($cart->items->isEmpty()) {
            throw ValidationException::withMessages([
                'cart' => ['Cart is empty.'],
            ]);
        }

        foreach ($cart->items as $item) {
            if ($item->product === null || ! $item->product->is_available) {
                throw ValidationException::withMessages([
                    'cart' => ["Product {$item->external_product_id} is unavailable."],
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $session
     * @return array{0: string, 1: string}
     */
    private function contact(array $session): array
    {
        $contact = $session['metadata']['contact'] ?? null;

        if (! is_array($contact)) {
            throw ValidationException::withMessages([
                'contact' => ['Customer contact is required before checkout.'],
            ]);
        }

        $name = trim((string) ($contact['name'] ?? ''));
        $rawPhone = trim((string) ($contact['phone'] ?? ''));
        $phone = preg_replace('/\D+/', '', $rawPhone) ?? '';

        if (preg_match('/^0\d{9}$/', $phone) === 1) {
            $phone = '38'.$phone;
        }

        if ($name === '' || mb_strlen($name) > 100) {
            throw ValidationException::withMessages([
                'contact.name' => [
                    'Customer name is required and must not exceed 100 characters.',
                ],
            ]);
        }

        if (preg_match('/^380\d{9}$/', $phone) !== 1) {
            throw ValidationException::withMessages([
                'contact.phone' => ['Phone must be a valid Ukrainian number.'],
            ]);
        }

        return [$name, $phone];
    }

    /** @return array<string, mixed> */
    private function buildPayload(
        Restaurant $restaurant,
        Cart $cart,
        string $customerName,
        string $customerPhone,
        int $deliveryTime,
    ): array {
        $cityId = config('dots.city_id');
        $companyAddressId = config('dots.company_address_id');
        $companyId = $restaurant->external_company_id;

        if (! is_string($cityId) || $cityId === '') {
            throw new RuntimeException('DOTS_CITY_ID is not configured.');
        }

        if (! is_string($companyAddressId) || $companyAddressId === '') {
            throw new RuntimeException(
                'DOTS_COMPANY_ADDRESS_ID is not configured.',
            );
        }

        if (! is_string($companyId) || $companyId === '') {
            throw new RuntimeException(
                'Restaurant does not have a Dots company id.',
            );
        }

        return [
            'orderFields' => [
                'cityId' => $cityId,
                'companyId' => $companyId,
                'companyAddressId' => $companyAddressId,
                'userName' => $customerName,
                'userPhone' => $customerPhone,
                'deliveryType' => 2,
                'paymentType' => 1,
                'deliveryTime' => $deliveryTime,
                'cartItems' => $cart->items
                    ->map(static fn ($item): array => [
                        'id' => $item->external_product_id,
                        'count' => $item->quantity,
                    ])
                    ->values()
                    ->all(),
            ],
        ];
    }

    /** @param array<string, mixed> $priceValidation */
    private function validatedTotal(array $priceValidation): string
    {
        $total = $priceValidation['totalPrice'] ?? null;

        if (! is_int($total) && ! is_float($total) && ! is_string($total)) {
            throw new RuntimeException(
                'Dots price validation response does not contain totalPrice.',
            );
        }

        $total = trim((string) $total);

        if (preg_match('/^\d+(?:\.\d{1,2})?$/', $total) !== 1) {
            throw new RuntimeException('Dots returned an invalid totalPrice.');
        }

        return $total;
    }

    private function cartSignature(Cart $cart): string
    {
        $data = $cart->items
            ->map(static fn ($item): array => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'external_product_id' => $item->external_product_id,
                'quantity' => $item->quantity,
                'unit_price' => (string) $item->unit_price,
                'total' => (string) $item->total,
                'available' => (bool) ($item->product?->is_available ?? false),
            ])
            ->values()
            ->all();

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function assertSameSession(Order $order, string $sessionId): void
    {
        if ($order->session_id !== $sessionId) {
            throw new ConflictHttpException(
                'Idempotency key is already in use.',
            );
        }
    }

    /** @return array{order: Order, created: bool} */
    private function result(Order $order, bool $created): array
    {
        return [
            'order' => $this->orders->refreshWithItems($order),
            'created' => $created,
        ];
    }
}
