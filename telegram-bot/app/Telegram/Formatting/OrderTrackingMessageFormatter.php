<?php

namespace App\Telegram\Formatting;

use App\DTO\OrderingBackend\OrderTrackingData;

final class OrderTrackingMessageFormatter
{
    public function format(OrderTrackingData $tracking): string
    {
        $lines = [
            $tracking->completedTime === null
                ? '📦 Інформація про замовлення'
                : '✅ Замовлення виконано',
            "Замовлення #{$tracking->orderId}",
            'Статус: '.$this->status($tracking->status),
        ];

        if (! $tracking->trackingAvailable) {
            $lines[] = '⏳ Актуальні дані Dots ще недоступні. Спробуйте трохи пізніше.';

            return implode("\n", $lines);
        }

        if ($tracking->number !== null) {
            $lines[] = "Номер Dots: {$tracking->number}";
        }

        if ($tracking->companyName !== null) {
            $lines[] = "🍽 Ресторан: {$tracking->companyName}";
        }

        if ($tracking->deliveryType !== null) {
            $lines[] = "🚚 Отримання: {$tracking->deliveryType}";
        }

        $maskedAddress = $this->maskedDeliveryAddress(
            $tracking->deliveryAddress,
        );

        if ($maskedAddress !== null) {
            $lines[] = "📍 Адреса: {$maskedAddress}";
        }

        if ($tracking->courier?->name !== null) {
            $lines[] = "🛵 Кур'єр: {$tracking->courier->name}";
        }

        return implode("\n", $lines);
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

    private function maskedDeliveryAddress(?string $address): ?string
    {
        if ($address === null || trim($address) === '') {
            return null;
        }

        $parts = preg_split('/\s*,\s*/u', trim($address));

        if (! is_array($parts) || count($parts) < 2) {
            return '**';
        }

        $street = trim($parts[0]);

        return $street === ''
            ? '**'
            : "{$street}, **";
    }
}
