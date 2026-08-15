<?php

namespace App\Integrations\OrderingBackend;

use App\Exceptions\OrderingBackendException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use JsonException;
use Psr\Log\LoggerInterface;

final class OrderingBackendClient
{
    public function __construct(
        private readonly Factory $http,
        private readonly LoggerInterface $logger,
    ) {}

    public function createTelegramSession(string $externalSessionId): string
    {
        try {
            $response = $this->request()
                ->post('api/sessions', [
                    'channel' => 'telegram',
                    'external_session_id' => $externalSessionId,
                ])
                ->throw();
        } catch (ConnectionException|RequestException $exception) {
            throw $this->requestException(
                operation: 'create_telegram_session',
                message: 'Unable to create an ordering backend session.',
                exception: $exception,
            );
        }

        $sessionToken = $response->json('data.session_token');

        if (! is_string($sessionToken) || trim($sessionToken) === '') {
            $this->logger->error('Ordering backend returned an invalid response.', [
                'operation' => 'create_telegram_session',
                'status' => $response->status(),
            ]);

            throw new OrderingBackendException('Ordering backend response did not contain a session token.');
        }

        return $sessionToken;
    }

    public function updateCurrentSessionContact(
        string $sessionToken,
        string $name,
        string $phone,
    ): void {
        try {
            $response = $this->request($sessionToken)
                ->put('api/sessions/current/contact', [
                    'name' => $name,
                    'phone' => $phone,
                ])
                ->throw();
        } catch (ConnectionException|RequestException $exception) {
            throw $this->requestException(
                operation: 'update_current_session_contact',
                message: 'Unable to update the ordering backend contact.',
                exception: $exception,
            );
        }

        if (! $this->isValidContactResponse($response)) {
            $this->logger->error('Ordering backend returned an invalid response.', [
                'operation' => 'update_current_session_contact',
                'status' => $response->status(),
            ]);

            throw new OrderingBackendException('Ordering backend response did not contain valid contact data.');
        }
    }

    /**
     * @return array{session_id: string, payment_type: int}
     */
    public function updateCurrentSessionPayment(
        string $sessionToken,
        int $paymentType,
    ): array {
        $response = $this->sessionBoundPut(
            sessionToken: $sessionToken,
            path: 'api/sessions/current/payment',
            operation: 'update_current_session_payment',
            message: 'Unable to update the ordering backend payment method.',
            data: [
                'payment_type' => $paymentType,
            ],
        );

        $data = $this->responseData(
            $response,
            'update_current_session_payment',
            'Ordering backend returned malformed payment data.',
        );

        if (
            ! is_array($data)
            || ! $this->isNonEmptyString($data['session_id'] ?? null)
            || ! $this->isPositiveInteger($data['payment_type'] ?? null)
        ) {
            throw $this->invalidResponse(
                $response,
                'update_current_session_payment',
                'Ordering backend returned malformed payment data.',
            );
        }

        return [
            'session_id' => $data['session_id'],
            'payment_type' => $data['payment_type'],
        ];
    }

    /**
     * @return array{session_id: string, status: string}
     */
    public function deleteCurrentSession(string $sessionToken): array
    {
        $response = $this->sessionBoundDelete(
            sessionToken: $sessionToken,
            path: 'api/sessions/current',
            operation: 'delete_current_session',
            message: 'Unable to close the current ordering backend session.',
        );

        $data = $this->responseData($response, 'delete_current_session', 'Ordering backend returned malformed session data.');

        if (! is_array($data)
            || ! $this->isNonEmptyString($data['session_id'] ?? null)
            || ! $this->isNonEmptyString($data['status'] ?? null)) {
            throw $this->invalidResponse($response, 'delete_current_session', 'Ordering backend returned malformed session data.');
        }

        return [
            'session_id' => $data['session_id'],
            'status' => $data['status'],
        ];
    }

    /**
     * @return array{expires_in: int, resend_available_in: int}
     */
    public function requestCurrentSessionOtp(string $sessionToken): array
    {
        $response = $this->sessionBoundPost(
            sessionToken: $sessionToken,
            path: 'api/sessions/current/otp',
            operation: 'request_current_session_otp',
            message: 'Unable to request an ordering backend OTP challenge.',
        );

        $data = $this->responseData($response, 'request_current_session_otp', 'Ordering backend returned malformed OTP data.');

        if (! is_array($data)
            || ! $this->isNonNegativeInteger($data['expires_in'] ?? null)
            || ! $this->isNonNegativeInteger($data['resend_available_in'] ?? null)) {
            throw $this->invalidResponse($response, 'request_current_session_otp', 'Ordering backend returned malformed OTP data.');
        }

        return [
            'expires_in' => $data['expires_in'],
            'resend_available_in' => $data['resend_available_in'],
        ];
    }

    /**
     * @return array{session_id: string, contact: array{name: string, phone: string, phone_verified: bool}}
     */
    public function verifyCurrentSessionOtp(string $sessionToken, string $code): array
    {
        $response = $this->sessionBoundPost(
            sessionToken: $sessionToken,
            path: 'api/sessions/current/otp/verify',
            operation: 'verify_current_session_otp',
            message: 'Unable to verify the ordering backend OTP challenge.',
            data: ['code' => $code],
        );

        return $this->contactFromResponse($response, 'verify_current_session_otp');
    }

