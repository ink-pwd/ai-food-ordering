<?php

namespace App\Services\Repositories;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;

class OtpChallengeRepository
{
    public function __construct(
        private readonly CacheFactory $cache,
    ) {}

    /**
     * @param  array{session_id: string, phone: string, code_hash: string, attempts_remaining: int, expires_at: string, resend_available_at: string}  $challenge
     */
    public function put(string $sessionId, array $challenge): void
    {
        $this->store()->put(
            $this->cacheKey($sessionId),
            json_encode($challenge, JSON_THROW_ON_ERROR),
            $this->ttlSeconds(),
        );
    }

    /**
     * @return array{session_id: string, phone: string, code_hash: string, attempts_remaining: int, expires_at: string, resend_available_at: string}|null
     */
    public function find(string $sessionId): ?array
    {
        $challenge = $this->store()->get($this->cacheKey($sessionId));

        if ($challenge === null) {
            return null;
        }

        return json_decode($challenge, true, flags: JSON_THROW_ON_ERROR);
    }

    public function forget(string $sessionId): bool
    {
        return $this->store()->forget($this->cacheKey($sessionId));
    }

    /**
     * @param  array{session_id: string, phone: string, code_hash: string, attempts_remaining: int, expires_at: string, resend_available_at: string}  $challenge
     */
    public function update(string $sessionId, array $challenge): void
    {
        $this->store()->put(
            $this->cacheKey($sessionId),
            json_encode($challenge, JSON_THROW_ON_ERROR),
            max(1, now()->diffInSeconds($this->expiresAt($challenge['expires_at']), false)),
        );
    }

    private function cacheKey(string $sessionId): string
    {
        return sprintf('%s:%s', $this->keyPrefix(), $sessionId);
    }

    private function store(): CacheRepository
    {
        return $this->cache->store((string) config('services.internal.otp.store'));
    }

    private function ttlSeconds(): int
    {
        return max(1, (int) config('services.internal.otp.ttl_seconds'));
    }

    private function keyPrefix(): string
    {
        return (string) config('services.internal.otp.key_prefix');
    }

    private function expiresAt(string $expiresAt): Carbon
    {
        return Carbon::parse($expiresAt);
    }
}
