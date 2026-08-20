<?php

namespace App\Telegram\Formatting;

use App\DTO\OrderingBackend\CurrentPaymentData;
use App\DTO\OrderingBackend\OrderData;

final class OrderMessageFormatter
{
    public function format(OrderData $order): string
    {
        $sections = [
            $this->statusHeading($order->status),
            implode("\n", array_filter([
                "Замовлення #{$order->id}",
                'Статус: '.$this->status($order->status),
                'Отримання: '.$this->receivingType($order->receivingType),
                'Оплата: '.$this->paymentType($order->paymentType),
                $this->fulfillmentSummary($order->fulfillment),
                "Разом: {$order->total} {$order->currency}",
            ])),
        ];

        foreach ($order->items as $item) {
            $sections[] = implode("\n", [
                $item->name,
                "{$item->quantity} × {$item->unitPrice} {$order->currency} = {$item->total} {$order->currency}",
            ]);
        }

        if ($order->status === 'failed') {
            $sections[] = '❌ Не вдалося створити замовлення.';
        }

        return implode("\n\n", $sections);
    }

    public function payment(
        CurrentPaymentData $payment,
        ?string $qrNotice = null,
    ): string {
        $lines = [];

        if ($payment->paymentReceivedAt !== null) {
            $lines[] = '✅ Оплату отримано.';
        } elseif ($payment->status === 'ready') {
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
            ], static fn (mixed $part): bool => is_string($part)
                && trim($part) !== '');

            if ($parts !== []) {
                return '📍 Адреса: '.implode(', ', $parts);
            }
        }

        $pickupTitle =
            $fulfillment['pickup_address']
            ?? $fulfillment['restaurant_address_title']
            ?? null;

        if (
            is_string($pickupTitle)
            && trim($pickupTitle) !== ''
        ) {
            return '📍 Самовивіз: '.trim($pickupTitle);
        }

        return null;
    }
}
