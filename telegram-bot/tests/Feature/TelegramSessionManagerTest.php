<?php

use App\Telegram\Session\TelegramSessionManager;
use App\Telegram\Session\TelegramSessionStore;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.ordering_backend.url', 'http://ordering-backend.test');
    config()->set('services.ordering_backend.token', 'internal-api-secret');
    config()->set('services.ordering_backend.timeout', 7);

    Http::preventStrayRequests();
});

test('it stores and reuses a backend session token in process memory', function () {
    $sessionToken = str_repeat('b', 64);

    Http::fake([
        'ordering-backend.test/api/sessions' => Http::response([
            'data' => [
                'session_token' => $sessionToken,
            ],
        ], 201),
    ]);

    $manager = app(TelegramSessionManager::class);

    $firstResolution = $manager->resolveForChatId(123456);
    $secondResolution = $manager->resolveForChatId(123456);

    expect($firstResolution)->toBe($sessionToken)
        ->and($secondResolution)->toBe($sessionToken)
        ->and(app(TelegramSessionStore::class)->get('telegram-chat-123456'))->toBe($sessionToken)
        ->and(app(TelegramSessionStore::class))->toBe(app(TelegramSessionStore::class));

    Http::assertSentCount(1);
});

test('the in-memory store can explicitly forget a session token', function () {
    $store = app(TelegramSessionStore::class);
    $store->put('telegram-chat-123456', 'session-token');

    $store->forget('telegram-chat-123456');

    expect($store->get('telegram-chat-123456'))->toBeNull();
});
