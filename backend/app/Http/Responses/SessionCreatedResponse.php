<?php

namespace App\Http\Responses;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SessionCreatedResponse implements Responsable
{
    /**
     * @param  array{token: string, session: array{id: string, restaurant_id: int, channel: string, external_session_id: string, status: string, metadata: array<string, mixed>, created_at: string, expires_at: string}, restaurant: array{name: string, slug: string, currency: string, locale: string, timezone: string}}  $createdSession
     */
    public function __construct(
        private readonly array $createdSession,
    ) {}

    public function toResponse($request): Response
    {
        /** @var Request $request */
        return response()->json([
            'data' => [
                'session_id' => $this->createdSession['session']['id'],
                'session_token' => $this->createdSession['token'],
                'channel' => $this->createdSession['session']['channel'],
                'status' => $this->createdSession['session']['status'],
                'expires_at' => $this->createdSession['session']['expires_at'],
                'restaurant' => $this->createdSession['restaurant'],
            ],
        ], Response::HTTP_CREATED);
    }
}
