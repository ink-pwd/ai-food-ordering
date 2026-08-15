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

test('/start creates a backend session and requests contact in Ukrainian', function () {
    $sessionToken = str_repeat('c', 64);

    Http::fake([
        'ordering-backend.test/api/sessions' => Http::response([
            'data' => [
                'session_token' => $sessionToken,
            ],
        ], 201),
    ]);

    telegramStartBot()
        ->hearText('/start')
        ->reply()
        ->assertReplyMessage([
            'text' => 'Щоб продовжити, надішліть свій номер телефону.',
            'reply_markup' => startContactRequestKeyboard(),
        ])
        ->assertRaw(function (TelegramRequest $request) use ($sessionToken): bool {
            $telegramOutput = (string) $request->getBody();

            expect($telegramOutput)
                ->toContain('📱 Надіслати номер телефону')
                ->not->toContain($sessionToken)
                ->not->toContain('internal-api-secret')
                ->not->toContain('🍕 Каталог')
                ->not->toContain('🛒 Кошик');

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

function telegramStartBot(): FakeNutgram
{
    /** @var FakeNutgram $bot */
    $bot = app(Nutgram::class);

    return $bot
        ->setCommonChat(Chat::make(id: 123456, type: ChatType::PRIVATE))
        ->setCommonUser(User::make(id: 654321, is_bot: false, first_name: 'Test'));
}

/** @return array{keyboard: array<int, array<int, array{text: string, request_contact: bool}>>, resize_keyboard: bool, one_time_keyboard: bool} */
function startContactRequestKeyboard(): array
{
    return [
        'keyboard' => [
            [[
                'text' => '📱 Надіслати номер телефону',
                'request_contact' => true,
            ]],
        ],
        'resize_keyboard' => true,
        'one_time_keyboard' => true,
    ];
}
