<?php

namespace App\Services\Handlers\Order;

use App\DTO\OrderCheckoutData;
use App\DTO\OrderFulfillmentSnapshotData;
use App\DTO\OrderPricingData;
use App\Integrations\Dots\CartPricesApi;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

readonly class ValidateOrderPricesHandler
{
    public function __construct(
        private CartPricesApi $cartPrices,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(
        array $payload,
        OrderCheckoutData $checkout,
    ): OrderPricingData {
        $validation = $this->validate(
            $payload,
        );

        return new OrderPricingData(
            validation: $validation,
            validatedTotal:
            $this->validatedTotal($validation),
            fulfillmentSnapshot:
            $this->fulfillmentSnapshot(
                $checkout,
                $validation,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validate(array $payload): array
    {
        try {
            return $this->cartPrices->validate(
                $payload,
            );
        } catch (RequestException $exception) {
            $body = $exception->response->json();

            $message = $this->rejectionMessage($body);

            throw new HttpException(
                $exception->response->clientError()
                    ? 422
                    : 502,
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

    private function rejectionMessage(mixed $body): string
    {
        return is_array($body)
            && is_string($body['message'] ?? null)
            && $body['message'] !== ''
                ? $body['message']
                : 'Dots rejected checkout data.';
    }

    private function hasUnsupportedTotalType(
        mixed $total,
    ): bool {
        return ! is_int($total)
            && ! is_float($total)
            && ! is_string($total);
    }

    /**
     * @param  array<string, mixed>  $priceValidation
     */
    private function validatedTotal(
        array $priceValidation,
    ): string {
        $total =
            $priceValidation['totalPrice'] ?? null;

        if ($this->hasUnsupportedTotalType($total)) {
            throw new RuntimeException(
                'Dots price validation response does not contain totalPrice.',
            );
        }

        /** @var int|float|string $rawTotal */
        $rawTotal = $total;
        $total = trim((string) $rawTotal);

        if (
            preg_match(
                '/^\d+(?:\.\d{1,2})?$/',
                $total,
            ) !== 1
        ) {
            throw new RuntimeException(
                'Dots returned an invalid totalPrice.',
            );
        }

        return $total;
    }

    /**
     * @param  array<string, mixed>  $priceValidation
     */
    private function fulfillmentSnapshot(
        OrderCheckoutData $checkout,
        array $priceValidation,
    ): OrderFulfillmentSnapshotData {
        $context = $checkout->fulfillmentContext;

        return new OrderFulfillmentSnapshotData(
            cityId: $checkout->city->id,
            externalCityId: $checkout->city->external_city_id,
            restaurantId: $checkout->restaurant->id,
            externalCompanyId: $checkout->restaurant->external_company_id,
            type: $context->type,
            dotsDeliveryType: $context->deliveryType,
            deliveryPrice: $context->deliveryPrice,
            priceValidationDeliveryPrice: $priceValidation['deliveryPrice'] ?? null,
            paymentType: $checkout->paymentType->value,
            restaurantAddressId: $context->restaurantAddress?->id,
            externalAddressId: $context->restaurantAddress?->external_address_id,
            deliveryAddress: $context->deliveryAddress,
        );
    }
}
