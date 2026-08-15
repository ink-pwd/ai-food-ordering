<?php

use App\Telegram\CallbackAcknowledger;
use App\Telegram\Handlers\CartHandler;
use App\Telegram\Handlers\CatalogHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Testing\FakeNutgram;

beforeEach(function () {
    config()->set('services.ordering_backend.url', 'http://ordering-backend.test');
    config()->set('services.ordering_backend.token', 'internal-api-secret');
    config()->set('services.ordering_backend.timeout', 7);

    Http::preventStrayRequests();
});

test('stale callback errors are acknowledged once and ignored safely', function (string $description) {
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('debug')
        ->once()
        ->with('Stale Telegram callback query ignored.', [
            'exception' => TelegramException::class,
            'code' => 400,
        ]);

    $bot = callbackFailureTelegramBot($description);

    expect((new CallbackAcknowledger($logger))->acknowledge($bot))->toBeFalse();

    $bot->assertCalled('answerCallbackQuery', 1);
})->with([
    'query is too old' => 'Bad Request: query is too old',
    'response timeout expired' => 'Bad Request: response timeout expired',
    'query id is invalid' => 'Bad Request: query ID is invalid',
]);

test('a stale catalog callback stops without backend work or a crash', function () {
    $bot = callbackFailureTelegramBot(
        'Bad Request: query is too old and response timeout expired or query ID is invalid',
    );

    app(CatalogHandler::class)->catalog($bot, 10, 'abcdef123456');

    $bot->assertCalled('answerCallbackQuery', 1);
    Http::assertNothingSent();
});

test('stale cart callbacks perform no backend work when acknowledgement fails', function (string $method, array $arguments) {
    $bot = callbackFailureTelegramBot(
        'Bad Request: query is too old and response timeout expired or query ID is invalid',
    );

    app(CartHandler::class)->{$method}($bot, ...$arguments);

    $bot->assertCalled('answerCallbackQuery', 1);
    Http::assertNothingSent();
})->with([
    'increment' => ['increment', [37, 10, 'abcdef123456']],
    'add' => ['add', [1, 10, 'abcdef123456']],
    'remove item' => ['remove', [51, 10, 'abcdef123456']],
    'clear confirmation' => ['confirmClear', [10, 'abcdef123456']],
]);

test('an unexpected Telegram exception is not swallowed', function () {
    $bot = callbackFailureTelegramBot('Bad Request: chat not found');

    expect(fn () => app(CatalogHandler::class)->catalog($bot, 10, 'abcdef123456'))
        ->toThrow(TelegramException::class, 'Bad Request: chat not found');

    $bot->assertCalled('answerCallbackQuery', 1);
    Http::assertNothingSent();
});

function callbackFailureTelegramBot(string $description): FakeNutgram
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
