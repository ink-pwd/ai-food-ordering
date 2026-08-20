# AI Food Ordering — Telegram Bot — Developer Documentation

## 1. Purpose and architecture

Telegram Bot is a thin Laravel/Nutgram client for the ordering backend service.

```text
Telegram user -> Telegram bot -> Backend REST API -> Dots
```

The Telegram service owns the Telegram presentation layer: messages, buttons, callbacks, and forwarding user actions to the backend. The backend owns business logic and state. Telegram Bot never calls Dots directly.

The Telegram user interface is Ukrainian only. This document is English developer documentation.

## 2. Service boundaries

Telegram Bot does:

- creates/reuses a backend session token;
- sends contact/OTP/city/restaurant/fulfillment/cart/order/payment actions to the backend;
- renders backend responses in Telegram;
- uploads backend-generated QR PNG bytes to Telegram.

The backend does:

- stores session/contact/city/restaurant/fulfillment/cart/order/payment state;
- validates and normalizes phone numbers;
- verifies OTP codes;
- validates delivery address, zone, and price;
- creates orders and payments;
- gets payment checkout URLs;
- generates QR through its own endpoint;
- integrates with Dots.

## 3. Complete user flow

```text
/start
  -> share contact
  -> OTP code
  -> choose city
  -> choose restaurant
  -> choose fulfillment method
     -> delivery: address type -> ForceReply address -> backend validation
     OR
     -> pickup: pickup address selection
  -> main menu
  -> catalog -> category -> product
  -> cart
  -> checkout confirmation
  -> POST /api/orders
  -> order/payment screen
  -> payment URL button
  -> backend QR PNG when available
  -> manual order/payment refresh
  -> /exit or 🚪 Вийти
```

A full automated E2E test for this path is intentionally not maintained. The full path is verified manually against real services.

## 4. Backend session lifecycle

`/start` calls `POST /api/sessions` through `OrderingBackendClient::createTelegramSession()`.

The backend returns `session_token`. Telegram Bot stores only this mapping:

```text
telegram-chat-{chatId} -> X-Session-Token
```

The store is an in-memory service in the current PHP process. Restarting `nutgram:run` can lose the token map. That is expected for this prototype.

On 401, `TelegramSessionRecovery` creates a new backend session and returns the user to contact onboarding. Interrupted write operations are not retried automatically.

## 5. Contact + OTP

A Telegram contact is accepted only when `contact.user_id` matches the sender's Telegram user id. The phone number is sent to the backend contact API without Telegram-specific country validation:

```http
PUT /api/sessions/current/contact
```

The backend remains the authoritative validator. After a successful contact update, the bot calls:

```http
POST /api/sessions/current/otp
```

The user enters the code as normal text. The numeric `onText {code}` route calls:

```http
POST /api/sessions/current/otp/verify
```

After successful verification, the bot shows city selection.

## 6. Phone normalization

Phone normalization lives in backend `UpdateSessionContactRequest`.

Generic E.164-style international `+` format is accepted:

```text
+[country code + subscriber digits]
```

Required valid examples:

```text
+380931234567
+34123456789
+14155552671
```

Automatic no-plus normalization supports unambiguous prefixes:

```text
380931234567 -> +380931234567
34123456789  -> +34123456789
14155552671  -> +14155552671
```

`00` international prefix normalizes to `+`:

```text
00380931234567 -> +380931234567
0034123456789  -> +34123456789
0014155552671  -> +14155552671
```

Ukrainian local format is preserved:

```text
0931234567 -> +380931234567
```

Spaces, hyphens, and parentheses are stripped:

```text
+380 (93) 123-45-67 -> +380931234567
+34 612 34 56 78    -> +34612345678
+1 (415) 555-2671   -> +14155552671
```

Ambiguous local numbers are not blindly converted to international numbers.

## 7. City/restaurant immutability

City and restaurant are selected through backend session endpoints. If the backend returns a conflict for a repeated selection, the bot does not create a new session and safely continues to the next step.

Further callbacks carry the backend-local `restaurantId`, not the restaurant slug.

## 8. Exit semantics

`/exit` and callback `exit` try to call:

```http
DELETE /api/sessions/current
```

Then the local token is forgotten, a new backend session is created, and the user returns to contact onboarding. Exit does not cancel an already-created backend/Dots order.

## 9. Fulfillment

### Delivery

Callbacks:

