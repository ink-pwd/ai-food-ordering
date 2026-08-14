<?php

namespace App\Services\Handlers\Order;

use App\Integrations\Dots\OrdersApi;
use App\Models\Order;
use App\Services\Repositories\OrderRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ResolveOrderPaymentHandler
{
    public function __construct(
        private readonly OrdersApi $ordersApi,
        private readonly OrderRepository $orders,
    ) {}

    /** @return array{status: 'ready'|'pending', checkout_url: string|null, order: Order} */
    public function handle(Order $order, bool $wait = true): array
    {
        if ($order->payment_checkout_url !== null) {
            return $this->ready($order);
        }

        if ($order->external_order_id === null) {
            return $this->pending($order);
        }

        $deadline = microtime(true) + ($wait ? $this->waitSeconds() : 0.0);

        do {
            $result = $this->attempt($order);

            if ($result['status'] === 'ready') {
                return $result;
            }

            if (! $wait || microtime(true) >= $deadline) {
                return $this->pending($order);
            }

            usleep($this->pollIntervalMicroseconds());
        } while (true);
    }

    /** @return array{status: 'ready'|'pending', checkout_url: string|null, order: Order} */
    private function attempt(Order $order): array
    {
        try {
            $paymentData = $this->ordersApi->getOnlinePaymentData($order->external_order_id);
        } catch (RequestException $exception) {
            if ($exception->response->notFound() || $exception->response->serverError()) {
                Log::info('Dots online payment data is not ready.', [
                    'order_id' => $order->id,
                    'external_order_id' => $order->external_order_id,
                    'status_code' => $exception->response->status(),
                ]);

                return $this->pending($order);
            }

            throw $exception;
        } catch (ConnectionException) {
            Log::warning('Dots online payment data connection failure.', [
                'order_id' => $order->id,
                'external_order_id' => $order->external_order_id,
            ]);

            return $this->pending($order);
        }

        $checkoutUrl = $paymentData['onlinePayment']['checkoutUrl'] ?? null;

        if (! $this->isValidCheckoutUrl($checkoutUrl)) {
            throw ValidationException::withMessages([
                'payment' => ['Dots returned invalid online payment data.'],
            ]);
        }

        $snapshot = $this->paymentSnapshot($paymentData);
        $order = $this->orders->markPaymentReady($order, $checkoutUrl, $snapshot);

        return $this->ready($order);
    }

    /** @return array<string, mixed> */
    private function paymentSnapshot(array $paymentData): array
    {
        $onlinePayment = $paymentData['onlinePayment'];

        return [
            'id' => $paymentData['id'] ?? null,
            'status' => $paymentData['status'] ?? null,
            'merchant_id' => $onlinePayment['merchantId'] ?? null,
            'order_price' => $onlinePayment['orderPrice'] ?? null,
            'description' => $onlinePayment['description'] ?? null,
            'currency' => $onlinePayment['currency'] ?? null,
            'operation_id' => $onlinePayment['operationId'] ?? null,
            'commission' => $onlinePayment['commission'] ?? null,
            'fee_amount' => $onlinePayment['feeAmount'] ?? null,
            'callback_url' => $onlinePayment['callbackUrl'] ?? null,
            'total_price' => $onlinePayment['totalPrice'] ?? null,
        ];
    }

    private function isValidCheckoutUrl(mixed $checkoutUrl): bool
    {
        return is_string($checkoutUrl)
            && trim($checkoutUrl) !== ''
            && filter_var($checkoutUrl, FILTER_VALIDATE_URL) !== false
            && parse_url($checkoutUrl, PHP_URL_SCHEME) === 'https';
    }

    /** @return array{status: 'ready', checkout_url: string, order: Order} */
    private function ready(Order $order): array
    {
        return [
            'status' => 'ready',
            'checkout_url' => $order->payment_checkout_url,
            'order' => $order,
        ];
    }

    /** @return array{status: 'pending', checkout_url: null, order: Order} */
    private function pending(Order $order): array
    {
        return [
            'status' => 'pending',
            'checkout_url' => null,
            'order' => $order,
        ];
    }

    private function waitSeconds(): float
    {
        return max(0.0, (float) config('services.internal.payment.wait_seconds'));
    }

    private function pollIntervalMicroseconds(): int
    {
        return max(1, (int) config('services.internal.payment.poll_interval_ms')) * 1000;
    }
}
