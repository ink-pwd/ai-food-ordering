<?php

namespace App\DTO\Llm;

final readonly class LlmToolParameterData
{
    public function __construct(
        public string $name,
        public string $type,
        public string $description,
        public bool $required = true,
        public ?int $minimum = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function schema(): array
    {
        $schema = [
            'type' => $this->type,
            'description' => $this->description,
        ];

        if ($this->minimum !== null) {
            $schema['minimum'] = $this->minimum;
        }

        return $schema;
    }
}
