<?php

namespace App\Mcp\Tools;

use App\Integrations\OrderingBackend\OrderingBackendClient;
use App\Mcp\Support\BackendSessionContext;
use App\Mcp\Support\OrderingToolExecutor;
use App\Mcp\Support\OrderingToolInputValidator;
use App\Mcp\Support\OrderingToolOutputSchema;
use App\Mcp\Support\OrderResponseMapper;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_order_status')]
#[Title('Получить статус текущего заказа')]
#[Description('Возвращает текущее авторитетное состояние последнего заказа сессии из бэкенда. Требует непрозрачный session_handle, переданный без изменений. Бэкенд может обратиться к Dots и сохранить обновлённый статус, поэтому инструмент не является операцией только чтения, но не выполняет деструктивных изменений. Не придумывайте статус и не опрашивайте Dots напрямую.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(false)]
#[IsOpenWorld]
final class GetOrderStatusTool extends Tool
{
    public function __construct(
        private OrderingBackendClient $backend,
        private OrderingToolExecutor $executor,
        private OrderResponseMapper $orderResponses,
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
                    '/api/orders/current',
                    sessionToken: $context->backendSessionToken(),
                );

                return Response::structured([
                    'order' => $this->orderResponses->orderFromResponse($response),
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
            'order' => OrderingToolOutputSchema::order($schema)->required(),
        ];
    }
}
