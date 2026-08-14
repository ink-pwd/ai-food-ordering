<?php

namespace App\Services\Support;

use App\Enums\CartStatus;
use App\Enums\FulfillmentType;
use App\Models\Restaurant;
use App\Services\Repositories\CartRepository;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class FulfillmentSelection
{
    public static function assertReady(array $session): void
    {
        $fulfillment = $session['fulfillment'] ?? null;

        if (! is_array($fulfillment)) {
            throw new ConflictHttpException('Fulfillment must be selected.');
        }

        if (($fulfillment['type'] ?? null) === FulfillmentType::Pickup->value) {
            if (! is_int($fulfillment['restaurant_address_id'] ?? null) || $fulfillment['restaurant_address_id'] < 1) {
                throw new ConflictHttpException('Pickup location must be selected.');
            }

            return;
        }

        if (($fulfillment['type'] ?? null) === FulfillmentType::Delivery->value) {
            if (! is_int($fulfillment['dots_delivery_type'] ?? null)
                || ! isset($fulfillment['delivery_price'])
                || ! is_array($fulfillment['delivery_address'] ?? null)
            ) {
                throw new ConflictHttpException('Validated delivery address is required.');
            }

            return;
        }

        throw new ConflictHttpException('Fulfillment must be selected.');
    }

    public static function assertMutable(CartRepository $carts, Restaurant $restaurant, string $sessionId): void
    {
        if ($carts->hasNonActiveCartForSession($restaurant, $sessionId)) {
            throw new ConflictHttpException('Fulfillment cannot be changed after checkout.');
        }
    }

    public static function supportsPickup(Restaurant $restaurant): bool
    {
        return in_array(2, array_map('intval', $restaurant->available_delivery_types ?? []), true);
    }

    public static function supportsDelivery(Restaurant $restaurant): bool
    {
        $types = array_map('intval', $restaurant->available_delivery_types ?? []);

        return in_array(0, $types, true) || in_array(1, $types, true);
    }

    /** @param array<int, array<string, mixed>> $deliveryTypes */
    public static function acceptableDeliveryType(array $deliveryTypes): ?array
    {
        foreach ([0, 1] as $preferredType) {
            foreach ($deliveryTypes as $deliveryType) {
                if ((int) ($deliveryType['type'] ?? -1) === $preferredType) {
                    return $deliveryType;
                }
            }
        }

        return null;
    }
}