```text
fulfillment:delivery:{restaurantId}:{fp}
delivery:type:{type}:{restaurantId}:{fp}
delivery:retry:{restaurantId}:{fp}
```

Address types:

```text
0 — 🏢 Квартира
1 — 🏠 Приватний будинок
2 — 🏢 Офіс
3 — 📍 Інше
```

After type selection, the bot sends a ForceReply with marker:

```text
#delivery-address:{type}:{restaurantId}:{fp}
```

The marker makes address handling stateless. The bot does not store an address draft locally.

The address is parsed as comma-separated input:

```text
Вулиця, будинок, квартира
```

The backend receives:

```http
POST /api/sessions/current/delivery-address
```

and returns availability, reason, delivery price, Dots delivery type, and fulfillment. The bot shows Ukrainian feedback for available delivery, price, invalid address, or outside delivery zone.

### Pickup

Callbacks:

```text
fulfillment:pickup:{restaurantId}:{fp}
pickup:{restaurantAddressId}:{restaurantId}:{fp}
```

The bot gets pickup addresses from the backend and saves the selected address through the backend endpoint. Pickup state is not stored locally.

## 10. Restaurant navigation context

Callback context:

```text
{restaurantId}:{fingerprint}
```

`fingerprint` is the first 12 characters of SHA-256 over the current backend session token. It is stale-callback protection, not a secret and not standalone authorization.

Every session-bound callback validates the fingerprint against the current token and, where needed, checks that the restaurant belongs to the current session through the backend restaurants endpoint.

## 11. Callback formats

Main formats:

```text
otp:resend
exit
city:{cityId}
restaurant:{restaurantId}
fulfillment:menu:{restaurantId}:{fp}
fulfillment:delivery:{restaurantId}:{fp}
fulfillment:pickup:{restaurantId}:{fp}
delivery:retry:{restaurantId}:{fp}
delivery:type:{type}:{restaurantId}:{fp}
pickup:{restaurantAddressId}:{restaurantId}:{fp}
catalog:{restaurantId}:{fp}
category:{categoryId}:{restaurantId}:{fp}
product:{categoryId}:{productId}:{restaurantId}:{fp}
main_menu:{restaurantId}:{fp}
menu:cart:{restaurantId}:{fp}
cart:add:{productId}:{restaurantId}:{fp}
cart:inc:{itemId}:{restaurantId}:{fp}
cart:dec:{itemId}:{restaurantId}:{fp}
cart:remove:{itemId}:{restaurantId}:{fp}
cart:clear:{restaurantId}:{fp}
cart:clear:confirm:{restaurantId}:{fp}
cart:clear:cancel:{restaurantId}:{fp}
cart:noop:{itemId}:{restaurantId}:{fp}
checkout:{restaurantId}:{fp}
oc:{uuid}:{restaurantId}:{fp}
order:cancel:{restaurantId}:{fp}
order:refresh:{restaurantId}:{fp}
payment:refresh:{restaurantId}:{fp}
```

`oc:` replaces long `order:confirm:` so Telegram `callback_data` remains within 64 bytes.

## 12. Catalog

Catalog callbacks validate current restaurant context, get restaurant slug from backend current session restaurants, then call backend catalog endpoints with the internal API token. The slug is not stored in callback data.

## 13. Cart

Cart operations are session-bound and context-aware. Mutations run exactly once after successful callback acknowledgement. After delete/clear, the backend client loads the authoritative current cart.

Telegram does not calculate prices or totals locally.

## 14. Checkout

Checkout loads the current cart, shows backend totals, and creates a UUID idempotency key exactly once when generating the confirmation keyboard.

Buttons:

```text
✅ Підтвердити -> oc:{uuid}:{restaurantId}:{fp}
❌ Скасувати  -> order:cancel:{restaurantId}:{fp}
```

Cancel is pre-submit return-to-cart only. It does not cancel an already-created backend/Dots order.

## 15. Idempotency key

The UUID from the confirmation callback is passed unchanged to:

```http
POST /api/orders
Idempotency-Key: {uuid}
```

`confirm()` does not generate a new UUID and does not automatically retry POST after 401 recovery or ambiguous failure.

## 16. Order states

- `creating`: `⏳ Замовлення створюється.` and button `🔄 Оновити замовлення`.
- `created`: `✅ Замовлення створено.` and transition to payment state.
- `failed`: `❌ Не вдалося створити замовлення.`.

