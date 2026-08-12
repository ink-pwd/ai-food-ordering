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

#[Name('get_category_products')]
#[Title('Получить товары категории')]
#[Description('Возвращает актуальные доступные товары указанной категории ресторана. Требует непрозрачный session_handle, переданный без изменений, и локальный category_id, полученный из get_categories. Ресторан извлекается только из доверенного контекста; цены и доступность возвращаются без пересчёта.')]
#[IsReadOnly]
#[IsOpenWorld]
final class GetCategoryProductsTool extends Tool
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
            'category_id',
        ]);

        $validated = $this->inputValidator->validate($request, [
            'session_handle' => ['required', 'string'],
            'category_id' => ['required', 'integer', 'min:1'],
        ]);

        return $this->executor->withSession(
            $validated['session_handle'],
            function (BackendSessionContext $context) use ($validated): ResponseFactory {
                $restaurant = rawurlencode($context->restaurantSlug());
                $response = $this->backend->get(
                    "/api/restaurants/{$restaurant}/categories/{$validated['category_id']}/products",
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
            'category_id' => $schema->integer()
                ->min(1)
                ->description('Локальный числовой category_id, полученный из get_categories. Это не идентификатор Dots или ресторана.')
                ->required(),
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
