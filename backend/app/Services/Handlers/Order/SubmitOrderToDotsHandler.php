<?php

namespace App\Services\Handlers\Order;

use App\Integrations\Dots\OrdersApi;
use App\Models\Order;
use App\Services\Repositories\CartRepository;
use App\Services\Repositories\OrderRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

readonly class SubmitOrderToDotsHandler
{
    public function __construct(
        private OrdersApi $dotsOrders,
        private OrderRepository $orders,
        private CartRepository $carts,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(
        Order $order,
        array $payload,
    ): void {
        try {
            $response = $this->dotsOrders->create(
                $payload,
            );

            $externalOrderId = $response['id'] ?? null;

            if (
                ! is_string($externalOrderId)
                || trim($externalOrderId) === ''
            ) {
                throw new RuntimeException(
                    'Dots order response does not contain an order id.',
                );
            }

            // Dots acceptance and cart checkout are one local state transition.
            DB::transaction(function () use (
                $order,
                $externalOrderId,
                $response,
            ): void {
                $this->markAccepted(
                    $order,
                    $externalOrderId,
                    $response,
                );
            });
        } catch (RequestException $exception) {
            $body = $exception->response->json();

            /** @var array<string, mixed>|null $responseBody */
            $responseBody = is_array($body)
                ? $body
                : null;

            $message = $this->rejectionMessage(
                $responseBody,
            );

            if ($exception->response->clientError()) {
                $this->orders->markFailed(
                    $order,
                    $message,
                    $responseBody,
                );

                throw new HttpException(
                    422,
                    $message,
                    $exception,
                );
            }

            $this->orders->markSubmissionUnknown(
                $order,
                'Dots returned a server error while creating order.',
                $responseBody,
            );

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

            throw new HttpException(
                502,
                'Ordering service returned an invalid response.',
                $exception,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    /**
     * @param  array<string, mixed>|null  $responseBody
     */
    private function rejectionMessage(
        ?array $responseBody,
    ): string {
        return $responseBody !== null
            && is_string(
                $responseBody['message'] ?? null,
            )
            && $responseBody['message'] !== ''
                ? $responseBody['message']
                : 'Dots rejected order creation.';
    }

    /** @param array<string, mixed> $response */
    private function markAccepted(
        Order $order,
        string $externalOrderId,
        array $response,
    ): void {
        $this->orders->markAcceptedByDots(
            $order,
            $externalOrderId,
            $response,
        );

        $this->carts->markCheckedOut(
            $this->carts->findForOrderOrFail(
                $order,
            ),
        );
    }
}
