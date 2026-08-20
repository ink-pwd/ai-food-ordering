<?php

namespace App\Integrations\Dots;

readonly class DiscoveryApi
{
    public function __construct(
        private DotsClient $dotsClient,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function refreshActiveCities(): array
    {
        return $this->dotsClient->get('/api/v2/cities');
    }

    /**
     * @return array<string, mixed>
     */
    public function getCity(string $cityId): array
    {
        return $this->dotsClient->get("/api/v2/cities/{$cityId}");
    }

    /**
     * @return array<string, mixed>
     */
    public function refreshCityCompanies(string $cityId): array
    {
        return $this->dotsClient->get("/api/v2/cities/{$cityId}/companies");
    }

    /**
     * @return array<string, mixed>
     */
    public function getCompany(string $companyId): array
    {
        return $this->dotsClient->get("/api/v2/companies/{$companyId}");
    }
}
