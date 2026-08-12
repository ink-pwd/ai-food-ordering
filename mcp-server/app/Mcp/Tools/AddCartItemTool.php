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

#[Name('add_cart_item')]
#[Title('Добавить товар в корзину')]
#[Description('Операция записи: добавляет товар в уже существующую текущую активную корзину. product_id — локальный Product.id из инструментов каталога, а не идентификатор строки корзины, ресторана или Dots. Требует непрозрачный session_handle и quantity не меньше 1. Бэкенд сам проверяет товар и определяет цены и суммы. Инструмент не создаёт корзину автоматически и не заменяет конфликт 409 вызовом update_cart_item.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(false)]
#[IsOpenWorld]
final class AddCartItemTool extends Tool
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
            'product_id',
            'quantity',
        ]);

        $validated = $this->inputValidator->validate($request, [
            'session_handle' => ['required', 'string'],
            'product_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        return $this->executor->withSession(
            $validated['session_handle'],
            function (BackendSessionContext $context) use ($validated): ResponseFactory {
                $response = $this->backend->post(
                    '/api/carts/current/items',
                    [
                        'product_id' => $validated['product_id'],
                        'quantity' => $validated['quantity'],
                    ],
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
            'product_id' => $schema->integer()
                ->min(1)
                ->description('Локальный числовой Product.id, полученный из инструмента каталога. Это не CartItem.id, идентификатор Dots или ресторана.')
                ->required(),
            'quantity' => $schema->integer()
                ->min(1)
                ->description('Количество добавляемого товара. Должно быть целым числом не меньше 1.')
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
