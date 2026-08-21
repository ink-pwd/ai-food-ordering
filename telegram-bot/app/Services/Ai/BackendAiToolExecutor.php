<?php

namespace App\Services\Ai;

use App\Contracts\AiToolExecutor;
use App\DTO\Ai\AiCartData;
use App\DTO\Ai\AiContextData;
use App\DTO\Ai\AiOrderTrackingResultData;
use App\DTO\Ai\AiProductSearchData;
use App\DTO\Ai\AiToolResultData;
use App\DTO\Llm\LlmToolCallData;
use App\DTO\OrderingBackend\CartData;
use App\DTO\OrderingBackend\CartItemData;
use App\Exceptions\LlmException;
use App\Exceptions\OrderingBackendException;
use App\Integrations\OrderingBackend\OrderingBackendClient;
use App\Telegram\Formatting\OrderTrackingMessageFormatter;
use JsonException;

final readonly class BackendAiToolExecutor implements AiToolExecutor
{
    public function __construct(
        private OrderingBackendClient $backend,
        private AiToolInputReader $input,
        private OrderTrackingMessageFormatter $trackingFormatter,
    ) {
    }

    public function execute(
        LlmToolCallData $toolCall,
        AiContextData $context,
    ): AiToolResultData {
        return match ($toolCall->name) {
            'search_products' => $this->searchProducts($toolCall, $context),
            'get_cart' => $this->getCart($context),
            'add_to_cart' => $this->addToCart($toolCall, $context),
            'set_cart_item_quantity' => $this->setCartItemQuantity($toolCall, $context),
            'remove_cart_item' => $this->removeCartItem($toolCall, $context),
            'get_order_tracking' => $this->getOrderTracking($toolCall, $context),
            default => throw new LlmException(
                "LLM requested unsupported tool {$toolCall->name}.",
            ),
        };
    }

    private function searchProducts(
        LlmToolCallData $toolCall,
        AiContextData $context,
    ): AiToolResultData {
        $query = $this->input->nonEmptyString($toolCall, 'query');
        $products = $this->backend->searchProducts(
            $context->restaurantSlug,
            $query,
            8,
        );

        return $this->encode(
            AiProductSearchData::fromProducts($query, $products)->toArray(),
        );
    }

    private function getCart(AiContextData $context): AiToolResultData
    {
        return $this->cartResult(
            $this->backend->getOrCreateCurrentCart(
                $context->sessionToken,
            ),
        );
    }

    private function addToCart(
        LlmToolCallData $toolCall,
        AiContextData $context,
    ): AiToolResultData {
        $productId = $this->input->positiveInteger(
            $toolCall,
            'product_id',
        );
        $quantity = $this->input->positiveInteger(
            $toolCall,
            'quantity',
        );

        $cart = $this->backend->getOrCreateCurrentCart(
            $context->sessionToken,
        );
        $existingItem = $this->findItemByProductId(
            $cart,
            $productId,
        );

        $updatedCart = $existingItem === null
            ? $this->backend->addCurrentCartItem(
                sessionToken: $context->sessionToken,
                productId: $productId,
                quantity: $quantity,
            )
            : $this->backend->updateCurrentCartItem(
                sessionToken: $context->sessionToken,
                itemId: $existingItem->id,
                quantity: $existingItem->quantity + $quantity,
            );

        return $this->cartResult($updatedCart);
    }

    private function setCartItemQuantity(
        LlmToolCallData $toolCall,
        AiContextData $context,
    ): AiToolResultData {
        $itemId = $this->input->positiveInteger(
            $toolCall,
            'item_id',
        );
        $quantity = $this->input->positiveInteger(
            $toolCall,
            'quantity',
        );

        return $this->cartResult(
            $this->backend->updateCurrentCartItem(
                sessionToken: $context->sessionToken,
                itemId: $itemId,
                quantity: $quantity,
            ),
        );
    }

    private function removeCartItem(
        LlmToolCallData $toolCall,
        AiContextData $context,
    ): AiToolResultData {
        $itemId = $this->input->positiveInteger(
            $toolCall,
            'item_id',
        );

        return $this->cartResult(
            $this->backend->removeCurrentCartItem(
                $itemId,
                $context->sessionToken,
            ),
        );
    }

    private function getOrderTracking(
        LlmToolCallData $toolCall,
        AiContextData $context,
    ): AiToolResultData {
        $orderId = $this->input->positiveInteger(
            $toolCall,
            'order_id',
        );

        try {
            $tracking = $this->backend->orderTracking(
                $context->sessionToken,
                $orderId,
            );
        } catch (OrderingBackendException $exception) {
            if ($exception->statusCode() !== 404) {
                throw $exception;
            }

            return $this->encode(
                AiOrderTrackingResultData::missing($orderId)->toArray(),
            );
        }

        return $this->encode(
            (new AiOrderTrackingResultData(
                orderId: $orderId,
                found: true,
                summary: $this->trackingFormatter->format($tracking),
            ))->toArray(),
        );
    }

    private function cartResult(CartData $cart): AiToolResultData
    {
        return $this->encode(
            AiCartData::fromCart($cart)->toArray(),
        );
    }

    private function findItemByProductId(
        CartData $cart,
        int $productId,
    ): ?CartItemData {
        foreach ($cart->items as $item) {
            if ($item->productId === $productId) {
                return $item;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $payload */
    private function encode(array $payload): AiToolResultData
    {
        try {
            return new AiToolResultData(
                json_encode(
                    $payload,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                ),
            );
        } catch (JsonException $exception) {
            throw new LlmException(
                'Unable to encode AI tool result.',
                $exception,
            );
        }
    }
}
