<?php

namespace App\Http\Responses;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SessionPaymentResponse implements Responsable
{
    public function __construct(
        private readonly array $session,
    ) {}

    public function toResponse($request): Response
    {
        return response()->json([
            'data' => [
                'session_id' => $this->session['id'],
                'payment_type' => $this->session['metadata']['payment_type'],
            ],
        ]);
    }
}
