<?php

namespace App\Integrations\Dots;

class CartPricesApi
{
    public function __construct(
        private readonly DotsClient $dotsClient,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function validate(array $payload): array
    {
        return $this->dotsClient->post(
            '/api/v2/cart/prices/validate',
            $payload,
        );
    }
}
