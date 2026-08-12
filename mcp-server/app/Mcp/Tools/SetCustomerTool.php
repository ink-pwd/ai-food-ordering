<?php

namespace App\Mcp\Tools;

use App\Integrations\OrderingBackend\OrderingBackendClient;
use App\Integrations\OrderingBackend\OrderingBackendException;
use App\Mcp\Support\BackendSessionContext;
use App\Mcp\Support\OrderingToolExecutor;
use App\Mcp\Support\OrderingToolInputValidator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
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

#[Name('set_customer')]
#[Title('Сохранить контактные данные')]
#[Description('Операция записи: сохраняет или заменяет имя и телефон клиента в текущем контексте заказа. Требует непрозрачный session_handle, переданный без изменений. Нормализацией и проверкой телефона управляет бэкенд; инструмент сам не помечает телефон как подтверждённый.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld]
final class SetCustomerTool extends Tool
{
    public function __construct(
        private OrderingBackendClient $backend,
        private OrderingToolExecutor $executor,
        private OrderingToolInputValidator $inputValidator,
        private ValidationFactory $validator,
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $this->inputValidator->rejectUnexpected($request, [
            'session_handle',
            'name',
            'phone',
        ]);

        $validated = $this->inputValidator->validate($request, [
            'session_handle' => ['required', 'string'],
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:32'],
        ]);

        return $this->executor->withSession(
            $validated['session_handle'],
            function (BackendSessionContext $context) use ($validated): ResponseFactory {
                $response = $this->backend->put(
                    '/api/sessions/current/contact',
                    [
                        'name' => $validated['name'],
                        'phone' => $validated['phone'],
                    ],
                    sessionToken: $context->backendSessionToken(),
                );

                return Response::structured([
                    'contact' => $this->safeContactFromResponse($response),
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
            'name' => $schema->string()
                ->min(1)
                ->max(100)
                ->description('Имя клиента, которое нужно сохранить для текущего контекста заказа.')
                ->required(),
            'phone' => $schema->string()
                ->min(1)
                ->max(32)
                ->description('Телефон клиента. Передайте введённое пользователем значение; сервис заказа сам проверит и нормализует его.')
                ->required(),
        ];
    }

    /** @return array<string, Type> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'contact' => $schema->object([
                'name' => $schema->string()->required(),
                'phone_verified' => $schema->boolean()->required(),
            ])->withoutAdditionalProperties()->required(),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $response
     * @return array{name: string, phone_verified: bool}
     */
    private function safeContactFromResponse(array $response): array
    {
        $validation = $this->validator->make($response, [
            'data' => ['required', 'array'],
            'data.session_id' => ['required', 'string'],
            'data.contact' => ['required', 'array'],
            'data.contact.name' => ['required', 'string'],
            'data.contact.phone' => ['required', 'string'],
            'data.contact.phone_verified' => ['required', 'boolean'],
        ]);

        if ($validation->fails()) {
            throw OrderingBackendException::invalidResponse();
        }

        return [
            'name' => $response['data']['contact']['name'],
            'phone_verified' => $response['data']['contact']['phone_verified'],
        ];
    }
}
