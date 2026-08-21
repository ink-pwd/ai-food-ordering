<?php

use App\Integrations\OrderingBackend\OrderingBackendTransport;
use App\Integrations\OrderingBackend\OrderTrackingOrderingBackendClient;
use App\Integrations\OrderingBackend\OrderTrackingResponseMapper;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Psr\Log\NullLogger;

test('order tracking client uses the local order id and normalizes backend tracking data', function (): void {
    $previousContainer = Container::getInstance();
    $container = new Container;
    Container::setInstance($container);

    try {
        $container->instance('config', new Repository([
            'services' => [
                'ordering_backend' => [
                    'url' => 'http://backend.test',
                    'token' => 'internal-token',
                    'timeout' => 10,
                ],
            ],
        ]));

        $http = new Factory;
        $http->fake([
            'http://backend.test/api/orders/42/tracking' => $http->response([
                'data' => [
                    'order_id' => 42,
                    'status' => 'created',
                    'external_order_id' => 'dots-id',
                    'tracking_available' => true,
                    'number' => '976-91940',
                    'company_name' => 'Restaurant',
                    'completed_time' => null,
                    'delivery' => [
                        'type' => 'Door delivery',
                        'address' => 'Main Street, 10',
                    ],
                    'courier' => [
                        'name' => 'Andrew',
                        'route_status' => 10,
                        'duration' => 54,
                        'last_updated' => 1622118255,
                        'position' => [
                            'latitude' => 51.496267,
                            'longitude' => 31.306502,
                        ],
                    ],
                ],
            ], 200),
        ]);

        $transport = new OrderingBackendTransport(
            $http,
            new NullLogger,
        );
        $client = new OrderTrackingOrderingBackendClient(
            $transport,
            new OrderTrackingResponseMapper($transport),
        );

        $tracking = $client->get(
            'session-token',
            42,
        );

        expect($tracking->orderId)->toBe(42)
            ->and($tracking->number)->toBe('976-91940')
            ->and($tracking->courier?->name)->toBe('Andrew')
            ->and($tracking->courier?->position?->latitude)
            ->toBe(51.496267);

        $http->assertSent(
            static fn (Request $request): bool => $request->url()
                === 'http://backend.test/api/orders/42/tracking'
                && $request->hasHeader(
                    'X-Session-Token',
                    'session-token',
                ),
        );
    } finally {
        Container::setInstance($previousContainer);
    }
});
