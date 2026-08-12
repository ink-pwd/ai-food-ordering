<?php

namespace App\Mcp\Tools;

use App\Integrations\OrderingBackend\OrderingBackendClient;
use App\Mcp\Support\BackendSessionContext;
use App\Mcp\Support\CartResponseMapper;
use App\Mcp\Support\OrderingToolExecutor;
use App\Mcp\Support\OrderingToolInputValidator;
use App\Mcp\Support\OrderingToolOutputSchema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_cart')]
#[Title('Получить текущую корзину')]
#[Description('Возвращает фактическое состояние текущей активной корзины из бэкенда. Требует непрозрачный session_handle, переданный без изменений. Это операция только чтения: если текущей корзины нет, инструмент возвращает безопасную ошибку и не создаёт корзину автоматически. Цены, суммы и статус возвращаются без пересчёта.')]
#[IsReadOnly]
#[IsOpenWorld]
final class GetCartTool extends Tool
{
    public function __construct(
        private OrderingBackendClient $backend,
        private OrderingToolExecutor $executor,
        private CartResponseMapper $cartResponses,
        private OrderingToolInputValidator $inputValidator,
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $this->inputValidator->rejectUnexpected($request, ['session_handle']);

        $validated = $this->inputValidator->validate($request, [
            'session_handle' => ['required', 'string'],
        ]);

        return $this->executor->withSession(
            $validated['session_handle'],
            function (BackendSessionContext $context): ResponseFactory {
                $response = $this->backend->get(
                    '/api/carts/current',
                    sessionToken: $context->backendSessionToken(),
                );

                return Response::structured([
                    'cart' => $this->cartResponses->cartFromResponse($response),
                ]);
            },
        );
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'session_handle' => $schema->string()
                ->description('Непрозрачный session_handle, возвращённый get_restaurant_context. Передавайте его без изменений; не пытайтесь интерпретировать или изменять.')
                ->required(),
        ];
    }

    /** @return array<string, Type> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'cart' => OrderingToolOutputSchema::cart($schema)->required(),
        ];
    }
}
