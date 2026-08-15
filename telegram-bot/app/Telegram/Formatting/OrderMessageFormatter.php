<?php

namespace App\Telegram\Formatting;

final class OrderMessageFormatter
{
    /**
     * @param  array{id: int, external_order_id: ?string, status: string, failure_message: ?string, receiving_type: string, payment_type: int, fulfillment: array<string, mixed>, total: string, currency: string, payment: array{status: string, checkout_url: ?string, payment_received_at: ?string, qr_ready: bool}, items: list<array{product_id: ?int, external_product_id: string, name: string, quantity: int, unit_price: string, total: string}>}  $order
     */
    public function format(array $order): string
    {
        $sections = [
            $this->statusHeading($order['status']),
            implode("\n", array_filter([
                "Замовлення #{$order['id']}",
                'Статус: '.$this->status($order['status']),
                'Отримання: '.$this->receivingType($order['receiving_type']),
                'Оплата: '.$this->paymentType($order['payment_type']),
                $this->fulfillmentSummary($order['fulfillment']),
                "Разом: {$order['total']} {$order['currency']}",
            ])),
        ];

        foreach ($order['items'] as $item) {
            $sections[] = implode("\n", [
                $item['name'],
                "{$item['quantity']} × {$item['unit_price']} {$order['currency']} = {$item['total']} {$order['currency']}",
            ]);
        }

        if ($order['status'] === 'failed') {
            $sections[] = '❌ Не вдалося створити замовлення.';
        }

        return implode("\n\n", $sections);
    }

    /**
     * @param  array{status: string, checkout_url: ?string, payment_received_at: ?string, http_status?: int}  $payment
     */
    public function payment(array $payment, ?string $qrNotice = null): string
    {
        $lines = [];

        if ($payment['payment_received_at'] !== null) {
            $lines[] = '✅ Оплату отримано.';
        } elseif ($payment['status'] === 'ready') {
            $lines[] = '💳 Оплата готова.';
            $lines[] = 'Натисніть кнопку нижче, щоб перейти до захищеної сторінки оплати.';
        } else {
            $lines[] = '⏳ Платіжні дані ще готуються.';
        }

        if ($qrNotice !== null) {
            $lines[] = $qrNotice;
        }

        return implode("\n\n", $lines);
    }

    private function statusHeading(string $status): string
    {
        return match ($status) {
            'creating' => '⏳ Замовлення створюється.',
            'created' => '✅ Замовлення створено.',
            'failed' => '❌ Не вдалося створити замовлення.',
            default => '🧾 Замовлення',
        };
    }

    private function status(string $status): string
    {
        return match ($status) {
            'draft' => 'Чернетка',
            'creating' => 'Створюється',
            'created' => 'Створено',
            'failed' => 'Помилка',
            default => $status,
        };
    }

    private function receivingType(string $receivingType): string
    {
        return match ($receivingType) {
            'pickup' => '🏃 Самовивіз',
            'delivery' => '🚚 Доставка',
            default => $receivingType,
        };
    }

    private function paymentType(int $paymentType): string
    {
        return match ($paymentType) {
            1 => '💵 Готівка',
            2 => '💳 Онлайн',
            3 => '💳 Термінал',
            default => "Тип #{$paymentType}",
        };
    }

    /**
     * @param  array<string, mixed>  $fulfillment
     */
    private function fulfillmentSummary(array $fulfillment): ?string
    {
        $address = $fulfillment['delivery_address'] ?? null;

        if (is_string($address) && trim($address) !== '') {
            return '📍 Адреса: '.trim($address);
        }

        if (is_array($address)) {
            $parts = array_filter([
                $address['street'] ?? null,
                $address['house'] ?? null,
                $address['flat'] ?? null,
            ], static fn (mixed $part): bool => is_string($part) && trim($part) !== '');

            if ($parts !== []) {
                return '📍 Адреса: '.implode(', ', $parts);
            }
        }

        $pickupTitle = $fulfillment['pickup_address'] ?? $fulfillment['restaurant_address_title'] ?? null;

        if (is_string($pickupTitle) && trim($pickupTitle) !== '') {
            return '📍 Самовивіз: '.trim($pickupTitle);
        }

        return null;
    }
}
