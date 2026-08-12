<?php

use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Laravel\Mcp\Facades\Mcp;

it('registers the food ordering server for web and local transports', function () {
    $webServer = Mcp::getWebServer('mcp/food-ordering');

    expect($webServer)
        ->toBeInstanceOf(Route::class)
        ->and($webServer?->uri())->toBe('mcp/food-ordering')
        ->and($webServer?->methods())->toContain('POST')
        ->and(is_callable(Mcp::getLocalServer('food-ordering')))->toBeTrue();
});

it('initializes the food ordering server over its web endpoint', function () {
    $response = $this->postJson('/mcp/food-ordering', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => [
                'name' => 'foundation-test',
                'version' => '1.0.0',
            ],
        ],
    ]);

    $response
        ->assertOk()
        ->assertHeader('MCP-Session-Id')
        ->assertJsonPath('jsonrpc', '2.0')
        ->assertJsonPath('id', 1)
        ->assertJsonPath('result.protocolVersion', '2025-06-18')
        ->assertJsonPath('result.serverInfo.name', 'Заказ еды с ИИ')
        ->assertJsonPath('result.serverInfo.version', '1.0.0');

    $instructions = $response->json('result.instructions');

    expect($instructions)
        ->toBeString()
        ->toContain('get_restaurant_context')
        ->toContain('единственным источником фактических данных')
        ->toContain('не придумывайте товары, цены, доступность, состояние корзины')
        ->toContain('search_products')
        ->toContain('не рассчитывайте цены, промежуточные итоги или итоговые суммы самостоятельно')
        ->toContain('локальный product_id')
        ->toContain('item_id, равный CartItem.id')
        ->toContain('а не product_id')
        ->toContain('Перед create_order вызовите get_cart')
        ->toContain('покажите пользователю актуальные товары, количества и авторитетную итоговую сумму бэкенда')
        ->toContain('confirmation=true только после явного подтверждения пользователя')
        ->toContain('не утверждайте, что заказ существует, пока create_order не вернул успешное состояние заказа')
        ->toContain('Не повторяйте create_order автоматически')
        ->toContain('get_order_status')
        ->toContain('session_handle')
        ->toContain('непрозрачный идентификатор');

    expect($response->headers->get('MCP-Session-Id'))
        ->toBeString()
        ->not->toBeEmpty();
});

