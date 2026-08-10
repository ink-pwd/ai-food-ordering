<?php

use App\Integrations\Dots\DotsClient;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.dots.base_url', 'https://api.example-dots.test');
    config()->set('services.dots.token', 'public-token');
    config()->set('services.dots.account_token', 'account-token');
    config()->set('services.dots.auth_token', 'auth-token');
    config()->set('services.dots.api_version', '9.9.9');
});

it('sends public get requests to the configured base url with required headers and query', function () {
    Http::fake([
        'api.example-dots.test/catalog/items*' => Http::response(['items' => []]),
    ]);

    $response = app(DotsClient::class)->get('/catalog/items', [
        'limit' => 25,
        'category' => 'pizza',
        'v' => '1.0.0',
    ]);

    expect($response)->toBe(['items' => []]);

    Http::assertSent(function (Request $request): bool {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        return $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://api.example-dots.test/catalog/items?')
            && $request->hasHeader('Api-Token', 'public-token')
            && $request->hasHeader('Api-Account-Token', 'account-token')
            && $request->hasHeader('Accept', 'application/json')
            && ! $request->hasHeader('Api-Auth-Token')
            && $query['v'] === '9.9.9'
            && $query['limit'] === '25'
            && $query['category'] === 'pizza';
    });
});

it('retries transient server errors and returns the later successful json response', function () {
    Http::fake([
        'api.example-dots.test/catalog/items*' => Http::sequence()
            ->push(['message' => 'temporary failure'], 500)
            ->push(['items' => [['id' => 123]]], 200),
    ]);

    $response = app(DotsClient::class)->get('/catalog/items');

    expect($response)->toBe(['items' => [['id' => 123]]]);

    Http::assertSentCount(2);
});

it('does not retry non retryable client errors and throws an exception', function () {
    Http::fake([
        'api.example-dots.test/catalog/items*' => Http::response(['message' => 'bad request'], 400),
    ]);

    app(DotsClient::class)->get('/catalog/items');
})->throws(RequestException::class)->after(function () {
    Http::assertSentCount(1);
});

it('sends public post requests with json payload and configured api version', function () {
    Http::fake([
        'api.example-dots.test/api/v2/orders*' => Http::response([
            'id' => '95760c30-ba38-48fa-ae19-5ad41cbe7749',
        ]),
    ]);

    $payload = [
        'deliveryType' => 2,
        'paymentType' => 1,
    ];

    $response = app(DotsClient::class)->post(
        '/api/v2/orders',
        $payload,
    );

    expect($response)->toBe([
        'id' => '95760c30-ba38-48fa-ae19-5ad41cbe7749',
    ]);

    Http::assertSent(function (Request $request) use ($payload): bool {
        parse_str(
            parse_url($request->url(), PHP_URL_QUERY) ?? '',
            $query,
        );

        return $request->method() === 'POST'
            && str_starts_with(
                $request->url(),
                'https://api.example-dots.test/api/v2/orders?',
            )
            && $request->hasHeader('Api-Token', 'public-token')
            && $request->hasHeader('Api-Account-Token', 'account-token')
            && $request->hasHeader('Accept', 'application/json')
            && ! $request->hasHeader('Api-Auth-Token')
            && $query['v'] === '9.9.9'
            && $request->data() === $payload;
    });
});

it('does not retry failed post requests', function () {
    Http::fake([
        'api.example-dots.test/api/v2/orders*' => Http::response([
            'message' => 'temporary failure',
        ], 500),
    ]);

    app(DotsClient::class)->post('/api/v2/orders', [
        'deliveryType' => 2,
    ]);
})->throws(RequestException::class)->after(function () {
    Http::assertSentCount(1);
});
