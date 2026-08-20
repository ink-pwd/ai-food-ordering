<?php

namespace App\Services\Handlers\Session;

use App\DTO\SessionData;
use App\Enums\SessionChannel;
use App\Enums\SessionStatus;
use App\Services\Repositories\SessionRepository;
use Illuminate\Support\Str;

readonly class CreateSessionHandler
{
    public function __construct(
        private SessionRepository $sessions,
    ) {
    }

    /**
     * @return array{token: string, session: SessionData}|null
     */
    public function handle(
        SessionChannel $channel,
        string $externalSessionId,
    ): ?array {
        /** @var int|string $ttlSecondsValue */
        $ttlSecondsValue = config('services.internal.session_ttl_seconds');
        $ttlSeconds = (int) $ttlSecondsValue;

        if ($ttlSeconds <= 0) {
            return null;
        }

        $createdAt = now();
        $expiresAt = $createdAt->copy()->addSeconds($ttlSeconds);
        $plainToken = bin2hex(random_bytes(32));

        $session = [
            'id' => (string) Str::ulid(),
            'city_id' => null,
            'restaurant_id' => null,
            'channel' => $channel->value,
            'external_session_id' => $externalSessionId,
            'status' => SessionStatus::Active->value,
            'metadata' => [],
            'created_at' => $createdAt->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
        ];

        $this->sessions->put($plainToken, $session);

        return [
            'token' => $plainToken,
            'session' => SessionData::fromArray($session),
        ];
    }
}
