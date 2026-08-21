<?php

namespace App\Services\Handlers\Order;

use App\DTO\OrderTrackingData;
use App\DTO\SessionData;
use App\Enums\OrderStatus;
use App\Integrations\Dots\OrdersApi;
use App\Models\Order;
use App\Services\Repositories\OrderRepository;
use App\Services\Support\SessionSelection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

readonly class ShowOrderTrackingHandler
{
    public function __construct(
        private OrderRepository $orders,
        private OrdersApi $dotsOrders,
    ) {
    }

    public function handle(SessionData $session, int $orderId): OrderTrackingData
    {
        SessionSelection::assertPhoneVerified($session);

        $contact = SessionSelection::contact($session);

        /** @var array{name: string, phone: string, phone_verified?: bool} $contact */
        $order = $this->orders->findByIdForCustomerPhone(
            $orderId,
            ltrim(trim($contact['phone']), '+'),
        );

        if ($order === null) {
            throw new NotFoundHttpException('Order was not found.');
        }

        $orderInfo = $this->orderInfo($order);

        if ($orderInfo !== null && $order->status === OrderStatus::Creating) {
            $order = $this->orders->markCreated($order, $orderInfo);
        }

        $courierData = $this->courierData($order);

        return $this->trackingData(
            $order,
            $orderInfo,
            $courierData,
        );
    }

    /** @return array<string, mixed>|null */
    private function orderInfo(Order $order): ?array
    {
        if ($order->external_order_id === null) {
            return null;
        }

        try {
            return $this->dotsOrders->get($order->external_order_id);
        } catch (RequestException $exception) {
            $this->logDotsRequestFailure(
                'Dots order tracking info request failed.',
                $order,
                $exception,
            );

            return null;
        } catch (ConnectionException) {
            Log::warning('Dots order tracking info connection failure.', [
                'order_id' => $order->id,
                'external_order_id' => $order->external_order_id,
            ]);

            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function courierData(Order $order): ?array
    {
        if ($order->external_order_id === null) {
            return null;
        }

        try {
            return $this->dotsOrders->getCourierData(
                $order->external_order_id,
            );
        } catch (RequestException $exception) {
            $this->logDotsRequestFailure(
                'Dots order courier tracking request failed.',
                $order,
                $exception,
            );

            return null;
        } catch (ConnectionException) {
            Log::warning('Dots order courier tracking connection failure.', [
                'order_id' => $order->id,
                'external_order_id' => $order->external_order_id,
            ]);

            return null;
        }
    }

    private function logDotsRequestFailure(
        string $message,
        Order $order,
        RequestException $exception,
    ): void {
        if ($exception->response->notFound()) {
            return;
        }

        Log::warning($message, [
            'order_id' => $order->id,
            'external_order_id' => $order->external_order_id,
            'status_code' => $exception->response->status(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $orderInfo
     * @param  array<string, mixed>|null  $courierData
     */
    private function trackingData(
        Order $order,
        ?array $orderInfo,
        ?array $courierData,
    ): OrderTrackingData {
        $delivery = $this->arrayValue($orderInfo, 'delivery');
        $courier = $this->arrayValue($courierData, 'courier');
        $courierRoute = $this->arrayValue($courierData, 'courierRoute');
        $position = $this->arrayValue($courierRoute, 'currentCourierPositionDTO');

        return new OrderTrackingData(
            orderId: $order->id,
            status: $order->status->value,
            externalOrderId: $order->external_order_id,
            trackingAvailable: $orderInfo !== null,
            number: $this->stringValue($orderInfo, 'number'),
            companyName: $this->stringValue($orderInfo, 'companyName'),
            completedTime: $this->intValue($orderInfo, 'completedTime'),
            deliveryType: $this->stringValue($delivery, 'deliveryTypeText'),
            deliveryAddress: $this->stringValue($delivery, 'deliveryAddress'),
            courierName: $this->stringValue($courier, 'name'),
            courierRouteStatus: $this->scalarValue($courierRoute, 'status'),
            courierRouteDuration: $this->intValue($courierRoute, 'duration'),
            courierLastUpdated: $this->intValue($courierRoute, 'lastUpdated'),
            courierLatitude: $this->floatValue($position, 'latitude'),
            courierLongitude: $this->floatValue($position, 'longitude'),
        );
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @return array<string, mixed>|null
     */
    private function arrayValue(?array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @param array<string, mixed>|null $data */
    private function stringValue(?array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string, mixed>|null $data */
    private function intValue(?array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    /** @param array<string, mixed>|null $data */
    private function floatValue(?array $data, string $key): ?float
    {
        $value = $data[$key] ?? null;

        return is_int($value) || is_float($value)
            ? (float) $value
            : null;
    }

    /** @param array<string, mixed>|null $data */
    private function scalarValue(?array $data, string $key): int|string|null
    {
        $value = $data[$key] ?? null;

        return is_int($value) || is_string($value)
            ? $value
            : null;
    }
}
