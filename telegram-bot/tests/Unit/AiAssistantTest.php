<?php

use App\Contracts\AiToolExecutor;
use App\Contracts\LlmClient;
use App\DTO\Ai\AiContextData;
use App\DTO\Ai\AiToolResultData;
use App\DTO\Llm\LlmCompletionData;
use App\DTO\Llm\LlmRequestData;
use App\DTO\Llm\LlmToolCallData;
use App\Services\Ai\AiAssistant;
use App\Services\Ai\AiSettings;
use App\Services\Ai\AiToolCatalog;
use App\Telegram\Support\AiConversationStore;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

it('executes an allowed tool and then returns the final llm answer', function (): void {
    $previousContainer = Container::getInstance();
    $container = new Container;
    Container::setInstance($container);

    try {
        $container->instance('config', new Repository([
            'llm' => [
                'history_messages' => 8,
                'max_tool_steps' => 6,
            ],
        ]));

        $llm = new class implements LlmClient {
            private int $calls = 0;

            public function complete(LlmRequestData $request): LlmCompletionData
            {
                $this->calls++;

                if ($this->calls === 1) {
                    return new LlmCompletionData(
                        content: null,
                        toolCalls: [
                            new LlmToolCallData(
                                id: 'call-1',
                                name: 'get_cart',
                                arguments: '{}',
                            ),
                        ],
                    );
                }

                return new LlmCompletionData(
                    content: 'У кошику один чизкейк.',
                );
            }
        };

        $tools = new class implements AiToolExecutor {
            public int $calls = 0;

            public function execute(
                LlmToolCallData $toolCall,
                AiContextData $context,
            ): AiToolResultData {
                $this->calls++;

                return new AiToolResultData(
                    '{"currency":"UAH","total":"68.00","items":[{"name":"Чизкейк","quantity":1}]}',
                );
            }
        };

        $settings = new AiSettings;
        $store = new AiConversationStore($settings);
        $assistant = new AiAssistant(
            llm: $llm,
            tools: $tools,
            toolCatalog: new AiToolCatalog,
            conversations: $store,
            settings: $settings,
        );

        $answer = $assistant->reply(
            chatId: 100,
            userMessage: 'Що в кошику?',
            context: new AiContextData(
                sessionToken: 'session-token',
                callbackContext: '7:fingerprint',
                restaurantId: 7,
                restaurantSlug: 'restaurant',
            ),
        );

        expect($answer)->toBe('У кошику один чизкейк.')
            ->and($tools->calls)->toBe(1)
            ->and($store->get(100))->toHaveCount(2);
    } finally {
        Container::setInstance($previousContainer);
    }
});

it('instructs the llm to retry an empty product search before giving up', function (): void {
    $previousContainer = Container::getInstance();
    $container = new Container;
    Container::setInstance($container);

    try {
        $container->instance('config', new Repository([
            'llm' => [
                'history_messages' => 8,
                'max_tool_steps' => 6,
            ],
        ]));

        $llm = new class implements LlmClient {
            public ?LlmRequestData $request = null;

            public function complete(LlmRequestData $request): LlmCompletionData
            {
                $this->request = $request;

                return new LlmCompletionData(
                    content: 'Добре.',
                );
            }
        };

        $tools = new class implements AiToolExecutor {
            public function execute(
                LlmToolCallData $toolCall,
                AiContextData $context,
            ): AiToolResultData {
                return new AiToolResultData('{}');
            }
        };

        $settings = new AiSettings;
        $assistant = new AiAssistant(
            llm: $llm,
            tools: $tools,
            toolCatalog: new AiToolCatalog,
            conversations: new AiConversationStore($settings),
            settings: $settings,
        );

        $assistant->reply(
            chatId: 100,
            userMessage: 'Додай 3 Гавайські Піцци до кошику',
            context: new AiContextData(
                sessionToken: 'session-token',
                callbackContext: '7:fingerprint',
                restaurantId: 7,
                restaurantSlug: 'restaurant',
            ),
        );

        $systemPrompt = $llm->request?->messages[0]->content;

        expect($systemPrompt)->not->toBeNull()
            ->and($systemPrompt)->toContain('case-insensitive substring matching')
            ->and($systemPrompt)->toContain('Retry the same product search up to two times')
            ->and($systemPrompt)->toContain('stable word fragment')
            ->and($systemPrompt)->toContain('exact product ids and names returned by the tool');
    } finally {
        Container::setInstance($previousContainer);
    }
});
