<?php

namespace App\Telegram\Handlers;

use App\Exceptions\OrderingBackendException;
use App\Integrations\OrderingBackend\OrderingBackendClient;
use App\Telegram\CallbackAcknowledger;
use App\Telegram\Formatting\CatalogMessageFormatter;
use App\Telegram\Keyboards\CatalogKeyboard;
use App\Telegram\Keyboards\MainMenuKeyboard;
use App\Telegram\Session\TelegramSessionRecovery;
use App\Telegram\Support\RestaurantNavigationContext;
use App\Telegram\TelegramMessageEditor;
use SergiX44\Nutgram\Nutgram;

final readonly class CatalogHandler
{
    public function __construct(
        private CallbackAcknowledger $callbackAcknowledger,
        private TelegramSessionRecovery $sessionRecovery,
        private OrderingBackendClient $backend,
        private CatalogKeyboard $keyboard,
        private CatalogMessageFormatter $formatter,
        private MainMenuKeyboard $mainMenu,
        private RestaurantNavigationContext $navigationContext,
        private TelegramMessageEditor $messageEditor,
    ) {
    }

    public function catalog(Nutgram $bot, int $restaurantId, string $fingerprint): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $context = $this->restaurantContext($bot, $restaurantId, $fingerprint);

        if ($context === null) {
            return;
        }

        try {
            $categories = $this->backend->categories($context['restaurantSlug']);
        } catch (OrderingBackendException $exception) {
            $this->messageEditor->edit(
                bot: $bot,
                text: $this->failureMessage($exception, 'Каталог не знайдено.'),
                keyboard: $this->keyboard->categories([], $context['callbackContext']),
            );

            return;
        }

        $this->messageEditor->edit(
            bot: $bot,
            text: $categories === [] ? 'Категорії поки недоступні.' : $this->formatter->categories(),
            keyboard: $this->keyboard->categories($categories, $context['callbackContext']),
        );
    }

    public function category(Nutgram $bot, int $categoryId, int $restaurantId, string $fingerprint): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $context = $this->restaurantContext($bot, $restaurantId, $fingerprint);

        if ($context === null) {
            return;
        }

        try {
            $products = $this->backend->categoryProducts($context['restaurantSlug'], $categoryId);
        } catch (OrderingBackendException $exception) {
            $this->messageEditor->edit(
                bot: $bot,
                text: $this->failureMessage($exception, 'Категорію не знайдено.'),
                keyboard: $this->keyboard->products($categoryId, [], $context['callbackContext']),
            );

            return;
        }

        $this->messageEditor->edit(
            bot: $bot,
            text: $products === [] ? 'У цій категорії поки немає товарів.' : $this->formatter->products(),
            keyboard: $this->keyboard->products($categoryId, $products, $context['callbackContext']),
        );
    }

    public function product(Nutgram $bot, int $categoryId, int $productId, int $restaurantId, string $fingerprint): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $context = $this->restaurantContext($bot, $restaurantId, $fingerprint);

        if ($context === null) {
            return;
        }

        try {
            $product = $this->backend->product($context['restaurantSlug'], $productId);
        } catch (OrderingBackendException $exception) {
            $this->messageEditor->edit(
                bot: $bot,
                text: $this->failureMessage($exception, 'Товар не знайдено.'),
                keyboard: $this->keyboard->backToCategory($categoryId, $context['callbackContext']),
            );

            return;
        }

        $this->messageEditor->edit(
            bot: $bot,
            text: $this->formatter->product($product),
            keyboard: $this->keyboard->product($categoryId, $productId, $context['callbackContext']),
        );
    }

    public function mainMenu(Nutgram $bot, int $restaurantId, string $fingerprint): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $context = $this->validatedContext($bot, $restaurantId, $fingerprint);

        if ($context === null) {
            return;
        }

        $this->messageEditor->edit(
            bot: $bot,
            text: 'Вітаємо! Оберіть дію:',
            keyboard: $this->mainMenu->make($context['callbackContext']),
        );
    }

    public function postOrderMainMenu(Nutgram $bot, int $restaurantId, string $fingerprint): void
    {
        if (! $this->callbackAcknowledger->acknowledge($bot)) {
            return;
        }

        $context = $this->validatedContext($bot, $restaurantId, $fingerprint);

        if ($context === null) {
            return;
        }

        $bot->sendMessage(
            text: 'Вітаємо! Оберіть дію:',
            reply_markup: $this->mainMenu->make($context['callbackContext']),
        );
    }

    /**
     * @return array{sessionToken: string, callbackContext: string, restaurantSlug: string}|null
     */
    private function restaurantContext(Nutgram $bot, int $restaurantId, string $fingerprint): ?array
    {
        $context = $this->validatedContext($bot, $restaurantId, $fingerprint);

        if ($context === null) {
            return null;
        }

        try {
            $restaurants = $this->backend->currentSessionRestaurants($context['sessionToken']);
        } catch (OrderingBackendException $exception) {
            if ($this->sessionRecovery->recoverIfUnauthorized($bot, $exception)) {
                return null;
            }

            $this->stale($bot, $restaurantId, $context['sessionToken']);

            return null;
        }

        foreach ($restaurants as $restaurant) {
            if ($restaurant->id === $restaurantId) {
                return $context + ['restaurantSlug' => $restaurant->slug];
            }
        }

        $this->stale($bot, $restaurantId, $context['sessionToken']);

        return null;
    }

    /**
     * @return array{sessionToken: string, callbackContext: string}|null
     */
    private function validatedContext(Nutgram $bot, int $restaurantId, string $fingerprint): ?array
    {
        $sessionToken = $this->sessionRecovery->tokenOrRecover($bot);

        if ($sessionToken === null) {
            return null;
        }

        if (! $this->navigationContext->isValid($restaurantId, $fingerprint, $sessionToken)) {
            $this->stale($bot, $restaurantId, $sessionToken);

            return null;
        }

        return [
            'sessionToken' => $sessionToken,
            'callbackContext' => $this->navigationContext->encode($restaurantId, $sessionToken),
        ];
    }

    private function stale(Nutgram $bot, int $restaurantId, string $sessionToken): void
    {
        $this->messageEditor->edit(
            bot: $bot,
            text: RestaurantNavigationContext::STALE_MESSAGE,
            keyboard: $this->mainMenu->make($this->navigationContext->encode($restaurantId, $sessionToken)),
        );
    }

    private function failureMessage(OrderingBackendException $exception, string $notFoundMessage): string
    {
        if ($exception->statusCode() === 404) {
            return $notFoundMessage;
        }

        return 'Сервіс каталогу тимчасово недоступний. Спробуйте пізніше.';
    }
}
