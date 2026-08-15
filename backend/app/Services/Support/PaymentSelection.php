<?php

namespace App\Services\Support;

use App\Enums\PaymentType;
use App\Models\Restaurant;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class PaymentSelection
{
    public static function type(array $session): PaymentType
    {
        $value = $session['metadata']['payment_type'] ?? null;

        $type = is_numeric($value)
            ? PaymentType::tryFrom((int) $value)
            : null;

        if ($type === null) {
            throw new ConflictHttpException('Payment method must be selected.');
        }

        return $type;
    }

    public static function assertSupported(
        Restaurant $restaurant,
        PaymentType $paymentType,
    ): void {
        $availableTypes = array_map(
            'intval',
            $restaurant->available_payment_types ?? [],
        );

        if (! in_array($paymentType->value, $availableTypes, true)) {
            throw new ConflictHttpException(
                'Selected payment method is not available for this restaurant.'
            );
        }
    }
}