    /**
     * @return list<array{id: int, name: string, slug: string, currency: string, timezone: string, center: array{latitude: ?string, longitude: ?string}}>
     */
    public function cities(): array
    {
        $response = $this->get(
            path: 'api/cities',
            operation: 'list_cities',
            message: 'Unable to retrieve ordering backend cities.',
        );

        $cities = $this->responseData($response, 'list_cities', 'Ordering backend returned malformed city data.');

        if (! is_array($cities) || ! array_is_list($cities)) {
            throw $this->invalidResponse($response, 'list_cities', 'Ordering backend returned malformed city data.');
        }

        return array_map(fn (mixed $city): array => $this->cityFromValue($city, $response, 'list_cities'), $cities);
    }

    /**
     * @return array{session_id: string, city: array{id: int, name: string, slug: string, currency: string, timezone: string, center: array{latitude: ?string, longitude: ?string}}}
     */
    public function selectCurrentSessionCity(string $sessionToken, int $cityId): array
    {
        $response = $this->sessionBoundPut(
            sessionToken: $sessionToken,
            path: 'api/sessions/current/city',
            operation: 'select_current_session_city',
            message: 'Unable to select the ordering backend city.',
            data: ['city_id' => $cityId],
        );

        $data = $this->responseData($response, 'select_current_session_city', 'Ordering backend returned malformed city data.');

        if (! is_array($data) || ! $this->isNonEmptyString($data['session_id'] ?? null)) {
            throw $this->invalidResponse($response, 'select_current_session_city', 'Ordering backend returned malformed city data.');
        }

        return [
            'session_id' => $data['session_id'],
            'city' => $this->cityFromValue($data['city'] ?? null, $response, 'select_current_session_city'),
        ];
    }

    /**
     * @return list<array{id: int, name: string, slug: string, image_url: ?string, currency: string, locale: string, timezone: string, available_payment_types: list<int>, available_delivery_types: list<int>, delivery_time_text: ?string, delivery_price_text: ?string}>
     */
    public function currentSessionRestaurants(string $sessionToken): array
    {
        $response = $this->sessionBoundGet(
            sessionToken: $sessionToken,
            path: 'api/sessions/current/restaurants',
            operation: 'list_current_session_restaurants',
            message: 'Unable to retrieve ordering backend restaurants.',
        );

        $restaurants = $this->responseData($response, 'list_current_session_restaurants', 'Ordering backend returned malformed restaurant data.');

        if (! is_array($restaurants) || ! array_is_list($restaurants)) {
            throw $this->invalidResponse($response, 'list_current_session_restaurants', 'Ordering backend returned malformed restaurant data.');
        }

        return array_map(fn (mixed $restaurant): array => $this->restaurantFromValue($restaurant, $response, 'list_current_session_restaurants'), $restaurants);
    }

    /**
     * @return array{session_id: string, restaurant: array{id: int, name: string, slug: string, image_url: ?string, currency: string, locale: string, timezone: string, available_payment_types: list<int>, available_delivery_types: list<int>, delivery_time_text: ?string, delivery_price_text: ?string}}
     */
    public function selectCurrentSessionRestaurant(string $sessionToken, int $restaurantId): array
    {
        $response = $this->sessionBoundPut(
            sessionToken: $sessionToken,
            path: 'api/sessions/current/restaurant',
            operation: 'select_current_session_restaurant',
            message: 'Unable to select the ordering backend restaurant.',
            data: ['restaurant_id' => $restaurantId],
        );

        $data = $this->responseData($response, 'select_current_session_restaurant', 'Ordering backend returned malformed restaurant data.');

        if (! is_array($data) || ! $this->isNonEmptyString($data['session_id'] ?? null)) {
            throw $this->invalidResponse($response, 'select_current_session_restaurant', 'Ordering backend returned malformed restaurant data.');
        }

        return [
            'session_id' => $data['session_id'],
            'restaurant' => $this->restaurantFromValue($data['restaurant'] ?? null, $response, 'select_current_session_restaurant'),
        ];
    }

    /**
     * @return list<array{type: string}>
     */
    public function currentSessionFulfillmentOptions(string $sessionToken): array
    {
        $response = $this->sessionBoundGet(
            sessionToken: $sessionToken,
            path: 'api/sessions/current/fulfillment-options',
            operation: 'list_current_session_fulfillment_options',
            message: 'Unable to retrieve ordering backend fulfillment options.',
        );

        $options = $this->responseData($response, 'list_current_session_fulfillment_options', 'Ordering backend returned malformed fulfillment data.');

        if (! is_array($options) || ! array_is_list($options)) {
            throw $this->invalidResponse($response, 'list_current_session_fulfillment_options', 'Ordering backend returned malformed fulfillment data.');
        }

        return array_map(function (mixed $option) use ($response): array {
            if (! is_array($option) || ! $this->isNonEmptyString($option['type'] ?? null)) {
                throw $this->invalidResponse($response, 'list_current_session_fulfillment_options', 'Ordering backend returned malformed fulfillment data.');
            }

            return ['type' => $option['type']];
        }, $options);
    }

    /**
     * @return array{session_id: string, fulfillment: array<string, mixed>}
     */
    public function selectCurrentSessionFulfillment(string $sessionToken, string $type): array
    {
        $response = $this->sessionBoundPut(
            sessionToken: $sessionToken,
            path: 'api/sessions/current/fulfillment',
            operation: 'select_current_session_fulfillment',
            message: 'Unable to select the ordering backend fulfillment type.',
            data: ['type' => $type],
        );

        return $this->fulfillmentStateFromResponse($response, 'select_current_session_fulfillment');
    }

