<?php

namespace App\DTO\Llm;

final readonly class LlmMessageData
{
    /**
     * @param  list<LlmToolCallData>  $toolCalls
     */
    public function __construct(
        public string $role,
        public ?string $content,
        public array $toolCalls = [],
        public ?string $toolCallId = null,
    ) {
    }

    public static function system(string $content): self
    {
        return new self('system', $content);
    }

    public static function user(string $content): self
    {
        return new self('user', $content);
    }

    public static function assistant(string $content): self
    {
        return new self('assistant', $content);
    }

    /**
     * @param  list<LlmToolCallData>  $toolCalls
     */
    public static function assistantWithTools(
        ?string $content,
        array $toolCalls,
    ): self {
        return new self(
            role: 'assistant',
            content: $content,
            toolCalls: $toolCalls,
        );
    }

    public static function tool(
        string $toolCallId,
        string $content,
    ): self {
        return new self(
            role: 'tool',
            content: $content,
            toolCallId: $toolCallId,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $message = [
            'role' => $this->role,
            'content' => $this->content,
        ];

        if ($this->toolCalls !== []) {
            $message['tool_calls'] = array_map(
                static fn (LlmToolCallData $toolCall): array => $toolCall->toArray(),
                $this->toolCalls,
            );
        }

        if ($this->toolCallId !== null) {
            $message['tool_call_id'] = $this->toolCallId;
        }

        return $message;
    }
}
