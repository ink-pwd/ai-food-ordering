<?php

use App\Telegram\CallbackAcknowledger;
use App\Telegram\Handlers\CartHandler;
use App\Telegram\Handlers\CatalogHandler;
use GuzzleHttp\Psr7\Request as TelegramRequest;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Testing\FakeNutgram;

beforeEach(function () {
    config()->set('services.ordering_backend.url', 'http://ordering-backend.test');
    config()->set('services.ordering_backend.token', 'internal-api-secret');
    config()->set('services.ordering_backend.restaurant_slug', 'test-restaurant');
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

test('acknowledgement occurs before backend work and a normal callback continues once', function () {
    $bot = Nutgram::fake();

    Http::fake(function (Request $request) use ($bot) {
        $history = $bot->getRequestHistory();

        expect($history)->toHaveCount(1);

        /** @var TelegramRequest $telegramRequest */
        $telegramRequest = array_values($history[0])[0];

        expect($telegramRequest->getUri()->getPath())->toBe('answerCallbackQuery');

        return Http::response(['data' => []]);
    });

    app(CatalogHandler::class)->catalog($bot);

    $bot
        ->assertCalled('answerCallbackQuery', 1)
        ->assertCalled('editMessageText', 1);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'http://ordering-backend.test/api/restaurants/test-restaurant/categories');
    Http::assertSentCount(1);
});

test('a stale catalog callback stops without backend work or a crash', function () {
    $bot = callbackFailureTelegramBot(
        'Bad Request: query is too old and response timeout expired or query ID is invalid',
    );

    app(CatalogHandler::class)->catalog($bot);

    $bot->assertCalled('answerCallbackQuery', 1);
    Http::assertNothingSent();
});

test('a stale cart increment performs no backend get or patch', function () {
    $bot = callbackFailureTelegramBot(
        'Bad Request: query is too old and response timeout expired or query ID is invalid',
    );

    app(CartHandler::class)->increment($bot, 37);

    $bot->assertCalled('answerCallbackQuery', 1);
    Http::assertNothingSent();
});

test('a stale cart add performs no backend post or patch', function () {
    $bot = callbackFailureTelegramBot(
        'Bad Request: query is too old and response timeout expired or query ID is invalid',
    );

    app(CartHandler::class)->add($bot, 1);

    $bot->assertCalled('answerCallbackQuery', 1);
    Http::assertNothingSent();
});

test('stale destructive cart callbacks perform no delete', function (string $method, array $arguments) {
    $bot = callbackFailureTelegramBot(
        'Bad Request: query is too old and response timeout expired or query ID is invalid',
    );

    app(CartHandler::class)->{$method}($bot, ...$arguments);

    $bot->assertCalled('answerCallbackQuery', 1);
    Http::assertNothingSent();
})->with([
    'remove item' => ['remove', [51]],
    'clear confirmation' => ['confirmClear', []],
]);

test('an unexpected Telegram exception is not swallowed', function () {
    $bot = callbackFailureTelegramBot('Bad Request: chat not found');

    expect(fn () => app(CatalogHandler::class)->catalog($bot))
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
