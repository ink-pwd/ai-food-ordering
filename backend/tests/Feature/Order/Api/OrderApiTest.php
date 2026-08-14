<?php

use App\Enums\CartStatus;
use App\Enums\OrderStatus;
use App\Enums\ReceivingType;
use App\Enums\SessionChannel;
use App\Enums\SessionStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\RestaurantAddress;
use App\Services\Repositories\SessionRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.internal.token', 'test-internal-token');
    config()->set('services.internal.session_store', 'array');
    config()->set('services.internal.session_ttl_seconds', 7200);
    config()->set('services.internal.session_key_prefix', 'test-order-session');

    config()->set('services.dots.base_url', 'https://dots.test');
    config()->set('services.dots.api_version', '2.1.0');
    config()->set('services.dots.token', 'dots-token');
    config()->set('services.dots.account_token', 'dots-account-token');
    config()->set('services.dots.auth_token', 'dots-auth-token');
    config()->set('services.internal.payment.wait_seconds', 0);
    config()->set('services.internal.payment.poll_interval_ms', 1);

    config()->set(
        'dots.city_id',
        'f68af3e1-5521-11eb-9cdd-f23c92a7f68e',
    );

    config()->set(
        'dots.company_address_id',
        '6e798cf2-5e50-473e-b75d-216c1f1f5d6d',
    );
});

