<?php

namespace App\Http\Responses;

use App\DTO\SessionData;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

readonly class SessionContactResponse implements Responsable
{
    public function __construct(
        private SessionData $session,
    ) {
    }

    public function toResponse($request): Response
    {
        /** @var Request $request */
        return response()->json([
            'data' => [
                'session_id' => $this->session->id,
                'contact' => $this->session->metadata['contact'],
            ],
        ]);
    }
}
