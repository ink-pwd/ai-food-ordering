<?php

namespace App\Integrations\Dots;

readonly class CartPricesApi
{
    public function __construct(
        private DotsClient $dotsClient,
    ) {
    }

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