    /**
     * @return list<array{id: int, title: ?string, latitude: ?string, longitude: ?string}>
     */
    public function currentSessionPickupAddresses(string $sessionToken): array
    {
        $response = $this->sessionBoundGet(
            sessionToken: $sessionToken,
            path: 'api/sessions/current/pickup-addresses',
            operation: 'list_current_session_pickup_addresses',
            message: 'Unable to retrieve ordering backend pickup addresses.',
        );

        $addresses = $this->responseData($response, 'list_current_session_pickup_addresses', 'Ordering backend returned malformed pickup address data.');

        if (! is_array($addresses) || ! array_is_list($addresses)) {
            throw $this->invalidResponse($response, 'list_current_session_pickup_addresses', 'Ordering backend returned malformed pickup address data.');
        }

        return array_map(function (mixed $address) use ($response): array {
            if (! is_array($address)
                || ! $this->isPositiveInteger($address['id'] ?? null)
                || ! $this->isOptionalString($address['title'] ?? null)
                || ! $this->isOptionalString($address['latitude'] ?? null)
                || ! $this->isOptionalString($address['longitude'] ?? null)) {
                throw $this->invalidResponse($response, 'list_current_session_pickup_addresses', 'Ordering backend returned malformed pickup address data.');
            }

            return [
                'id' => $address['id'],
                'title' => $address['title'] ?? null,
                'latitude' => $address['latitude'] ?? null,
                'longitude' => $address['longitude'] ?? null,
            ];
        }, $addresses);
    }

    /**
     * @return array{session_id: string, fulfillment: array<string, mixed>}
     */
    public function selectCurrentSessionPickupAddress(string $sessionToken, int $restaurantAddressId): array
    {
        $response = $this->sessionBoundPut(
            sessionToken: $sessionToken,
            path: 'api/sessions/current/pickup-address',
            operation: 'select_current_session_pickup_address',
            message: 'Unable to select the ordering backend pickup address.',
            data: ['restaurant_address_id' => $restaurantAddressId],
        );

        return $this->fulfillmentStateFromResponse($response, 'select_current_session_pickup_address');
    }

    /**
     * @param  array{type: int, street: string, house: string, flat?: ?string, stage?: ?string, note?: ?string, title?: ?string}  $address
     * @return array{session_id: string, delivery_available: bool, reason: ?string, delivery_price: mixed, dots_delivery_type: ?int, fulfillment: array<string, mixed>}
     */
    public function validateCurrentSessionDeliveryAddress(string $sessionToken, array $address): array
    {
        $response = $this->sessionBoundPost(
            sessionToken: $sessionToken,
            path: 'api/sessions/current/delivery-address',
            operation: 'validate_current_session_delivery_address',
            message: 'Unable to validate the ordering backend delivery address.',
            data: $address,
        );

        $data = $this->responseData($response, 'validate_current_session_delivery_address', 'Ordering backend returned malformed delivery address data.');

        if (! is_array($data)
            || ! $this->isNonEmptyString($data['session_id'] ?? null)
            || ! is_bool($data['delivery_available'] ?? null)
            || ! $this->isOptionalString($data['reason'] ?? null)
            || ! array_key_exists('delivery_price', $data)
            || ! $this->isOptionalInteger($data['dots_delivery_type'] ?? null)
            || ! is_array($data['fulfillment'] ?? null)) {
            throw $this->invalidResponse($response, 'validate_current_session_delivery_address', 'Ordering backend returned malformed delivery address data.');
        }

        return [
            'session_id' => $data['session_id'],
            'delivery_available' => $data['delivery_available'],
            'reason' => $data['reason'] ?? null,
            'delivery_price' => $data['delivery_price'],
            'dots_delivery_type' => $data['dots_delivery_type'] ?? null,
            'fulfillment' => $data['fulfillment'],
        ];
    }

    /**
     * @return array{id: int, status: string, currency: string, subtotal: string, total: string, expires_at: string, items: list<array{id: int, product_id: int, external_product_id: string, name: string, quantity: int, unit_price: string, total: string}>}
     */
    public function getOrCreateCurrentCart(string $sessionToken): array
    {
        $this->ensureCurrentCart($sessionToken);

        return $this->currentCart($sessionToken);
    }

    /**
     * @return array{id: int, status: string, currency: string, subtotal: string, total: string, expires_at: string, items: list<array{id: int, product_id: int, external_product_id: string, name: string, quantity: int, unit_price: string, total: string}>}
     */
    public function ensureCurrentCart(string $sessionToken): array
    {
        $response = $this->sessionBoundPost(
            sessionToken: $sessionToken,
            path: 'api/carts',
            operation: 'ensure_current_cart',
            message: 'Unable to get or create the current ordering backend cart.',
        );

        return $this->cartFromResponse($response, 'ensure_current_cart');
    }

    /**
     * @return array{id: int, status: string, currency: string, subtotal: string, total: string, expires_at: string, items: list<array{id: int, product_id: int, external_product_id: string, name: string, quantity: int, unit_price: string, total: string}>}
     */
    public function currentCart(string $sessionToken): array
    {
        $response = $this->sessionBoundGet(
            sessionToken: $sessionToken,
            path: 'api/carts/current',
            operation: 'get_current_cart',
            message: 'Unable to retrieve the current ordering backend cart.',
        );

        return $this->cartFromResponse($response, 'get_current_cart');
    }

