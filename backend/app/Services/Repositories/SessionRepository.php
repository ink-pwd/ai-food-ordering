<?php

namespace App\Services\Repositories;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;

class SessionRepository
{
    public function __construct(
        private readonly CacheFactory $cache,
    ) {}

    /**
     * @param  array{id: string, restaurant_id: int, channel: string, external_session_id: string, status: string, metadata: array<string, mixed>, created_at: string, expires_at: string}  $session
     */
    public function put(string $plainToken, array $session): void
    {
        $this->store()->put(
            $this->cacheKey($plainToken),
            json_encode($session, JSON_THROW_ON_ERROR),
            $this->ttlSeconds(),
        );
    }

    /**
     * @return array{id: string, restaurant_id: int, channel: string, external_session_id: string, status: string, metadata: array<string, mixed>, created_at: string, expires_at: string}|null
     */
    public function findByToken(string $plainToken): ?array
    {
        $session = $this->store()->get($this->cacheKey($plainToken));

        if ($session === null) {
            return null;
        }

        return json_decode($session, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{id: string, restaurant_id: int, channel: string, external_session_id: string, status: string, metadata: array<string, mixed>, created_at: string, expires_at: string}|null
     */
    public function updateMetadata(string $plainToken, array $metadata): ?array
    {
        $session = $this->findByToken($plainToken);

        if ($session === null) {
            return null;
        }

        $expiresAt = Carbon::parse($session['expires_at']);
        $remainingTtlSeconds = now()->diffInSeconds($expiresAt, false);

        if ($remainingTtlSeconds <= 0) {
            $this->deleteByToken($plainToken);

            return null;
        }

        $session['metadata'] = array_merge($session['metadata'] ?? [], $metadata);

        $this->store()->put(
            $this->cacheKey($plainToken),
            json_encode($session, JSON_THROW_ON_ERROR),
            $remainingTtlSeconds,
        );

        return $session;
    }

    public function deleteByToken(string $plainToken): bool
    {
        return $this->store()->forget($this->cacheKey($plainToken));
    }

    private function cacheKey(string $plainToken): string
    {
        return sprintf('%s:%s', $this->keyPrefix(), hash('sha256', $plainToken));
    }

    private function store(): CacheRepository
    {
        return $this->cache->store((string) config('services.internal.session_store'));
    }

    private function ttlSeconds(): int
    {
        return (int) config('services.internal.session_ttl_seconds');
    }

    private function keyPrefix(): string
    {
        return (string) config('services.internal.session_key_prefix');
    }
}
