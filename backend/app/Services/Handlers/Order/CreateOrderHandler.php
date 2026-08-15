<?php

namespace App\Services\Handlers\Order;

use App\Enums\CartStatus;
use App\Enums\FulfillmentType;
use App\Enums\OrderStatus;
use App\Enums\ReceivingType;
use App\Enums\SessionChannel;
use App\Enums\PaymentType;
use App\Services\Support\PaymentSelection;
use App\Integrations\Dots\CartPricesApi;
use App\Integrations\Dots\FulfillmentApi;
use App\Integrations\Dots\OrdersApi;
use App\Models\Cart;
use App\Models\City;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\RestaurantAddress;
use App\Services\Repositories\CartRepository;
use App\Services\Repositories\CityRepository;
use App\Services\Repositories\OrderRepository;
use App\Services\Repositories\RestaurantAddressRepository;
use App\Services\Repositories\RestaurantRepository;
use App\Services\Repositories\SessionRepository;
use App\Services\Support\FulfillmentSelection;
use App\Services\Support\SessionSelection;
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
        private readonly CityRepository $cities,
        private readonly RestaurantRepository $restaurants,
        private readonly RestaurantAddressRepository $addresses,
        private readonly CartRepository $carts,
        private readonly OrderRepository $orders,
        private readonly SessionRepository $sessions,
        private readonly CartPricesApi $cartPrices,
        private readonly OrdersApi $dotsOrders,
        private readonly FulfillmentApi $fulfillmentApi,
        private readonly ResolveOrderPaymentHandler $payments,
    ) {}

    /**
     * @param  array<string, mixed>  $session
     * @return array{order: Order, created: bool}
     */
    public function handle(array $session, string $plainToken, string $idempotencyKey, int $deliveryTime): array
    {
        if ($existing = $this->findExistingOrder($idempotencyKey, $session['id'])) {
            if (
                PaymentType::tryFrom((int) $existing->payment_type)?->requiresOnlinePayment() === true
                && $existing->payment_checkout_url === null
                && $existing->external_order_id !== null
            ) {
                $existing = $this->payments->handle($existing)['order'];
            }

            return $this->result($existing, false);
        }

        SessionSelection::assertPhoneVerified($session);
        FulfillmentSelection::assertReady($session);

        $city = $this->resolveCity($session);
        $restaurant = $this->resolveRestaurant($session, $city);
        $paymentType = PaymentSelection::type($session);

        PaymentSelection::assertSupported(
            $restaurant,
            $paymentType,
        );

        $cart = $this->carts->findForSession($restaurant, $session['id']);

        if ($cart === null) {
            throw new NotFoundHttpException('Active cart was not found.');
        }

        $this->assertCartCanCheckout($cart);
        [$customerName, $customerPhone] = $this->contact($session);

        $context = $this->checkoutContext($plainToken, $session, $city, $restaurant);
        $payload = $this->buildPayload($context, $cart, $customerName, $customerPhone, $paymentType, $deliveryTime);
        $priceValidation = $this->validatePrices($payload);
        $validatedTotal = $this->validatedTotal($priceValidation);
        $snapshot = $this->fulfillmentSnapshot(
            $context,
            $priceValidation,
            $paymentType,
        );

        $localResult = $this->createLocalOrder(
            restaurant: $restaurant,
            session: $session,
            idempotencyKey: $idempotencyKey,
            payload: $payload,
            fulfillmentSnapshot: $snapshot,
            receivingType: $context['receiving_type'],
            paymentType: $paymentType,
            validatedTotal: $validatedTotal,
            customerName: $customerName,
            customerPhone: $customerPhone,
            cartSignature: $this->cartSignature($cart),
        );

        if (! $localResult['created']) {
            return $this->result($localResult['order'], false);
        }

        $order = $localResult['order'];

        $this->submitToDots($order, $payload);

        if ($paymentType->requiresOnlinePayment()) {
            $order = $this->payments->handle($order)['order'];
        }

        return $this->result($order, true);
    }

    private function findExistingOrder(string $idempotencyKey, string $sessionId): ?Order
    {
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
     * @param  array<string, mixed>  $session
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $fulfillmentSnapshot
     * @return array{order: Order, created: bool}
     */
    private function createLocalOrder(
        Restaurant $restaurant,
        array $session,
        string $idempotencyKey,
        array $payload,
        array $fulfillmentSnapshot,
        ReceivingType $receivingType,
        PaymentType $paymentType,
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
                $fulfillmentSnapshot,
                $receivingType,
                $paymentType,
                $validatedTotal,
                $customerName,
                $customerPhone,
                $cartSignature,
            ): array {
                $existingByKey = $this->orders->findByIdempotencyKeyForUpdate($idempotencyKey);

                if ($existingByKey !== null) {
                    $this->assertSameSession($existingByKey, $session['id']);

                    return ['order' => $existingByKey, 'created' => false];
                }

                $cart = $this->carts->findForSessionForUpdate($restaurant, $session['id']);

                if ($cart === null) {
                    throw new NotFoundHttpException('Active cart was not found.');
                }

                $cart->load(['items' => fn ($query) => $query->orderBy('id')->with('product')]);
                $this->assertCartCanCheckout($cart);

                if ($this->cartSignature($cart) !== $cartSignature) {
                    throw new ConflictHttpException('Cart changed during checkout. Please confirm the cart again.');
                }

                $existingForCart = $this->orders->findForCartForUpdate($cart->id);

                if ($existingForCart !== null) {
                    if ($existingForCart->idempotency_key !== $idempotencyKey) {
                        throw new ConflictHttpException('An order has already been created for this cart.');
                    }

                    return ['order' => $existingForCart, 'created' => false];
                }

                $order = $this->orders->create(
                    restaurantId: $restaurant->id,
                    cartId: $cart->id,
                    sessionId: $session['id'],
                    idempotencyKey: $idempotencyKey,
                    channel: SessionChannel::from($session['channel']),
                    status: OrderStatus::Creating,
                    receivingType: $receivingType,
                    paymentType: $paymentType->value,
                    customerName: $customerName,
                    customerPhone: $customerPhone,
                    total: $validatedTotal,
                    currency: $cart->currency,
                    fulfillmentSnapshot: $fulfillmentSnapshot,
                    requestPayload: $payload,
                );

                foreach ($cart->items as $item) {
                    $this->orders->createItem($order, $item->product_id, $item->external_product_id, $item->product?->name ?? 'Unknown product', $item->quantity, (string) $item->unit_price, (string) $item->total);
                }

                return ['order' => $order, 'created' => true];
            });
        } catch (QueryException $exception) {
            $existing = $this->orders->findByIdempotencyKey($idempotencyKey);

            if ($existing === null) {
                throw $exception;
            }

            $this->assertSameSession($existing, $session['id']);

            return ['order' => $existing, 'created' => false];
        }
    }

    /** @param array<string, mixed> $payload */
    private function submitToDots(Order $order, array $payload): void
    {
        try {
            $response = $this->dotsOrders->create($payload);
            $externalOrderId = $response['id'] ?? null;

            if (! is_string($externalOrderId) || trim($externalOrderId) === '') {
                throw new RuntimeException('Dots order response does not contain an order id.');
            }

            DB::transaction(function () use ($order, $externalOrderId, $response): void {
                $this->orders->markAcceptedByDots($order, $externalOrderId, $response);
                $this->carts->markCheckedOut($order->cart()->firstOrFail());
            });
        } catch (RequestException $exception) {
            $body = $exception->response->json();
            $message = is_array($body) && is_string($body['message'] ?? null) && $body['message'] !== '' ? $body['message'] : 'Dots rejected order creation.';

            if ($exception->response->clientError()) {
                $this->orders->markFailed($order, $message, is_array($body) ? $body : null);
                throw new HttpException(422, $message, $exception);
            }

            $this->orders->markSubmissionUnknown($order, 'Dots returned a server error while creating order.', is_array($body) ? $body : null);
            throw new HttpException(502, 'Ordering service is temporarily unavailable.', $exception);
        } catch (ConnectionException $exception) {
            $this->orders->markSubmissionUnknown($order, 'Dots connection outcome is unknown.');
            throw new HttpException(503, 'Ordering service is temporarily unavailable.', $exception);
        } catch (RuntimeException $exception) {
            $this->orders->markSubmissionUnknown($order, $exception->getMessage());
            throw new HttpException(502, 'Ordering service returned an invalid response.', $exception);
        }
    }

    /** @param array<string, mixed> $session */
    private function resolveCity(array $session): City
    {
        $city = $this->cities->findActiveById(SessionSelection::cityId($session));

        if ($city === null) {
            throw new NotFoundHttpException('City not found.');
        }

        return $city;
    }

    private function resolveRestaurant(array $session, City $city): Restaurant
    {
        $restaurant = $this->restaurants->findActiveForCityById($city, SessionSelection::restaurantId($session));

        if ($restaurant === null) {
            throw new NotFoundHttpException('Restaurant not found.');
        }

        return $restaurant;
    }

    /**
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    private function checkoutContext(string $plainToken, array $session, City $city, Restaurant $restaurant): array
    {
        $fulfillment = $session['fulfillment'];

        if (($fulfillment['type'] ?? null) === FulfillmentType::Pickup->value) {
            $address = $this->addresses->findActiveForRestaurantById($restaurant, (int) $fulfillment['restaurant_address_id']);

            if ($address === null) {
                throw new ConflictHttpException('Pickup location is no longer available.');
            }

            return [
                'type' => FulfillmentType::Pickup->value,
                'receiving_type' => ReceivingType::Pickup,
                'city' => $city,
                'restaurant' => $restaurant,
                'restaurant_address' => $address,
                'delivery_type' => 2,
                'delivery_price' => null,
                'delivery_address' => null,
            ];
        }

        $deliveryAddress = $fulfillment['delivery_address'] ?? null;

        if (($fulfillment['type'] ?? null) !== FulfillmentType::Delivery->value || ! is_array($deliveryAddress)) {
            throw new ConflictHttpException('Validated delivery address is required.');
        }

        $freshDeliveryType = $this->freshDeliveryType($restaurant, $deliveryAddress);

        if ($freshDeliveryType === null) {
            $this->sessions->updateFulfillment($plainToken, array_merge($fulfillment, [
                'dots_delivery_type' => null,
                'delivery_price' => null,
                'delivery_address' => null,
            ]));

            throw new ConflictHttpException('delivery_unavailable');
        }

        $freshFulfillment = array_merge($fulfillment, [
            'dots_delivery_type' => (int) $freshDeliveryType['type'],
            'delivery_price' => $freshDeliveryType['price'],
        ]);

        $this->sessions->updateFulfillment($plainToken, $freshFulfillment);

        return [
            'type' => FulfillmentType::Delivery->value,
            'receiving_type' => ReceivingType::Delivery,
            'city' => $city,
            'restaurant' => $restaurant,
            'restaurant_address' => null,
            'delivery_type' => (int) $freshDeliveryType['type'],
            'delivery_price' => $freshDeliveryType['price'],
            'delivery_address' => $deliveryAddress,
        ];
    }

    /** @param array<string, mixed> $deliveryAddress */
    private function freshDeliveryType(Restaurant $restaurant, array $deliveryAddress): ?array
    {
        $response = $this->fulfillmentApi->getCompanyDeliveryTypes($restaurant->external_company_id, (string) $deliveryAddress['latitude'], (string) $deliveryAddress['longitude']);
        $items = array_is_list($response) ? $response : ($response['items'] ?? $response['deliveryTypes'] ?? []);

        return is_array($items) ? FulfillmentSelection::acceptableDeliveryType($items) : null;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function buildPayload(array $context, Cart $cart, string $customerName, string $customerPhone, PaymentType $paymentType, int $deliveryTime): array
    {
        /** @var City $city */
        $city = $context['city'];
        /** @var Restaurant $restaurant */
        $restaurant = $context['restaurant'];

        $fields = [
            'cityId' => $city->external_city_id,
            'companyId' => $restaurant->external_company_id,
            'userName' => $customerName,
            'userPhone' => $customerPhone,
            'deliveryType' => $context['delivery_type'],
            'paymentType' => $paymentType->value,
            'deliveryTime' => $deliveryTime,
            'cartItems' => $cart->items->map(static fn ($item): array => ['id' => $item->external_product_id, 'count' => $item->quantity])->values()->all(),
        ];

        if ($context['type'] === FulfillmentType::Pickup->value) {
            /** @var RestaurantAddress $address */
            $address = $context['restaurant_address'];
            $fields['companyAddressId'] = $address->external_address_id;
        } else {
            $deliveryAddress = $context['delivery_address'];
            $fields['deliveryAddressStreet'] = $deliveryAddress['street'];
            $fields['deliveryAddressHouse'] = $deliveryAddress['house'];
        }

        return ['orderFields' => $fields];
    }

    /** @return array<string, mixed> */
    private function validatePrices(array $payload): array
    {
        try {
            return $this->cartPrices->validate($payload);
        } catch (RequestException $exception) {
            $body = $exception->response->json();
            $message = is_array($body) && is_string($body['message'] ?? null) && $body['message'] !== '' ? $body['message'] : 'Dots rejected checkout data.';
            throw new HttpException($exception->response->clientError() ? 422 : 502, $exception->response->clientError() ? $message : 'Ordering service is temporarily unavailable.', $exception);
        } catch (ConnectionException $exception) {
            throw new HttpException(503, 'Ordering service is temporarily unavailable.', $exception);
        }
    }

    private function assertCartCanCheckout(Cart $cart): void
    {
        if ($cart->status !== CartStatus::Active) {
            throw new ConflictHttpException('Cart is not active.');
        }

        if ($cart->expires_at->isPast()) {
            throw ValidationException::withMessages(['cart' => ['Cart has expired.']]);
        }

        if ($cart->items->isEmpty()) {
            throw ValidationException::withMessages(['cart' => ['Cart is empty.']]);
        }

        foreach ($cart->items as $item) {
            if ($item->product === null || ! $item->product->is_available) {
                throw ValidationException::withMessages(['cart' => ["Product {$item->external_product_id} is unavailable."]]);
            }
        }
    }

    /** @param array<string, mixed> $session */
    private function contact(array $session): array
    {
        $contact = SessionSelection::contact($session);

        if ($contact === null || ($contact['phone_verified'] ?? null) !== true) {
            throw ValidationException::withMessages(['contact' => ['Verified customer contact is required before checkout.']]);
        }

        return [trim($contact['name']), ltrim(trim($contact['phone']), '+')];
    }

    /** @param array<string, mixed> $priceValidation */
    private function validatedTotal(array $priceValidation): string
    {
        $total = $priceValidation['totalPrice'] ?? null;

        if (! is_int($total) && ! is_float($total) && ! is_string($total)) {
            throw new RuntimeException('Dots price validation response does not contain totalPrice.');
        }

        $total = trim((string) $total);

        if (preg_match('/^\d+(?:\.\d{1,2})?$/', $total) !== 1) {
            throw new RuntimeException('Dots returned an invalid totalPrice.');
        }

        return $total;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $priceValidation
     * @return array<string, mixed>
     */
    private function fulfillmentSnapshot(array $context, array $priceValidation, PaymentType $paymentType): array
    {
        /** @var City $city */
        $city = $context['city'];
        /** @var Restaurant $restaurant */
        $restaurant = $context['restaurant'];

        return [
            'city_id' => $city->id,
            'external_city_id' => $city->external_city_id,
            'restaurant_id' => $restaurant->id,
            'external_company_id' => $restaurant->external_company_id,
            'type' => $context['type'],
            'dots_delivery_type' => $context['delivery_type'],
            'delivery_price' => $context['delivery_price'],
            'price_validation_delivery_price' => $priceValidation['deliveryPrice'] ?? null,
            'payment_type' => $paymentType->value,
            'restaurant_address_id' => $context['restaurant_address']?->id,
            'external_address_id' => $context['restaurant_address']?->external_address_id,
            'delivery_address' => $context['delivery_address'],
        ];
    }

    private function cartSignature(Cart $cart): string
    {
        return hash('sha256', json_encode($cart->items->map(static fn ($item): array => [
            'id' => $item->id,
            'product_id' => $item->product_id,
            'external_product_id' => $item->external_product_id,
            'quantity' => $item->quantity,
            'unit_price' => (string) $item->unit_price,
            'total' => (string) $item->total,
            'available' => (bool) ($item->product?->is_available ?? false),
        ])->values()->all(), JSON_THROW_ON_ERROR));
    }

    private function assertSameSession(Order $order, string $sessionId): void
    {
        if ($order->session_id !== $sessionId) {
            throw new ConflictHttpException('Idempotency key is already in use.');
        }
    }

    private function result(Order $order, bool $created): array
    {
        return ['order' => $this->orders->refreshWithItems($order), 'created' => $created];
    }
}
