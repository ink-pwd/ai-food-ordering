<?php

namespace App\DTO\Llm;

final readonly class LlmToolCallData
{
    public function __construct(
        public string $id,
        public string $name,
        public string $arguments,
    ) {
    }

    /**
     * @return array{
     *     id: string,
     *     type: 'function',
     *     function: array{name: string, arguments: string}
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'arguments' => $this->arguments,
            ],
        ];
    }
}
