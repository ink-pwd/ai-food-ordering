<?php

namespace App\Http\Controllers\Api\Session;

use App\Http\Controllers\Controller;
use App\Http\Requests\VerifySessionOtpRequest;
use App\Http\Responses\SessionContactResponse;
use App\Services\Handlers\Session\VerifySessionOtpHandler;

class SessionOtpVerifyController extends Controller
{
    public function __invoke(VerifySessionOtpRequest $request, VerifySessionOtpHandler $verifyOtp): SessionContactResponse
    {
        $session = $verifyOtp->handle(
            $request->sessionToken(),
            $request->internalSession(),
            $request->code(),
        );

        return new SessionContactResponse($session);
    }
}
