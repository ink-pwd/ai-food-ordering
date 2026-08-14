# AI Food Ordering Backend — Technical Documentation

## 1. Overview

The backend is the authoritative business service for AI Food Ordering. Telegram and MCP clients call the backend internal REST API; the backend owns session state, Dots-derived topology/catalog data, cart state, checkout validation, order creation, online payment link retrieval, and payment QR generation.

The backend never trusts client-provided Dots identifiers, prices, checkout URLs, QR paths, or order ownership. Those values are derived from PostgreSQL and Redis state created by prior validated steps.

## 2. Architecture

```text
Telegram / MCP clients
  -> Backend internal REST API
  -> Dots Clients API
```

The code follows the current Laravel pipeline:

```text
Middleware -> FormRequest -> Controller -> Handler/Service -> Repository -> Model/Database
```

Controllers stay thin. Handlers contain business logic. Repositories own persistence writes. External Dots calls go through integration classes under `App\Integrations\Dots`.

## 3. Infrastructure/services

- Laravel 13 on PHP 8.5.
- PostgreSQL for durable entities: cities, restaurants, addresses, catalog, carts, orders, QR metadata.
- Redis for internal session and OTP state in normal environments.
- RabbitMQ for background topology/catalog synchronization.
- Dots Clients API for topology, catalog, fulfillment, price validation, order creation, order status, and online payment data.
- `endroid/qr-code` for backend PNG QR generation.

## 4. Environment/configuration

Configuration is read through Laravel `config()`. Application code must not read `env()` directly.

Important groups:

- `services.dots.base_url`
- `services.dots.account_token`
- `services.dots.token`
- `services.dots.auth_token`
- `services.dots.api_version`
- `services.internal.token`
- `services.internal.session_store`
- `services.internal.session_ttl_seconds`
- `services.internal.session_key_prefix`
- `services.internal.otp.*`
- `services.internal.payment.wait_seconds`
- `services.internal.payment.poll_interval_ms`

## 5. Authentication/security

All API routes use `internal.api` and require:

```text
X-Internal-Api-Token: <internal token>
```

Session-bound routes also use `internal.session` and require:

```text
X-Session-Token: <64-character session token>
```

Order creation requires:

```text
Idempotency-Key: <client retry key>
```

Security decisions:

- no arbitrary session id input;
- no arbitrary restaurant/company/city Dots IDs from clients;
- no arbitrary payment URL input;
- no arbitrary QR path input;
- no public QR directory;
- current order/cart are resolved from the authenticated session;
- payment checkout URL is intentionally exposed only as the customer payment URL;
- QR contains the payment URL because the user must scan it.

## 6. Session lifecycle

`POST /api/sessions` creates an active Redis-backed session and returns `session_token`. The new session has no selected city or restaurant. The client then saves contact, verifies OTP, selects city and restaurant, selects fulfillment, and proceeds to cart/checkout.

`DELETE /api/sessions/current` closes the current session. Active unfinished carts are abandoned. Checked-out carts and orders remain historical records.

## 7. OTP lifecycle

Contact is saved through `PUT /api/sessions/current/contact`. Saving or changing contact resets `phone_verified` to `false` and invalidates outstanding OTP challenges.

`POST /api/sessions/current/otp` creates an OTP challenge for the current contact. The challenge stores:

- session id;
- phone;
- hashed code;
- expiration time;
- resend cooldown;
- attempts remaining.

`POST /api/sessions/current/otp/verify` verifies the code, consumes the challenge, and sets `metadata.contact.phone_verified = true`.

The local/testing OTP sender is log/fake based. A production SMS provider is not implemented in this backend stage.

## 8. Cities and restaurants

Cities and restaurants are synchronized from Dots topology. Users select an active city first and then list/select an active restaurant in that city.

City and restaurant selection are immutable during a session. Attempts to replace either selection return a conflict.

## 9. Catalog synchronization

Current Artisan command:

```bash
php artisan catalog:sync
```

The command queues `SyncDotsTopology`, which synchronizes:

- active cities;
- restaurants/companies;
- restaurant pickup addresses;
- catalog categories;
- products.

RabbitMQ workers process the job:

```bash
php artisan queue:work rabbitmq
```

## 10. Catalog browsing/search

Catalog endpoints are internal-token protected and not session-bound. Restaurants are identified by the route parameter currently resolved as a restaurant slug for product/category controllers.

Main operations:

- list categories;
- list products for a category;
- show product;
- search products;
- show full restaurant catalog.

Responses use JSON resources and expose safe catalog fields only.

## 11. Fulfillment

Fulfillment is selected after city and restaurant. It remains mutable before checkout while the active cart/order state allows mutation. Switching fulfillment clears stale state from the previous mode.

Supported values:

- `pickup`
- `delivery`

## 12. Pickup

Pickup requires:

1. selected city;
2. selected restaurant;
3. verified phone;
4. restaurant supports pickup;
5. selected active `RestaurantAddress`.

The session stores local and external address identifiers as trusted backend state.

## 13. Delivery

Delivery requires selected city/restaurant and verified phone. The client submits human address fields only. The backend enriches the request with the trusted Dots city id, asks Dots to validate the address, then checks delivery types for the selected restaurant at the trusted coordinates.

## 14. Address validation

The delivery-address endpoint prohibits client-provided coordinates and Dots city identifiers. Dots address validation must return the selected city id, `inCityPolygon = true`, and coordinates.

If validation fails, the endpoint returns `delivery_available: false` with a reason such as `invalid_address`.

## 15. Delivery-zone validation

