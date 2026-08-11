<?php

use App\Enums\CartStatus;
use App\Models\Cart;
use App\Models\Restaurant;
use App\Services\Repositories\CartRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('returns only the active cart from current session lookups', function () {
    $restaurant = Restaurant::factory()->create();
    $sessionId = (string) Str::ulid();

    Cart::factory()->for($restaurant)->create([
        'session_id' => $sessionId,
        'status' => CartStatus::CheckedOut,
    ]);
    Cart::factory()->for($restaurant)->create([
        'session_id' => $sessionId,
        'status' => CartStatus::Expired,
    ]);
    $activeCart = Cart::factory()->for($restaurant)->create([
        'session_id' => $sessionId,
        'status' => CartStatus::Active,
    ]);

    $repository = app(CartRepository::class);

    expect($repository->findForSession($restaurant, $sessionId)?->id)
        ->toBe($activeCart->id)
        ->and($repository->findForSessionForUpdate($restaurant, $sessionId)?->id)
        ->toBe($activeCart->id);
});

it('returns null from current session lookups when only historical carts exist', function () {
    $restaurant = Restaurant::factory()->create();
    $sessionId = (string) Str::ulid();

    Cart::factory()->for($restaurant)->create([
        'session_id' => $sessionId,
        'status' => CartStatus::CheckedOut,
    ]);
    Cart::factory()->for($restaurant)->create([
        'session_id' => $sessionId,
        'status' => CartStatus::Expired,
    ]);

    $repository = app(CartRepository::class);

    expect($repository->findForSession($restaurant, $sessionId))->toBeNull()
        ->and($repository->findForSessionForUpdate($restaurant, $sessionId))->toBeNull();
});

it('allows historical carts but enforces one active cart per restaurant session', function () {
    $restaurant = Restaurant::factory()->create();
    $sessionId = (string) Str::ulid();

    Cart::factory()->count(2)->for($restaurant)->create([
        'session_id' => $sessionId,
        'status' => CartStatus::CheckedOut,
    ]);
    Cart::factory()->for($restaurant)->create([
        'session_id' => $sessionId,
        'status' => CartStatus::Expired,
    ]);
    Cart::factory()->for($restaurant)->create([
        'session_id' => $sessionId,
        'status' => CartStatus::Active,
    ]);

    expect(fn () => DB::transaction(fn () => Cart::factory()
        ->for($restaurant)
        ->create([
            'session_id' => $sessionId,
            'status' => CartStatus::Active,
        ])))->toThrow(UniqueConstraintViolationException::class);

    expect(Cart::query()
        ->whereBelongsTo($restaurant)
        ->where('session_id', $sessionId)
        ->where('status', CartStatus::CheckedOut)
        ->count())->toBe(2)
        ->and(Cart::query()
            ->whereBelongsTo($restaurant)
            ->where('session_id', $sessionId)
            ->where('status', CartStatus::Active)
            ->count())->toBe(1);
});

it('recovers an insert race without creating duplicate active carts', function () {
    DB::connection()->commit();

    $restaurant = Restaurant::factory()->create();
    $restaurantId = $restaurant->id;
    $sessionId = (string) Str::ulid();
    $expiresAt = now()->addHour()->toIso8601String();

    DB::unprepared(<<<'SQL'
        CREATE FUNCTION test_delay_active_cart_insert() RETURNS trigger AS $$
        BEGIN
            IF NEW.status = 'active' THEN
                PERFORM pg_sleep(1);
            END IF;

            RETURN NEW;
        END;
        $$ LANGUAGE plpgsql
        SQL);
    DB::statement(<<<'SQL'
        CREATE TRIGGER test_delay_active_cart_insert_trigger
        BEFORE INSERT ON carts
        FOR EACH ROW EXECUTE FUNCTION test_delay_active_cart_insert()
        SQL);

    try {
        $workers = [];

        for ($worker = 0; $worker < 2; $worker++) {
            $sockets = stream_socket_pair(
                STREAM_PF_UNIX,
                STREAM_SOCK_STREAM,
                STREAM_IPPROTO_IP,
            );

            if ($sockets === false) {
                throw new RuntimeException('Unable to create worker sockets.');
            }

            [$parentSocket, $childSocket] = $sockets;
            $processId = pcntl_fork();

            if ($processId === -1) {
                throw new RuntimeException('Unable to fork cart creation worker.');
            }

            if ($processId === 0) {
                fclose($parentSocket);
                DB::purge();

                try {
                    $workerRestaurant = Restaurant::query()->findOrFail($restaurantId);
                    $result = app(CartRepository::class)->findOrCreateForSession(
                        $workerRestaurant,
                        $sessionId,
                        Carbon::parse($expiresAt),
                    );

                    fwrite($childSocket, json_encode([
                        'id' => $result['cart']->id,
                        'created' => $result['created'],
                    ], JSON_THROW_ON_ERROR));
                    fclose($childSocket);
                    exit(0);
                } catch (Throwable $exception) {
                    fwrite($childSocket, json_encode([
                        'error' => $exception->getMessage(),
                    ], JSON_THROW_ON_ERROR));
                    fclose($childSocket);
                    exit(1);
                }
            }

            fclose($childSocket);
            $workers[] = [
                'process_id' => $processId,
                'socket' => $parentSocket,
            ];
        }

        $results = [];

        foreach ($workers as $worker) {
            $output = stream_get_contents($worker['socket']);
            fclose($worker['socket']);
            pcntl_waitpid($worker['process_id'], $status);

            if (pcntl_wexitstatus($status) !== 0) {
                throw new RuntimeException("Cart creation worker failed: {$output}");
            }

            $results[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        }
    } finally {
        DB::statement('DROP TRIGGER IF EXISTS test_delay_active_cart_insert_trigger ON carts');
        DB::statement('DROP FUNCTION IF EXISTS test_delay_active_cart_insert()');
    }

    try {
        expect(collect($results)->pluck('id')->unique())->toHaveCount(1)
            ->and(collect($results)->where('created', true))->toHaveCount(1)
            ->and(Cart::query()
                ->whereBelongsTo($restaurant)
                ->where('session_id', $sessionId)
                ->where('status', CartStatus::Active)
                ->count())->toBe(1);
    } finally {
        $restaurant->delete();
    }
});
