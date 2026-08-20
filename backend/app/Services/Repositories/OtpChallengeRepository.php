<?php

namespace App\Services\Repositories;

use App\DTO\OtpChallengeData;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;

readonly class OtpChallengeRepository
{
    public function __construct(
        private CacheFactory $cache,
    ) {
    }

    public function put(string $sessionId, OtpChallengeData $challenge): void
    {
        $this->store()->put(
            $this->cacheKey($sessionId),
            json_encode($challenge->toArray(), JSON_THROW_ON_ERROR),
            $this->ttlSeconds(),
        );
    }

    public function find(string $sessionId): ?OtpChallengeData
    {
        $challenge = $this->store()->get($this->cacheKey($sessionId));

        if ($challenge === null) {
            return null;
        }

        /** @var string $challenge */
        $challenge = $challenge;
        /** @var array{session_id: string, phone: string, code_hash: string, attempts_remaining: int, expires_at: string, resend_available_at: string} $decoded */
        $decoded = json_decode($challenge, true, flags: JSON_THROW_ON_ERROR);

        return OtpChallengeData::fromArray($decoded);
    }

    public function forget(string $sessionId): bool
    {
        return $this->store()->forget($this->cacheKey($sessionId));
    }

    public function update(string $sessionId, OtpChallengeData $challenge): void
    {
        $this->store()->put(
            $this->cacheKey($sessionId),
            json_encode($challenge->toArray(), JSON_THROW_ON_ERROR),
            (int) max(1, now()->diffInSeconds($this->expiresAt($challenge->expiresAt), false)),
        );
    }

    private function cacheKey(string $sessionId): string
    {
        return sprintf('%s:%s', $this->keyPrefix(), $sessionId);
    }

    private function store(): CacheRepository
    {
        /** @var string $store */
        $store = config('services.internal.otp.store');

        return $this->cache->store((string) $store);
    }

    private function ttlSeconds(): int
    {
        /** @var int|string $ttlSeconds */
        $ttlSeconds = config('services.internal.otp.ttl_seconds');

        return max(1, (int) $ttlSeconds);
    }

    private function keyPrefix(): string
    {
        /** @var string $keyPrefix */
        $keyPrefix = config('services.internal.otp.key_prefix');

        return (string) $keyPrefix;
    }

    private function expiresAt(string $expiresAt): Carbon
    {
        return Carbon::parse($expiresAt);
    }
}
