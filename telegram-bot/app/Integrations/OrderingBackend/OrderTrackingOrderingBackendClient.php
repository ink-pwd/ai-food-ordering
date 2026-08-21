<?php

namespace App\Integrations\OrderingBackend;

use App\DTO\OrderingBackend\OrderTrackingData;

final readonly class OrderTrackingOrderingBackendClient
{
    public function __construct(
        private OrderingBackendTransport $transport,
        private OrderTrackingResponseMapper $mapper,
    ) {
    }

    public function get(
        string $sessionToken,
        int $orderId,
    ): OrderTrackingData {
        $response = $this->transport->sessionBoundGet(
            sessionToken: $sessionToken,
            path: "api/orders/{$orderId}/tracking",
            operation: 'get_order_tracking',
            message: 'Unable to retrieve the ordering backend order tracking data.',
        );

        return $this->mapper->fromResponse($response);
    }
}
