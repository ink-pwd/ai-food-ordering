# AI Food Ordering Backend

Laravel backend service for the AI Food Ordering project. It owns the business state for sessions, city/restaurant selection, fulfillment, catalog data, carts, Dots order creation, online payment links, and backend-generated payment QR codes.

Telegram and MCP clients are thin clients. They call this internal REST API; they do not talk to Dots directly and they do not provide trusted Dots identifiers, prices, payment URLs, or QR paths.

## Architecture

```text
Telegram / MCP clients
  -> Backend internal REST API (Laravel)
  -> Dots Clients API
```

Infrastructure:

- PHP 8.5
- Laravel 13
- PostgreSQL
- Redis
- RabbitMQ
- Dots Clients API
- Pest / PHPUnit
- Docker Compose

The backend persists synchronized Dots topology/catalog data, stores Redis-backed session state, creates local carts/orders in PostgreSQL, validates checkout prices with Dots, creates Dots online-payment orders with `paymentType = 2`, polls/retries online payment data, stores the trusted checkout URL, and generates private PNG QR codes for that checkout URL.

## Core flow

1. Create session.
2. Save contact.
3. Request OTP.
4. Verify OTP.
5. Select city.
6. List and select restaurant.
7. Select pickup or delivery fulfillment.
8. Browse catalog.
9. Create/update cart.
10. Checkout.
11. Create local and Dots order.
12. Resolve online payment checkout URL.
13. Retrieve backend-generated payment QR PNG.

## Main domain entities

- `City` — synchronized Dots city/topology node exposed to clients for selection.
- `Restaurant` — local restaurant/company synchronized from Dots and scoped to a city.
- `RestaurantAddress` — active pickup address for a restaurant.
- `Category` — synchronized restaurant catalog category.
- `Product` — synchronized restaurant product.
- `Session` — Redis state for the current internal client journey.
- `Cart` — PostgreSQL cart bound to the selected restaurant/session.
- `CartItem` — product and quantity inside a cart.
- `Order` — historical checkout record with fulfillment snapshot, Dots order id, payment URL, and QR metadata.

## Synchronization

Global Dots topology/catalog synchronization is queued with the current Artisan command:

```bash
docker compose -f ../docker-compose.yml exec --user "$(id -u):$(id -g)" app \
php artisan catalog:sync
```

The command dispatches `SyncDotsTopology`, which synchronizes cities, restaurants, restaurant addresses, and restaurant catalogs into PostgreSQL.

## Queues

RabbitMQ is the queue backend. Run workers from `backend/` with:

```bash
docker compose -f ../docker-compose.yml exec --user "$(id -u):$(id -g)" app \
php artisan queue:work rabbitmq
```

Use deployment-specific process supervision in production.

## Sessions

Session state is stored outside PostgreSQL using the configured internal session store (`redis` in normal environments, `array` in tests). Session-bound endpoints require `X-Session-Token`. Exiting a session closes Redis session state and abandons unfinished active carts, but historical orders and QR metadata remain in PostgreSQL.

## OTP

The local/testing OTP driver logs/generated codes through the configured sender. OTP challenges store a hash, TTL, resend cooldown, and remaining attempts. A real production SMS provider is intentionally not implemented in this backend stage and must be integrated before production SMS delivery.

## Fulfillment

Pickup and delivery are selected after city and restaurant selection. Pickup requires choosing an active `RestaurantAddress`. Delivery validates the user-provided address through Dots using backend-trusted city and restaurant data, stores trusted coordinates, checks city polygon and restaurant delivery zone availability, and records the Dots delivery type/price.

## Orders

Checkout uses the selected city/restaurant/fulfillment and current cart. Dots is authoritative for price validation. Order creation is idempotent through the `Idempotency-Key` header. The backend never accepts client-provided Dots IDs, prices, payment type, fulfillment snapshots, or order item totals.

## Payments

Orders use Dots online payment (`paymentType = 2`). Dots creates the payment as part of order creation. The backend polls/retries online-payment-data retrieval with bounded waiting, represents payment as `pending` or `ready`, and persists the trusted `checkoutUrl` when ready. Idempotent order replay does not create another Dots order and may resolve missing payment data.

## QR

The backend generates a PNG QR code from the persisted trusted checkout URL only. QR PNG files are stored privately on the Laravel `local` disk under:

```text
payment-qr/{order-id}.png
```

QR metadata (`payment_qr_path`, `payment_qr_fingerprint`) is stored on `orders`. The authenticated endpoint returns image bytes directly; no public storage link or base64 JSON is used.

## Security

All API endpoints require:

```text
X-Internal-Api-Token
```

Session-bound endpoints also require:

```text
X-Session-Token
```

Order creation also requires:

```text
Idempotency-Key
```

The backend derives trusted Dots city, restaurant, address, price, payment, and QR data from persisted state. It rejects arbitrary checkout URLs, QR paths, Dots IDs, session IDs, and totals from clients.

## Installation

From the repository root:

```bash
docker compose up -d
```

From `backend/`, install dependencies and initialize Laravel:

```bash
docker compose -f ../docker-compose.yml exec --user "$(id -u):$(id -g)" app composer install
docker compose -f ../docker-compose.yml exec --user "$(id -u):$(id -g)" app php artisan key:generate
docker compose -f ../docker-compose.yml exec --user "$(id -u):$(id -g)" app php artisan migrate
```

Create `backend/.env` from `.env.example` and configure placeholders for PostgreSQL, Redis, RabbitMQ, Dots API tokens, and `INTERNAL_API_TOKEN`. Never commit real secrets.

Synchronize Dots data:

```bash
docker compose -f ../docker-compose.yml exec --user "$(id -u):$(id -g)" app php artisan catalog:sync
docker compose -f ../docker-compose.yml exec --user "$(id -u):$(id -g)" app php artisan queue:work rabbitmq --once -v
```

## Testing

Run formatting:

```bash
docker compose -f ../docker-compose.yml exec --user "$(id -u):$(id -g)" app vendor/bin/pint --dirty --format agent
```

Run backend tests:

```bash
docker compose -f ../docker-compose.yml exec --user "$(id -u):$(id -g)" app php artisan test --compact
```

Run E2E only:

```bash
docker compose -f ../docker-compose.yml exec --user "$(id -u):$(id -g)" app php artisan test --compact tests/E2E/DotsOrderE2ETest.php
```

E2E tests fake external Dots HTTP calls and do not create real paid orders.

## API documentation

- English technical documentation: [`DocsENG.md`](DocsENG.md)
- Russian technical documentation: [`DocsRUS.md`](DocsRUS.md)
- OpenAPI/Swagger: [`openapi.yaml`](openapi.yaml)
