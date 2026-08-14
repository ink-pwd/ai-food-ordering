<?php

use App\Enums\CartStatus;
use App\Enums\FulfillmentType;
use App\Enums\OrderStatus;
use App\Models\City;
use App\Models\Order;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\RestaurantAddress;
use App\Services\Contracts\OtpSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fakes\FakeOtpSender;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.internal.token', 'test-internal-token');
    config()->set('services.internal.session_store', 'array');
    config()->set('services.internal.session_ttl_seconds', 7200);
    config()->set('services.internal.session_key_prefix', 'e2e-session');
    config()->set('services.internal.otp.store', 'array');
    config()->set('services.internal.otp.key_prefix', 'e2e-session-otp');
    config()->set('services.internal.otp.delivery_driver', 'log');
    config()->set('services.internal.otp.code_length', 6);
    config()->set('services.internal.otp.ttl_seconds', 300);
    config()->set('services.internal.otp.resend_cooldown_seconds', 60);
    config()->set('services.internal.otp.max_attempts', 3);
    config()->set('services.internal.payment.wait_seconds', 0);
    config()->set('services.internal.payment.poll_interval_ms', 1);

    config()->set('services.dots.base_url', 'https://dots.test');
    config()->set('services.dots.api_version', '2.1.0');
    config()->set('services.dots.token', 'dots-token');
    config()->set('services.dots.account_token', 'dots-account-token');
    config()->set('services.dots.auth_token', 'dots-auth-token');

    $this->otpSender = new FakeOtpSender;
    app()->instance(OtpSender::class, $this->otpSender);
});

