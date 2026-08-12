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
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_or_create_cart')]
#[Title('Получить или создать корзину')]
#[Description('Операция записи: просит бэкенд вернуть текущую активную корзину или создать новую пустую активную корзину, если текущей нет. Требует непрозрачный session_handle, переданный без изменений. Жизненным циклом, статусом и выбором корзины управляет только бэкенд; инструмент не принимает данные корзины и не работает с историческими корзинами.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld]
final class GetOrCreateCartTool extends Tool
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
                $response = $this->backend->post(
                    '/api/carts',
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
