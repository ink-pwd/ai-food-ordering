<?php

namespace App\Http\Controllers\Api\Cart;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateCartRequest;
use App\Http\Responses\CartResponse;
use App\Services\Handlers\Cart\CreateCartHandler;

class CartStoreController extends Controller
{
    public function __invoke(CreateCartRequest $request, CreateCartHandler $createCart): CartResponse
    {
        $result = $createCart->handle($request->internalSession());

        return new CartResponse($result['cart'], $result['created']);
    }
}
