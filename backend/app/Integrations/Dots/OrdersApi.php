<?php

namespace App\Integrations\Dots;

class OrdersApi
{
    public function __construct(
        private readonly DotsClient $dotsClient,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function create(array $payload): array
    {
        return $this->dotsClient->authenticatedPost(
            '/api/v2/orders',
            $payload,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $orderId): array
    {
        return $this->dotsClient->authenticatedGet(
            "/api/v2/orders/{$orderId}",
        );
    }
}
