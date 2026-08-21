<?php

namespace App\Telegram\Ai;

use App\DTO\Ai\AiPromptReplyContextData;
use App\Telegram\Keyboards\AiAssistantKeyboard;
use App\Telegram\Support\AiPromptStore;
use App\Telegram\Support\RestaurantNavigationContext;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ForceReply;

final readonly class AiPrompt
{
    public function __construct(
        private AiPromptStore $store,
        private RestaurantNavigationContext $navigationContext,
        private AiAssistantKeyboard $keyboard,
    ) {
    }

    public function ask(
        Nutgram $bot,
        int $restaurantId,
        string $sessionToken,
        ?string $message = null,
    ): void {
        $sentMessage = $bot->sendMessage(
            text: $message ?? '🤖 Напишіть, що знайти, додати або змінити в кошику, чи запитайте про замовлення за його номером.',
            reply_markup: ForceReply::make(
                force_reply: true,
                input_field_placeholder: 'Наприклад: додай два чизкейки',
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
            text: 'Або поверніться до звичайного меню:',
            reply_markup: $this->keyboard->back(
                $this->navigationContext->encode(
                    $restaurantId,
                    $sessionToken,
                ),
            ),
        );
    }

    public function replyContext(
        Nutgram $bot,
    ): ?AiPromptReplyContextData {
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

        return new AiPromptReplyContextData(
            chatId: $chatId,
            promptMessageId: $replyMessage->message_id,
            restaurantId: $prompt->restaurantId,
            fingerprint: $prompt->fingerprint,
        );
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
