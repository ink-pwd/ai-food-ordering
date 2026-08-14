<?php

namespace App\Http\Responses;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SessionCreatedResponse implements Responsable
{
    /**
     * @param  array{token: string, session: array{id: string, city_id: int|null, restaurant_id: int|null, channel: string, external_session_id: string, status: string, metadata: array<string, mixed>, created_at: string, expires_at: string}}  $createdSession
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
                'city' => null,
                'restaurant' => null,
            ],
        ], Response::HTTP_CREATED);
    }
}
