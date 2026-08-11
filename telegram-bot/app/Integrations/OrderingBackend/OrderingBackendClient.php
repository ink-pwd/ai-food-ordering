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
     * @return array{id: int, external_order_id: ?string, status: string, failure_message: ?string, receiving_type: string, total: string, currency: string, items: list<array{product_id: int, external_product_id: string, name: string, quantity: int, unit_price: string, total: string}>}
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
     * @return array{id: int, external_order_id: ?string, status: string, failure_message: ?string, receiving_type: string, total: string, currency: string, items: list<array{product_id: int, external_product_id: string, name: string, quantity: int, unit_price: string, total: string}>}
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
     * @return list<array{id: int, name: string}>
     */
    public function categories(): array
    {
        $response = $this->get(
            path: "api/restaurants/{$this->restaurantSlug()}/categories",
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
    public function categoryProducts(int $categoryId): array
    {
        $response = $this->get(
            path: "api/restaurants/{$this->restaurantSlug()}/categories/{$categoryId}/products",
            operation: 'list_category_products',
            message: 'Unable to retrieve ordering backend category products.',
        );

        $products = $this->responseData($response, 'list_category_products');

        if (! is_array($products) || ! array_is_list($products)) {
            throw $this->invalidResponse($response, 'list_category_products');
        }

        return array_map(function (mixed $product) use ($response): array {
            if (! is_array($product)
                || ! $this->isPositiveInteger($product['id'] ?? null)
                || ! $this->isNonEmptyString($product['name'] ?? null)
                || ! $this->isNonEmptyString($product['price'] ?? null)
                || ! $this->isOptionalNonEmptyString($product['promotion_price'] ?? null)
                || ! $this->isNonEmptyString($product['currency'] ?? null)) {
                throw $this->invalidResponse($response, 'list_category_products');
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

    /**
     * @return array{id: int, name: string, description: ?string, price: string, promotion_price: ?string, currency: string, is_available: bool}
     */
    public function product(int $productId): array
    {
        $response = $this->get(
            path: "api/restaurants/{$this->restaurantSlug()}/products/{$productId}",
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

    private function get(string $path, string $operation, string $message): Response
    {
        try {
            return $this->request()->get($path)->throw();
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
            previous: $previous,
        );
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
     * @return array{id: int, external_order_id: ?string, status: string, failure_message: ?string, receiving_type: string, total: string, currency: string, items: list<array{product_id: int, external_product_id: string, name: string, quantity: int, unit_price: string, total: string}>}
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
            || ! $this->isNonEmptyString($order['total'] ?? null)
            || ! $this->isNonEmptyString($order['currency'] ?? null)
            || ! is_array($order['items'] ?? null)
            || ! array_is_list($order['items'])) {
            throw $this->invalidResponse($response, $operation, $invalidMessage);
        }

        $items = array_map(function (mixed $item) use ($response, $operation, $invalidMessage): array {
            if (! is_array($item)
                || ! $this->isPositiveInteger($item['product_id'] ?? null)
                || ! $this->isNonEmptyString($item['external_product_id'] ?? null)
                || ! $this->isNonEmptyString($item['name'] ?? null)
                || ! $this->isPositiveInteger($item['quantity'] ?? null)
                || ! $this->isNonEmptyString($item['unit_price'] ?? null)
                || ! $this->isNonEmptyString($item['total'] ?? null)) {
                throw $this->invalidResponse($response, $operation, $invalidMessage);
            }

            return [
                'product_id' => $item['product_id'],
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
            'total' => $order['total'],
            'currency' => $order['currency'],
            'items' => $items,
        ];
    }

    private function restaurantSlug(): string
    {
        return (string) config('services.ordering_backend.restaurant_slug');
    }

    private function isPositiveInteger(mixed $value): bool
    {
        return is_int($value) && $value > 0;
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
