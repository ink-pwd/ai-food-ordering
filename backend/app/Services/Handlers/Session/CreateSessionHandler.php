<?php

namespace App\Services\Handlers\Session;

use App\Enums\SessionChannel;
use App\Enums\SessionStatus;
use App\Services\Repositories\RestaurantRepository;
use App\Services\Repositories\SessionRepository;
use Illuminate\Support\Str;

class CreateSessionHandler
{
    public function __construct(
        private readonly RestaurantRepository $restaurants,
        private readonly SessionRepository $sessions,
    ) {}

    /**
     * @return array{token: string, session: array{id: string, restaurant_id: int, channel: string, external_session_id: string, status: string, metadata: array<string, mixed>, created_at: string, expires_at: string}, restaurant: array{name: string, slug: string, currency: string, locale: string, timezone: string}}|null
     */
    public function handle(SessionChannel $channel, string $externalSessionId): ?array
    {
        $restaurantSlug = config('services.internal.restaurant_slug');

        if (! is_string($restaurantSlug) || $restaurantSlug === '') {
            return null;
        }

        $restaurant = $this->restaurants->findActiveBySlug($restaurantSlug);

        if ($restaurant === null) {
            return null;
        }

        $ttlSeconds = (int) config('services.internal.session_ttl_seconds');

        if ($ttlSeconds <= 0) {
            return null;
        }

        $createdAt = now();
        $expiresAt = $createdAt->copy()->addSeconds($ttlSeconds);
        $plainToken = bin2hex(random_bytes(32));

        $session = [
            'id' => (string) Str::ulid(),
            'restaurant_id' => $restaurant->id,
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
            'restaurant' => [
                'name' => $restaurant->name,
                'slug' => $restaurant->slug,
                'currency' => $restaurant->currency,
                'locale' => $restaurant->locale,
                'timezone' => $restaurant->timezone,
            ],
        ];
    }
}
