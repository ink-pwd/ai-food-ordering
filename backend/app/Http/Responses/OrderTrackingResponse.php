<?php

namespace App\Http\Responses;

use App\DTO\OrderTrackingData;
use Illuminate\Contracts\Support\Responsable;
use Symfony\Component\HttpFoundation\Response;

readonly class OrderTrackingResponse implements Responsable
{
    public function __construct(
        private OrderTrackingData $tracking,
    ) {
    }

    public function toResponse($request): Response
    {
        return response()->json([
            'data' => $this->tracking->toArray(),
        ]);
    }
}
