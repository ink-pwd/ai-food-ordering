<?php

namespace App\Http\Controllers\Api\Session;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSessionContactRequest;
use App\Http\Responses\SessionContactResponse;
use App\Services\Handlers\Session\UpdateSessionContactHandler;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class SessionContactController extends Controller
{
    public function __invoke(UpdateSessionContactRequest $request, UpdateSessionContactHandler $updateContact): SessionContactResponse|JsonResponse
    {
        $session = $updateContact->handle(
            $request->sessionToken(),
            $request->contactName(),
            $request->normalizedPhone(),
        );

        if ($session === null) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return new SessionContactResponse($session);
    }
}
