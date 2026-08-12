<?php

namespace App\Mcp\Tools;

use App\Integrations\OrderingBackend\OrderingBackendClient;
use App\Integrations\OrderingBackend\OrderingBackendException;
use App\Mcp\Support\BackendSessionContext;
use App\Mcp\Support\OrderingToolExecutor;
use App\Mcp\Support\OrderingToolInputValidator;
use App\Mcp\Support\OrderingToolOutputSchema;
use App\Mcp\Support\SessionContextHandle;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_restaurant_context')]
#[Title('Инициализировать контекст заказа')]
#[Description('Инициализирует новый контекст заказа для ChatGPT. Вызывайте этот инструмент перед операциями сессии или каталога. Инструмент не принимает идентификаторы ресторана или аккаунта: ресторан выбирает бэкенд. Возвращает подтверждённые бэкендом данные ресторана и непрозрачный session_handle, который нужно передавать без изменений.')]
#[IsReadOnly]
#[IsOpenWorld]
final class GetRestaurantContextTool extends Tool
{
    public function __construct(
        private OrderingBackendClient $backend,
        private SessionContextHandle $sessionContextHandle,
        private OrderingToolExecutor $executor,
        private OrderingToolInputValidator $inputValidator,
        private ValidationFactory $validator,
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $this->inputValidator->rejectUnexpected($request, []);

        return $this->executor->execute(function (): ResponseFactory {
            $response = $this->backend->post('/api/sessions', [
                'channel' => 'chatgpt',
                'external_session_id' => Str::uuid()->toString(),
            ]);

            [$context, $restaurant] = $this->sessionContextFromResponse($response);

            return Response::structured([
                'session_handle' => $this->sessionContextHandle->issue($context),
                'restaurant' => $restaurant,
            ]);
        });
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /** @return array<string, Type> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'session_handle' => $schema->string()
                ->description('Непрозрачный session_handle для последующих вызовов.')
                ->required(),
            'restaurant' => OrderingToolOutputSchema::restaurant($schema)->required(),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $response
     * @return array{BackendSessionContext, array{name: string, slug: string, currency: string, locale: string, timezone: string}}
     */
    private function sessionContextFromResponse(array $response): array
    {
        $validation = $this->validator->make($response, [
            'data' => ['required', 'array'],
            'data.session_id' => ['required', 'string'],
            'data.session_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'data.expires_at' => ['required', 'string'],
            'data.restaurant' => ['required', 'array'],
            'data.restaurant.name' => ['required', 'string'],
            'data.restaurant.slug' => ['required', 'string'],
            'data.restaurant.currency' => ['required', 'string'],
            'data.restaurant.locale' => ['required', 'string'],
            'data.restaurant.timezone' => ['required', 'string'],
        ]);

        if ($validation->fails()) {
            throw OrderingBackendException::invalidResponse();
        }

        $data = $response['data'];

        try {
            $expiresAt = CarbonImmutable::parse($data['expires_at']);
        } catch (InvalidFormatException) {
            throw OrderingBackendException::invalidResponse();
        }

        if ($expiresAt->lessThanOrEqualTo(CarbonImmutable::now())) {
            throw OrderingBackendException::invalidResponse();
        }

        $restaurant = [
            'name' => $data['restaurant']['name'],
            'slug' => $data['restaurant']['slug'],
            'currency' => $data['restaurant']['currency'],
            'locale' => $data['restaurant']['locale'],
            'timezone' => $data['restaurant']['timezone'],
        ];

        return [
            new BackendSessionContext(
                backendSessionId: $data['session_id'],
                backendSessionToken: $data['session_token'],
                restaurantSlug: $restaurant['slug'],
                expiresAt: $expiresAt,
            ),
            $restaurant,
        ];
    }
}
