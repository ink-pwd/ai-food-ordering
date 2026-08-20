<?php

namespace App\Http\Controllers\Api\Session;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSessionPaymentRequest;
use App\Http\Responses\SessionPaymentResponse;
use App\Services\Handlers\Session\UpdateSessionPaymentHandler;

final class SessionPaymentController extends Controller
{
    public function __invoke(
        UpdateSessionPaymentRequest $request,
        UpdateSessionPaymentHandler $updateSessionPaymentHandler,
    ): SessionPaymentResponse {
        $updatedSession = $updateSessionPaymentHandler->handle(
            session: $request->internalSession(),
            plainToken: (string) $request->header('X-Session-Token'),
            paymentType: $request->paymentType(),
        );

        return new SessionPaymentResponse($updatedSession);
    }
}
