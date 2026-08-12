<?php

namespace App\Mcp\Tools;

use App\Integrations\OrderingBackend\OrderingBackendClient;
use App\Integrations\OrderingBackend\OrderingBackendException;
use App\Mcp\Support\BackendSessionContext;
use App\Mcp\Support\CartResponseMapper;
use App\Mcp\Support\OrderIdempotencyKey;
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

#[Name('create_order')]
#[Title('Создать заказ')]
#[Description('Деструктивная операция оформления: создаёт заказ из текущей активной корзины и может передать его во внешнюю систему. Перед вызовом получите актуальную корзину, покажите пользователю товары, количества и авторитетную итоговую сумму бэкенда, затем запросите явное подтверждение. Вызывайте инструмент только после такого подтверждения и передавайте confirmation=true. Не считайте просмотр корзины, вопрос о цене или обсуждение оформления подтверждением. Инструмент получает текущую корзину из бэкенда, формирует внутренний Idempotency-Key и отправляет только delivery_time; confirmation и внутренние идентификаторы в бэкенд не передаются. Запрос создания заказа автоматически не повторяется.')]
#[IsReadOnly(false)]
#[IsDestructive]
#[IsIdempotent(false)]
#[IsOpenWorld]
final class CreateOrderTool extends Tool
{
    public function __construct(
        private OrderingBackendClient $backend,
        private OrderingToolExecutor $executor,
        private CartResponseMapper $cartResponses,
        private OrderResponseMapper $orderResponses,
        private OrderIdempotencyKey $idempotencyKey,
        private OrderingToolInputValidator $inputValidator,
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $this->inputValidator->rejectUnexpected($request, [
            'session_handle',
            'confirmation',
            'delivery_time',
        ]);

        if ($request->get('confirmation') !== true) {
            return Response::error('Пользователь не подтвердил создание заказа.');
        }

        $validated = $this->inputValidator->validate($request, [
            'session_handle' => ['required', 'string'],
            'confirmation' => ['required', 'boolean:strict'],
            'delivery_time' => ['required', 'integer', 'min:0'],
        ]);

        return $this->executor->withSession(
            $validated['session_handle'],
            function (BackendSessionContext $context) use ($validated): Response|ResponseFactory {
                $cartResponse = $this->backend->get(
                    '/api/carts/current',
                    sessionToken: $context->backendSessionToken(),
                );
                $cart = $this->cartResponses->cartFromResponse($cartResponse);

                try {
                    $orderResponse = $this->backend->post(
                        '/api/orders',
                        ['delivery_time' => $validated['delivery_time']],
                        sessionToken: $context->backendSessionToken(),
                        idempotencyKey: $this->idempotencyKey->forCart(
                            $context,
                            $cart['id'],
                        ),
                    );
                } catch (OrderingBackendException $exception) {
                    if ($exception->backendStatus() === 422) {
                        return Response::error(
                            'Не удалось оформить заказ. Возможно, ресторан сейчас не принимает заказы или выбранное время получения недоступно.'
                        );
                    }

                    throw $exception;
                }

                return Response::structured([
                    'order' => $this->orderResponses->orderFromResponse($orderResponse),
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
            'confirmation' => $schema->boolean()
                ->description('Передайте true только после того, как пользователь увидел актуальную корзину и итоговую сумму бэкенда и явно подтвердил создание заказа.')
                ->required(),
            'delivery_time' => $schema->integer()
                ->min(0)
                ->description('Время получения по контракту бэкенда: 0 означает как можно скорее, положительное значение — будущая временная метка Unix для запланированного самовывоза.')
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