After successful createOrder, the bot does not call ensureNextCart/ensureCurrentCart for a next cart. The user stays in the order/payment flow.

## 17. Online payment

Payment refresh:

```text
payment:refresh:{restaurantId}:{fp}
```

It is a read-only GET-style operation. It never creates an order, generates an idempotency key, or mutates the cart.

## 18. Pending / ready / received

- `pending` or HTTP 202: `⏳ Платіжні дані ще готуються.` and `🔄 Оновити оплату`.
- `ready` + trusted HTTPS checkout URL: `💳 Оплата готова.` and URL button `💳 Оплатити`.
- `ready` does not mean paid.
- Only `payment_received_at != null` shows `✅ Оплату отримано.`.

Checkout URL is only a Telegram URL button, never callback data.

## 19. QR behavior

QR is loaded only from the backend:

```http
GET /api/orders/current/payment/qr
```

Backend ready response: HTTP 200 `image/png` raw bytes. Telegram Bot sends them through Nutgram `sendPhoto()` with an ephemeral `php://temp` stream.

Backend pending response: HTTP 202 JSON. This is not an error; the bot says QR is still being prepared.

If checkout URL is valid but QR is temporarily unavailable, the bot keeps `💳 Оплатити` available and shows a safe warning.

Telegram Bot does not generate QR locally and does not persist it.

## 20. Session recovery / 401

On 401, session-bound handlers use `TelegramSessionRecovery`. Recovery returns the user to contact onboarding. Interrupted write operations are not retried automatically.

## 21. Errors and status handling

Callback handlers call `CallbackAcknowledger` exactly once before backend work. Stale/expired callback queries safely stop the handler before backend mutation. Unexpected Telegram exceptions are not swallowed.

Backend validation details are not displayed directly. User-facing errors are Ukrainian and safe.

## 22. Localization and emoji rules

Telegram UI is Ukrainian only. Every visible button has an emoji. Backend names/content are data and are not translated.

## 23. Security

Callback data does not contain:

- backend `X-Session-Token`;
- `X-Internal-Api-Token`;
- checkout URL;
- QR bytes;
- Dots credentials;
- full address JSON;
- coordinates;
- payment secrets.

Fingerprint protects against stale context but does not replace backend session authorization. The backend remains authoritative.

## 24. Local state / persistence policy

Telegram Bot does not locally store:

- city;
- restaurant;
- fulfillment;
- address;
- cart;
- order;
- payment;
- QR;
- idempotency key.

There are no Telegram business DB models, Redis/cache business state, or local price caches.

## 25. Configuration

Main variables in `telegram-bot/.env.example`:

```env
TELEGRAM_BOT_TOKEN=
BACKEND_URL=http://app
BACKEND_INTERNAL_API_TOKEN=
BACKEND_TIMEOUT=10
CACHE_STORE=array
```

Secrets must not be committed.

## 26. Local development / run commands

From repository root:

```bash
cp telegram-bot/.env.example telegram-bot/.env
docker compose up -d
docker compose exec --user "$(id -u):$(id -g)" telegram-bot composer install
docker compose exec --user "$(id -u):$(id -g)" telegram-bot php artisan key:generate
docker compose exec telegram-bot php artisan nutgram:run
```

Check handlers:

```bash
docker compose exec telegram-bot php artisan nutgram:list
```

## 27. Testing strategy

Automated tests should be focused and cheap to maintain:

- backend client contracts;
- phone normalization;
- callback context/security;
- idempotency key;
- focused payment/QR behavior;
- formatters/helpers and small handler contracts.

Obsolete historical tests and expensive pseudo-E2E tests should be deleted rather than migrated. The full Telegram journey is manually verified against actual Telegram/backend services.

Useful commands:

```bash
php artisan test --compact tests/Feature/TelegramStartTest.php tests/Feature/TelegramContactTest.php tests/Feature/TelegramOnboardingTest.php tests/Feature/TelegramFulfillmentTest.php tests/Feature/TelegramCatalogTest.php tests/Feature/TelegramCartTest.php tests/Feature/TelegramCheckoutTest.php tests/Feature/TelegramCallbackAcknowledgementTest.php tests/Feature/TelegramMessageEditorTest.php tests/Feature/TelegramSessionManagerTest.php tests/Feature/OrderingBackendClientTest.php

cd ../backend
php artisan test --compact tests/Feature/SessionContactPhoneNormalizationTest.php
```
