<?php

namespace App\Mcp\Tools;

use App\Integrations\OrderingBackend\OrderingBackendClient;
use App\Mcp\Support\BackendSessionContext;
use App\Mcp\Support\CatalogResponseMapper;
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

#[Name('search_products')]
#[Title('Найти товары')]
#[Description('Ищет товары в актуальном каталоге ресторана через сервис заказа. Используйте инструмент для поиска товара по названию, соответствия описанию пользователя или получения фактических данных для рекомендаций. Требует непрозрачный session_handle, переданный без изменений. Поиск полностью выполняет бэкенд; инструмент не применяет локальный нечёткий поиск, фильтрацию, ранжирование или расчёт цен и ничего не придумывает.')]
#[IsReadOnly]
#[IsOpenWorld]
final class SearchProductsTool extends Tool
{
    public function __construct(
        private OrderingBackendClient $backend,
        private OrderingToolExecutor $executor,
        private CatalogResponseMapper $catalogResponses,
        private OrderingToolInputValidator $inputValidator,
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $this->inputValidator->rejectUnexpected($request, [
            'session_handle',
            'query',
            'limit',
        ]);

        $validated = $this->inputValidator->validate($request, [
            'session_handle' => ['required', 'string'],
            'query' => ['required', 'string', 'min:1', 'max:100'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        return $this->executor->withSession(
            $validated['session_handle'],
            function (BackendSessionContext $context) use ($validated): ResponseFactory {
                $restaurant = rawurlencode($context->restaurantSlug());
                $response = $this->backend->get(
                    "/api/restaurants/{$restaurant}/products/search",
                    [
                        'q' => $validated['query'],
                        'limit' => $validated['limit'] ?? 10,
                    ],
                );

                return Response::structured([
                    'products' => $this->catalogResponses->products($response),
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
            'query' => $schema->string()
                ->min(1)
                ->max(100)
                ->description('Поисковый запрос по товарам, который будет передан в поиск каталога бэкенда. Может содержать Unicode и специальные символы.')
                ->required(),
            'limit' => $schema->integer()
                ->min(1)
                ->max(50)
                ->default(10)
                ->description('Максимальное количество результатов поиска бэкенда. По умолчанию 10, не более 50.'),
        ];
    }

    /** @return array<string, Type> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'products' => $schema->array()
                ->items(OrderingToolOutputSchema::product($schema))
                ->required(),
        ];
    }
}
