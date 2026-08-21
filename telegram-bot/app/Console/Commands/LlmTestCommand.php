<?php

namespace App\Console\Commands;

use App\Contracts\LlmClient;
use App\DTO\Llm\LlmMessageData;
use App\DTO\Llm\LlmRequestData;
use App\Exceptions\LlmException;
use Illuminate\Console\Command;

final class LlmTestCommand extends Command
{
    protected $signature = 'llm:test {prompt=Відповідай тільки словом OK}';

    protected $description = 'Send one smoke-test request to the configured LLM provider';

    public function handle(LlmClient $llm): int
    {
        $prompt = $this->argument('prompt');

        if (! is_string($prompt) || trim($prompt) === '') {
            $this->error('Prompt must be a non-empty string.');

            return self::FAILURE;
        }

        try {
            $completion = $llm->complete(
                new LlmRequestData([
                    LlmMessageData::system(
                        'Reply briefly in the same language as the user.',
                    ),
                    LlmMessageData::user(trim($prompt)),
                ]),
            );
        } catch (LlmException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $answer = trim($completion->content ?? '');

        if ($answer === '') {
            $this->error('LLM returned an empty answer.');

            return self::FAILURE;
        }

        $this->line($answer);

        return self::SUCCESS;
    }
}
