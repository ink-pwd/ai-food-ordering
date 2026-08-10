<?php

namespace App\Integrations\Dots;

use Illuminate\Contracts\Cache\Factory as CacheFactory;

class CatalogApi
{
    public function __construct(
        private readonly DotsClient $dotsClient,
        private readonly CacheFactory $cache,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getCompanyCatalog(string $companyId): array
    {
        $cache = $this->cache->store('redis');
        $cacheKey = $this->cacheKey($companyId);

        if ($cache->has($cacheKey)) {
            return $cache->get($cacheKey);
        }

        $catalog = $this->fetchCompanyCatalog($companyId);

        $cache->put($cacheKey, $catalog, $this->cacheTtlSeconds());

        return $catalog;
    }

    /**
     * @return array<string, mixed>
     */
    public function refreshCompanyCatalog(string $companyId): array
    {
        $catalog = $this->fetchCompanyCatalog($companyId);

        $this->cache->store('redis')->put(
            $this->cacheKey($companyId),
            $catalog,
            $this->cacheTtlSeconds(),
        );

        return $catalog;
    }

    private function fetchCompanyCatalog(string $companyId): array
    {
        return $this->dotsClient->get("/api/v2/companies/{$companyId}/items-by-categories");
    }

    private function cacheKey(string $companyId): string
    {
        return sprintf(
            'dots:catalog:v:%s:company:%s:items-by-categories',
            config('services.dots.api_version'),
            $companyId,
        );
    }

    private function cacheTtlSeconds(): int
    {
        return (int) config('services.dots.catalog_cache_ttl_seconds');
    }
}
