<?php

namespace App\DTO\Llm;

use stdClass;

final readonly class LlmToolDefinitionData
{
    /** @param list<LlmToolParameterData> $parameters */
    public function __construct(
        public string $name,
        public string $description,
        public array $parameters = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $properties = [];
        $required = [];

        foreach ($this->parameters as $parameter) {
            $properties[$parameter->name] = $parameter->schema();

            if ($parameter->required) {
                $required[] = $parameter->name;
            }
        }

        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'description' => $this->description,
                'parameters' => [
                    'type' => 'object',
                    'properties' => $properties === []
                        ? new stdClass
                        : $properties,
                    'required' => $required,
                    'additionalProperties' => false,
                ],
            ],
        ];
    }
}
