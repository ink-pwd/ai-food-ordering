<?php

use App\Mcp\Servers\FoodOrderingServer;
use App\Mcp\Tools\SetCustomerTool;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    configureOrderingToolBackend();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('sets customer contact with the trusted backend session token and omits the full phone from output', function () {
    $backendSessionToken = 'backend-session-token-secret';
    $phone = '093 123-45-67';
    $normalizedPhone = '+380931234567';

    Http::fake([
        'https://ordering-backend.test/api/sessions/current/contact' => Http::response([
            'data' => [
                'session_id' => 'backend-session-id',
                'contact' => [
                    'name' => 'Yehor',
                    'phone' => $normalizedPhone,
                    'phone_verified' => false,
                ],
            ],
        ]),
    ]);

    FoodOrderingServer::tool(SetCustomerTool::class, [
        'session_handle' => orderingToolSessionHandle(backendSessionToken: $backendSessionToken),
        'name' => 'Yehor',
        'phone' => $phone,
    ])
        ->assertOk()
        ->assertName('set_customer')
        ->assertStructuredContent([
            'contact' => [
                'name' => 'Yehor',
                'phone_verified' => false,
            ],
        ])
        ->assertDontSee([$backendSessionToken, $normalizedPhone, 'internal-api-token-secret']);

    Http::assertSent(function (Request $request) use ($backendSessionToken, $phone): bool {
        return $request->method() === 'PUT'
            && $request->url() === 'https://ordering-backend.test/api/sessions/current/contact'
            && $request->hasHeader('X-Internal-Api-Token', 'internal-api-token-secret')
            && $request->hasHeader('X-Session-Token', $backendSessionToken)
            && $request->data() === [
                'name' => 'Yehor',
                'phone' => $phone,
            ];
    });
});

it('rejects an invalid session handle without making an HTTP request', function () {
    Http::fake();

    FoodOrderingServer::tool(SetCustomerTool::class, [
        'session_handle' => 'not-an-encrypted-handle',
        'name' => 'Yehor',
        'phone' => '+380931234567',
    ])->assertHasErrors(['Контекст заказа недействителен или истёк. Создайте новый контекст заказа.']);

    Http::assertNothingSent();
});

it('rejects an expired session handle without making an HTTP request', function () {
    CarbonImmutable::setTestNow('2026-08-12T12:00:00+00:00');
    Http::fake();

    FoodOrderingServer::tool(SetCustomerTool::class, [
        'session_handle' => orderingToolSessionHandle(expiresAt: CarbonImmutable::now()),
        'name' => 'Yehor',
        'phone' => '+380931234567',
    ])->assertHasErrors(['Контекст заказа недействителен или истёк. Создайте новый контекст заказа.']);

    Http::assertNothingSent();
});

it('maps backend contact validation failures safely', function () {
    Http::fake([
        'https://ordering-backend.test/api/sessions/current/contact' => Http::response([
            'message' => 'backend-validation-secret',
            'errors' => [
                'phone' => ['backend-phone-detail-secret'],
            ],
        ], 422),
    ]);

    FoodOrderingServer::tool(SetCustomerTool::class, [
        'session_handle' => orderingToolSessionHandle(),
        'name' => 'Yehor',
        'phone' => 'invalid-but-forwarded',
    ])
        ->assertHasErrors(['Запрос отклонён: входные данные или состояние оформления заказа недействительны.'])
        ->assertDontSee(['backend-validation-secret', 'backend-phone-detail-secret']);

    Http::assertSentCount(1);
});
