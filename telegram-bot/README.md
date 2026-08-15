# AI Food Ordering — Telegram Bot

## What it is

The Telegram bot is a thin Laravel/Nutgram client for the AI Food Ordering backend.

```text
Telegram user -> Telegram bot -> Backend REST API -> Dots
```

The bot presents Telegram messages and inline keyboards, forwards user intent to the backend, and renders backend responses. The backend owns business logic and state: sessions, contact verification, city and restaurant selection, fulfillment, catalog data, carts, orders, payment status, payment links, and QR generation. The Telegram bot does not call Dots directly.

## Features

- Telegram backend session creation.
- Contact sharing with backend-owned phone validation and OTP verification.
- City and restaurant selection.
- Delivery or pickup fulfillment.
- Delivery address validation, zone/price feedback, and pickup address selection.
- Main menu, catalog, categories, product cards, and add-to-cart.
- Cart display, quantity changes, item removal, and cart clearing.
- Checkout confirmation with backend totals.
- Backend order creation with idempotency key.
- Order status refresh.
- Online payment status refresh.
- Telegram URL button for backend checkout URL.
- Backend-generated payment QR PNG upload.
- `/exit` and `🚪 Вийти` to close the current backend session and restart onboarding.

## User flow

```text
/start
  -> share phone contact
  -> enter OTP
  -> choose city
  -> choose restaurant
  -> choose delivery or pickup
     -> delivery address validation
     OR pickup address selection
  -> main menu
  -> catalog -> category -> product -> cart
  -> checkout confirmation
  -> POST backend order
  -> order/payment screen
  -> payment URL and optional backend QR PNG
  -> user-triggered order/payment refresh
  -> /exit or 🚪 Вийти
```

## Architecture and statelessness

The bot intentionally stores no Telegram business state in a database, Redis, files, or Laravel cache.

The only local runtime state is an in-memory association:

```text
Telegram chat/user -> backend X-Session-Token
```

Restarting the long-running bot process can lose this map. That is expected for this prototype; users can restart onboarding and receive a fresh backend session.

Restaurant navigation context travels in callback data as:

```text
{restaurantId}:{12-char session fingerprint}
```

The fingerprint protects against stale callbacks without exposing the backend session token. Catalog, cart, checkout, order, and payment callbacks include that context where required.

## Requirements

From `composer.json`:

- PHP `^8.3`.
- Laravel Framework `^13.8`.
- `nutgram/laravel` `^1.7`.
- Pest `^5.1` for tests.
- Laravel Pint `^1.27` for formatting.

The local Docker Compose setup uses `webdevops/php-nginx:8.5-alpine` for the Telegram container.

## Configuration

Important variables from `.env.example`:

| Variable | Purpose |
| --- | --- |
| `TELEGRAM_BOT_TOKEN` | Telegram bot token used by Nutgram. Never commit a real token. |
| `BACKEND_URL` | Ordering backend base URL. In Compose, `http://app`. |
| `BACKEND_INTERNAL_API_TOKEN` | Shared internal token sent as `X-Internal-Api-Token`. Never commit a real token. |
| `BACKEND_TIMEOUT` | Backend HTTP timeout in seconds. |
| `CACHE_STORE=array` | Keeps bot runtime state in process memory for this prototype. |

Do not put real Telegram tokens, backend tokens, session tokens, checkout URLs, Dots credentials, OTP codes, or payment secrets in documentation or source control.

## Installation and running

From the repository root:

```bash
cp telegram-bot/.env.example telegram-bot/.env
# Fill TELEGRAM_BOT_TOKEN, BACKEND_URL, BACKEND_INTERNAL_API_TOKEN.

docker compose up -d

docker compose exec --user "$(id -u):$(id -g)" telegram-bot composer install
docker compose exec --user "$(id -u):$(id -g)" telegram-bot php artisan key:generate
```

Run long polling:

```bash
docker compose exec telegram-bot php artisan nutgram:run
```

Useful commands:

```bash
docker compose exec telegram-bot php artisan nutgram:list
docker compose exec telegram-bot php artisan optimize:clear
docker compose exec telegram-bot vendor/bin/pint --dirty --format agent
```

## Telegram commands

Registered commands:

- `/start` — create or reuse a backend session and request contact sharing.
- `/exit` — close the current backend session when possible, forget the local token, and restart onboarding.

Inline callbacks cover OTP resend, city/restaurant selection, fulfillment, catalog, cart, checkout, order refresh, payment refresh, and exit.

## Testing

The project intentionally keeps automated tests focused. It does not maintain a full Telegram/Dots E2E automation suite. The complete real flow is expected to be tested manually against actual Telegram and backend services.

Useful focused commands:

```bash
php artisan test --compact tests/Feature/TelegramStartTest.php tests/Feature/TelegramContactTest.php tests/Feature/TelegramOnboardingTest.php
php artisan test --compact tests/Feature/TelegramFulfillmentTest.php tests/Feature/TelegramCatalogTest.php tests/Feature/TelegramCartTest.php tests/Feature/TelegramCheckoutTest.php
php artisan test --compact tests/Feature/TelegramCallbackAcknowledgementTest.php tests/Feature/TelegramMessageEditorTest.php tests/Feature/TelegramSessionManagerTest.php tests/Feature/OrderingBackendClientTest.php
```

Backend phone normalization has its own focused backend test file:

```bash
cd ../backend
php artisan test --compact tests/Feature/SessionContactPhoneNormalizationTest.php
```

## Security notes

- `X-Internal-Api-Token` is sent only to the backend.
- `X-Session-Token` is kept only in the in-memory session store and request headers to the backend.
- Callback data never contains backend session tokens, internal tokens, checkout URLs, QR bytes, Dots credentials, full address JSON, or payment secrets.
- Order confirmation uses compact callback data: `oc:{uuid}:{restaurantId}:{fp}`.
- The UUID from the confirmation callback is forwarded unchanged as `Idempotency-Key`; it is not regenerated in `confirm()`.
- Stale callbacks are acknowledged once and stopped before backend mutations.
- Order and payment refresh callbacks are read-only.
- Payment URLs are Telegram URL buttons, not callback data.
- QR PNGs are fetched from the backend and uploaded ephemerally; the bot does not generate or persist QR images.
