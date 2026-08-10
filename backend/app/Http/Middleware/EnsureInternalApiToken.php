<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInternalApiToken
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expectedToken = config('services.internal.token');
        $providedToken = $request->headers->get('X-Internal-Api-Token');

        if (! is_string($expectedToken) || $expectedToken === '') {
            return $this->unauthenticated();
        }

        if (! is_string($providedToken) || $providedToken === '') {
            return $this->unauthenticated();
        }

        if (! hash_equals($expectedToken, $providedToken)) {
            return $this->unauthenticated();
        }

        return $next($request);
    }

    private function unauthenticated(): JsonResponse
    {
        return response()->json([
            'message' => 'Unauthenticated.',
        ], Response::HTTP_UNAUTHORIZED);
    }
}
