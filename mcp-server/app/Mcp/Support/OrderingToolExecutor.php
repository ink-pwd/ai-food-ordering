<?php

namespace App\Mcp\Support;

use App\Integrations\OrderingBackend\OrderingBackendException;
use Closure;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use SensitiveParameter;

final readonly class OrderingToolExecutor
{
    public function __construct(private SessionContextHandle $sessionContextHandle) {}

    /**
     * @param  Closure(): (Response|ResponseFactory)  $operation
     */
    public function execute(Closure $operation): Response|ResponseFactory
    {
        try {
            return $operation();
        } catch (InvalidSessionContextException|OrderingBackendException $exception) {
            return Response::error($exception->getMessage());
        }
    }

    /**
     * @param  Closure(BackendSessionContext): (Response|ResponseFactory)  $operation
     */
    public function withSession(
        #[SensitiveParameter] string $sessionHandle,
        Closure $operation,
    ): Response|ResponseFactory {
        return $this->execute(
            fn (): Response|ResponseFactory => $operation(
                $this->sessionContextHandle->restore($sessionHandle),
            ),
        );
    }
}
