<?php

namespace App\Integrations\Dots;

use Illuminate\Contracts\Cache\Factory as CacheFactory;

readonly class CatalogApi
{
    public function __construct(
        private DotsClient $dotsClient,
        private CacheFactory $cache,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getCompanyCatalog(string $companyId): array
    {
        $cache = $this->cache->store('redis');
        $cacheKey = $this->cacheKey($companyId);

        if ($cache->has($cacheKey)) {
            /** @var array<string, mixed> $catalog */
            $catalog = $cache->get($cacheKey);

            return $catalog;
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

    /** @return array<string, mixed> */
    private function fetchCompanyCatalog(string $companyId): array
    {
        return $this->dotsClient->get("/api/v2/companies/{$companyId}/items-by-categories");
    }

    private function cacheKey(string $companyId): string
    {
        /** @var string $apiVersion */
        $apiVersion = config('services.dots.api_version');

        return sprintf(
            'dots:catalog:v:%s:company:%s:items-by-categories',
            $apiVersion,
            $companyId,
        );
    }

    private function cacheTtlSeconds(): int
    {
        /** @var int|string $ttlSeconds */
        $ttlSeconds = config('services.dots.catalog_cache_ttl_seconds');

        return (int) $ttlSeconds;
    }
}
