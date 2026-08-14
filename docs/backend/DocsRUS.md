# AI Food Ordering Backend — техническая документация

## 1. Обзор

Backend является основным бизнес-сервисом AI Food Ordering. Telegram и MCP работают как тонкие клиенты и обращаются к внутреннему REST API backend. Backend управляет состоянием сессий, синхронизированными из Dots городами/ресторанами/каталогом, корзинами, checkout, заказами, онлайн-оплатой и QR-кодами для оплаты.

Backend не доверяет клиентским Dots идентификаторам, ценам, checkout URL, QR путям или владельцу заказа. Эти значения выводятся только из проверенного состояния в PostgreSQL и Redis.

## 2. Архитектура

```text
Telegram / MCP clients
  -> Backend internal REST API
  -> Dots Clients API
```

Текущий pipeline:

```text
Middleware -> FormRequest -> Controller -> Handler/Service -> Repository -> Model/Database
```

Controllers остаются тонкими. Handlers/Services содержат бизнес-логику. Repositories отвечают за записи в хранилища. Все внешние вызовы Dots идут через `App\Integrations\Dots`.

## 3. Инфраструктура и сервисы

- Laravel 13, PHP 8.5.
- PostgreSQL: города, рестораны, адреса, каталог, корзины, заказы, QR metadata.
- Redis: runtime-сессии и OTP state.
- RabbitMQ: фоновые задачи синхронизации topology/catalog.
- Dots Clients API: topology, catalog, fulfillment, price validation, orders, online payment data.
- `endroid/qr-code`: генерация PNG QR на backend.

## 4. Environment/configuration

Код читает настройки через Laravel `config()`, а не напрямую через `env()`.

Ключевые настройки:

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

Все API routes защищены `internal.api` и требуют:

```text
X-Internal-Api-Token: <internal token>
```

Session-bound routes дополнительно требуют:

```text
X-Session-Token: <64-character session token>
```

Создание заказа требует:

```text
Idempotency-Key: <client retry key>
```

Backend не принимает произвольные session id, Dots city/company/address ids, payment URL, QR path, цены и totals. Current cart/order всегда резолвятся по authenticated current session.

## 6. Жизненный цикл сессии

`POST /api/sessions` создает активную Redis-backed сессию и возвращает `session_token`. Новая сессия не содержит city/restaurant. Далее клиент сохраняет contact, проходит OTP, выбирает city, restaurant, fulfillment и переходит к cart/checkout.

`DELETE /api/sessions/current` закрывает сессию и abandoned unfinished active carts. Historical orders и QR metadata остаются в PostgreSQL.

## 7. OTP lifecycle

`PUT /api/sessions/current/contact` сохраняет contact и сбрасывает `phone_verified` в `false`. Смена contact инвалидирует существующие OTP challenges.

`POST /api/sessions/current/otp` создает challenge с hashed code, TTL, cooldown и attempts_remaining.

`POST /api/sessions/current/otp/verify` проверяет code, удаляет challenge и устанавливает `metadata.contact.phone_verified = true`.

Local/testing OTP sender пишет/фейкует код. Production SMS provider в этом backend этапе не реализован.

## 8. Города и рестораны

Cities и Restaurants синхронизируются из Dots topology. Пользователь выбирает active city, затем получает список active restaurants в этом city и выбирает restaurant. City и restaurant immutable в рамках сессии.

## 9. Catalog synchronization

Текущая команда:

```bash
php artisan catalog:sync
```

Она ставит в очередь `SyncDotsTopology`, который синхронизирует cities, restaurants, restaurant addresses, categories и products. Worker:

```bash
php artisan queue:work rabbitmq
```

## 10. Catalog browsing/search

Catalog endpoints защищены internal token и не требуют session token. Route parameter `{restaurant}` сейчас используется как restaurant slug для product/category/catalog controllers.

Операции: full catalog, categories, category products, product show, product search. Ответы идут через JSON resources и содержат только безопасные поля каталога.

## 11. Fulfillment

Fulfillment выбирается после city/restaurant и verified phone. До checkout fulfillment можно менять, если cart/order state позволяет. Переключение delivery/pickup очищает stale state предыдущего режима.

Типы:

- `pickup`
- `delivery`

## 12. Pickup

Pickup требует выбранного active pickup address (`RestaurantAddress`) выбранного ресторана. В session сохраняются backend-trusted local/external address ids.

## 13. Delivery

Клиент передает только человекочитаемые поля адреса. Backend добавляет trusted Dots city id, валидирует адрес через Dots, получает trusted coordinates и проверяет delivery types выбранного restaurant.

## 14. Address validation

Endpoint delivery-address запрещает client coordinates и Dots city id. Dots должен вернуть selected city id, `inCityPolygon = true` и coordinates. Если адрес невалиден, возвращается `delivery_available: false`, например `reason: invalid_address`.

