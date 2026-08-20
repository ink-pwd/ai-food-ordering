<?php

namespace App\Http\Controllers\Api\Session;

use App\Http\Controllers\Controller;
use App\Http\Requests\CurrentSessionRequest;
use App\Services\Handlers\Session\ExitSessionHandler;
use Illuminate\Http\JsonResponse;

class SessionExitController extends Controller
{
    public function __invoke(
        CurrentSessionRequest $request,
        ExitSessionHandler $exitSession,
    ): JsonResponse {
        $session = $exitSession->handle(
            $request->sessionToken(),
            $request->internalSession(),
        );

        return response()->json([
            'data' => [
                'session_id' => $session->id,
                'status' => $session->status,
            ],
        ]);
    }
}
