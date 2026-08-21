<?php

namespace App\Integrations\Groq;

use App\Contracts\LlmClient;
use App\DTO\Llm\LlmCompletionData;
use App\DTO\Llm\LlmMessageData;
use App\DTO\Llm\LlmRequestData;
use App\DTO\Llm\LlmToolCallData;
use App\DTO\Llm\LlmToolDefinitionData;
use App\Exceptions\LlmException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use JsonException;
use Psr\Log\LoggerInterface;

final readonly class GroqLlmClient implements LlmClient
{
    public function __construct(
        private Factory $http,
        private LoggerInterface $logger,
        private GroqSettings $settings,
    ) {
    }

    public function complete(LlmRequestData $request): LlmCompletionData
    {
        $apiKey = $this->settings->apiKey();

        try {
            $response = $this->http
                ->baseUrl($this->settings->baseUrl())
                ->acceptJson()
                ->asJson()
                ->withToken($apiKey)
                ->timeout($this->settings->timeout())
                ->post('chat/completions', $this->payload($request))
                ->throw();
        } catch (ConnectionException|RequestException $exception) {
            $this->logger->error('Groq request failed.', [
                'exception' => $exception::class,
                'status' => $exception instanceof RequestException
                    ? $exception->response->status()
                    : null,
            ]);

            throw new LlmException(
                'Unable to receive a response from Groq.',
                $exception,
            );
        }

        return $this->completionFromResponse($response);
    }

    /** @return array<string, mixed> */
    private function payload(LlmRequestData $request): array
    {
        $payload = [
            'model' => $this->settings->model(),
            'messages' => array_map(
                static fn (LlmMessageData $message): array => $message->toArray(),
                $request->messages,
            ),
            'reasoning_effort' => 'low',
            'max_completion_tokens' => max(
                64,
                $this->settings->maxCompletionTokens(),
            ),
        ];

        if ($request->tools !== []) {
            $payload['tools'] = array_map(
                static fn (LlmToolDefinitionData $tool): array => $tool->toArray(),
                $request->tools,
            );
            $payload['tool_choice'] = 'auto';
            $payload['parallel_tool_calls'] = false;
        }

        return $payload;
    }

    private function completionFromResponse(
        Response $response,
    ): LlmCompletionData {
        try {
            $payload = $response->json();
        } catch (JsonException $exception) {
            throw new LlmException(
                'Groq returned malformed JSON.',
                $exception,
            );
        }

        if (! is_array($payload)) {
            throw new LlmException('Groq returned malformed completion data.');
        }

        $choices = $payload['choices'] ?? null;

        if (! is_array($choices) || ! array_is_list($choices)) {
            throw new LlmException('Groq returned malformed completion choices.');
        }

        $choice = $choices[0] ?? null;

        if (! is_array($choice)) {
            throw new LlmException('Groq returned an empty completion.');
        }

        $message = $choice['message'] ?? null;

        if (! is_array($message)) {
            throw new LlmException('Groq returned malformed completion message.');
        }

        $content = $message['content'] ?? null;

        if ($content !== null && ! is_string($content)) {
            throw new LlmException('Groq returned malformed completion content.');
        }

        return new LlmCompletionData(
            content: is_string($content) ? trim($content) : null,
            toolCalls: $this->toolCalls($message['tool_calls'] ?? []),
        );
    }

    /**
     * @return list<LlmToolCallData>
     */
    private function toolCalls(mixed $toolCalls): array
    {
        if (! is_array($toolCalls) || ! array_is_list($toolCalls)) {
            throw new LlmException('Groq returned malformed tool calls.');
        }

        return array_map(
            fn (mixed $toolCall): LlmToolCallData => $this->toolCall($toolCall),
            $toolCalls,
        );
    }

    private function toolCall(mixed $toolCall): LlmToolCallData
    {
        if (! is_array($toolCall)) {
            throw new LlmException('Groq returned malformed tool call data.');
        }

        $function = $toolCall['function'] ?? null;
        $id = $toolCall['id'] ?? null;

        if (! is_array($function) || ! is_string($id) || trim($id) === '') {
            throw new LlmException('Groq returned malformed tool call metadata.');
        }

        $name = $function['name'] ?? null;
        $arguments = $function['arguments'] ?? null;

        if (
            ! is_string($name)
            || trim($name) === ''
            || ! is_string($arguments)
        ) {
            throw new LlmException('Groq returned malformed tool call function.');
        }

        return new LlmToolCallData(
            id: $id,
            name: $name,
            arguments: $arguments,
        );
    }
}