it('discovers all catalog cart and order tools with safe schemas and annotations over MCP', function () {
    $initializeResponse = $this->postJson('/mcp/food-ordering', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => [
                'name' => 'discovery-test',
                'version' => '1.0.0',
            ],
        ],
    ])->assertOk();

    $response = $this
        ->withHeader('MCP-Session-Id', (string) $initializeResponse->headers->get('MCP-Session-Id'))
        ->postJson('/mcp/food-ordering', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => (object) [],
        ]);

    $response
        ->assertOk()
        ->assertJsonPath('jsonrpc', '2.0')
        ->assertJsonPath('id', 2)
        ->assertJsonCount(14, 'result.tools');

    /** @var Collection<string, array<string, mixed>> $tools */
    $tools = collect($response->json('result.tools'))->keyBy('name');

    expect($tools->keys()->all())->toBe([
        'get_restaurant_context',
        'set_customer',
        'get_categories',
        'get_category_products',
        'search_products',
        'get_product',
        'get_or_create_cart',
        'get_cart',
        'add_cart_item',
        'update_cart_item',
        'remove_cart_item',
        'clear_cart',
        'create_order',
        'get_order_status',
    ]);

    expect($tools->map(fn (array $tool): string => $tool['title'])->all())->toBe([
        'get_restaurant_context' => 'Инициализировать контекст заказа',
        'set_customer' => 'Сохранить контактные данные',
        'get_categories' => 'Получить категории ресторана',
        'get_category_products' => 'Получить товары категории',
        'search_products' => 'Найти товары',
        'get_product' => 'Получить товар',
        'get_or_create_cart' => 'Получить или создать корзину',
        'get_cart' => 'Получить текущую корзину',
        'add_cart_item' => 'Добавить товар в корзину',
        'update_cart_item' => 'Изменить количество в корзине',
        'remove_cart_item' => 'Удалить строку из корзины',
        'clear_cart' => 'Очистить корзину',
        'create_order' => 'Создать заказ',
        'get_order_status' => 'Получить статус текущего заказа',
    ]);

    $forbiddenArguments = [
        'restaurant',
        'restaurant_slug',
        'restaurant_id',
        'account_id',
        'cart_id',
        'session_id',
        'order_id',
        'customer_id',
        'idempotency_key',
        'Idempotency-Key',
        'external_product_id',
        'external_order_id',
        'price',
        'unit_price',
        'subtotal',
        'total',
        'currency',
        'status',
        'receiving_type',
        'payment_type',
        'customer',
        'items',
        'backend_session_token',
        'session_token',
        'internal_api_token',
    ];

    foreach ($tools as $tool) {
        expect($tool['description'])->toBeString()->not->toBeEmpty()
            ->and(preg_match('/[А-Яа-яЁё]/u', $tool['description']))->toBe(1)
            ->and($tool)->toHaveKey('outputSchema')
            ->and(array_intersect(
                $forbiddenArguments,
                array_keys((array) $tool['inputSchema']['properties']),
            ))->toBe([]);

        foreach ((array) $tool['inputSchema']['properties'] as $property) {
            expect($property['description'] ?? null)->toBeString()
                ->and(preg_match('/[А-Яа-яЁё]/u', $property['description']))->toBe(1);
        }
    }

    foreach ($tools->except('create_order') as $tool) {
        expect((array) $tool['inputSchema']['properties'])
            ->not->toHaveKey('confirmation')
            ->not->toHaveKey('delivery_time');
    }

    expect((array) $tools['get_restaurant_context']['inputSchema']['properties'])->toBe([])
        ->and($tools['get_restaurant_context']['annotations']['readOnlyHint'])->toBeTrue()
        ->and($tools['get_restaurant_context']['description'])->toContain('ресторан выбирает бэкенд')
        ->and($tools['get_restaurant_context']['outputSchema']['properties']['session_handle']['description'])
        ->toBe('Непрозрачный session_handle для последующих вызовов.')
        ->and($tools['set_customer']['annotations'])->toMatchArray([
            'readOnlyHint' => false,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => true,
        ])
        ->and($tools['set_customer']['description'])->toContain('Операция записи')
        ->and(array_keys($tools['get_categories']['inputSchema']['properties']))->toBe(['session_handle'])
        ->and(array_keys($tools['get_category_products']['inputSchema']['properties']))->toBe([
            'session_handle',
            'category_id',
        ])
        ->and(array_keys($tools['get_product']['inputSchema']['properties']))->toBe([
            'session_handle',
            'product_id',
        ])
        ->and(array_keys($tools['search_products']['inputSchema']['properties']))->toBe([
            'session_handle',
            'query',
            'limit',
        ])
        ->and($tools['search_products']['inputSchema']['properties']['limit'])->toMatchArray([
            'type' => 'integer',
            'minimum' => 1,
            'maximum' => 50,
            'default' => 10,
        ])
        ->and($tools['search_products']['description'])->toContain('рекомендаций')
        ->and($tools['get_product']['description'])->toContain('наличие товара в ответе не означает его доступность')
        ->and(array_keys($tools['get_or_create_cart']['inputSchema']['properties']))->toBe(['session_handle'])
        ->and($tools['get_or_create_cart']['annotations'])->toMatchArray([
            'readOnlyHint' => false,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => true,
        ])
        ->and($tools['get_or_create_cart']['description'])->toContain('вернуть текущую активную корзину или создать новую')
        ->and(array_keys($tools['get_cart']['inputSchema']['properties']))->toBe(['session_handle'])
        ->and($tools['get_cart']['annotations'])->toMatchArray([
            'readOnlyHint' => true,
            'openWorldHint' => true,
        ])
        ->and($tools['get_cart']['description'])->toContain('не создаёт корзину автоматически')
        ->and(array_keys($tools['add_cart_item']['inputSchema']['properties']))->toBe([
            'session_handle',
            'product_id',
            'quantity',
        ])
        ->and($tools['add_cart_item']['annotations'])->toMatchArray([
            'readOnlyHint' => false,
            'destructiveHint' => false,
            'idempotentHint' => false,
            'openWorldHint' => true,
        ])
        ->and($tools['add_cart_item']['description'])->toContain('product_id — локальный Product.id')
        ->and(array_keys($tools['update_cart_item']['inputSchema']['properties']))->toBe([
            'session_handle',
            'item_id',
            'quantity',
        ])
        ->and($tools['update_cart_item']['annotations'])->toMatchArray([
            'readOnlyHint' => false,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => true,
        ])
        ->and($tools['update_cart_item']['description'])->toContain('item_id — локальный CartItem.id')
        ->and(array_keys($tools['remove_cart_item']['inputSchema']['properties']))->toBe([
            'session_handle',
            'item_id',
        ])
        ->and($tools['remove_cart_item']['annotations'])->toMatchArray([
            'readOnlyHint' => false,
            'destructiveHint' => true,
            'idempotentHint' => true,
            'openWorldHint' => true,
        ])
        ->and($tools['remove_cart_item']['description'])->toContain('Деструктивная операция записи')
        ->and(array_keys($tools['clear_cart']['inputSchema']['properties']))->toBe(['session_handle'])
        ->and($tools['clear_cart']['annotations'])->toMatchArray([
            'readOnlyHint' => false,
            'destructiveHint' => true,
            'idempotentHint' => true,
            'openWorldHint' => true,
        ])
        ->and($tools['clear_cart']['description'])->toContain('безвозвратно удаляет все строки')
        ->and(array_keys($tools['create_order']['inputSchema']['properties']))->toBe([
            'session_handle',
            'confirmation',
            'delivery_time',
        ])
        ->and($tools['create_order']['inputSchema']['required'])->toBe([
            'session_handle',
            'confirmation',
            'delivery_time',
        ])
        ->and($tools['create_order']['inputSchema']['properties']['confirmation']['type'])->toBe('boolean')
        ->and($tools['create_order']['inputSchema']['properties']['delivery_time'])->toMatchArray([
            'type' => 'integer',
            'minimum' => 0,
        ])
        ->and($tools['create_order']['annotations'])->toMatchArray([
            'readOnlyHint' => false,
            'destructiveHint' => true,
            'idempotentHint' => false,
            'openWorldHint' => true,
        ])
        ->and($tools['create_order']['description'])->toContain('только после такого подтверждения')
        ->and($tools['create_order']['description'])->toContain('confirmation=true')
        ->and($tools['create_order']['description'])->toContain('автоматически не повторяется')
        ->and(array_keys($tools['get_order_status']['inputSchema']['properties']))->toBe(['session_handle'])
        ->and($tools['get_order_status']['annotations'])->toMatchArray([
            'readOnlyHint' => false,
            'destructiveHint' => false,
            'idempotentHint' => false,
            'openWorldHint' => true,
        ])
        ->and($tools['get_order_status']['description'])->toContain('не является операцией только чтения')
        ->and($tools['get_order_status']['description'])->toContain('не опрашивайте Dots напрямую');

    foreach ([
        'get_or_create_cart',
        'get_cart',
        'add_cart_item',
        'update_cart_item',
        'remove_cart_item',
        'clear_cart',
    ] as $cartToolName) {
        $cartSchema = $tools[$cartToolName]['outputSchema']['properties']['cart'];

        expect(array_keys($cartSchema['properties']))->toBe([
            'id',
            'status',
            'currency',
            'subtotal',
            'total',
            'expires_at',
            'items',
        ])->and(array_keys($cartSchema['properties']['items']['items']['properties']))->toBe([
            'id',
            'product_id',
            'name',
            'quantity',
            'unit_price',
            'total',
        ])->and($cartSchema['properties']['items']['items']['properties'])->not->toHaveKey('external_product_id');
    }

    foreach (['create_order', 'get_order_status'] as $orderToolName) {
        $orderSchema = $tools[$orderToolName]['outputSchema']['properties']['order'];
        $orderProperties = $orderSchema['properties'];
        $orderItemProperties = $orderProperties['items']['items']['properties'];

        expect(array_keys($orderProperties))->toBe([
            'id',
            'external_order_id',
            'status',
            'receiving_type',
            'total',
            'currency',
            'items',
        ])->and(array_keys($orderItemProperties))->toBe([
            'product_id',
            'name',
            'quantity',
            'unit_price',
            'total',
        ])->and($orderProperties)->not->toHaveKeys([
            'failure_message',
            'idempotency_key',
            'session_id',
            'restaurant_id',
        ])->and($orderItemProperties)->not->toHaveKey('external_product_id');

        foreach ([$orderProperties, $orderItemProperties] as $properties) {
            foreach ($properties as $property) {
                expect($property['description'] ?? null)->toBeString()
                    ->and(preg_match('/[А-Яа-яЁё]/u', $property['description']))->toBe(1);
            }
        }
    }
});
