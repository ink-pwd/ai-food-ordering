<?php

namespace App\Http\Middleware;

use App\DTO\SessionData;
use App\Enums\SessionChannel;
use App\Enums\SessionStatus;
use App\Services\Repositories\SessionRepository;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

readonly class EnsureSessionToken
{
    public function __construct(
        private SessionRepository $sessions,
    ) {
    }

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $plainToken = $request->headers->get('X-Session-Token');

        if (! is_string($plainToken) || preg_match('/\A[a-f0-9]{64}\z/', $plainToken) !== 1) {
            return $this->unauthenticated();
        }

        $session = $this->sessions->findByToken($plainToken);

        if (! $this->isValidSession($session)) {
            return $this->unauthenticated();
        }

        /** @var array{id: string, city_id?: int|null, restaurant_id?: int|null, fulfillment?: array<string, mixed>|null, channel: string, external_session_id: string, status: string, metadata: array<string, mixed>, created_at: string, expires_at: string} $session */
        $request->attributes->set(
            'internal_session',
            SessionData::fromArray($session),
        );

        return $next($request);
    }

    /**
     * @param  array<string, mixed>|null  $session
     */
    private function isValidSession(?array $session): bool
    {
        if ($session === null) {
            return false;
        }

        if ($this->hasInvalidSessionId($session)) {
            return false;
        }

        foreach (['city_id', 'restaurant_id'] as $selectionKey) {
            if ($this->hasInvalidSelection($session, $selectionKey)) {
                return false;
            }
        }

        if ($this->hasInvalidChannel($session)) {
            return false;
        }

        if (! array_key_exists('external_session_id', $session) || ! is_string($session['external_session_id'])) {
            return false;
        }

        if (($session['status'] ?? null) !== SessionStatus::Active->value) {
            return false;
        }

        if (! isset($session['expires_at']) || ! is_string($session['expires_at'])) {
            return false;
        }

        try {
            $expiresAt = Carbon::parse($session['expires_at']);
        } catch (\Throwable) {
            return false;
        }

        return $expiresAt->isFuture();
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function hasInvalidSessionId(array $session): bool
    {
        return ! isset($session['id'])
            || ! is_string($session['id'])
            || $session['id'] === '';
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function hasInvalidSelection(
        array $session,
        string $selectionKey,
    ): bool {
        return array_key_exists($selectionKey, $session)
            && $session[$selectionKey] !== null
            && (
                ! is_int($session[$selectionKey])
                || $session[$selectionKey] < 1
            );
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function hasInvalidChannel(array $session): bool
    {
        return ! isset($session['channel'])
            || ! is_string($session['channel'])
            || ! SessionChannel::tryFrom($session['channel']);
    }

    private function unauthenticated(): JsonResponse
    {
        return response()->json([
            'message' => 'Unauthenticated.',
        ], Response::HTTP_UNAUTHORIZED);
    }
}
