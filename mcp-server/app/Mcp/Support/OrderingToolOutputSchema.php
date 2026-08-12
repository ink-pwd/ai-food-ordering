<?php

namespace App\Mcp\Support;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\ObjectType;

final class OrderingToolOutputSchema
{
    public static function restaurant(JsonSchema $schema): ObjectType
    {
        return $schema->object([
            'name' => $schema->string()->required(),
            'slug' => $schema->string()->required(),
            'currency' => $schema->string()->required(),
            'locale' => $schema->string()->required(),
            'timezone' => $schema->string()->required(),
        ])->withoutAdditionalProperties();
    }

    public static function category(JsonSchema $schema): ObjectType
    {
        return $schema->object([
            'id' => $schema->integer()->min(1)->required(),
            'name' => $schema->string()->required(),
            'slug' => $schema->string()->required(),
            'sort_order' => $schema->integer()->min(0)->required(),
        ])->withoutAdditionalProperties();
    }

    public static function product(JsonSchema $schema): ObjectType
    {
        return $schema->object([
            'id' => $schema->integer()->min(1)->required(),
            'name' => $schema->string()->required(),
            'description' => $schema->string()->nullable()->required(),
            'price' => $schema->string()->required(),
            'promotion_price' => $schema->string()->nullable()->required(),
            'currency' => $schema->string()->min(3)->max(3)->required(),
            'image_url' => $schema->string()->nullable()->required(),
            'is_available' => $schema->boolean()->required(),
        ])->withoutAdditionalProperties();
    }

    public static function cartItem(JsonSchema $schema): ObjectType
    {
        return $schema->object([
            'id' => $schema->integer()
                ->min(1)
                ->description('Локальный идентификатор строки корзины CartItem.id. Используйте его как item_id для изменения или удаления этой строки.')
                ->required(),
            'product_id' => $schema->integer()
                ->min(1)
                ->description('Локальный идентификатор товара Product.id. Не используйте его как item_id при изменении или удалении строки.')
                ->required(),
            'name' => $schema->string()
                ->description('Название товара в точности как его вернул бэкенд.')
                ->required(),
            'quantity' => $schema->integer()
                ->min(1)
                ->description('Количество товара, подтверждённое бэкендом.')
                ->required(),
            'unit_price' => $schema->string()
                ->pattern('^-?\\d+\\.\\d{2}$')
                ->description('Цена одной единицы в виде денежной строки бэкенда без пересчёта.')
                ->required(),
            'total' => $schema->string()
                ->pattern('^-?\\d+\\.\\d{2}$')
                ->description('Итог строки корзины в виде денежной строки бэкенда без пересчёта.')
                ->required(),
        ])->withoutAdditionalProperties();
    }

    public static function cart(JsonSchema $schema): ObjectType
    {
        return $schema->object([
            'id' => $schema->integer()
                ->min(1)
                ->description('Локальный идентификатор текущей корзины, назначенный бэкендом.')
                ->required(),
            'status' => $schema->string()
                ->enum(['active', 'checked_out', 'expired'])
                ->description('Статус корзины в точности как его вернул бэкенд.')
                ->required(),
            'currency' => $schema->string()
                ->min(3)
                ->max(3)
                ->description('Код валюты корзины, возвращённый бэкендом.')
                ->required(),
            'subtotal' => $schema->string()
                ->pattern('^-?\\d+\\.\\d{2}$')
                ->description('Промежуточный итог корзины из бэкенда без пересчёта.')
                ->required(),
            'total' => $schema->string()
                ->pattern('^-?\\d+\\.\\d{2}$')
                ->description('Итог корзины из бэкенда без пересчёта.')
                ->required(),
            'expires_at' => $schema->string()
                ->format('date-time')
                ->description('Время истечения корзины, возвращённое бэкендом.')
                ->required(),
            'items' => $schema->array()
                ->items(self::cartItem($schema))
                ->description('Строки текущей корзины; пустой список является допустимым состоянием.')
                ->required(),
        ])->withoutAdditionalProperties();
    }

    public static function orderItem(JsonSchema $schema): ObjectType
    {
        return $schema->object([
            'product_id' => $schema->integer()
                ->min(1)
                ->nullable()
                ->description('Локальный Product.id из снимка заказа; значение может быть null, если локальный товар позже удалён.')
                ->required(),
            'name' => $schema->string()
                ->description('Название товара в точности как его вернул бэкенд.')
                ->required(),
            'quantity' => $schema->integer()
                ->min(1)
                ->description('Количество товара в снимке заказа, возвращённое бэкендом.')
                ->required(),
            'unit_price' => $schema->string()
                ->pattern('^-?\\d+\\.\\d{2}$')
                ->description('Цена единицы из снимка заказа в виде денежной строки бэкенда без пересчёта.')
                ->required(),
            'total' => $schema->string()
                ->pattern('^-?\\d+\\.\\d{2}$')
                ->description('Итог строки заказа в виде денежной строки бэкенда без пересчёта.')
                ->required(),
        ])->withoutAdditionalProperties();
    }

    public static function order(JsonSchema $schema): ObjectType
    {
        return $schema->object([
            'id' => $schema->integer()
                ->min(1)
                ->description('Локальный идентификатор заказа, назначенный бэкендом.')
                ->required(),
            'external_order_id' => $schema->string()
                ->nullable()
                ->description('Идентификатор заказа во внешней системе, если бэкенд уже получил его.')
                ->required(),
            'status' => $schema->string()
                ->enum(['draft', 'creating', 'created', 'awaiting_payment', 'paid', 'failed', 'cancelled'])
                ->description('Текущий статус заказа в точности как его вернул бэкенд.')
                ->required(),
            'receiving_type' => $schema->string()
                ->enum(['pickup'])
                ->description('Способ получения заказа, определённый бэкендом.')
                ->required(),
            'total' => $schema->string()
                ->pattern('^-?\\d+\\.\\d{2}$')
                ->description('Авторитетная итоговая сумма заказа, проверенная Dots и возвращённая бэкендом, без пересчёта.')
                ->required(),
            'currency' => $schema->string()
                ->min(3)
                ->max(3)
                ->description('Код валюты заказа, возвращённый бэкендом.')
                ->required(),
            'items' => $schema->array()
                ->items(self::orderItem($schema))
                ->description('Снимки строк заказа, сохранённые и возвращённые бэкендом.')
                ->required(),
        ])->withoutAdditionalProperties();
    }
}
