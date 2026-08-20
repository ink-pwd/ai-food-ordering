<?php

namespace App\Integrations\Dots;

readonly class FulfillmentApi
{
    public function __construct(
        private DotsClient $dotsClient,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function validateUserAddress(array $payload): array
    {
        return $this->dotsClient->post('/api/v2/user-addresses/validate', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function getCompanyDeliveryTypes(string $companyId, ?string $latitude = null, ?string $longitude = null): array
    {
        $query = [];

        if ($latitude !== null && $longitude !== null) {
            $query['latitude'] = $latitude;
            $query['longitude'] = $longitude;
        }

        return $this->dotsClient->get("/api/v2/companies/{$companyId}/delivery-types", $query);
    }
}