After address validation, the backend calls Dots company delivery types for the selected restaurant and trusted coordinates. If no acceptable delivery type exists, the endpoint returns `delivery_available: false` with `outside_delivery_zone`.

When available, the session stores:

- Dots delivery type;
- delivery price;
- normalized trusted delivery address;
- latitude/longitude from Dots.

## 16. Cart lifecycle

A session can create a current active cart after restaurant and fulfillment readiness. Cart items are added by local `product_id`; the backend derives product, external product id, unit price, totals, currency, and restaurant.

Cart statuses include active, checked out, expired, and abandoned. Exit abandons active unfinished carts. Checkout marks the cart checked out.

## 17. Checkout

`POST /api/orders` checks the current session, verified phone, fulfillment readiness, active cart, and product availability. It builds a Dots order payload from trusted backend state.

## 18. Dots price validation

Before local/Dots order creation, the backend calls Dots cart price validation. Dots `totalPrice` becomes the authoritative local order total. Local catalog prices are not trusted as final checkout price.

## 19. Order creation

The backend creates a local order with:

- restaurant id;
- cart id;
- session id;
- idempotency key;
- channel;
- receiving type;
- payment type `2`;
- customer contact;
- authoritative total;
- fulfillment snapshot;
- request payload.

It then calls Dots order creation. Dots returns asynchronous external order id. The local order remains `creating` until a later status refresh confirms creation.

## 20. Idempotency

Order creation requires `Idempotency-Key`. Replaying the same key for the same session returns the existing order and never creates another Dots order. If payment data was missing, replay may resolve it from Dots online-payment-data.

## 21. Async Dots order lifecycle

`GET /api/orders/current` resolves the current session order. If it is still `creating` and has an external Dots id, the backend checks Dots order status. A successful Dots response marks the local order `created`. Dots 404/connection/transient failures keep the local order in its current state.

## 22. Online payment lifecycle

Orders use Dots online payment (`paymentType = 2`). Dots creates the payment automatically for the Dots order. The backend calls `/online-payment-data` for the external order id.

The payment handler uses bounded polling configured by `services.internal.payment.*`.

## 23. Payment pending/ready

If Dots payment data is not ready or temporarily unavailable, payment remains `pending`, the order remains valid, and no second Dots order is created.

When Dots returns valid `onlinePayment.checkoutUrl`, the backend validates that it is a non-empty HTTPS URL and persists it on the order. The payment state becomes `ready`.

`GET /api/orders/current/payment` returns payment state and may resolve pending payment later.

## 24. QR generation/storage

`GET /api/orders/current/payment/qr` returns PNG bytes for a ready payment.

QR generation rules:

- source is only the persisted trusted `orders.payment_checkout_url`;
- no request URL/path input is accepted;
- PNG generated by `endroid/qr-code`;
- private Laravel `local` disk;
- storage path: `payment-qr/{order-id}.png`;
- QR fingerprint: `sha256(checkoutUrl)`;
- existing file is reused when fingerprint matches;
- stale QR is regenerated when checkout URL changes.

If payment is pending, the endpoint returns the same pending JSON semantics with HTTP `202` and does not create a file.

## 25. Exit/abandon behavior

Session exit closes Redis session state and abandons active unfinished carts. It does not delete historical orders, payment URLs, QR metadata, or QR files. Abandoned carts without orders never have QR files.

## 26. Error handling

Common error patterns:

- `401` unauthenticated for missing/invalid internal or session token;
- `404` for missing current cart/order/resource;
- `409` for invalid business state, immutable selections, unavailable fulfillment, duplicate checkout state;
- `422` for validation failures;
- `502/503` for upstream Dots or QR generation/storage technical failures.

Payment pending is not treated as a technical failure.

## 27. Queue/background jobs

RabbitMQ processes synchronization jobs. Redis is not used as the queue backend. Run workers with `php artisan queue:work rabbitmq`.

## 28. Database model overview

Durable PostgreSQL models include:

- `City`
- `Restaurant`
- `RestaurantAddress`
- `Category`
- `Product`
- `Cart`
- `CartItem`
- `Order`
- `OrderItem`

The `orders` table stores fulfillment snapshots, Dots response payloads, online payment checkout URL, payment snapshot, QR path, and QR fingerprint.

## 29. Redis state overview

Redis stores current internal session state and OTP challenge state. Session data includes selected city/restaurant, fulfillment, and contact metadata. Redis expiration does not delete durable orders.

## 30. API endpoint reference summary

See `openapi.yaml` for full contracts. Endpoint groups:

- Cities: `GET /api/cities`
- Sessions: create, contact, OTP, city/restaurant selection, fulfillment, pickup/delivery, exit
- Catalog: restaurant catalog, categories, category products, product show, search
- Cart: create/current, add/update/remove/clear items
- Orders: create/current
- Payment: current payment state, current payment QR

## 31. Testing

Run formatting:

```bash
vendor/bin/pint --dirty --format agent
```

Run backend tests:

```bash
php artisan test --compact
```

Run E2E:

```bash
php artisan test --compact tests/E2E/DotsOrderE2ETest.php
```

Tests run in Docker app container and use a testing PostgreSQL database. External Dots calls are faked in focused tests/E2E.

## 32. Known limitations / future integration requirements

- Production SMS delivery requires a real OTP/SMS provider integration.
- Payment completion webhooks/confirmation are not implemented in this backend stage.
- Refunds are not implemented.
- Telegram and MCP user experiences are implemented in separate services and should consume this internal API.
- QR cleanup scheduling is not implemented.
