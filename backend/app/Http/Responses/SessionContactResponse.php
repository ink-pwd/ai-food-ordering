<?php

namespace App\Http\Responses;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SessionContactResponse implements Responsable
{
    /**
     * @param  array{id: string, restaurant_id: int, channel: string, external_session_id: string, status: string, metadata: array<string, mixed>, created_at: string, expires_at: string}  $session
     */
    public function __construct(
        private readonly array $session,
    ) {}

    public function toResponse($request): Response
    {
        /** @var Request $request */
        return response()->json([
            'data' => [
                'session_id' => $this->session['id'],
                'contact' => $this->session['metadata']['contact'],
            ],
        ]);
    }
}
