<?php

namespace App\Http\Controllers\Api\Cart;

use App\DTO\SessionData;
use App\Http\Controllers\Controller;
use App\Http\Responses\CartResponse;
use App\Services\Handlers\Cart\ShowCurrentCartHandler;
use Illuminate\Http\Request;

class CartShowController extends Controller
{
    public function __invoke(
        Request $request,
        ShowCurrentCartHandler $showCurrentCart,
    ): CartResponse {
        /** @var SessionData $session */
        $session = $request->attributes->get('internal_session');

        return new CartResponse(
            $showCurrentCart->handle($session),
            false,
        );
    }
}