    /**
     * @return array{id: int, status: string, currency: string, subtotal: string, total: string, expires_at: string, items: list<array{id: int, product_id: int, external_product_id: string, name: string, quantity: int, unit_price: string, total: string}>}
     */
    public function addCurrentCartItem(
        string $sessionToken,
        int $productId,
        int $quantity,
    ): array {
        $response = $this->sessionBoundPost(
            sessionToken: $sessionToken,
            path: 'api/carts/current/items',
            operation: 'add_current_cart_item',
            message: 'Unable to add an item to the current ordering backend cart.',
            data: [
                'product_id' => $productId,
                'quantity' => $quantity,
            ],
        );

        return $this->cartFromResponse($response, 'add_current_cart_item');
    }

    /**
     * @return array{id: int, status: string, currency: string, subtotal: string, total: string, expires_at: string, items: list<array{id: int, product_id: int, external_product_id: string, name: string, quantity: int, unit_price: string, total: string}>}
     */
    public function updateCurrentCartItem(
        string $sessionToken,
        int $itemId,
        int $quantity,
    ): array {
        $response = $this->sessionBoundPatch(
            sessionToken: $sessionToken,
            path: "api/carts/current/items/{$itemId}",
            operation: 'update_current_cart_item',
            message: 'Unable to update an item in the current ordering backend cart.',
            data: [
                'quantity' => $quantity,
            ],
        );

        return $this->cartFromResponse($response, 'update_current_cart_item');
    }

    /**
     * @return array{id: int, status: string, currency: string, subtotal: string, total: string, expires_at: string, items: list<array{id: int, product_id: int, external_product_id: string, name: string, quantity: int, unit_price: string, total: string}>}
     */
    public function removeCurrentCartItem(int $itemId, string $sessionToken): array
    {
        $this->sessionBoundDelete(
            sessionToken: $sessionToken,
            path: "api/carts/current/items/{$itemId}",
            operation: 'remove_current_cart_item',
            message: 'Unable to remove an item from the current ordering backend cart.',
        );

        return $this->currentCart($sessionToken);
    }

    /**
     * @return array{id: int, status: string, currency: string, subtotal: string, total: string, expires_at: string, items: list<array{id: int, product_id: int, external_product_id: string, name: string, quantity: int, unit_price: string, total: string}>}
     */
    public function clearCurrentCart(string $sessionToken): array
    {
        $this->sessionBoundDelete(
            sessionToken: $sessionToken,
            path: 'api/carts/current/items',
            operation: 'clear_current_cart',
            message: 'Unable to clear the current ordering backend cart.',
        );

        return $this->currentCart($sessionToken);
    }

    /**
     * @return array{id: int, external_order_id: ?string, status: string, failure_message: ?string, receiving_type: string, payment_type: int, fulfillment: array<string, mixed>, total: string, currency: string, payment: array{status: string, checkout_url: ?string, payment_received_at: ?string, qr_ready: bool}, items: list<array{product_id: ?int, external_product_id: string, name: string, quantity: int, unit_price: string, total: string}>}
     */
    public function createOrder(
        string $sessionToken,
        string $idempotencyKey,
        int $deliveryTime = 0,
    ): array {
        $response = $this->sessionBoundPost(
            sessionToken: $sessionToken,
            path: 'api/orders',
            operation: 'create_order',
            message: 'Unable to create the current ordering backend order.',
            data: [
                'delivery_time' => $deliveryTime,
            ],
            headers: [
                'Idempotency-Key' => $idempotencyKey,
            ],
        );

        return $this->orderFromResponse($response, 'create_order');
    }

    /**
     * @return array{id: int, external_order_id: ?string, status: string, failure_message: ?string, receiving_type: string, payment_type: int, fulfillment: array<string, mixed>, total: string, currency: string, payment: array{status: string, checkout_url: ?string, payment_received_at: ?string, qr_ready: bool}, items: list<array{product_id: ?int, external_product_id: string, name: string, quantity: int, unit_price: string, total: string}>}
     */
    public function currentOrder(string $sessionToken): array
    {
        $response = $this->sessionBoundGet(
            sessionToken: $sessionToken,
            path: 'api/orders/current',
            operation: 'get_current_order',
            message: 'Unable to retrieve the current ordering backend order.',
        );

        return $this->orderFromResponse($response, 'get_current_order');
    }

    /**
     * @return array{status: string, checkout_url: ?string, payment_received_at: ?string, http_status: int}
     */
    public function currentPayment(string $sessionToken): array
    {
        $response = $this->sessionBoundGet(
            sessionToken: $sessionToken,
            path: 'api/orders/current/payment',
            operation: 'get_current_payment',
            message: 'Unable to retrieve the current ordering backend payment.',
        );

        return $this->paymentStateFromResponse($response, 'get_current_payment') + [
            'http_status' => $response->status(),
        ];
    }

