<?php

namespace App\Telegram\Handlers;

use App\Exceptions\OrderingBackendException;
use App\Integrations\OrderingBackend\OrderingBackendClient;
use App\Telegram\CallbackAcknowledger;
use App\Telegram\Formatting\CatalogMessageFormatter;
use App\Telegram\Keyboards\CatalogKeyboard;
use App\Telegram\Keyboards\MainMenuKeyboard;
use App\Telegram\TelegramMessageEditor;
use SergiX44\Nutgram\Nutgram;

final class CatalogHandler
{
    public function __construct(
        private readonly CallbackAcknowledger $callbackAcknowledger,
        private readonly OrderingBackendClient $backend,
        private readonly CatalogKeyboard $keyboard,
        private readonly CatalogMessageFormatter $formatter,
        private readonly MainMenuKeyboard $mainMenu,
        private readonly TelegramMessageEditor $messageEditor,
    ) {}

    public function catalog(Nutgram $bot): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        try {
            $categories = $this->backend->categories();
        } catch (OrderingBackendException $exception) {
            $this->messageEditor->edit(
                bot: $bot,
                text: $this->failureMessage($exception, 'Каталог не найден.'),
                keyboard: $this->keyboard->categories([]),
            );

            return;
        }

        $this->messageEditor->edit(
            bot: $bot,
            text: $categories === [] ? 'Категории пока недоступны.' : $this->formatter->categories(),
            keyboard: $this->keyboard->categories($categories),
        );
    }

    public function category(Nutgram $bot, int $categoryId): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        try {
            $products = $this->backend->categoryProducts($categoryId);
        } catch (OrderingBackendException $exception) {
            $this->messageEditor->edit(
                bot: $bot,
                text: $this->failureMessage($exception, 'Категория не найдена.'),
                keyboard: $this->keyboard->products($categoryId, []),
            );

            return;
        }

        $this->messageEditor->edit(
            bot: $bot,
            text: $products === [] ? 'В этой категории пока нет товаров.' : $this->formatter->products(),
            keyboard: $this->keyboard->products($categoryId, $products),
        );
    }

    public function product(Nutgram $bot, int $categoryId, int $productId): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        try {
            $product = $this->backend->product($productId);
        } catch (OrderingBackendException $exception) {
            $this->messageEditor->edit(
                bot: $bot,
                text: $this->failureMessage($exception, 'Товар не найден.'),
                keyboard: $this->keyboard->backToCategory($categoryId),
            );

            return;
        }

        $this->messageEditor->edit(
            bot: $bot,
            text: $this->formatter->product($product),
            keyboard: $this->keyboard->product($categoryId, $productId),
        );
    }

    public function mainMenu(Nutgram $bot): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $this->messageEditor->edit(
            bot: $bot,
            text: 'Приветствую! Выберите действие:',
            keyboard: $this->mainMenu->make(),
        );
    }

    private function failureMessage(OrderingBackendException $exception, string $notFoundMessage): string
    {
        if ($exception->statusCode() === 404) {
            return $notFoundMessage;
        }

        return 'Сервис каталога временно недоступен. Попробуйте снова позже.';
    }
}
