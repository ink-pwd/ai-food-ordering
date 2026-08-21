<?php

namespace App\Telegram\Tracking;

use App\Telegram\Keyboards\OrderTrackingKeyboard;
use App\Telegram\Support\OrderTrackingPromptStore;
use App\Telegram\Support\RestaurantNavigationContext;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ForceReply;

final readonly class OrderTrackingPrompt
{
    public function __construct(
        private OrderTrackingPromptStore $store,
        private RestaurantNavigationContext $navigationContext,
        private OrderTrackingKeyboard $keyboard,
    ) {
    }

    public function ask(
        Nutgram $bot,
        int $restaurantId,
        string $sessionToken,
        ?string $message = null,
    ): void {
        $sentMessage = $bot->sendMessage(
            text: $message
                ?? '🔎 Введіть номер замовлення, який бот показав після оформлення.',
            reply_markup: ForceReply::make(
                force_reply: true,
                input_field_placeholder: 'Наприклад: 42',
                selective: true,
            ),
        );

        $chatId = $bot->chatId();

        if ($sentMessage === null || $chatId === null) {
            return;
        }

        $this->store->put(
            chatId: $chatId,
            messageId: $sentMessage->message_id,
            restaurantId: $restaurantId,
            fingerprint: $this->navigationContext->fingerprint(
                $sessionToken,
            ),
        );

        $bot->sendMessage(
            text: 'Або поверніться до меню:',
            reply_markup: $this->keyboard->back(
                $this->navigationContext->encode(
                    $restaurantId,
                    $sessionToken,
                ),
            ),
        );
    }

    /**
     * @return array{
     *     chatId: int,
     *     promptMessageId: int,
     *     restaurantId: int,
     *     fingerprint: string
     * }|null
     */
    public function replyContext(Nutgram $bot): ?array
    {
        $replyMessage = $bot->message()?->reply_to_message;
        $chatId = $bot->chatId();

        if ($replyMessage === null || $chatId === null) {
            return null;
        }

        $prompt = $this->store->get(
            $chatId,
            $replyMessage->message_id,
        );

        if ($prompt === null) {
            return null;
        }

        return [
            'chatId' => $chatId,
            'promptMessageId' => $replyMessage->message_id,
            'restaurantId' => $prompt['restaurant_id'],
            'fingerprint' => $prompt['fingerprint'],
        ];
    }

    public function forget(int $chatId, int $promptMessageId): void
    {
        $this->store->forget($chatId, $promptMessageId);
    }

    public function forgetChat(int $chatId): void
    {
        $this->store->forgetChat($chatId);
    }
}
