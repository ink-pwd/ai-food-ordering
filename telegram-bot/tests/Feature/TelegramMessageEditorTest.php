<?php

use App\Telegram\TelegramMessageEditor;
use GuzzleHttp\Psr7\Request as TelegramRequest;
use GuzzleHttp\Psr7\Response;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Testing\FakeNutgram;

test('an unchanged Telegram message edit is a successful no-op without a fallback message', function () {
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('debug')
        ->once()
        ->with('Unchanged Telegram message edit ignored.', [
            'exception' => TelegramException::class,
            'code' => 400,
        ]);

    $bot = telegramMessageEditFailureBot(
        'Bad Request: message is not modified: specified new message content and reply markup are exactly the same',
    );

    (new TelegramMessageEditor($logger))->edit(
        bot: $bot,
        text: 'Без змін',
        keyboard: telegramMessageEditorKeyboard(),
    );

    $bot
        ->assertCalled('editMessageText', 1)
        ->assertCalled('sendMessage', 0);
});

test('an unexpected Telegram message edit exception still propagates', function () {
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldNotReceive('debug');
    $bot = telegramMessageEditFailureBot('Bad Request: chat not found');

    expect(fn () => (new TelegramMessageEditor($logger))->edit(
        bot: $bot,
        text: 'Повідомлення',
        keyboard: telegramMessageEditorKeyboard(),
    ))->toThrow(TelegramException::class, 'Bad Request: chat not found');

    $bot
        ->assertCalled('editMessageText', 1)
        ->assertCalled('sendMessage', 0);
});

test('a normal Telegram message edit is sent unchanged', function () {
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldNotReceive('debug');
    $bot = Nutgram::fake();

    (new TelegramMessageEditor($logger))->edit(
        bot: $bot,
        text: 'Оновлене повідомлення',
        keyboard: telegramMessageEditorKeyboard(),
    );

    $bot
        ->assertCalled('editMessageText', 1)
        ->assertCalled('sendMessage', 0)
        ->assertRaw(function (TelegramRequest $request): bool {
            $payload = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);

            expect($payload['text'])->toBe('Оновлене повідомлення')
                ->and($payload['reply_markup']['inline_keyboard'][0][0])->toMatchArray([
                    'text' => '⬅️ Головне меню',
                    'callback_data' => 'main_menu:10:abcdef123456',
                ]);

            return true;
        });
});

function telegramMessageEditFailureBot(string $description): FakeNutgram
{
    return Nutgram::fake(responses: [
        new Response(
            status: 400,
            headers: ['Content-Type' => 'application/json'],
            body: json_encode([
                'ok' => false,
                'error_code' => 400,
                'description' => $description,
            ], JSON_THROW_ON_ERROR),
        ),
    ]);
}

function telegramMessageEditorKeyboard(): InlineKeyboardMarkup
{
    return InlineKeyboardMarkup::make()
        ->addRow(InlineKeyboardButton::make(
            text: '⬅️ Головне меню',
            callback_data: 'main_menu:10:abcdef123456',
        ));
}
