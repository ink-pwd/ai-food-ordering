<?php

return [
    'history_messages' => (int) env('AI_HISTORY_MESSAGES', 8),
    'max_tool_steps' => (int) env('AI_MAX_TOOL_STEPS', 8),

    'groq' => [
        'api_key' => env('GROQ_API_KEY'),
        'base_url' => env(
            'GROQ_BASE_URL',
            'https://api.groq.com/openai/v1',
        ),
        'model' => env('GROQ_MODEL', 'openai/gpt-oss-20b'),
        'timeout' => (int) env('GROQ_TIMEOUT', 20),
        'max_completion_tokens' => (int) env(
            'GROQ_MAX_COMPLETION_TOKENS',
            512,
        ),
    ],
];
