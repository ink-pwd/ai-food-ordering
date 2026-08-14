<?php

namespace App\Services\Handlers\Session;

use App\Enums\SessionChannel;
use App\Enums\SessionStatus;
use App\Services\Repositories\SessionRepository;
use Illuminate\Support\Str;

class CreateSessionHandler
{
    public function __construct(
        private readonly SessionRepository $sessions,
    ) {}

    /**
     * @return array{token: string, session: array{id: string, city_id: int|null, restaurant_id: int|null, channel: string, external_session_id: string, status: string, metadata: array<string, mixed>, created_at: string, expires_at: string}}|null
     */
    public function handle(SessionChannel $channel, string $externalSessionId): ?array
    {
        $ttlSeconds = (int) config('services.internal.session_ttl_seconds');

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
            'session' => $session,
        ];
    }
}
