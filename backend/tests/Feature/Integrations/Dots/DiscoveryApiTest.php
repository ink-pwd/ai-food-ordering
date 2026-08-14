<?php

use App\Integrations\Dots\DiscoveryApi;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.dots.base_url', 'https://api.example-dots.test');
    config()->set('services.dots.token', 'public-token');
    config()->set('services.dots.account_token', 'account-token');
    config()->set('services.dots.api_version', '2.1.0');
});

it('fetches active cities through Dots client', function () {
    $cities = ['items' => [discoveryCity()], 'hasNext' => false];

    Http::fake([
        'api.example-dots.test/api/v2/cities*' => Http::response($cities),
    ]);

    $response = app(DiscoveryApi::class)->refreshActiveCities();

    expect($response)->toBe($cities);

    Http::assertSent(function (Request $request): bool {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        return $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://api.example-dots.test/api/v2/cities?')
            && $query['v'] === '2.1.0'
            && $request->hasHeader('Api-Token', 'public-token')
            && $request->hasHeader('Api-Account-Token', 'account-token')
            && $request->hasHeader('Api-lang', 'ua');
    });
});

it('fetches city details through Dots client', function () {
    $cityId = '11111111-1111-1111-1111-111111111111';
    $city = discoveryCity(['id' => $cityId]);

    Http::fake([
        "api.example-dots.test/api/v2/cities/{$cityId}*" => Http::response($city),
    ]);

    expect(app(DiscoveryApi::class)->getCity($cityId))->toBe($city);
});

it('fetches city companies through Dots client', function () {
    $cityId = '11111111-1111-1111-1111-111111111111';
    $companies = ['items' => [discoveryCompany()], 'hasNext' => false];

    Http::fake([
        "api.example-dots.test/api/v2/cities/{$cityId}/companies*" => Http::response($companies),
    ]);

    expect(app(DiscoveryApi::class)->refreshCityCompanies($cityId))->toBe($companies);
});

it('fetches company details through Dots client', function () {
    $companyId = '22222222-2222-2222-2222-222222222222';
    $company = discoveryCompany(['id' => $companyId]);

    Http::fake([
        "api.example-dots.test/api/v2/companies/{$companyId}*" => Http::response($company),
    ]);

    expect(app(DiscoveryApi::class)->getCompany($companyId))->toBe($company);
});

function discoveryCity(array $overrides = []): array
{
    return array_replace_recursive([
        'id' => '11111111-1111-1111-1111-111111111111',
        'name' => 'Чернигов',
        'url' => 'chernigov',
        'status' => 1,
    ], $overrides);
}

function discoveryCompany(array $overrides = []): array
{
    return array_replace_recursive([
        'id' => '22222222-2222-2222-2222-222222222222',
        'name' => 'Papa Jon',
        'url' => 'papa-jon',
        'status' => 1,
    ], $overrides);
}
