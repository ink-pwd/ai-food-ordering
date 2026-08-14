<?php

namespace App\Http\Controllers\Api\Session;

use App\Http\Controllers\Controller;
use App\Http\Requests\CurrentSessionRequest;
use App\Services\Handlers\Session\RequestSessionOtpHandler;
use Illuminate\Http\JsonResponse;

class SessionOtpController extends Controller
{
    public function __invoke(CurrentSessionRequest $request, RequestSessionOtpHandler $requestOtp): JsonResponse
    {
        $result = $requestOtp->handle($request->internalSession());

        return response()->json([
            'data' => $result,
        ]);
    }
}
