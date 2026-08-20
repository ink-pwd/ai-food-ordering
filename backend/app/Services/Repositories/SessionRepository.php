<?php

namespace App\Services\Repositories;

use App\Enums\SessionStatus;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;

readonly class SessionRepository
{
    public function __construct(
        private CacheFactory $cache,
    ) {
    }

    /**
     * @param  array{id: string, city_id?: int|null, restaurant_id?: int|null, fulfillment?: array<string, mixed>|null, channel: string, external_session_id: string, status: string, metadata: array<string, mixed>, created_at: string, expires_at: string}  $session
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
     * @return array{id: string, city_id?: int|null, restaurant_id?: int|null, fulfillment?: array<string, mixed>|null, channel: string, external_session_id: string, status: string, metadata: array<string, mixed>, created_at: string, expires_at: string}|null
     */
    public function findByToken(string $plainToken): ?array
    {
        $session = $this->store()->get($this->cacheKey($plainToken));

        if ($session === null) {
            return null;
        }

        /** @var string $session */
        $session = $session;
        /** @var array{id: string, city_id?: int|null, restaurant_id?: int|null, fulfillment?: array<string, mixed>|null, channel: string, external_session_id: string, status: string, metadata: array<string, mixed>, created_at: string, expires_at: string} $decoded */
        $decoded = json_decode($session, true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{id: string, city_id?: int|null, restaurant_id?: int|null, fulfillment?: array<string, mixed>|null, channel: string, external_session_id: string, status: string, metadata: array<string, mixed>, created_at: string, expires_at: string}|null
     */
    public function updateMetadata(string $plainToken, array $metadata): ?array
    {
        return $this->mutateActive($plainToken, function (array $session) use ($metadata): array {
            $session['metadata'] = array_merge(
                $session['metadata'] ?? [],
                $metadata,
            );

            return $session;
        });
    }

    /**
     * @return array{id: string, city_id?: int|null, restaurant_id?: int|null, fulfillment?: array<string, mixed>|null, channel: string, external_session_id: string, status: string, metadata: array<string, mixed>, created_at: string, expires_at: string}|null
     */
    public function selectCity(string $plainToken, int $cityId): ?array
    {
        return $this->mutateActive($plainToken, function (array $session) use ($cityId): array {
            $session['city_id'] = $cityId;

            return $session;
        });
    }

    /**
     * @return array{id: string, city_id?: int|null, restaurant_id?: int|null, fulfillment?: array<string, mixed>|null, channel: string, external_session_id: string, status: string, metadata: array<string, mixed>, created_at: string, expires_at: string}|null
     */
    public function selectRestaurant(
        string $plainToken,
        int $restaurantId,
    ): ?array {
        return $this->mutateActive($plainToken, function (array $session) use ($restaurantId): array {
            $session['restaurant_id'] = $restaurantId;

            return $session;
        });
    }

    /**
     * @param  array<string, mixed>|null  $fulfillment
     * @return array{id: string, city_id?: int|null, restaurant_id?: int|null, fulfillment?: array<string, mixed>|null, channel: string, external_session_id: string, status: string, metadata: array<string, mixed>, created_at: string, expires_at: string}|null
     */
    public function updateFulfillment(
        string $plainToken,
        ?array $fulfillment,
    ): ?array {
        return $this->mutateActive($plainToken, function (array $session) use ($fulfillment): array {
            $session['fulfillment'] = $fulfillment;

            return $session;
        });
    }

    /**
     * @return array{id: string, city_id?: int|null, restaurant_id?: int|null, fulfillment?: array<string, mixed>|null, channel: string, external_session_id: string, status: string, metadata: array<string, mixed>, created_at: string, expires_at: string}|null
     */
    public function close(string $plainToken): ?array
    {
        return $this->mutateActive($plainToken, function (array $session): array {
            $session['status'] = SessionStatus::Closed->value;

            return $session;
        });
    }

    public function deleteByToken(string $plainToken): bool
    {
        return $this->store()->forget(
            $this->cacheKey($plainToken),
        );
    }

    /**
     * @return array{id: string, city_id?: int|null, restaurant_id?: int|null, fulfillment?: array<string, mixed>|null, channel: string, external_session_id: string, status: string, metadata: array<string, mixed>, created_at: string, expires_at: string}|null
     */
    private function mutateActive(
        string $plainToken,
        callable $callback,
    ): ?array {
        $session = $this->findByToken($plainToken);

        if ($session === null) {
            return null;
        }

        $expiresAt = Carbon::parse($session['expires_at']);

        $remainingTtlSeconds = now()->diffInSeconds(
            $expiresAt,
            false,
        );

        if ($remainingTtlSeconds <= 0) {
            $this->deleteByToken($plainToken);

            return null;
        }

        $session = $callback($session);

        $this->store()->put(
            $this->cacheKey($plainToken),
            json_encode($session, JSON_THROW_ON_ERROR),
            (int) $remainingTtlSeconds,
        );

        return $session;
    }

    private function cacheKey(string $plainToken): string
    {
        return sprintf(
            '%s:%s',
            $this->keyPrefix(),
            hash('sha256', $plainToken),
        );
    }

    private function store(): CacheRepository
    {
        /** @var string $store */
        $store = config('services.internal.session_store');

        return $this->cache->store(
            (string) $store,
        );
    }

    private function ttlSeconds(): int
    {
        /** @var int|string $ttlSeconds */
        $ttlSeconds = config(
            'services.internal.session_ttl_seconds',
        );

        return (int) $ttlSeconds;
    }

    private function keyPrefix(): string
    {
        /** @var string $keyPrefix */
        $keyPrefix = config(
            'services.internal.session_key_prefix',
        );

        return (string) $keyPrefix;
    }
}
