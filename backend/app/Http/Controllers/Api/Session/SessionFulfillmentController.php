<?php

namespace App\Http\Controllers\Api\Session;

use App\Http\Controllers\Controller;
use App\Http\Requests\SelectFulfillmentTypeRequest;
use App\Services\Handlers\Fulfillment\SelectFulfillmentTypeHandler;
use Illuminate\Http\JsonResponse;

class SessionFulfillmentController extends Controller
{
    public function __invoke(
        SelectFulfillmentTypeRequest $request,
        SelectFulfillmentTypeHandler $selectFulfillment,
    ): JsonResponse {
        $session = $selectFulfillment->handle(
            $request->sessionToken(),
            $request->internalSession(),
            $request->fulfillmentType(),
        );

        return response()->json([
            'data' => [
                'session_id' => $session->id,
                'fulfillment' => $session->fulfillment,
            ],
        ]);
    }
}
