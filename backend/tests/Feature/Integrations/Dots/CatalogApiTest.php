<?php

use App\Integrations\Dots\CatalogApi;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.dots.base_url', 'https://api.example-dots.test');
    config()->set('services.dots.token', 'public-token');
    config()->set('services.dots.account_token', 'account-token');
    config()->set('services.dots.api_version', '2.1.0');
    config()->set('services.dots.catalog_cache_ttl_seconds', 300);

    $this->catalogCacheKeys = [];
});

afterEach(function () {
    foreach ($this->catalogCacheKeys as $cacheKey) {
        Cache::store('redis')->forget($cacheKey);
    }
});

it('gets a company catalog by categories and returns the response unchanged', function () {
    $companyId = 'company-existing-structure';
    trackCatalogCacheKey($this, $companyId);

    $catalog = catalogResponse();

    Http::fake([
        "api.example-dots.test/api/v2/companies/{$companyId}/items-by-categories*" => Http::response($catalog),
    ]);

    $response = app(CatalogApi::class)->getCompanyCatalog($companyId);

    expect($response)->toBe($catalog);

    Http::assertSent(function (Request $request) use ($companyId): bool {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        return $request->method() === 'GET'
            && str_starts_with($request->url(), "https://api.example-dots.test/api/v2/companies/{$companyId}/items-by-categories?")
            && $query['v'] === '2.1.0';
    });
});

it('returns an empty company catalog unchanged', function () {
    $companyId = 'company-empty-existing';
    trackCatalogCacheKey($this, $companyId);

    $catalog = emptyCatalogResponse();

    Http::fake([
        "api.example-dots.test/api/v2/companies/{$companyId}/items-by-categories*" => Http::response($catalog),
    ]);

    $response = app(CatalogApi::class)->getCompanyCatalog($companyId);

    expect($response)->toBe($catalog);
});

it('requests the catalog on cache miss and stores the complete response in redis', function () {
    $companyId = 'company-cache-miss';
    $cacheKey = trackCatalogCacheKey($this, $companyId);
    $catalog = catalogResponse();

    Http::fake([
        "api.example-dots.test/api/v2/companies/{$companyId}/items-by-categories*" => Http::response($catalog),
    ]);

    $response = app(CatalogApi::class)->getCompanyCatalog($companyId);

    expect($response)->toBe($catalog)
        ->and(Cache::store('redis')->get($cacheKey))->toBe($catalog);

    Http::assertSentCount(1);
});

it('returns cached response for a second request without another http request', function () {
    $companyId = 'company-cache-hit';
    trackCatalogCacheKey($this, $companyId);

    Http::fake([
        "api.example-dots.test/api/v2/companies/{$companyId}/items-by-categories*" => Http::response(catalogResponse(['source' => 'dots'])),
    ]);

    $catalogApi = app(CatalogApi::class);

    $first = $catalogApi->getCompanyCatalog($companyId);
    $second = $catalogApi->getCompanyCatalog($companyId);

    expect($second)->toBe($first);

    Http::assertSentCount(1);
});

it('caches an empty successful catalog response', function () {
    $companyId = 'company-empty-cache';
    $cacheKey = trackCatalogCacheKey($this, $companyId);
    $catalog = emptyCatalogResponse();

    Http::fake([
        "api.example-dots.test/api/v2/companies/{$companyId}/items-by-categories*" => Http::response($catalog),
    ]);

    $response = app(CatalogApi::class)->getCompanyCatalog($companyId);

    expect($response)->toBe($catalog)
        ->and(Cache::store('redis')->get($cacheKey))->toBe($catalog);
});

it('uses different cache entries for different company ids', function () {
    $firstCompanyId = 'company-cache-one';
    $secondCompanyId = 'company-cache-two';
    $firstKey = trackCatalogCacheKey($this, $firstCompanyId);
    $secondKey = trackCatalogCacheKey($this, $secondCompanyId);

    Http::fake([
        "api.example-dots.test/api/v2/companies/{$firstCompanyId}/items-by-categories*" => Http::response(catalogResponse(['company' => 'one'])),
        "api.example-dots.test/api/v2/companies/{$secondCompanyId}/items-by-categories*" => Http::response(catalogResponse(['company' => 'two'])),
    ]);

    app(CatalogApi::class)->getCompanyCatalog($firstCompanyId);
    app(CatalogApi::class)->getCompanyCatalog($secondCompanyId);

    expect(Cache::store('redis')->get($firstKey)['company'])->toBe('one')
        ->and(Cache::store('redis')->get($secondKey)['company'])->toBe('two');
});

it('uses different cache entries for different api versions', function () {
    $companyId = 'company-versioned-cache';
    $versionOneKey = trackCatalogCacheKey($this, $companyId, '2.1.0');
    $versionTwoKey = trackCatalogCacheKey($this, $companyId, '2.2.0');

    Http::fake([
        "api.example-dots.test/api/v2/companies/{$companyId}/items-by-categories*" => Http::sequence()
            ->push(catalogResponse(['version' => '2.1.0']))
            ->push(catalogResponse(['version' => '2.2.0'])),
    ]);

    app(CatalogApi::class)->getCompanyCatalog($companyId);

    config()->set('services.dots.api_version', '2.2.0');

    app(CatalogApi::class)->getCompanyCatalog($companyId);

    expect(Cache::store('redis')->get($versionOneKey)['version'])->toBe('2.1.0')
        ->and(Cache::store('redis')->get($versionTwoKey)['version'])->toBe('2.2.0');

    Http::assertSentCount(2);
});

