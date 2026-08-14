<?php

namespace App\Http\Controllers\Api\Session;

use App\Http\Controllers\Controller;
use App\Http\Requests\CurrentSessionRequest;
use App\Services\Handlers\Fulfillment\FulfillmentOptionsHandler;
use Illuminate\Http\JsonResponse;

class SessionFulfillmentOptionsController extends Controller
{
    public function __invoke(CurrentSessionRequest $request, FulfillmentOptionsHandler $options): JsonResponse
    {
        return response()->json([
            'data' => $options->handle($request->internalSession()),
        ]);
    }
}
