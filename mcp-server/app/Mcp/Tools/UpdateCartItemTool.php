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

#[Name('update_cart_item')]
#[Title('Изменить количество в корзине')]
#[Description('Операция записи: устанавливает новое количество существующей строки текущей активной корзины. item_id — локальный CartItem.id из ответа корзины, а не product_id. Требует непрозрачный session_handle и quantity не меньше 1. Только quantity управляется клиентом; бэкенд проверяет принадлежность строки и товара и заново определяет цены и суммы.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld]
final class UpdateCartItemTool extends Tool
{
    public function __construct(
        private OrderingBackendClient $backend,
        private OrderingToolExecutor $executor,
        private CartResponseMapper $cartResponses,
        private OrderingToolInputValidator $inputValidator,
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $this->inputValidator->rejectUnexpected($request, [
            'session_handle',
            'item_id',
            'quantity',
        ]);

        $validated = $this->inputValidator->validate($request, [
            'session_handle' => ['required', 'string'],
            'item_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        return $this->executor->withSession(
            $validated['session_handle'],
            function (BackendSessionContext $context) use ($validated): ResponseFactory {
                $response = $this->backend->patch(
                    "/api/carts/current/items/{$validated['item_id']}",
                    ['quantity' => $validated['quantity']],
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
            'item_id' => $schema->integer()
                ->min(1)
                ->description('Локальный числовой CartItem.id из массива items ответа корзины. Это не product_id и не идентификатор Dots.')
                ->required(),
            'quantity' => $schema->integer()
                ->min(1)
                ->description('Новое количество строки корзины. Должно быть целым числом не меньше 1.')
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
