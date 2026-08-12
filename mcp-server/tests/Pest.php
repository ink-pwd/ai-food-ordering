<?php

use App\Mcp\Support\BackendSessionContext;
use App\Mcp\Support\SessionContextHandle;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

function configureOrderingToolBackend(): void
{
    config()->set('services.ordering_backend', [
        'url' => 'https://ordering-backend.test',
        'internal_api_token' => 'internal-api-token-secret',
        'timeout' => 7,
    ]);

    Http::preventStrayRequests();
}

function orderingToolSessionHandle(
    string $restaurantSlug = 'trusted-restaurant',
    string $backendSessionToken = 'backend-session-token-secret',
    ?CarbonImmutable $expiresAt = null,
    string $backendSessionId = 'backend-session-id',
): string {
    return app(SessionContextHandle::class)->issue(new BackendSessionContext(
        backendSessionId: $backendSessionId,
        backendSessionToken: $backendSessionToken,
        restaurantSlug: $restaurantSlug,
        expiresAt: $expiresAt ?? CarbonImmutable::now()->addHour(),
    ));
}

/** @return array<string, mixed> */
function orderingBackendCategory(array $overrides = []): array
{
    return array_replace([
        'id' => 17,
        'external_id' => '11111111-1111-1111-1111-111111111111',
        'name' => 'Pizza',
        'slug' => 'pizza',
        'sort_order' => 2,
    ], $overrides);
}

/** @return array<string, mixed> */
function orderingBackendProduct(array $overrides = []): array
{
    return array_replace([
        'id' => 42,
        'external_id' => '22222222-2222-2222-2222-222222222222',
        'name' => 'Margherita',
        'description' => 'Tomato and cheese',
        'price' => '249.00',
        'promotion_price' => null,
        'currency' => 'UAH',
        'image_url' => 'https://images.example/margherita.jpg',
        'is_available' => true,
        'sort_order' => 3,
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $product
 * @return array<string, mixed>
 */
function orderingToolProductOutput(array $product): array
{
    return Arr::only($product, [
        'id',
        'name',
        'description',
        'price',
        'promotion_price',
        'currency',
        'image_url',
        'is_available',
    ]);
}

/** @return array<string, mixed> */
function orderingBackendCart(array $overrides = []): array
{
    return array_replace([
        'id' => 51,
        'status' => 'active',
        'currency' => 'UAH',
        'subtotal' => '199.90',
        'total' => '199.90',
        'expires_at' => '2026-08-12T19:00:00+03:00',
        'items' => [[
            'id' => 37,
            'product_id' => 91,
            'external_product_id' => '33333333-3333-3333-3333-333333333333',
            'name' => 'Пицца Маргарита',
            'quantity' => 2,
            'unit_price' => '99.95',
            'total' => '199.90',
        ]],
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $cart
 * @return array<string, mixed>
 */
function orderingToolCartOutput(array $cart): array
{
    return [
        ...Arr::only($cart, [
            'id',
            'status',
            'currency',
            'subtotal',
            'total',
            'expires_at',
        ]),
        'items' => array_map(
            static fn (array $item): array => Arr::only($item, [
                'id',
                'product_id',
                'name',
                'quantity',
                'unit_price',
                'total',
            ]),
            $cart['items'],
        ),
    ];
}

/** @return array<string, mixed> */
function orderingBackendOrder(array $overrides = []): array
{
    return array_replace([
        'id' => 73,
        'external_order_id' => '44444444-4444-4444-4444-444444444444',
        'status' => 'creating',
        'failure_message' => null,
        'receiving_type' => 'pickup',
        'total' => '180.01',
        'currency' => 'UAH',
        'items' => [[
            'product_id' => 91,
            'external_product_id' => '33333333-3333-3333-3333-333333333333',
            'name' => 'Пицца Маргарита',
            'quantity' => 2,
            'unit_price' => '99.95',
            'total' => '199.90',
        ]],
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $order
 * @return array<string, mixed>
 */
function orderingToolOrderOutput(array $order): array
{
    return [
        ...Arr::only($order, [
            'id',
            'external_order_id',
            'status',
            'receiving_type',
            'total',
            'currency',
        ]),
        'items' => array_map(
            static fn (array $item): array => Arr::only($item, [
                'product_id',
                'name',
                'quantity',
                'unit_price',
                'total',
            ]),
            $order['items'],
        ),
    ];
}