it('completes the current backend order and payment QR flow with mocked Dots API', function () {
    Storage::fake('local');

    $city = City::factory()->create([
        'external_city_id' => 'f68af3e1-5521-11eb-9cdd-f23c92a7f68e',
        'name' => 'Chernihiv',
        'is_active' => true,
    ]);

    $restaurant = Restaurant::factory()->for($city)->create([
        'external_company_id' => 'f7add4f1-5521-11eb-9cdd-f23c92a7f68e',
        'slug' => 'e2e-restaurant',
        'currency' => 'UAH',
        'is_active' => true,
        'available_payment_types' => [2],
        'available_delivery_types' => [2],
    ]);

    $pickupAddress = RestaurantAddress::factory()->for($restaurant)->create([
        'external_address_id' => '6e798cf2-5e50-473e-b75d-216c1f1f5d6d',
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
    $checkoutUrl = 'https://checkout.example.test/pay/e2e-placeholder-token';

    Http::fake(function (Request $request) use ($externalOrderId, $checkoutUrl) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        if ($request->method() === 'POST' && $path === '/api/v2/cart/prices/validate') {
            return Http::response([
                'totalPrice' => 80,
                'deliveryPrice' => 0,
            ]);
        }

        if ($request->method() === 'POST' && $path === '/api/v2/orders') {
            return Http::response([
                'id' => $externalOrderId,
            ]);
        }

        if ($request->method() === 'GET' && $path === "/api/v2/orders/{$externalOrderId}/online-payment-data") {
            return Http::response([
                'id' => $externalOrderId,
                'status' => 'created',
                'onlinePayment' => [
                    'checkoutUrl' => $checkoutUrl,
                    'merchantId' => 'merchant-e2e',
                    'orderPrice' => 80,
                    'description' => 'E2E order',
                    'currency' => 'UAH',
                    'operationId' => 'operation-e2e',
                    'commission' => 0,
                    'feeAmount' => 0,
                    'callbackUrl' => 'https://backend.example.test/payment/callback',
                    'totalPrice' => 80,
                ],
            ]);
        }

        if ($request->method() === 'GET' && $path === "/api/v2/orders/{$externalOrderId}") {
            return Http::response([
                'id' => $externalOrderId,
                'status' => 'accepted',
            ]);
        }

        return Http::response([
            'message' => 'Unexpected mocked Dots request.',
        ], 500);
    });

    $sessionResponse = $this
        ->withHeader('X-Internal-Api-Token', 'test-internal-token')
        ->postJson('/api/sessions', [
            'channel' => 'chatgpt',
            'external_session_id' => 'e2e-'.Str::uuid(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.city', null)
        ->assertJsonPath('data.restaurant', null);

    $sessionToken = $sessionResponse->json('data.session_token');

    expect($sessionToken)->toBeString()->not->toBeEmpty();

    $headers = [
        'X-Internal-Api-Token' => 'test-internal-token',
        'X-Session-Token' => $sessionToken,
    ];

    $this->withHeaders($headers)
        ->putJson('/api/sessions/current/contact', [
            'name' => 'Yehor',
            'phone' => '+380931234567',
        ])
        ->assertOk()
        ->assertJsonPath('data.contact.phone_verified', false);

    $this->withHeaders($headers)
        ->postJson('/api/sessions/current/otp')
        ->assertOk()
        ->assertJsonStructure(['data' => ['expires_in', 'resend_available_in']]);

    $this->withHeaders($headers)
        ->postJson('/api/sessions/current/otp/verify', [
            'code' => $this->otpSender->lastCode(),
        ])
        ->assertOk()
        ->assertJsonPath('data.contact.phone_verified', true);

    $this->withHeaders($headers)
        ->getJson('/api/cities')
        ->assertOk()
        ->assertJsonPath('data.0.id', $city->id);

    $this->withHeaders($headers)
        ->putJson('/api/sessions/current/city', [
            'city_id' => $city->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.city.id', $city->id);

    $this->withHeaders($headers)
        ->getJson('/api/sessions/current/restaurants')
        ->assertOk()
        ->assertJsonPath('data.0.id', $restaurant->id);

    $this->withHeaders($headers)
        ->putJson('/api/sessions/current/restaurant', [
            'restaurant_id' => $restaurant->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.restaurant.id', $restaurant->id);

    $this->withHeaders($headers)
        ->getJson('/api/sessions/current/fulfillment-options')
        ->assertOk()
        ->assertJsonFragment(['type' => FulfillmentType::Pickup->value]);

    $this->withHeaders($headers)
        ->putJson('/api/sessions/current/fulfillment', [
            'type' => FulfillmentType::Pickup->value,
        ])
        ->assertOk()
        ->assertJsonPath('data.fulfillment.type', FulfillmentType::Pickup->value);

    $this->withHeaders($headers)
        ->getJson('/api/sessions/current/pickup-addresses')
        ->assertOk()
        ->assertJsonPath('data.0.id', $pickupAddress->id);

    $this->withHeaders($headers)
        ->putJson('/api/sessions/current/pickup-address', [
            'restaurant_address_id' => $pickupAddress->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.fulfillment.restaurant_address_id', $pickupAddress->id);

    $this->withHeader('X-Internal-Api-Token', 'test-internal-token')
        ->getJson("/api/restaurants/{$restaurant->slug}/products/{$product->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $product->id);

    $this->withHeaders($headers)
        ->postJson('/api/carts')
        ->assertCreated()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.total', '0.00');

    $this->withHeaders($headers)
        ->postJson('/api/carts/current/items', [
            'product_id' => $product->id,
            'quantity' => 1,
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonCount(1, 'data.items');

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
        ->assertJsonPath('data.external_order_id', $externalOrderId)
        ->assertJsonPath('data.status', OrderStatus::Creating->value)
        ->assertJsonPath('data.total', '80.00')
        ->assertJsonPath('data.payment.status', 'ready')
        ->assertJsonPath('data.payment.checkout_url', $checkoutUrl);

    $localOrderId = $orderResponse->json('data.id');

    $retryResponse = $this
        ->withHeaders([
            ...$headers,
            'Idempotency-Key' => $idempotencyKey,
        ])
        ->postJson('/api/orders', [
            'delivery_time' => now()->addHour()->timestamp,
        ])
        ->assertOk()
        ->assertJsonPath('data.payment.status', 'ready');

    expect($retryResponse->json('data.id'))->toBe($localOrderId)
        ->and($retryResponse->json('data.external_order_id'))->toBe($externalOrderId)
        ->and(Order::query()->count())->toBe(1);

    $this->withHeaders($headers)
        ->getJson('/api/orders/current/payment')
        ->assertOk()
        ->assertJsonPath('data.status', 'ready')
        ->assertJsonPath('data.checkout_url', $checkoutUrl);

    $this->withHeaders($headers)
        ->getJson('/api/orders/current/payment/qr')
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    $this->withHeaders($headers)
        ->getJson('/api/carts/current')
        ->assertNotFound();

    expect(Order::query()->with('cart')->sole()->cart->status)->toBe(CartStatus::CheckedOut);

    $this->withHeaders($headers)
        ->getJson('/api/orders/current')
        ->assertOk()
        ->assertJsonPath('data.id', $localOrderId)
        ->assertJsonPath('data.external_order_id', $externalOrderId)
        ->assertJsonPath('data.status', OrderStatus::Created->value)
        ->assertJsonPath('data.failure_message', null)
        ->assertJsonPath('data.payment.qr_ready', true);

    $order = Order::query()->sole();

    expect($order->status)->toBe(OrderStatus::Created)
        ->and($order->payment_checkout_url)->toBe($checkoutUrl)
        ->and($order->payment_qr_path)->toBe("payment-qr/{$order->id}.png")
        ->and($order->payment_qr_fingerprint)->toBe(hash('sha256', $checkoutUrl));

    Storage::disk('local')->assertExists($order->payment_qr_path);

    expect(Http::recorded()
        ->map(fn (array $record): Request => $record[0])
        ->filter(fn (Request $request): bool => $request->method() === 'POST' && parse_url($request->url(), PHP_URL_PATH) === '/api/v2/orders')
        ->count())->toBe(1);
});
