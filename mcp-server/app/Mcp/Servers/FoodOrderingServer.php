<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddCartItemTool;
use App\Mcp\Tools\ClearCartTool;
use App\Mcp\Tools\CreateOrderTool;
use App\Mcp\Tools\GetCartTool;
use App\Mcp\Tools\GetCategoriesTool;
use App\Mcp\Tools\GetCategoryProductsTool;
use App\Mcp\Tools\GetOrCreateCartTool;
use App\Mcp\Tools\GetOrderStatusTool;
use App\Mcp\Tools\GetProductTool;
use App\Mcp\Tools\GetRestaurantContextTool;
use App\Mcp\Tools\RemoveCartItemTool;
use App\Mcp\Tools\SearchProductsTool;
use App\Mcp\Tools\SetCustomerTool;
use App\Mcp\Tools\UpdateCartItemTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Заказ еды с ИИ')]
#[Version('1.0.0')]
#[Instructions('Перед вызовом инструментов, которым требуется сессия, инициализируйте контекст заказа через get_restaurant_context. Считайте результаты инструментов единственным источником фактических данных о ресторане, каталоге, корзине и заказе: не придумывайте товары, цены, доступность, состояние корзины, суммы или статус заказа. Используйте search_products для поиска товаров и получения актуальных данных для рекомендаций. Все изменения корзины выполняйте только соответствующими инструментами корзины; не рассчитывайте цены, промежуточные итоги или итоговые суммы самостоятельно. Для добавления товара передавайте локальный product_id. Для изменения или удаления строки передавайте item_id, равный CartItem.id из массива items, а не product_id. Перед create_order вызовите get_cart, покажите пользователю актуальные товары, количества и авторитетную итоговую сумму бэкенда, затем запросите явное подтверждение создания заказа. Вызывайте create_order с confirmation=true только после явного подтверждения пользователя; просмотр корзины, вопрос о цене и обсуждение оформления не являются подтверждением. Никогда не рассчитывайте окончательную сумму самостоятельно и не утверждайте, что заказ существует, пока create_order не вернул успешное состояние заказа. Не повторяйте create_order автоматически после ошибки или неопределённого результата. Для получения текущего авторитетного состояния заказа используйте get_order_status. Сохраняйте session_handle между вызовами: это непрозрачный идентификатор, который нельзя интерпретировать или изменять. Не переводите и не изменяйте названия и описания, полученные от бэкенда.')]
class FoodOrderingServer extends Server
{
    protected array $tools = [
        GetRestaurantContextTool::class,
        SetCustomerTool::class,
        GetCategoriesTool::class,
        GetCategoryProductsTool::class,
        SearchProductsTool::class,
        GetProductTool::class,
        GetOrCreateCartTool::class,
        GetCartTool::class,
        AddCartItemTool::class,
        UpdateCartItemTool::class,
        RemoveCartItemTool::class,
        ClearCartTool::class,
        CreateOrderTool::class,
        GetOrderStatusTool::class,
    ];

    protected array $resources = [];

    protected array $prompts = [];
}