it('creates an order using the total validated by Dots', function () {
    $scenario = orderApiScenario();

    $externalOrderId = '11111111-1111-1111-1111-111111111111';

    orderApiFakeSuccessfulCreation($externalOrderId);

    orderApiCreateRequest('order-key-success')
        ->assertCreated()
        ->assertJsonPath('data.external_order_id', $externalOrderId)
        ->assertJsonPath('data.status', OrderStatus::Creating->value)
        ->assertJsonPath('data.total', '80.00')
        ->assertJsonPath('data.failure_message', null);

    $order = Order::query()->sole();

    expect($order->total)->toBe('80.00')
        ->and($order->external_order_id)->toBe($externalOrderId)
        ->and($order->status)->toBe(OrderStatus::Creating)
        ->and($order->cart_id)->toBe($scenario['cart']->id)
        ->and($order->items)->toHaveCount(1)
        ->and($scenario['cart']->refresh()->status)
        ->toBe(CartStatus::CheckedOut);

    // First request also resolves online payment data.
    Http::assertSentCount(3);

    $validationRequest = orderApiRecordedDotsRequest(
        'POST',
        '/api/v2/cart/prices/validate',
    );

    $orderRequest = orderApiRecordedDotsRequest(
        'POST',
        '/api/v2/orders',
    );

    expect($validationRequest)->not->toBeNull()
        ->and($orderRequest)->not->toBeNull();

    $validationPayload = json_decode(
        $validationRequest->body(),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $orderPayload = json_decode(
        $orderRequest->body(),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    expect(data_get($validationPayload, 'orderFields.companyId'))
        ->toBe($scenario['restaurant']->external_company_id)
        ->and(data_get($validationPayload, 'orderFields.userName'))
        ->toBe('Yehor')
        ->and(data_get($validationPayload, 'orderFields.userPhone'))
        ->toBe('380931234567')
        ->and(data_get($validationPayload, 'orderFields.deliveryType'))
        ->toBe(2)
        ->and(data_get($validationPayload, 'orderFields.paymentType'))
        ->toBe(2)
        ->and(data_get($validationPayload, 'orderFields.cartItems.0.id'))
        ->toBe($scenario['product']->external_id)
        ->and(data_get($validationPayload, 'orderFields.cartItems.0.count'))
        ->toBe(1);

    expect($orderPayload)->toBe($validationPayload);

    // Price validation does not require user authorization.
    expect($validationRequest->header('Api-Token'))
        ->toBe(['dots-token'])
        ->and($validationRequest->header('Api-Account-Token'))
        ->toBe(['dots-account-token'])
        ->and($validationRequest->header('Api-Auth-Token'))
        ->toBe([]);

    // Real order creation requires all three Dots tokens.
    expect($orderRequest->header('Api-Token'))
        ->toBe(['dots-token'])
        ->and($orderRequest->header('Api-Account-Token'))
        ->toBe(['dots-account-token'])
        ->and($orderRequest->header('Api-Auth-Token'))
        ->toBe(['dots-auth-token']);
});

it('does not submit the order twice for the same idempotency key', function () {
    orderApiScenario();

    orderApiFakeSuccessfulCreation(
        '22222222-2222-2222-2222-222222222222',
    );

    $first = orderApiCreateRequest('same-idempotency-key')
        ->assertCreated();

    $second = orderApiCreateRequest('same-idempotency-key')
        ->assertOk();

    expect($second->json('data.id'))
        ->toBe($first->json('data.id'))
        ->and(Order::query()->count())
        ->toBe(1);

    // First request:
    // 1 x prices/validate
    // 1 x orders
    //
    // Second request must not create another Dots order; the first request also resolved payment data.
    Http::assertSentCount(3);
});

it('marks the local order failed when Dots rejects creation', function () {
    $scenario = orderApiScenario();

    Http::fake(function (Request $request) {
        if (orderApiIsDotsRequest(
            $request,
            'POST',
            '/api/v2/cart/prices/validate',
        )) {
            return Http::response([
                'totalPrice' => 80,
            ]);
        }

        if (orderApiIsDotsRequest(
            $request,
            'POST',
            '/api/v2/orders',
        )) {
            return Http::response([
                'message' => 'Order cannot be created.',
            ], 422);
        }

        return Http::response([], 500);
    });

    orderApiCreateRequest('rejected-order-key')
        ->assertUnprocessable()
        ->assertJsonPath(
            'message',
            'Order cannot be created.',
        );

    $order = Order::query()->sole();

    expect($order->status)
        ->toBe(OrderStatus::Failed)
        ->and($order->failure_message)
        ->toBe('Order cannot be created.')
        ->and($order->external_order_id)
        ->toBeNull()
        ->and($scenario['cart']->refresh()->status)
        ->toBe(CartStatus::Active);

    Http::assertSentCount(2);
});

it('keeps the order creating when Dots returns a server error', function () {
    $scenario = orderApiScenario();

    Http::fake(function (Request $request) {
        if (orderApiIsDotsRequest(
            $request,
            'POST',
            '/api/v2/cart/prices/validate',
        )) {
            return Http::response([
                'totalPrice' => 80,
            ]);
        }

        if (orderApiIsDotsRequest(
            $request,
            'POST',
            '/api/v2/orders',
        )) {
            return Http::response([
                'message' => 'Internal server error.',
            ], 500);
        }

        return Http::response([], 500);
    });

    orderApiCreateRequest('ambiguous-order-key')
        ->assertStatus(502);

    $order = Order::query()->sole();

    expect($order->status)
        ->toBe(OrderStatus::Creating)
        ->and($order->external_order_id)
        ->toBeNull()
        ->and($order->failure_message)
        ->not->toBeNull()
        ->and($scenario['cart']->refresh()->status)
        ->toBe(CartStatus::Active);

    // POST /orders must never be automatically retried.
    Http::assertSentCount(2);
});

it('keeps creating on Dots 404 and marks created after order info becomes available', function () {
    $scenario = orderApiScenario();

    $externalOrderId = '33333333-3333-3333-3333-333333333333';

    $order = Order::query()->create([
        'restaurant_id' => $scenario['restaurant']->id,
        'cart_id' => $scenario['cart']->id,
        'session_id' => $scenario['session_id'],
        'idempotency_key' => 'status-check-key',
        'external_order_id' => $externalOrderId,
        'channel' => SessionChannel::ChatGPT,
        'status' => OrderStatus::Creating,
        'receiving_type' => ReceivingType::Pickup,
        'customer_name' => 'Yehor',
        'customer_phone' => '380931234567',
        'total' => '80.00',
        'currency' => 'UAH',
        'request_payload' => [],
        'response_payload' => [
            'id' => $externalOrderId,
        ],
    ]);

    $attempt = 0;

    Http::fake(function (Request $request) use (
        &$attempt,
        $externalOrderId,
    ) {
        if (! orderApiIsDotsRequest(
            $request,
            'GET',
            "/api/v2/orders/{$externalOrderId}",
        )) {
            return Http::response([], 500);
        }

        $attempt++;

        if ($attempt === 1) {
            return Http::response([
                'message' => '',
            ], 404);
        }

        return Http::response([
            'id' => $externalOrderId,
            'status' => 'accepted',
        ]);
    });

    orderApiCurrentRequest()
        ->assertOk()
        ->assertJsonPath(
            'data.status',
            OrderStatus::Creating->value,
        );

    expect($order->refresh()->status)
        ->toBe(OrderStatus::Creating);

    orderApiCurrentRequest()
        ->assertOk()
        ->assertJsonPath(
            'data.status',
            OrderStatus::Created->value,
        )
        ->assertJsonPath(
            'data.external_order_id',
            $externalOrderId,
        );

    expect($order->refresh()->status)
        ->toBe(OrderStatus::Created)
        ->and($order->response_payload['id'])
        ->toBe($externalOrderId);

    $orderInfoRequests = Http::recorded()
        ->map(fn (array $record): Request => $record[0])
        ->filter(
            fn (Request $request): bool => orderApiIsDotsRequest(
                $request,
                'GET',
                "/api/v2/orders/{$externalOrderId}",
            ),
        );

    expect($orderInfoRequests)->toHaveCount(2);

    foreach ($orderInfoRequests as $request) {
        expect($request->header('Api-Token'))
            ->toBe(['dots-token'])
            ->and($request->header('Api-Account-Token'))
            ->toBe(['dots-account-token'])
            ->and($request->header('Api-Auth-Token'))
            ->toBe(['dots-auth-token']);
    }

    Http::assertSentCount(2);
});

it('returns pending payment when Dots payment data is temporarily unavailable', function () {
    $externalOrderId = '44444444-4444-4444-4444-444444444444';

    orderApiScenario();
    orderApiFakeSuccessfulCreation($externalOrderId, paymentReady: false);

    orderApiCreateRequest('payment-pending-key')
        ->assertCreated()
        ->assertJsonPath('data.external_order_id', $externalOrderId)
        ->assertJsonPath('data.payment.status', 'pending')
        ->assertJsonPath('data.payment.checkout_url', null);

    $order = Order::query()->sole();

    expect($order->external_order_id)
        ->toBe($externalOrderId)
        ->and($order->payment_checkout_url)
        ->toBeNull()
        ->and($order->payment_received_at)
        ->toBeNull();

    $replay = orderApiCreateRequest('payment-pending-key')
        ->assertOk()
        ->assertJsonPath('data.id', $order->id)
        ->assertJsonPath('data.payment.status', 'pending');

    expect($replay->json('data.external_order_id'))
        ->toBe($externalOrderId)
        ->and(Order::query()->count())
        ->toBe(1)
        ->and(orderApiRecordedDotsRequests('POST', '/api/v2/orders'))
        ->toHaveCount(1)
        ->and(orderApiRecordedDotsRequests('GET', "/api/v2/orders/{$externalOrderId}/online-payment-data"))
        ->toHaveCount(2);

    Http::assertSentCount(4);
});

it('resolves pending payment from the current payment endpoint', function () {
    $externalOrderId = '55555555-5555-5555-5555-555555555555';
    $checkoutUrl = 'https://checkout.dots.test/pay/555';

    orderApiScenario();
    orderApiFakeSuccessfulCreation(
        $externalOrderId,
        paymentReady: false,
        checkoutUrl: $checkoutUrl,
        paymentReadyOnAttempt: 2,
    );

    orderApiCreateRequest('payment-retry-key')
        ->assertCreated()
        ->assertJsonPath('data.payment.status', 'pending');

    $order = Order::query()->sole();
    expect($order->external_order_id)->toBe($externalOrderId);

    orderApiCurrentPaymentRequest()
        ->assertOk()
        ->assertJsonPath('data.status', 'ready')
        ->assertJsonPath('data.checkout_url', $checkoutUrl);

    $order = Order::query()->sole();

    expect($order->payment_checkout_url)
        ->toBe($checkoutUrl)
        ->and($order->payment_snapshot['operation_id'])
        ->toBe('operation-'.$externalOrderId)
        ->and($order->payment_received_at)
        ->not->toBeNull()
        ->and(orderApiRecordedDotsRequests('POST', '/api/v2/orders'))
        ->toHaveCount(1)
        ->and(orderApiRecordedDotsRequests('GET', "/api/v2/orders/{$externalOrderId}/online-payment-data"))
        ->toHaveCount(2);

    Http::assertSentCount(4);
});

it('returns persisted checkout url without calling Dots payment data again', function () {
    $externalOrderId = '66666666-6666-6666-6666-666666666666';
    $checkoutUrl = 'https://checkout.dots.test/pay/666';

    orderApiScenario();
    orderApiFakeSuccessfulCreation($externalOrderId, checkoutUrl: $checkoutUrl);

    orderApiCreateRequest('persisted-payment-key')
        ->assertCreated()
        ->assertJsonPath('data.payment.status', 'ready')
        ->assertJsonPath('data.payment.checkout_url', $checkoutUrl);

    Http::fake(fn () => Http::response([], 500));

    orderApiCurrentPaymentRequest()
        ->assertOk()
        ->assertJsonPath('data.status', 'ready')
        ->assertJsonPath('data.checkout_url', $checkoutUrl);

    Http::assertSentCount(0);
});

it('idempotent order replay does not create another Dots order and can resolve missing payment data', function () {
    $externalOrderId = '77777777-7777-7777-7777-777777777777';
    $checkoutUrl = 'https://checkout.dots.test/pay/777';

    orderApiScenario();
    orderApiFakeSuccessfulCreation(
        $externalOrderId,
        paymentReady: false,
        checkoutUrl: $checkoutUrl,
        paymentReadyOnAttempt: 2,
    );

    orderApiCreateRequest('idempotent-payment-resolution-key')
        ->assertCreated()
        ->assertJsonPath('data.payment.status', 'pending');

    orderApiCreateRequest('idempotent-payment-resolution-key')
        ->assertOk()
        ->assertJsonPath('data.payment.status', 'ready')
        ->assertJsonPath('data.payment.checkout_url', $checkoutUrl);

    expect(Order::query()->count())
        ->toBe(1)
        ->and(Order::query()->sole()->payment_checkout_url)
        ->toBe($checkoutUrl)
        ->and(orderApiRecordedDotsRequests('POST', '/api/v2/orders'))
        ->toHaveCount(1)
        ->and(orderApiRecordedDotsRequests('GET', "/api/v2/orders/{$externalOrderId}/online-payment-data"))
        ->toHaveCount(2);

    Http::assertSentCount(4);
});

it('returns the current ready payment QR png and stores it privately', function () {
    Storage::fake('local');

    $checkoutUrl = 'https://checkout.dots.test/pay/ready?token=secret-token';
    $order = orderApiPersistedOrder(checkoutUrl: $checkoutUrl);

    orderApiCurrentPaymentQrRequest()
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    $order->refresh();

    expect($order->payment_qr_path)
        ->toBe("payment-qr/{$order->id}.png")
        ->and($order->payment_qr_fingerprint)
        ->toBe(hash('sha256', $checkoutUrl));

    Storage::disk('local')->assertExists($order->payment_qr_path);
    expect(Storage::disk('local')->get($order->payment_qr_path))
        ->toStartWith("\x89PNG\r\n\x1a\n");
});

it('reuses an existing current payment QR for the same checkout URL', function () {
    Storage::fake('local');

    $checkoutUrl = 'https://checkout.dots.test/pay/reuse?token=secret-token';
    $order = orderApiPersistedOrder(checkoutUrl: $checkoutUrl);

    orderApiCurrentPaymentQrRequest()->assertOk();

    $order->refresh();
    $path = $order->payment_qr_path;
    $firstContents = Storage::disk('local')->get($path);

    orderApiCurrentPaymentQrRequest()->assertOk();

    $order->refresh();

    expect($order->payment_qr_path)
        ->toBe($path)
        ->and(Storage::disk('local')->allFiles('payment-qr'))
        ->toBe([$path])
        ->and(Storage::disk('local')->get($path))
        ->toBe($firstContents);
});

it('regenerates a stale current payment QR when checkout URL changes', function () {
    Storage::fake('local');

    $oldCheckoutUrl = 'https://checkout.dots.test/pay/old?token=old-secret-token';
    $newCheckoutUrl = 'https://checkout.dots.test/pay/new?token=new-secret-token';

    $order = orderApiPersistedOrder(checkoutUrl: $oldCheckoutUrl);

    orderApiCurrentPaymentQrRequest()->assertOk();

    $oldFingerprint = $order->refresh()->payment_qr_fingerprint;
    $oldContents = Storage::disk('local')->get($order->payment_qr_path);

    $order->forceFill(['payment_checkout_url' => $newCheckoutUrl])->save();

    orderApiCurrentPaymentQrRequest()->assertOk();

    $order->refresh();

    expect($order->payment_qr_path)
        ->toBe("payment-qr/{$order->id}.png")
        ->and($order->payment_qr_fingerprint)
        ->toBe(hash('sha256', $newCheckoutUrl))
        ->and($order->payment_qr_fingerprint)
        ->not->toBe($oldFingerprint)
        ->and(Storage::disk('local')->get($order->payment_qr_path))
        ->not->toBe($oldContents);
});

it('does not create a QR when current payment is pending', function () {
    Storage::fake('local');

    orderApiPersistedOrder(checkoutUrl: null);

    orderApiCurrentPaymentQrRequest()
        ->assertAccepted()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.checkout_url', null);

    expect(Storage::disk('local')->allFiles('payment-qr'))->toBe([])
        ->and(Order::query()->sole()->payment_qr_path)
        ->toBeNull();
});

it('does not expose another session current payment QR', function () {
    Storage::fake('local');

    orderApiPersistedOrder(checkoutUrl: 'https://checkout.dots.test/pay/session-one');

    $otherSessionId = (string) Str::ulid();

    app(SessionRepository::class)->put(
        orderApiOtherToken(),
        [
            'id' => $otherSessionId,
            'channel' => SessionChannel::ChatGPT->value,
            'external_session_id' => 'other-order-test-conversation',
            'status' => SessionStatus::Active->value,
            'metadata' => [],
            'created_at' => now()->toIso8601String(),
            'expires_at' => now()->addHours(2)->toIso8601String(),
        ],
    );

    orderApiCurrentPaymentQrRequest(orderApiOtherToken())
        ->assertNotFound();

    expect(Storage::disk('local')->allFiles('payment-qr'))->toBe([]);
});

it('generates QR from persisted checkout URL only', function () {
    Storage::fake('local');

    $checkoutUrl = 'https://checkout.dots.test/pay/source-trust?token=persisted-token';
    $order = orderApiPersistedOrder(checkoutUrl: $checkoutUrl);

    test()
        ->withHeaders([
            'X-Internal-Api-Token' => 'test-internal-token',
            'X-Session-Token' => orderApiToken(),
        ])
        ->getJson(route('internal.orders.current.payment.qr.show').'?checkout_url=https://evil.test/pay&path=../evil.png')
        ->assertOk();

    $order->refresh();

    expect($order->payment_qr_fingerprint)
        ->toBe(hash('sha256', $checkoutUrl))
        ->and(Storage::disk('local')->allFiles('payment-qr'))
        ->toBe(["payment-qr/{$order->id}.png"]);
});

/**
 * @return array{
 *     restaurant: Restaurant,
 *     cart: Cart,
 *     product: Product,
 *     session_id: string
 * }
 */
function orderApiScenario(): array
{
    $restaurant = Restaurant::factory()->create([
        'external_company_id' => 'f7add4f1-5521-11eb-9cdd-f23c92a7f68e',
        'currency' => 'UAH',
        'is_active' => true,
        'available_payment_types' => [2],
        'available_delivery_types' => [2],
    ]);
    $pickupAddress = RestaurantAddress::factory()->for($restaurant)->create([
        'external_address_id' => '6e798cf2-5e50-473e-b75d-216c1f1f5d6d',
        'is_active' => true,
    ]);

    $sessionId = (string) Str::ulid();

    app(SessionRepository::class)->put(
        orderApiToken(),
        [
            'id' => $sessionId,
            'city_id' => $restaurant->city_id,
            'restaurant_id' => $restaurant->id,
            'fulfillment' => [
                'type' => 'pickup',
                'dots_delivery_type' => null,
                'delivery_price' => null,
                'delivery_address' => null,
                'restaurant_address_id' => $pickupAddress->id,
                'external_address_id' => $pickupAddress->external_address_id,
            ],
            'channel' => SessionChannel::ChatGPT->value,
            'external_session_id' => 'order-test-conversation',
            'status' => SessionStatus::Active->value,
            'metadata' => [
                'contact' => [
                    'name' => 'Yehor',
                    'phone' => '+380931234567',
                    'phone_verified' => true,
                ],
            ],
            'created_at' => now()->toIso8601String(),
            'expires_at' => now()
                ->addHours(2)
                ->toIso8601String(),
        ],
    );

    $cart = Cart::factory()->create([
        'restaurant_id' => $restaurant->id,
        'session_id' => $sessionId,
        'status' => CartStatus::Active,
        'currency' => 'UAH',
        'subtotal' => '100.00',
        'total' => '100.00',
        'expires_at' => now()->addHour(),
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

    CartItem::factory()->create([
        'cart_id' => $cart->id,
        'product_id' => $product->id,
        'external_product_id' => $product->external_id,
        'quantity' => 1,
        'unit_price' => '100.00',
        'total' => '100.00',
    ]);

    return [
        'restaurant' => $restaurant,
        'cart' => $cart,
        'product' => $product,
        'session_id' => $sessionId,
    ];
}

function orderApiFakeSuccessfulCreation(
    string $externalOrderId,
    bool $paymentReady = true,
    ?string $checkoutUrl = null,
    ?int $paymentReadyOnAttempt = null,
): void {
    $paymentAttempts = 0;

    Http::fake(function (Request $request) use ($externalOrderId, $paymentReady, $checkoutUrl, $paymentReadyOnAttempt, &$paymentAttempts) {
        if (orderApiIsDotsRequest(
            $request,
            'POST',
            '/api/v2/cart/prices/validate',
        )) {
            return Http::response([
                'totalPrice' => 80,
            ]);
        }

        if (orderApiIsDotsRequest(
            $request,
            'POST',
            '/api/v2/orders',
        )) {
            return Http::response([
                'id' => $externalOrderId,
            ]);
        }

        if (orderApiIsDotsRequest(
            $request,
            'GET',
            "/api/v2/orders/{$externalOrderId}/online-payment-data",
        )) {
            $paymentAttempts++;

            if (! $paymentReady && ($paymentReadyOnAttempt === null || $paymentAttempts < $paymentReadyOnAttempt)) {
                return Http::response([
                    'message' => 'Online payment data is not ready.',
                ], 404);
            }

            return Http::response(orderApiPaymentData(
                $externalOrderId,
                $checkoutUrl ?? "https://checkout.dots.test/pay/{$externalOrderId}",
            ));
        }

        return Http::response([], 500);
    });
}

function orderApiCreateRequest(
    string $idempotencyKey,
): TestResponse {
    return test()
        ->withHeaders([
            'X-Internal-Api-Token' => 'test-internal-token',
            'X-Session-Token' => orderApiToken(),
            'Idempotency-Key' => $idempotencyKey,
        ])
        ->postJson(
            route('internal.orders.store'),
            [
                'delivery_time' => now()
                    ->addHour()
                    ->timestamp,
            ],
        );
}

function orderApiCurrentRequest(): TestResponse
{
    return test()
        ->withHeaders([
            'X-Internal-Api-Token' => 'test-internal-token',
            'X-Session-Token' => orderApiToken(),
        ])
        ->getJson(
            route('internal.orders.current.show'),
        );
}

function orderApiCurrentPaymentRequest(): TestResponse
{
    return test()
        ->withHeaders([
            'X-Internal-Api-Token' => 'test-internal-token',
            'X-Session-Token' => orderApiToken(),
        ])
        ->getJson(
            route('internal.orders.current.payment.show'),
        );
}

function orderApiCurrentPaymentQrRequest(?string $sessionToken = null): TestResponse
{
    return test()
        ->withHeaders([
            'X-Internal-Api-Token' => 'test-internal-token',
            'X-Session-Token' => $sessionToken ?? orderApiToken(),
        ])
        ->getJson(
            route('internal.orders.current.payment.qr.show'),
        );
}

function orderApiPersistedOrder(?string $checkoutUrl): Order
{
    $scenario = orderApiScenario();

    return Order::factory()->create([
        'restaurant_id' => $scenario['restaurant']->id,
        'cart_id' => $scenario['cart']->id,
        'session_id' => $scenario['session_id'],
        'external_order_id' => '88888888-8888-8888-8888-888888888888',
        'status' => OrderStatus::Creating,
        'payment_checkout_url' => $checkoutUrl,
        'payment_received_at' => $checkoutUrl === null ? null : now(),
        'response_payload' => [
            'id' => '88888888-8888-8888-8888-888888888888',
        ],
    ]);
}

function orderApiOtherToken(): string
{
    return str_repeat('c', 64);
}

/** @return array<string, mixed> */
function orderApiPaymentData(string $externalOrderId, string $checkoutUrl): array
{
    return [
        'id' => $externalOrderId,
        'status' => 'created',
        'onlinePayment' => [
            'checkoutUrl' => $checkoutUrl,
            'merchantId' => 'merchant-'.$externalOrderId,
            'orderPrice' => 80,
            'description' => 'Order '.$externalOrderId,
            'currency' => 'UAH',
            'operationId' => 'operation-'.$externalOrderId,
            'commission' => 0,
            'feeAmount' => 0,
            'callbackUrl' => 'https://backend.test/dots/payment/callback',
            'totalPrice' => 80,
        ],
    ];
}

function orderApiToken(): string
{
    return str_repeat('b', 64);
}

function orderApiRecordedDotsRequest(
    string $method,
    string $path,
): ?Request {
    return Http::recorded()
        ->map(
            fn (array $record): Request => $record[0],
        )
        ->first(
            fn (Request $request): bool => orderApiIsDotsRequest(
                $request,
                $method,
                $path,
            ),
        );
}

function orderApiRecordedDotsRequests(
    string $method,
    string $path,
): \Illuminate\Support\Collection {
    return Http::recorded()
        ->map(
            fn (array $record): Request => $record[0],
        )
        ->filter(
            fn (Request $request): bool => orderApiIsDotsRequest(
                $request,
                $method,
                $path,
            ),
        )
        ->values();
}

function orderApiIsDotsRequest(
    Request $request,
    string $method,
    string $path,
): bool {
    return $request->method() === $method
        && parse_url(
            $request->url(),
            PHP_URL_PATH,
        ) === $path;
}
