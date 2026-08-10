<?php

namespace App\Http\Controllers\Api\Session;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateSessionRequest;
use App\Http\Responses\SessionCreatedResponse;
use App\Services\Handlers\Session\CreateSessionHandler;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class SessionStoreController extends Controller
{
    public function __invoke(CreateSessionRequest $request, CreateSessionHandler $createSession): SessionCreatedResponse|JsonResponse
    {
        $createdSession = $createSession->handle(
            $request->channel(),
            $request->externalSessionId(),
        );

        if ($createdSession === null) {
            return response()->json([
                'message' => 'Session service unavailable.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return new SessionCreatedResponse($createdSession);
    }
}
