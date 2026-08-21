<?php

use App\DTO\SessionData;
use App\Enums\OrderStatus;
use App\Integrations\Dots\DotsClient;
use App\Integrations\Dots\OrdersApi;
use App\Models\Order;
use App\Services\Handlers\Order\ShowOrderTrackingHandler;
use App\Services\Repositories\OrderRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class TrackingDotsClientFake extends DotsClient
{
    /** @var array<string, array<string, mixed>> */
    public array $responses = [];

    /** @var list<string> */
    public array $requestedPaths = [];

    /** @return array<string, mixed> */
    public function authenticatedGet(string $path, array $query = []): array
    {
        $this->requestedPaths[] = $path;

        return $this->responses[$path] ?? [];
    }
}

final class TrackingOrderRepositoryFake extends OrderRepository
{
    public ?Order $order = null;

    public ?int $requestedOrderId = null;

    public ?string $requestedCustomerPhone = null;

    public int $markCreatedCalls = 0;

    public function findByIdForCustomerPhone(int $orderId, string $customerPhone): ?Order
    {
        $this->requestedOrderId = $orderId;
        $this->requestedCustomerPhone = $customerPhone;

        return $this->order;
    }

    /** @param array<string, mixed> $responsePayload */
    public function markCreated(Order $order, array $responsePayload): Order
    {
        $this->markCreatedCalls++;
        $order->status = OrderStatus::Created;
        $order->response_payload = $responsePayload;

        return $order;
    }
}

function trackingSession(string $phone = '+380993557488'): SessionData
{
    return new SessionData(
        id: 'session-1',
        cityId: null,
        restaurantId: null,
        channel: 'telegram',
        externalSessionId: 'chat-1',
        status: 'active',
        metadata: [
            'contact' => [
                'name' => 'Test User',
                'phone' => $phone,
                'phone_verified' => true,
            ],
        ],
        createdAt: '2026-08-21T10:00:00+00:00',
        expiresAt: '2026-08-21T11:00:00+00:00',
    );
}

function trackingOrder(OrderStatus $status = OrderStatus::Creating): Order
{
    $order = new Order;
    $order->forceFill([
        'id' => 42,
        'external_order_id' => 'dots-order-id',
        'status' => $status,
    ]);

    return $order;
}

test('tracking resolves a local order through verified phone and maps dots data', function (): void {
    $repository = new TrackingOrderRepositoryFake;
    $repository->order = trackingOrder();

    $dotsClient = new TrackingDotsClientFake;
    $dotsClient->responses = [
        '/api/v2/orders/dots-order-id' => [
            'number' => '976-91940',
            'companyName' => 'Jack\'s Burgers',
            'completedTime' => null,
            'delivery' => [
                'deliveryTypeText' => 'Door delivery',
                'deliveryAddress' => 'Main Street, 10',
            ],
        ],
        '/api/v2/orders/dots-order-id/courier-data' => [
            'courier' => ['name' => 'Andrew 127'],
            'courierRoute' => [
                'status' => 10,
                'duration' => 54,
                'lastUpdated' => 1622118255,
                'currentCourierPositionDTO' => [
                    'latitude' => 51.496267236017,
                    'longitude' => 31.306502453193,
                ],
            ],
        ],
    ];

    $tracking = (new ShowOrderTrackingHandler(
        $repository,
        new OrdersApi($dotsClient),
    ))->handle(trackingSession(), 42);

    expect($repository->requestedOrderId)->toBe(42)
        ->and($repository->requestedCustomerPhone)->toBe('380993557488')
        ->and($repository->markCreatedCalls)->toBe(1)
        ->and($dotsClient->requestedPaths)->toBe([
            '/api/v2/orders/dots-order-id',
            '/api/v2/orders/dots-order-id/courier-data',
        ])
        ->and($tracking->toArray())->toMatchArray([
            'order_id' => 42,
            'status' => 'created',
            'tracking_available' => true,
            'number' => '976-91940',
            'company_name' => 'Jack\'s Burgers',
            'delivery' => [
                'type' => 'Door delivery',
                'address' => 'Main Street, 10',
            ],
            'courier' => [
                'name' => 'Andrew 127',
                'route_status' => 10,
                'duration' => 54,
                'last_updated' => 1622118255,
                'position' => [
                    'latitude' => 51.496267236017,
                    'longitude' => 31.306502453193,
                ],
            ],
        ]);
});

test('tracking does not expose an order that is not available for the verified phone', function (): void {
    $repository = new TrackingOrderRepositoryFake;
    $dotsClient = new TrackingDotsClientFake;

    $handler = new ShowOrderTrackingHandler(
        $repository,
        new OrdersApi($dotsClient),
    );

    expect(fn () => $handler->handle(trackingSession('+380001112233'), 42))
        ->toThrow(NotFoundHttpException::class, 'Order was not found.');

    expect($repository->requestedOrderId)->toBe(42)
        ->and($repository->requestedCustomerPhone)->toBe('380001112233')
        ->and($dotsClient->requestedPaths)->toBe([]);
});
