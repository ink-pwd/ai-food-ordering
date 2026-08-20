<?php

namespace App\Http\Responses;

use App\DTO\SessionData;
use Illuminate\Contracts\Support\Responsable;
use Symfony\Component\HttpFoundation\Response;

final readonly class SessionPaymentResponse implements Responsable
{
    public function __construct(
        private SessionData $session,
    ) {
    }

    public function toResponse($request): Response
    {
        return response()->json([
            'data' => [
                'session_id' => $this->session->id,
                'payment_type' => $this->session->metadata['payment_type'],
            ],
        ]);
    }
}
