<?php

use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.internal.token', 'test-internal-token');
    config()->set('services.internal.session_store', 'array');
    config()->set('services.internal.session_ttl_seconds', 7200);
    config()->set('services.internal.session_key_prefix', 'e2e-session');
    config()->set('services.internal.restaurant_slug', 'e2e-restaurant');

    config()->set('services.dots.base_url', 'https://dots.test');
    config()->set('services.dots.api_version', '2.1.0');
    config()->set('services.dots.token', 'dots-token');
    config()->set('services.dots.account_token', 'dots-account-token');
    config()->set('services.dots.auth_token', 'dots-auth-token');

    config()->set(
        'dots.city_id',
        'f68af3e1-5521-11eb-9cdd-f23c92a7f68e',
    );

    config()->set(
        'dots.company_address_id',
        '6e798cf2-5e50-473e-b75d-216c1f1f5d6d',
    );
});

it('completes the order flow with mocked Dots API', function () {
    $restaurant = Restaurant::factory()->create([
        'external_company_id' => 'f7add4f1-5521-11eb-9cdd-f23c92a7f68e',
        'slug' => 'e2e-restaurant',
        'currency' => 'UAH',
        'is_active' => true,
    ]);

    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'external_id' => 'f81dcdad-d703-40e7-ae80-a0b974731f60',
        'name' => 'Pizza Gavaiskaya',
        'price' => '100.00',
        'promotion_price' => null,
        'currency' => 'UAH',
        'is_available' => true,
    ]);

    $externalOrderId = '11111111-2222-3333-4444-555555555555';

    Http::fake(function (Request $request) use ($externalOrderId) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        if (
            $request->method() === 'POST'
            && $path === '/api/v2/cart/prices/validate'
        ) {
            return Http::response([
                'totalPrice' => 80,
            ]);
        }

        if (
            $request->method() === 'POST'
            && $path === '/api/v2/orders'
        ) {
            return Http::response([
                'id' => $externalOrderId,
            ]);
        }

        if (
            $request->method() === 'GET'
            && $path === "/api/v2/orders/{$externalOrderId}"
        ) {
            return Http::response([
                'id' => $externalOrderId,
                'status' => 'accepted',
            ]);
        }

        return Http::response([
            'message' => 'Unexpected mocked Dots request.',
        ], 500);
    });

    /*
     * 1. Create session through the real Laravel API.
     */
    $sessionResponse = $this
        ->withHeader(
            'X-Internal-Api-Token',
            'test-internal-token',
        )
        ->postJson('/api/sessions', [
            'channel' => 'chatgpt',
            'external_session_id' => 'e2e-'.Str::uuid(),
        ])
        ->assertCreated();

    $sessionToken = $sessionResponse->json(
        'data.session_token',
    );

    expect($sessionToken)
        ->toBeString()
        ->not->toBeEmpty();

    $headers = [
        'X-Internal-Api-Token' => 'test-internal-token',
        'X-Session-Token' => $sessionToken,
    ];

    /*
     * 2. Save contact.
     */
    $this->withHeaders($headers)
        ->putJson('/api/sessions/current/contact', [
            'name' => 'Yehor',
            'phone' => '+380931234567',
        ])
        ->assertOk();

    /*
     * 3. Create cart.
     */
    $this->withHeaders($headers)
        ->postJson('/api/carts')
        ->assertCreated()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.total', '0.00');

    /*
     * 4. Add product.
     */
    $this->withHeaders($headers)
        ->postJson('/api/carts/current/items', [
            'product_id' => $product->id,
            'quantity' => 1,
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonCount(1, 'data.items');

    /*
     * 5. Checkout.
     *
     * Laravel executes the real order flow.
     * Only requests leaving our application for Dots are mocked.
     */
    $idempotencyKey = 'e2e-'.Str::uuid();

    $orderResponse = $this
        ->withHeaders([
            ...$headers,
            'Idempotency-Key' => $idempotencyKey,
        ])
        ->postJson('/api/orders', [
            'delivery_time' => now()->addHour()->timestamp,
        ])
        ->assertCreated()
        ->assertJsonPath(
            'data.external_order_id',
            $externalOrderId,
        )
        ->assertJsonPath(
            'data.status',
            OrderStatus::Creating->value,
        )
        ->assertJsonPath('data.total', '80.00')
        ->assertJsonPath('data.failure_message', null);

    $localOrderId = $orderResponse->json('data.id');

    /*
     * 6. Same idempotency key must not submit another Dots order.
     */
    $retryResponse = $this
        ->withHeaders([
            ...$headers,
            'Idempotency-Key' => $idempotencyKey,
        ])
        ->postJson('/api/orders', [
            'delivery_time' => now()->addHour()->timestamp,
        ])
        ->assertOk();

    expect($retryResponse->json('data.id'))
        ->toBe($localOrderId)
        ->and($retryResponse->json('data.external_order_id'))
        ->toBe($externalOrderId)
        ->and(Order::query()->count())
        ->toBe(1);

    /*
     * 7. Accepted order checks out the historical cart, leaving no current cart.
     */
    $this->withHeaders($headers)
        ->getJson('/api/carts/current')
        ->assertNotFound();

    expect(Order::query()->with('cart')->sole()->cart->status)
        ->toBe(CartStatus::CheckedOut);

    /*
     * 8. Refresh order status.
     *
     * This executes our real GET-order lifecycle logic,
     * while the external Dots response is mocked above.
     */
    $this->withHeaders($headers)
        ->getJson('/api/orders/current')
        ->assertOk()
        ->assertJsonPath('data.id', $localOrderId)
        ->assertJsonPath(
            'data.external_order_id',
            $externalOrderId,
        )
        ->assertJsonPath(
            'data.status',
            OrderStatus::Created->value,
        )
        ->assertJsonPath('data.failure_message', null);

    expect(Order::query()->sole()->status)
        ->toBe(OrderStatus::Created);

    /*
     * Exactly:
     * 1. POST prices/validate
     * 2. POST orders
     * 3. GET order
     *
     * Idempotency retry must not make another Dots request.
     */
    Http::assertSentCount(3);
});
