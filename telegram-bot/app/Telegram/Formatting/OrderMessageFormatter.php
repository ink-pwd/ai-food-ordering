<?php

namespace App\Telegram\Formatting;

final class OrderMessageFormatter
{
    /**
     * @param  array{id: int, external_order_id: ?string, status: string, failure_message: ?string, receiving_type: string, total: string, currency: string, items: list<array{product_id: int, external_product_id: string, name: string, quantity: int, unit_price: string, total: string}>}  $order
     */
    public function format(array $order): string
    {
        $sections = [
            "Заказ #{$order['id']}",
            implode("\n", [
                "Статус: {$this->status($order['status'])}",
                "Получение: {$this->receivingType($order['receiving_type'])}",
                "Итого: {$order['total']} {$order['currency']}",
            ]),
        ];

        foreach ($order['items'] as $item) {
            $sections[] = implode("\n", [
                $item['name'],
                "{$item['quantity']} × {$item['unit_price']} {$order['currency']} = {$item['total']} {$order['currency']}",
            ]);
        }

        if ($order['status'] === 'failed') {
            $sections[] = 'Не удалось создать заказ. Проверьте статус позже или обратитесь в поддержку.';
        }

        return implode("\n\n", $sections);
    }

    private function status(string $status): string
    {
        return match ($status) {
            'draft' => 'Черновик',
            'creating' => 'Создаётся',
            'created' => 'Создан',
            'awaiting_payment' => 'Ожидает оплаты',
            'paid' => 'Оплачен',
            'failed' => 'Ошибка',
            'cancelled' => 'Отменён',
            default => $status,
        };
    }

    private function receivingType(string $receivingType): string
    {
        return match ($receivingType) {
            'pickup' => 'Самовывоз',
            'delivery' => 'Доставка',
            default => $receivingType,
        };
    }
}