    /**
     * @return array{status: 'ready', content_type: string, contents: string}|array{status: 'pending', payment: array{status: string, checkout_url: ?string, payment_received_at: ?string, http_status: int}}
     */
    public function currentPaymentQr(string $sessionToken): array
    {
        $response = $this->sessionBoundGet(
            sessionToken: $sessionToken,
            path: 'api/orders/current/payment/qr',
            operation: 'get_current_payment_qr',
            message: 'Unable to retrieve the current ordering backend payment QR.',
        );

        if ($response->status() === 202) {
            return [
                'status' => 'pending',
                'payment' => $this->paymentStateFromResponse($response, 'get_current_payment_qr') + [
                    'http_status' => $response->status(),
                ],
            ];
        }

        $contentType = (string) $response->header('Content-Type');

        if ($response->status() !== 200 || ! str_starts_with($contentType, 'image/png')) {
            throw $this->invalidResponse($response, 'get_current_payment_qr', 'Ordering backend returned malformed payment QR data.');
        }

        return [
            'status' => 'ready',
            'content_type' => 'image/png',
            'contents' => $response->body(),
        ];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function categories(string $restaurantSlug): array
    {
        $response = $this->get(
            path: "api/restaurants/{$restaurantSlug}/categories",
            operation: 'list_categories',
            message: 'Unable to retrieve ordering backend categories.',
        );

        $categories = $this->responseData($response, 'list_categories');

        if (! is_array($categories) || ! array_is_list($categories)) {
            throw $this->invalidResponse($response, 'list_categories');
        }

        return array_map(function (mixed $category) use ($response): array {
            if (! is_array($category)
                || ! $this->isPositiveInteger($category['id'] ?? null)
                || ! $this->isNonEmptyString($category['name'] ?? null)) {
                throw $this->invalidResponse($response, 'list_categories');
            }

            return [
                'id' => $category['id'],
                'name' => $category['name'],
            ];
        }, $categories);
    }

    /**
     * @return list<array{id: int, name: string, price: string, promotion_price: ?string, currency: string}>
     */
    public function categoryProducts(string $restaurantSlug, int $categoryId): array
    {
        $response = $this->get(
            path: "api/restaurants/{$restaurantSlug}/categories/{$categoryId}/products",
            operation: 'list_category_products',
            message: 'Unable to retrieve ordering backend category products.',
        );

        return $this->productsFromResponse($response, 'list_category_products');
    }

    /**
     * @return array{id: int, name: string, description: ?string, price: string, promotion_price: ?string, currency: string, is_available: bool}
     */
    public function product(string $restaurantSlug, int $productId): array
    {
        $response = $this->get(
            path: "api/restaurants/{$restaurantSlug}/products/{$productId}",
            operation: 'get_product',
            message: 'Unable to retrieve an ordering backend product.',
        );

        $product = $this->responseData($response, 'get_product');

        if (! is_array($product)
            || ($product['id'] ?? null) !== $productId
            || ! $this->isNonEmptyString($product['name'] ?? null)
            || ! $this->isOptionalString($product['description'] ?? null)
            || ! $this->isNonEmptyString($product['price'] ?? null)
            || ! $this->isOptionalNonEmptyString($product['promotion_price'] ?? null)
            || ! $this->isNonEmptyString($product['currency'] ?? null)
            || ! is_bool($product['is_available'] ?? null)) {
            throw $this->invalidResponse($response, 'get_product');
        }

        return [
            'id' => $product['id'],
            'name' => $product['name'],
            'description' => $product['description'] ?? null,
            'price' => $product['price'],
            'promotion_price' => $product['promotion_price'] ?? null,
            'currency' => $product['currency'],
            'is_available' => $product['is_available'],
        ];
    }

    /**
     * @return list<array{id: int, name: string, price: string, promotion_price: ?string, currency: string}>
     */
    public function searchProducts(string $restaurantSlug, string $query, int $limit = 10): array
    {
        $response = $this->get(
            path: "api/restaurants/{$restaurantSlug}/products/search",
            operation: 'search_products',
            message: 'Unable to search ordering backend products.',
            query: [
                'q' => $query,
                'limit' => $limit,
            ],
        );

        return $this->productsFromResponse($response, 'search_products');
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function get(string $path, string $operation, string $message, array $query = []): Response
    {
        try {
            return $this->request()->get($path, $query)->throw();
        } catch (ConnectionException|RequestException $exception) {
            throw $this->requestException(
                operation: $operation,
                message: $message,
                exception: $exception,
            );
        }
    }

    private function sessionBoundGet(
        string $sessionToken,
        string $path,
        string $operation,
        string $message,
    ): Response {
        try {
            return $this->request($sessionToken)->get($path)->throw();
        } catch (ConnectionException|RequestException $exception) {
            throw $this->requestException(
                operation: $operation,
                message: $message,
                exception: $exception,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    private function sessionBoundPost(
        string $sessionToken,
        string $path,
        string $operation,
        string $message,
        array $data = [],
        array $headers = [],
    ): Response {
        try {
            return $this->request($sessionToken)
                ->withHeaders($headers)
                ->post($path, $data)
                ->throw();
        } catch (ConnectionException|RequestException $exception) {
            throw $this->requestException(
                operation: $operation,
                message: $message,
                exception: $exception,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function sessionBoundPut(
        string $sessionToken,
        string $path,
        string $operation,
        string $message,
        array $data,
    ): Response {
        try {
            return $this->request($sessionToken)->put($path, $data)->throw();
        } catch (ConnectionException|RequestException $exception) {
            throw $this->requestException(
                operation: $operation,
                message: $message,
                exception: $exception,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function sessionBoundPatch(
        string $sessionToken,
        string $path,
        string $operation,
        string $message,
        array $data,
    ): Response {
        try {
            return $this->request($sessionToken)->patch($path, $data)->throw();
        } catch (ConnectionException|RequestException $exception) {
            throw $this->requestException(
                operation: $operation,
                message: $message,
                exception: $exception,
            );
        }
    }

    private function sessionBoundDelete(
        string $sessionToken,
        string $path,
        string $operation,
        string $message,
    ): Response {
        try {
            return $this->request($sessionToken)->delete($path)->throw();
        } catch (ConnectionException|RequestException $exception) {
            throw $this->requestException(
                operation: $operation,
                message: $message,
                exception: $exception,
            );
        }
    }

    private function request(?string $sessionToken = null): PendingRequest
    {
        $request = $this->http
            ->baseUrl((string) config('services.ordering_backend.url'))
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('services.ordering_backend.timeout'))
            ->withHeaders([
                'X-Internal-Api-Token' => (string) config('services.ordering_backend.token'),
            ]);

        if ($sessionToken !== null) {
            $request->withHeaders([
                'X-Session-Token' => $sessionToken,
            ]);
        }

        return $request;
    }

    private function requestException(
        string $operation,
        string $message,
        ConnectionException|RequestException $exception,
    ): OrderingBackendException {
        $statusCode = $exception instanceof RequestException
            ? $exception->response->status()
            : null;

        $this->logger->error('Ordering backend request failed.', [
            'operation' => $operation,
            'status' => $statusCode,
            'exception' => $exception::class,
        ]);

        return new OrderingBackendException(
            message: $message,
            statusCode: $statusCode,
            responseMessage: $this->responseMessageFromException($exception),
            responseErrors: $this->responseErrorsFromException($exception),
            previous: $exception,
        );
    }

    private function responseMessageFromException(
        ConnectionException|RequestException $exception,
    ): ?string {
        if (! $exception instanceof RequestException) {
            return null;
        }

        try {
            $responseMessage = $exception->response->json('message');
        } catch (JsonException) {
            return null;
        }

        if (! is_string($responseMessage) || trim($responseMessage) === '') {
            return null;
        }

        return trim($responseMessage);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function responseErrorsFromException(ConnectionException|RequestException $exception): ?array
    {
        if (! $exception instanceof RequestException) {
            return null;
        }

        try {
            $responseErrors = $exception->response->json('errors');
        } catch (JsonException) {
            return null;
        }

        return is_array($responseErrors) ? $responseErrors : null;
    }

    private function responseData(
        Response $response,
        string $operation,
        string $invalidMessage = 'Ordering backend returned malformed catalog data.',
    ): mixed {
        try {
            return $response->json('data');
        } catch (JsonException $exception) {
            throw $this->invalidResponse(
                response: $response,
                operation: $operation,
                message: $invalidMessage,
                previous: $exception,
            );
        }
    }

    private function invalidResponse(
        Response $response,
        string $operation,
        string $message = 'Ordering backend returned malformed catalog data.',
        ?JsonException $previous = null,
    ): OrderingBackendException {
        $this->logger->error('Ordering backend returned an invalid response.', [
            'operation' => $operation,
            'status' => $response->status(),
            'exception' => $previous === null ? null : $previous::class,
        ]);

        return new OrderingBackendException(
            message: $message,
            statusCode: $response->status(),
            previous: $previous,
        );
    }

    /**
     * @return array{session_id: string, contact: array{name: string, phone: string, phone_verified: bool}}
     */
    private function contactFromResponse(Response $response, string $operation): array
    {
        $invalidMessage = 'Ordering backend returned malformed contact data.';
        $data = $this->responseData($response, $operation, $invalidMessage);

        if (! is_array($data)
            || ! $this->isNonEmptyString($data['session_id'] ?? null)
            || ! is_array($data['contact'] ?? null)) {
            throw $this->invalidResponse($response, $operation, $invalidMessage);
        }

        $contact = $data['contact'];

        if (! $this->isNonEmptyString($contact['name'] ?? null)
            || ! $this->isNonEmptyString($contact['phone'] ?? null)
            || ! is_bool($contact['phone_verified'] ?? null)) {
            throw $this->invalidResponse($response, $operation, $invalidMessage);
        }

        return [
            'session_id' => $data['session_id'],
            'contact' => [
                'name' => $contact['name'],
                'phone' => $contact['phone'],
                'phone_verified' => $contact['phone_verified'],
            ],
        ];
    }

    /**
     * @return array{id: int, name: string, slug: string, currency: string, timezone: string, center: array{latitude: ?string, longitude: ?string}}
     */
    private function cityFromValue(mixed $city, Response $response, string $operation): array
    {
        $invalidMessage = 'Ordering backend returned malformed city data.';

        if (! is_array($city)
            || ! $this->isPositiveInteger($city['id'] ?? null)
            || ! $this->isNonEmptyString($city['name'] ?? null)
            || ! $this->isNonEmptyString($city['slug'] ?? null)
            || ! $this->isNonEmptyString($city['currency'] ?? null)
            || ! $this->isNonEmptyString($city['timezone'] ?? null)
            || ! is_array($city['center'] ?? null)
            || ! $this->isOptionalString($city['center']['latitude'] ?? null)
            || ! $this->isOptionalString($city['center']['longitude'] ?? null)) {
            throw $this->invalidResponse($response, $operation, $invalidMessage);
        }

        return [
            'id' => $city['id'],
            'name' => $city['name'],
            'slug' => $city['slug'],
            'currency' => $city['currency'],
            'timezone' => $city['timezone'],
            'center' => [
                'latitude' => $city['center']['latitude'] ?? null,
                'longitude' => $city['center']['longitude'] ?? null,
            ],
        ];
    }

    /**
     * @return array{id: int, name: string, slug: string, image_url: ?string, currency: string, locale: string, timezone: string, available_payment_types: list<int>, available_delivery_types: list<int>, delivery_time_text: ?string, delivery_price_text: ?string}
     */
    private function restaurantFromValue(mixed $restaurant, Response $response, string $operation): array
    {
        $invalidMessage = 'Ordering backend returned malformed restaurant data.';

        if (! is_array($restaurant)
            || ! $this->isPositiveInteger($restaurant['id'] ?? null)
            || ! $this->isNonEmptyString($restaurant['name'] ?? null)
            || ! $this->isNonEmptyString($restaurant['slug'] ?? null)
            || ! $this->isOptionalString($restaurant['image_url'] ?? null)
            || ! $this->isNonEmptyString($restaurant['currency'] ?? null)
            || ! $this->isNonEmptyString($restaurant['locale'] ?? null)
            || ! $this->isNonEmptyString($restaurant['timezone'] ?? null)
            || ! $this->isIntegerList($restaurant['available_payment_types'] ?? null)
            || ! $this->isIntegerList($restaurant['available_delivery_types'] ?? null)
            || ! $this->isOptionalString($restaurant['delivery_time_text'] ?? null)
            || ! $this->isOptionalString($restaurant['delivery_price_text'] ?? null)) {
            throw $this->invalidResponse($response, $operation, $invalidMessage);
        }

        return [
            'id' => $restaurant['id'],
            'name' => $restaurant['name'],
            'slug' => $restaurant['slug'],
            'image_url' => $restaurant['image_url'] ?? null,
            'currency' => $restaurant['currency'],
            'locale' => $restaurant['locale'],
            'timezone' => $restaurant['timezone'],
            'available_payment_types' => $restaurant['available_payment_types'],
            'available_delivery_types' => $restaurant['available_delivery_types'],
            'delivery_time_text' => $restaurant['delivery_time_text'] ?? null,
            'delivery_price_text' => $restaurant['delivery_price_text'] ?? null,
        ];
    }

    /**
     * @return array{session_id: string, fulfillment: array<string, mixed>}
     */
    private function fulfillmentStateFromResponse(Response $response, string $operation): array
    {
        $invalidMessage = 'Ordering backend returned malformed fulfillment data.';
        $data = $this->responseData($response, $operation, $invalidMessage);

        if (! is_array($data)
            || ! $this->isNonEmptyString($data['session_id'] ?? null)
            || ! is_array($data['fulfillment'] ?? null)) {
            throw $this->invalidResponse($response, $operation, $invalidMessage);
        }

        return [
            'session_id' => $data['session_id'],
            'fulfillment' => $data['fulfillment'],
        ];
    }

    /**
     * @return array{id: int, status: string, currency: string, subtotal: string, total: string, expires_at: string, items: list<array{id: int, product_id: int, external_product_id: string, name: string, quantity: int, unit_price: string, total: string}>}
     */
    private function cartFromResponse(Response $response, string $operation): array
    {
        $invalidMessage = 'Ordering backend returned malformed cart data.';
        $cart = $this->responseData($response, $operation, $invalidMessage);

        if (! is_array($cart)
            || ! $this->isPositiveInteger($cart['id'] ?? null)
            || ! $this->isNonEmptyString($cart['status'] ?? null)
            || ! $this->isNonEmptyString($cart['currency'] ?? null)
            || ! $this->isNonEmptyString($cart['subtotal'] ?? null)
            || ! $this->isNonEmptyString($cart['total'] ?? null)
            || ! $this->isNonEmptyString($cart['expires_at'] ?? null)
            || ! is_array($cart['items'] ?? null)
            || ! array_is_list($cart['items'])) {
            throw $this->invalidResponse($response, $operation, $invalidMessage);
        }

        $items = array_map(function (mixed $item) use ($response, $operation, $invalidMessage): array {
            if (! is_array($item)
                || ! $this->isPositiveInteger($item['id'] ?? null)
                || ! $this->isPositiveInteger($item['product_id'] ?? null)
                || ! $this->isNonEmptyString($item['external_product_id'] ?? null)
                || ! $this->isNonEmptyString($item['name'] ?? null)
                || ! $this->isPositiveInteger($item['quantity'] ?? null)
                || ! $this->isNonEmptyString($item['unit_price'] ?? null)
                || ! $this->isNonEmptyString($item['total'] ?? null)) {
                throw $this->invalidResponse($response, $operation, $invalidMessage);
            }

            return [
                'id' => $item['id'],
                'product_id' => $item['product_id'],
                'external_product_id' => $item['external_product_id'],
                'name' => $item['name'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'total' => $item['total'],
            ];
        }, $cart['items']);

        return [
            'id' => $cart['id'],
            'status' => $cart['status'],
            'currency' => $cart['currency'],
            'subtotal' => $cart['subtotal'],
            'total' => $cart['total'],
            'expires_at' => $cart['expires_at'],
            'items' => $items,
        ];
    }

    /**
     * @return array{id: int, external_order_id: ?string, status: string, failure_message: ?string, receiving_type: string, payment_type: int, fulfillment: array<string, mixed>, total: string, currency: string, payment: array{status: string, checkout_url: ?string, payment_received_at: ?string, qr_ready: bool}, items: list<array{product_id: ?int, external_product_id: string, name: string, quantity: int, unit_price: string, total: string}>}
     */
    private function orderFromResponse(Response $response, string $operation): array
    {
        $invalidMessage = 'Ordering backend returned malformed order data.';
        $order = $this->responseData($response, $operation, $invalidMessage);

        if (! is_array($order)
            || ! $this->isPositiveInteger($order['id'] ?? null)
            || ! array_key_exists('external_order_id', $order)
            || ! $this->isOptionalNonEmptyString($order['external_order_id'] ?? null)
            || ! $this->isNonEmptyString($order['status'] ?? null)
            || ! array_key_exists('failure_message', $order)
            || ! $this->isOptionalString($order['failure_message'] ?? null)
            || ! $this->isNonEmptyString($order['receiving_type'] ?? null)
            || ! $this->isPositiveInteger($order['payment_type'] ?? null)
            || ! is_array($order['fulfillment'] ?? null)
            || ! $this->isNonEmptyString($order['total'] ?? null)
            || ! $this->isNonEmptyString($order['currency'] ?? null)
            || ! is_array($order['payment'] ?? null)
            || ! is_array($order['items'] ?? null)
            || ! array_is_list($order['items'])) {
            throw $this->invalidResponse($response, $operation, $invalidMessage);
        }

        $payment = $this->paymentStateFromValue($order['payment'], $response, $operation);

        if (! is_bool($order['payment']['qr_ready'] ?? null)) {
            throw $this->invalidResponse($response, $operation, $invalidMessage);
        }

        $items = array_map(function (mixed $item) use ($response, $operation, $invalidMessage): array {
            if (! is_array($item)
                || ! $this->isOptionalPositiveInteger($item['product_id'] ?? null)
                || ! $this->isNonEmptyString($item['external_product_id'] ?? null)
                || ! $this->isNonEmptyString($item['name'] ?? null)
                || ! $this->isPositiveInteger($item['quantity'] ?? null)
                || ! $this->isNonEmptyString($item['unit_price'] ?? null)
                || ! $this->isNonEmptyString($item['total'] ?? null)) {
                throw $this->invalidResponse($response, $operation, $invalidMessage);
            }

            return [
                'product_id' => $item['product_id'] ?? null,
                'external_product_id' => $item['external_product_id'],
                'name' => $item['name'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'total' => $item['total'],
            ];
        }, $order['items']);

        return [
            'id' => $order['id'],
            'external_order_id' => $order['external_order_id'] ?? null,
            'status' => $order['status'],
            'failure_message' => $order['failure_message'] ?? null,
            'receiving_type' => $order['receiving_type'],
            'payment_type' => $order['payment_type'],
            'fulfillment' => $order['fulfillment'],
            'total' => $order['total'],
            'currency' => $order['currency'],
            'payment' => $payment + ['qr_ready' => $order['payment']['qr_ready']],
            'items' => $items,
        ];
    }

    /**
     * @return array{status: string, checkout_url: ?string, payment_received_at: ?string}
     */
    private function paymentStateFromResponse(Response $response, string $operation): array
    {
        $invalidMessage = 'Ordering backend returned malformed payment data.';
        $payment = $this->responseData($response, $operation, $invalidMessage);

        return $this->paymentStateFromValue($payment, $response, $operation);
    }

    /**
     * @return array{status: string, checkout_url: ?string, payment_received_at: ?string}
     */
    private function paymentStateFromValue(mixed $payment, Response $response, string $operation): array
    {
        $invalidMessage = 'Ordering backend returned malformed payment data.';

        if (! is_array($payment)
            || ! $this->isNonEmptyString($payment['status'] ?? null)
            || ! array_key_exists('checkout_url', $payment)
            || ! $this->isOptionalNonEmptyString($payment['checkout_url'] ?? null)
            || ! array_key_exists('payment_received_at', $payment)
            || ! $this->isOptionalNonEmptyString($payment['payment_received_at'] ?? null)) {
            throw $this->invalidResponse($response, $operation, $invalidMessage);
        }

        return [
            'status' => $payment['status'],
            'checkout_url' => $payment['checkout_url'] ?? null,
            'payment_received_at' => $payment['payment_received_at'] ?? null,
        ];
    }

    /**
     * @return list<array{id: int, name: string, price: string, promotion_price: ?string, currency: string}>
     */
    private function productsFromResponse(Response $response, string $operation): array
    {
        $products = $this->responseData($response, $operation);

        if (! is_array($products) || ! array_is_list($products)) {
            throw $this->invalidResponse($response, $operation);
        }

        return array_map(function (mixed $product) use ($response, $operation): array {
            if (! is_array($product)
                || ! $this->isPositiveInteger($product['id'] ?? null)
                || ! $this->isNonEmptyString($product['name'] ?? null)
                || ! $this->isNonEmptyString($product['price'] ?? null)
                || ! $this->isOptionalNonEmptyString($product['promotion_price'] ?? null)
                || ! $this->isNonEmptyString($product['currency'] ?? null)) {
                throw $this->invalidResponse($response, $operation);
            }

            return [
                'id' => $product['id'],
                'name' => $product['name'],
                'price' => $product['price'],
                'promotion_price' => $product['promotion_price'] ?? null,
                'currency' => $product['currency'],
            ];
        }, $products);
    }

    private function isPositiveInteger(mixed $value): bool
    {
        return is_int($value) && $value > 0;
    }

    private function isOptionalPositiveInteger(mixed $value): bool
    {
        return $value === null || $this->isPositiveInteger($value);
    }

    private function isNonNegativeInteger(mixed $value): bool
    {
        return is_int($value) && $value >= 0;
    }

    private function isOptionalInteger(mixed $value): bool
    {
        return $value === null || is_int($value);
    }

    private function isNonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function isOptionalString(mixed $value): bool
    {
        return $value === null || is_string($value);
    }

    private function isOptionalNonEmptyString(mixed $value): bool
    {
        return $value === null || $this->isNonEmptyString($value);
    }

    private function isIntegerList(mixed $value): bool
    {
        return is_array($value)
            && array_is_list($value)
            && array_all($value, fn (mixed $item): bool => is_int($item));
    }

    private function isValidContactResponse(Response $response): bool
    {
        $contact = $response->json('data.contact');

        return is_string($response->json('data.session_id'))
            && is_array($contact)
            && is_string($contact['name'] ?? null)
            && is_string($contact['phone'] ?? null)
            && is_bool($contact['phone_verified'] ?? null);
    }
}
