<?php

namespace App\Services\Ai;

final class AiSettings
{
    public function historyMessages(): int
    {
        return $this->positiveInteger(
            'llm.history_messages',
            8,
        );
    }

    public function maxToolSteps(): int
    {
        return $this->positiveInteger(
            'llm.max_tool_steps',
            8,
        );
    }

    private function positiveInteger(
        string $key,
        int $default,
    ): int {
        $value = config($key, $default);

        return is_int($value) && $value > 0
            ? $value
            : $default;
    }
}
