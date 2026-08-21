<?php

use App\DTO\Llm\LlmMessageData;
use App\DTO\Llm\LlmRequestData;
use App\Integrations\Groq\GroqLlmClient;
use App\Integrations\Groq\GroqSettings;
use App\Services\Ai\AiToolCatalog;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Psr\Log\NullLogger;

it('sends an openai compatible groq request and maps tool calls', function (): void {
    $previousContainer = Container::getInstance();
    $container = new Container;
    Container::setInstance($container);

    try {
        $container->instance('config', new Repository([
            'llm' => [
                'groq' => [
                    'api_key' => 'groq-test-key',
                    'base_url' => 'https://api.groq.test/openai/v1',
                    'model' => 'openai/gpt-oss-20b',
                    'timeout' => 20,
                ],
            ],
        ]));

        $http = new Factory;
        $http->fake([
            'https://api.groq.test/openai/v1/chat/completions' => $http->response([
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => null,
                            'tool_calls' => [
                                [
                                    'id' => 'call-1',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'search_products',
                                        'arguments' => '{"query":"чизкейк"}',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $client = new GroqLlmClient(
            $http,
            new NullLogger,
            new GroqSettings,
        );
        $completion = $client->complete(
            new LlmRequestData(
                messages: [
                    LlmMessageData::user('Знайди чизкейк'),
                ],
                tools: (new AiToolCatalog)->definitions(),
            ),
        );

        expect($completion->content)->toBeNull()
            ->and($completion->toolCalls)->toHaveCount(1)
            ->and($completion->toolCalls[0]->name)
            ->toBe('search_products');

        $http->assertSent(
            static function (Request $request): bool {
                $data = $request->data();

                return $request->url()
                    === 'https://api.groq.test/openai/v1/chat/completions'
                    && $request->hasHeader(
                        'Authorization',
                        'Bearer groq-test-key',
                    )
                    && ($data['model'] ?? null)
                    === 'openai/gpt-oss-20b'
                    && ($data['parallel_tool_calls'] ?? null) === false;
            },
        );
    } finally {
        Container::setInstance($previousContainer);
    }
});
