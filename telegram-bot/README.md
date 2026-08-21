# AI Food Ordering — Telegram Bot

## What it is

The Telegram bot is a thin Laravel/Nutgram client for the AI Food Ordering backend, with an optional Groq-backed AI assistant for catalog/cart assistance and order tracking.

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
- `📍 Де замовлення?` tracking by the local order number shown after checkout, with privacy-safe delivery address rendering.
- Post-order `⬅️ Назад` navigation to return to the main menu and start another order or check a previous one.
- `🤖 AI-помічник` backed by Groq for product search, cart building/updates, and existing-order tracking. Checkout, payment, OTP, and fulfillment changes remain manual.
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
     -> 📍 Де замовлення? -> enter local order number -> tracking summary -> back
     -> 🤖 AI-помічник -> search products / build cart / track an existing order -> back
  -> checkout confirmation
  -> POST backend order
  -> order/payment screen
  -> payment URL and optional backend QR PNG
  -> user-triggered order/payment refresh
  -> ⬅️ Назад -> main menu
  -> /exit or 🚪 Вийти
```

## Architecture and statelessness

The bot intentionally stores no Telegram business state persistently in a database, Redis, files, or Laravel cache.

Local runtime state is ephemeral and kept in memory. It includes the association between a Telegram chat/user and the backend `X-Session-Token`, plus short-lived prompt/navigation context and bounded AI conversation history.

```text
Telegram chat/user -> backend X-Session-Token
Telegram chat      -> prompt / AI conversation context
```

Restarting the long-running bot process can lose this in-memory state. That is expected for this prototype; users can restart onboarding and receive a fresh backend session.

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
| `GROQ_API_KEY` | Groq API key used by the optional AI assistant. Never commit a real key. |
| `GROQ_BASE_URL` | Groq OpenAI-compatible API base URL. |
| `GROQ_MODEL` | LLM model used by the assistant. Default: `openai/gpt-oss-20b`. |
| `GROQ_TIMEOUT` | Groq HTTP timeout in seconds. |
| `GROQ_MAX_COMPLETION_TOKENS` | Maximum completion tokens requested from the model. |
| `AI_HISTORY_MESSAGES` | Maximum bounded conversation history supplied to the assistant. |
| `AI_MAX_TOOL_STEPS` | Maximum LLM/tool-call steps for one user request. Default: `8`. |
| `CACHE_STORE=array` | Keeps bot runtime state in process memory for this prototype. |

Do not put real Telegram tokens, backend tokens, Groq API keys, session tokens, checkout URLs, Dots credentials, OTP codes, or payment secrets in documentation or source control.

The default AI integration uses Groq with `openai/gpt-oss-20b`. Groq provides a free tier suitable for development/testing, but it is rate-limited; temporary `429` responses can occur when request or token limits are reached.

## Installation and running

From the repository root:

```bash
cp telegram-bot/.env.example telegram-bot/.env
# Fill TELEGRAM_BOT_TOKEN, BACKEND_URL, BACKEND_INTERNAL_API_TOKEN.
# Fill GROQ_API_KEY as well if the AI assistant is enabled.

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
docker compose exec telegram-bot php artisan llm:test "Відповідай тільки словом OK"
docker compose exec telegram-bot ./vendor/bin/phpstan analyse --memory-limit=1G
docker compose exec telegram-bot ./vendor/bin/php-cs-fixer fix --dry-run --diff
```

## Telegram commands

Registered commands:

- `/start` — create or reuse a backend session and request contact sharing.
- `/exit` — close the current backend session when possible, forget the local token, and restart onboarding.

Inline callbacks cover OTP resend, city/restaurant selection, fulfillment, catalog, cart, checkout, order refresh, payment refresh, order tracking/navigation, AI assistant navigation, and exit.

## Testing

The project intentionally keeps automated tests focused. It does not maintain a full Telegram/Dots E2E automation suite. The complete real flow is expected to be tested manually against actual Telegram and backend services.

Run the complete Telegram bot test suite:

```bash
php artisan test --compact
```

Run PHPStan and PHP CS Fixer checks:

```bash
./vendor/bin/phpstan analyse --memory-limit=1G
./vendor/bin/php-cs-fixer fix --dry-run --diff
```

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
- Order tracking uses the local order number and the backend's verified-customer authorization; the Telegram presentation masks the delivery address before it is shown or supplied to the LLM.
- The AI assistant never receives the backend session token or internal API token. Its tools are limited to product search, cart operations, and read-only order tracking; checkout, payment, OTP, contact, and fulfillment actions stay outside LLM control.
- `GROQ_API_KEY` belongs only in `.env` and must never be committed.
- Payment URLs are Telegram URL buttons, not callback data.
- QR PNGs are fetched from the backend and uploaded ephemerally; the bot does not generate or persist QR images.