it('refresh company catalog bypasses an existing cached value', function () {
    $companyId = 'company-refresh-bypass';
    $cacheKey = trackCatalogCacheKey($this, $companyId);
    $cachedCatalog = catalogResponse(['source' => 'cache']);
    $freshCatalog = catalogResponse(['source' => 'dots']);

    Cache::store('redis')->put($cacheKey, $cachedCatalog, 300);

    Http::fake([
        "api.example-dots.test/api/v2/companies/{$companyId}/items-by-categories*" => Http::response($freshCatalog),
    ]);

    $response = app(CatalogApi::class)->refreshCompanyCatalog($companyId);

    expect($response)->toBe($freshCatalog);

    Http::assertSentCount(1);
});

it('successful refresh replaces the previous cached value', function () {
    $companyId = 'company-refresh-replace';
    $cacheKey = trackCatalogCacheKey($this, $companyId);
    $cachedCatalog = catalogResponse(['source' => 'old']);
    $freshCatalog = catalogResponse(['source' => 'new']);

    Cache::store('redis')->put($cacheKey, $cachedCatalog, 300);

    Http::fake([
        "api.example-dots.test/api/v2/companies/{$companyId}/items-by-categories*" => Http::response($freshCatalog),
    ]);

    app(CatalogApi::class)->refreshCompanyCatalog($companyId);

    expect(Cache::store('redis')->get($cacheKey))->toBe($freshCatalog);
});

it('failed refresh preserves the previous cached value', function () {
    $companyId = 'company-refresh-fails';
    $cacheKey = trackCatalogCacheKey($this, $companyId);
    $cachedCatalog = catalogResponse(['source' => 'old']);

    Cache::store('redis')->put($cacheKey, $cachedCatalog, 300);

    Http::fake([
        "api.example-dots.test/api/v2/companies/{$companyId}/items-by-categories*" => Http::response(['message' => 'bad request'], 400),
    ]);

    try {
        app(CatalogApi::class)->refreshCompanyCatalog($companyId);
    } catch (RequestException) {
        expect(Cache::store('redis')->get($cacheKey))->toBe($cachedCatalog);

        return;
    }

    $this->fail('Expected refresh request to throw a request exception.');
});

it('failed request on empty cache does not create a cache entry', function () {
    $companyId = 'company-empty-failure';
    $cacheKey = trackCatalogCacheKey($this, $companyId);

    Http::fake([
        "api.example-dots.test/api/v2/companies/{$companyId}/items-by-categories*" => Http::response(['message' => 'bad request'], 400),
    ]);

    try {
        app(CatalogApi::class)->getCompanyCatalog($companyId);
    } catch (RequestException) {
        expect(Cache::store('redis')->has($cacheKey))->toBeFalse();

        return;
    }

    $this->fail('Expected catalog request to throw a request exception.');
});

it('explicitly uses redis when the default cache store is array', function () {
    config()->set('cache.default', 'array');

    $companyId = 'company-explicit-redis';
    $cacheKey = trackCatalogCacheKey($this, $companyId);
    $catalog = catalogResponse(['store' => 'redis']);

    Http::fake([
        "api.example-dots.test/api/v2/companies/{$companyId}/items-by-categories*" => Http::response($catalog),
    ]);

    app(CatalogApi::class)->getCompanyCatalog($companyId);

    expect(Cache::store('redis')->get($cacheKey))->toBe($catalog)
        ->and(Cache::store('array')->has($cacheKey))->toBeFalse();
});

it('does not transform the raw catalog response before caching or returning it', function () {
    $companyId = 'company-raw-structure';
    $cacheKey = trackCatalogCacheKey($this, $companyId);
    $catalog = catalogResponse([
        'hasNext' => true,
        'promotions' => [['id' => 'promotion-id', 'name' => 'Promo']],
        'customTopLevelField' => ['nested' => ['value' => 123]],
    ]);

    Http::fake([
        "api.example-dots.test/api/v2/companies/{$companyId}/items-by-categories*" => Http::response($catalog),
    ]);

    $response = app(CatalogApi::class)->getCompanyCatalog($companyId);

    expect($response)->toBe($catalog)
        ->and(Cache::store('redis')->get($cacheKey))->toBe($catalog);
});

function trackCatalogCacheKey(object $test, string $companyId, string $apiVersion = '2.1.0'): string
{
    $cacheKey = catalogCacheKey($companyId, $apiVersion);

    Cache::store('redis')->forget($cacheKey);

    $test->catalogCacheKeys[] = $cacheKey;

    return $cacheKey;
}

function catalogCacheKey(string $companyId, string $apiVersion = '2.1.0'): string
{
    return "dots:catalog:v:{$apiVersion}:company:{$companyId}:items-by-categories";
}

function emptyCatalogResponse(): array
{
    return [
        'items' => [],
        'hasNext' => false,
        'promotions' => [],
    ];
}

function catalogResponse(array $overrides = []): array
{
    return array_replace_recursive([
        'items' => [
            [
                'id' => 'category-external-id',
                'name' => 'Category name',
                'url' => 'category-slug',
                'items' => [
                    [
                        'id' => 'product-external-id',
                        'companyCategoryId' => 'category-external-id',
                        'isAvailableToOrder' => true,
                        'name' => 'Product name',
                        'description' => 'Short description',
                        'fullDescription' => 'Full description',
                        'measureText' => '320 g',
                        'price' => 105,
                        'promotionPrice' => null,
                        'packagePrice' => 0,
                        'maxCountPositionsInPackage' => 1,
                        'image' => 'https://example.com/product.png',
                        'promotionsIds' => [],
                        'modifiers' => [],
                        'nutrientsDataTitle' => 'Nutrition value per 100 g',
                        'nutrientsData' => [],
                        'foodTypes' => [],
                        'promoLabelText' => null,
                    ],
                ],
            ],
        ],
        'hasNext' => false,
        'promotions' => [],
    ], $overrides);
}
