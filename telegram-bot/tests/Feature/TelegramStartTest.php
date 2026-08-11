<?php

use GuzzleHttp\Psr7\Request as TelegramRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User;
use SergiX44\Nutgram\Testing\FakeNutgram;

beforeEach(function () {
    config()->set('cache.default', 'array');
    config()->set('services.ordering_backend.url', 'http://ordering-backend.test');
    config()->set('services.ordering_backend.token', 'internal-api-secret');
    config()->set('services.ordering_backend.timeout', 7);

    Http::preventStrayRequests();
});

test('/start creates a backend session and requests contact before showing the main menu', function () {
    $sessionToken = str_repeat('c', 64);

    Http::fake([
        'ordering-backend.test/api/sessions' => Http::response([
            'data' => [
                'session_token' => $sessionToken,
            ],
        ], 201),
    ]);

    /** @var FakeNutgram $bot */
    $bot = app(Nutgram::class);
    $bot->setCommonChat(Chat::make(id: 123456, type: ChatType::PRIVATE))
        ->setCommonUser(User::make(id: 654321, is_bot: false, first_name: 'Test'))
        ->hearText('/start')
        ->reply()
        ->assertReplyMessage([
            'text' => 'Чтобы продолжить, поделитесь своим контактом.',
            'reply_markup' => [
                'keyboard' => [
                    [[
                        'text' => '📱 Поделиться контактом',
                        'request_contact' => true,
                    ]],
                ],
                'resize_keyboard' => true,
                'one_time_keyboard' => true,
            ],
        ])
        ->assertRaw(function (TelegramRequest $request) use ($sessionToken): bool {
            $telegramOutput = (string) $request->getBody();

            expect($telegramOutput)
                ->not->toContain($sessionToken)
                ->not->toContain('internal-api-secret')
                ->not->toContain('🍕 Каталог')
                ->not->toContain('🛒 Корзина');

            return true;
        });

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://ordering-backend.test/api/sessions'
        && $request->hasHeader('X-Internal-Api-Token', 'internal-api-secret')
        && $request->data() === [
            'channel' => 'telegram',
            'external_session_id' => 'telegram-chat-123456',
        ]);
    Http::assertSentCount(1);
});
