<?php

namespace App\Integrations\OrderingBackend;

use RuntimeException;

class OrderingBackendException extends RuntimeException
{
    private function __construct(
        string $message,
        private readonly string $failureCategory,
        private readonly ?int $backendStatus = null,
    ) {
        parent::__construct($message);
    }

    public static function connectionFailure(): self
    {
        return new self(
            'Не удалось связаться с сервисом заказа. Повторите попытку позже.',
            'connection',
        );
    }

    public static function invalidResponse(?int $backendStatus = null): self
    {
        return new self(
            'Сервис заказа вернул некорректный ответ. Повторите попытку позже.',
            'invalid_response',
            $backendStatus,
        );
    }

    public static function forBackendStatus(int $backendStatus): self
    {
        return match (true) {
            $backendStatus === 401 => new self(
                'Не удалось авторизовать запрос к сервису заказа. Создайте новый контекст заказа.',
                'authentication',
                $backendStatus,
            ),
            $backendStatus === 404 => new self(
                'Запрошенный ресурс не найден.',
                'not_found',
                $backendStatus,
            ),
            $backendStatus === 409 => new self(
                'Запрос конфликтует с текущим состоянием заказа.',
                'conflict',
                $backendStatus,
            ),
            $backendStatus === 422 => new self(
                'Запрос отклонён: входные данные или состояние оформления заказа недействительны.',
                'validation',
                $backendStatus,
            ),
            $backendStatus >= 500 => new self(
                'Сервис заказа временно недоступен.',
                'service_unavailable',
                $backendStatus,
            ),
            $backendStatus >= 400 => new self(
                'Сервис заказа отклонил запрос.',
                'request_rejected',
                $backendStatus,
            ),
            default => self::invalidResponse($backendStatus),
        };
    }

    public function category(): string
    {
        return $this->failureCategory;
    }

    public function backendStatus(): ?int
    {
        return $this->backendStatus;
    }
}