## 15. Delivery-zone validation

Backend вызывает Dots company delivery types для selected restaurant и trusted coordinates. Если нет подходящего delivery type, возвращается `delivery_available: false`, `reason: outside_delivery_zone`. Если доставка доступна, session хранит Dots delivery type, delivery price и trusted normalized address.

## 16. Cart lifecycle

Сессия создает current active cart после restaurant и fulfillment readiness. Items добавляются по local `product_id`; backend сам выводит product, external_product_id, unit price, totals, currency и restaurant.

Statuses: active, checked_out, expired, abandoned. Exit abandon active unfinished carts. Checkout переводит cart в checked_out.

## 17. Checkout

`POST /api/orders` проверяет current session, verified phone, fulfillment readiness, active cart и product availability. Dots payload строится только из trusted backend state.

## 18. Dots price validation

Перед заказом backend вызывает Dots cart price validation. `totalPrice` от Dots становится authoritative local order total. Локальные catalog prices не считаются финальной ценой checkout.

## 19. Order creation

Order хранит restaurant/cart/session/idempotency, channel, receiving type, payment type `2`, customer contact, authoritative total, fulfillment snapshot и request payload. После local order backend вызывает Dots order creation и сохраняет external order id. Status остается `creating`, пока async status check не подтвердит order.

## 20. Idempotency

`Idempotency-Key` обязателен. Повтор того же key в той же session возвращает existing order и не создает второй Dots order. Если payment data отсутствовала, replay может дозагрузить ее из Dots.

## 21. Async Dots order lifecycle

`GET /api/orders/current` возвращает current order. Если status `creating` и есть external order id, backend проверяет Dots order status. Успешный ответ переводит локальный order в `created`. 404/connection/transient failures не ломают order.

## 22. Online payment lifecycle

Orders используют online payment (`paymentType = 2`). Dots создает payment для Dots order. Backend запрашивает `/online-payment-data` и использует bounded polling.

## 23. Payment pending/ready

Если Dots payment data еще недоступна, payment остается `pending`, order валиден, второй Dots order не создается. Когда Dots возвращает valid HTTPS `onlinePayment.checkoutUrl`, backend сохраняет его и payment становится `ready`.

`GET /api/orders/current/payment` возвращает state и может позже разрешить pending payment.

## 24. QR generation/storage

`GET /api/orders/current/payment/qr` возвращает PNG bytes для ready payment.

Правила:

- источник — только persisted trusted `orders.payment_checkout_url`;
- URL/path из request не принимаются;
- PNG генерируется `endroid/qr-code`;
- private Laravel `local` disk;
- path: `payment-qr/{order-id}.png`;
- fingerprint: `sha256(checkoutUrl)`;
- matching file переиспользуется;
- stale QR регенерируется при изменении checkout URL.

Pending payment возвращает HTTP `202` JSON pending и не создает файл.

## 25. Exit/abandon behavior

Exit закрывает Redis session и abandons active unfinished carts. Historical orders, payment URLs, QR metadata/files не удаляются. Abandoned carts без order не имеют QR.

## 26. Error handling

Типовые ответы:

- `401` — invalid/missing internal or session token;
- `404` — current cart/order/resource отсутствует;
- `409` — business conflict или недоступное состояние;
- `422` — validation error;
- `502/503` — upstream Dots или technical QR generation/storage failure.

Payment pending не является technical failure.

## 27. Queue/background jobs

RabbitMQ обрабатывает synchronization jobs. Redis не используется как queue backend. Worker: `php artisan queue:work rabbitmq`.

## 28. Database model overview

Основные durable models:

- `City`
- `Restaurant`
- `RestaurantAddress`
- `Category`
- `Product`
- `Cart`
- `CartItem`
- `Order`
- `OrderItem`

`orders` хранит fulfillment snapshots, Dots payloads, checkout URL, payment snapshot, QR path и QR fingerprint.

## 29. Redis state overview

Redis хранит current session state и OTP challenges. Session содержит selected city/restaurant, fulfillment и contact metadata. Redis expiration не удаляет durable orders.

## 30. API endpoint reference summary

Полный контракт см. в `openapi.yaml`. Группы endpoints:

- Cities
- Sessions/contact/OTP/selection/exit
- Fulfillment/pickup/delivery
- Catalog
- Cart
- Orders
- Payment
- QR

## 31. Testing

Форматирование:

```bash
vendor/bin/pint --dirty --format agent
```

Тесты backend:

```bash
php artisan test --compact
```

E2E:

```bash
php artisan test --compact tests/E2E/DotsOrderE2ETest.php
```

External Dots calls в тестах фейкуются.

## 32. Known limitations / future integration requirements

- Production SMS provider не реализован.
- Payment completion webhook/confirmation не реализованы.
- Refunds не реализованы.
- Telegram и MCP реализуются отдельными сервисами.
- QR cleanup scheduler не реализован.
