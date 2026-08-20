<?php

namespace App\Integrations\OrderingBackend;

use App\DTO\OrderingBackend\CartData;
use App\DTO\OrderingBackend\CityData;
use App\DTO\OrderingBackend\CurrentPaymentData;
use App\DTO\OrderingBackend\DeliveryAddressData;
use App\DTO\OrderingBackend\DeliveryValidationData;
use App\DTO\OrderingBackend\OrderData;
use App\DTO\OrderingBackend\PickupAddressData;
use App\DTO\OrderingBackend\ProductData;
use App\DTO\OrderingBackend\ProductSummaryData;
use App\DTO\OrderingBackend\RestaurantData;

final readonly class OrderingBackendClient
{
    public function __construct(
        private SessionOrderingBackendClient $session,
        private SelectionOrderingBackendClient $selection,
        private FulfillmentOrderingBackendClient $fulfillment,
        private CartOrderingBackendClient $cart,
        private OrderOrderingBackendClient $order,
        private CatalogOrderingBackendClient $catalog,
    ) {
    }

    public function createTelegramSession(
        string $externalSessionId,
    ): string {
        return $this->session->createTelegramSession(
            $externalSessionId,
        );
    }

    public function updateCurrentSessionContact(
        string $sessionToken,
        string $name,
        string $phone,
    ): void {
        $this->session->updateCurrentSessionContact(
            $sessionToken,
            $name,
            $phone,
        );
    }

    /**
     * @return array{session_id: string, payment_type: int}
     */
    public function updateCurrentSessionPayment(
        string $sessionToken,
        int $paymentType,
    ): array {
        return $this->session->updateCurrentSessionPayment(
            $sessionToken,
            $paymentType,
        );
    }

    /**
     * @return array{session_id: string, status: string}
     */
    public function deleteCurrentSession(
        string $sessionToken,
    ): array {
        return $this->session->deleteCurrentSession(
            $sessionToken,
        );
    }

    /**
     * @return array{expires_in: int, resend_available_in: int, code: string}
     */
    public function requestCurrentSessionOtp(
        string $sessionToken,
    ): array {
        return $this->session->requestCurrentSessionOtp(
            $sessionToken,
        );
    }

    /**
     * @return array{session_id: string, contact: array{name: string, phone: string, phone_verified: bool}}
     */
    public function verifyCurrentSessionOtp(
        string $sessionToken,
        string $code,
    ): array {
        return $this->session->verifyCurrentSessionOtp(
            $sessionToken,
            $code,
        );
    }

    /**
     * @return list<CityData>
     */
    public function cities(): array
    {
        return $this->selection->cities();
    }

    /**
     * @return array{session_id: string, city: CityData}
     */
    public function selectCurrentSessionCity(
        string $sessionToken,
        int $cityId,
    ): array {
        return $this->selection->selectCurrentSessionCity(
            $sessionToken,
            $cityId,
        );
    }

    /**
     * @return list<RestaurantData>
     */
    public function currentSessionRestaurants(
        string $sessionToken,
    ): array {
        return $this->selection->currentSessionRestaurants(
            $sessionToken,
        );
    }

    /**
     * @return array{session_id: string, restaurant: RestaurantData}
     */
    public function selectCurrentSessionRestaurant(
        string $sessionToken,
        int $restaurantId,
    ): array {
        return $this->selection->selectCurrentSessionRestaurant(
            $sessionToken,
            $restaurantId,
        );
    }

    /**
     * @return list<array{type: string}>
     */
    public function currentSessionFulfillmentOptions(
        string $sessionToken,
    ): array {
        return $this->fulfillment
            ->currentSessionFulfillmentOptions(
                $sessionToken,
            );
    }

    /**
     * @return array{session_id: string, fulfillment: array<string, mixed>}
     */
    public function selectCurrentSessionFulfillment(
        string $sessionToken,
        string $type,
    ): array {
        return $this->fulfillment
            ->selectCurrentSessionFulfillment(
                $sessionToken,
                $type,
            );
    }

    /**
     * @return list<PickupAddressData>
     */
    public function currentSessionPickupAddresses(
        string $sessionToken,
    ): array {
        return $this->fulfillment
            ->currentSessionPickupAddresses(
                $sessionToken,
            );
    }

    /**
     * @return array{session_id: string, fulfillment: array<string, mixed>}
     */
    public function selectCurrentSessionPickupAddress(
        string $sessionToken,
        int $restaurantAddressId,
    ): array {
        return $this->fulfillment
            ->selectCurrentSessionPickupAddress(
                $sessionToken,
                $restaurantAddressId,
            );
    }

    public function validateCurrentSessionDeliveryAddress(
        string $sessionToken,
        DeliveryAddressData $address,
    ): DeliveryValidationData {
        return $this->fulfillment
            ->validateCurrentSessionDeliveryAddress(
                $sessionToken,
                $address,
            );
    }

    public function getOrCreateCurrentCart(
        string $sessionToken,
    ): CartData {
        return $this->cart->getOrCreateCurrentCart(
            $sessionToken,
        );
    }

    public function ensureCurrentCart(
        string $sessionToken,
    ): CartData {
        return $this->cart->ensureCurrentCart(
            $sessionToken,
        );
    }

    public function currentCart(
        string $sessionToken,
    ): CartData {
        return $this->cart->currentCart(
            $sessionToken,
        );
    }

    public function addCurrentCartItem(
        string $sessionToken,
        int $productId,
        int $quantity,
    ): CartData {
        return $this->cart->addCurrentCartItem(
            $sessionToken,
            $productId,
            $quantity,
        );
    }

    public function updateCurrentCartItem(
        string $sessionToken,
        int $itemId,
        int $quantity,
    ): CartData {
        return $this->cart->updateCurrentCartItem(
            $sessionToken,
            $itemId,
            $quantity,
        );
    }

    public function removeCurrentCartItem(
        int $itemId,
        string $sessionToken,
    ): CartData {
        return $this->cart->removeCurrentCartItem(
            $itemId,
            $sessionToken,
        );
    }

    public function clearCurrentCart(
        string $sessionToken,
    ): CartData {
        return $this->cart->clearCurrentCart(
            $sessionToken,
        );
    }

    public function createOrder(
        string $sessionToken,
        string $idempotencyKey,
        int $deliveryTime = 0,
    ): OrderData {
        return $this->order->createOrder(
            $sessionToken,
            $idempotencyKey,
            $deliveryTime,
        );
    }

    public function currentOrder(
        string $sessionToken,
    ): OrderData {
        return $this->order->currentOrder(
            $sessionToken,
        );
    }

    public function currentPayment(
        string $sessionToken,
    ): CurrentPaymentData {
        return $this->order->currentPayment(
            $sessionToken,
        );
    }

    /**
     * @return array{status: 'ready', content_type: string, contents: string}|array{status: 'pending', payment: CurrentPaymentData}
     */
    public function currentPaymentQr(
        string $sessionToken,
    ): array {
        return $this->order->currentPaymentQr(
            $sessionToken,
        );
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function categories(
        string $restaurantSlug,
    ): array {
        return $this->catalog->categories(
            $restaurantSlug,
        );
    }

    /**
     * @return list<ProductSummaryData>
     */
    public function categoryProducts(
        string $restaurantSlug,
        int $categoryId,
    ): array {
        return $this->catalog->categoryProducts(
            $restaurantSlug,
            $categoryId,
        );
    }

    public function product(
        string $restaurantSlug,
        int $productId,
    ): ProductData {
        return $this->catalog->product(
            $restaurantSlug,
            $productId,
        );
    }

    /**
     * @return list<ProductSummaryData>
     */
    public function searchProducts(
        string $restaurantSlug,
        string $query,
        int $limit = 10,
    ): array {
        return $this->catalog->searchProducts(
            $restaurantSlug,
            $query,
            $limit,
        );
    }
}
