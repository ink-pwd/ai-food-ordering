# AI Food Ordering Telegram Bot

Laravel Telegram bot service for the AI Food Ordering project.

The service receives Telegram commands, contacts, and inline-keyboard callbacks, presents data returned by the Ordering Backend, and forwards user actions to the backend REST API through Nutgram.

```text
Telegram User
    ↓
Telegram Bot
    ↓
Laravel telegram-bot service
    ↓
AI Food Ordering Backend REST API
    ↓
Dots
```

The Telegram service is a client and presentation layer. It does not own catalog, cart, pricing, restaurant-selection, or order business logic and never communicates with Dots directly. Those responsibilities belong to the backend.

Detailed service documentation is available in [DocsRUS.md](DocsRUS.md) and [DocsENG.md](DocsENG.md).

## Stack

* PHP 8.5 in Docker (`composer.json` supports PHP 8.3 or newer)
* Laravel 13
* Nutgram 4 through `nutgram/laravel`
* Docker Compose
* Pest 5 / PHPUnit 13

## Requirements

The recommended local setup requires:

* Docker;
* Docker Compose;
* Git;
* a Telegram bot token;
* a configured and running AI Food Ordering Backend.

PHP and Composer do not need to be installed on the host when Docker is used.

---

# Project Setup

Commands in this section are run from the repository root.

## 1. Clone the repository

```bash
git clone <repository-url>
cd ai-food-ordering
```

The Laravel application is in the `telegram-bot` directory. The shared `docker-compose.yml` is in the repository root.

---

## 2. Create the environment file

```bash
cp telegram-bot/.env.example telegram-bot/.env
```

Configure the Telegram and backend integration values in `telegram-bot/.env`:

```env
TELEGRAM_BOT_TOKEN=

BACKEND_URL=http://app
BACKEND_INTERNAL_API_TOKEN=
BACKEND_RESTAURANT_SLUG=papa-jon
BACKEND_TIMEOUT=10

CACHE_STORE=array
```

The variables have the following purposes:

| Variable | Purpose |
| --- | --- |
| `TELEGRAM_BOT_TOKEN` | Secret token used by Nutgram to communicate with Telegram. |
| `BACKEND_URL` | Ordering Backend base URL. `http://app` is the Docker Compose service address. |
| `BACKEND_INTERNAL_API_TOKEN` | Shared secret sent to the backend as `X-Internal-Api-Token`. It must match the backend internal API token. |
| `BACKEND_RESTAURANT_SLUG` | Local backend restaurant slug used by catalog endpoints. |
| `BACKEND_TIMEOUT` | Backend HTTP timeout in seconds. |
| `CACHE_STORE` | Laravel/Nutgram cache driver. The current prototype intentionally uses the in-process `array` driver. |

When running the Telegram application directly on the host rather than in Docker, point `BACKEND_URL` to the host-accessible backend URL, such as `http://localhost:8080`.

Generate a unique `APP_KEY` during setup. Keep the real `.env`, Telegram token, internal API token, and application key out of version control.

`CACHE_STORE=array` belongs in `.env`; it should not be passed as an extra `docker compose exec -e` option. The Telegram service does not need database-backed cache for its runtime state.

---

## 3. Start Docker services

```bash
docker compose up -d
```

This starts the services defined by the shared Compose project, including the Ordering Backend and `telegram-bot` containers.

Check their status:

```bash
docker compose ps
```

The Telegram container mounts `./telegram-bot` at `/app` and reaches the backend through the Compose service name `app`.

---

## 4. Install PHP dependencies

Use the host user's UID and GID so generated files remain writable on the host:

```bash
docker compose exec --user "$(id -u):$(id -g)" telegram-bot \
composer install
```

---

## 5. Generate the Laravel application key

```bash
docker compose exec --user "$(id -u):$(id -g)" telegram-bot \
php artisan key:generate
```

The current Telegram flows do not require local application persistence or a Telegram-specific database migration. Standard Laravel database infrastructure remains available for future framework use.

---

# Running the Bot

Start Telegram long polling manually:

```bash
docker compose exec telegram-bot php artisan nutgram:run
```

`nutgram:run` is a long-running polling process. Keep the command running while manually testing the bot and stop it with `Ctrl+C` when finished.

Polling is intentionally manual in the current local development setup. Supervisor, polling autostart, and automatic polling-process restarts are not configured.

After changing Telegram PHP code, handlers, keyboards, formatters, or integration code, stop and restart `nutgram:run`. The long-lived process continues using the application code it already loaded. Backend-only changes do not require restarting Telegram polling.

Do not start a second polling process for the same bot token while another instance is active.

---

# Current Telegram Flow

```text
/start
  ↓
Backend session
  ↓
Contact sharing
  ↓
Main menu
  ├── Catalog
  │     ↓
  │   Category → Product → Add to cart
  └── Cart
        ↓
      Checkout confirmation
        ↓
      Order → Optional status refresh
```

The main menu contains the currently functional `🍕 Каталог` and `🛒 Корзина` actions.

---

# State Model

The service intentionally does not persist Telegram user, catalog, cart, checkout, or order state in its own database, Redis, files, or Laravel cache.

The backend `X-Session-Token` is the only locally retained runtime value. `TelegramSessionStore` keeps it ephemerally in the memory of the running PHP polling process, keyed by a stable Telegram chat identifier.

Restarting the bot process clears this local token map. A user may therefore be returned to contact onboarding so the service can create or resolve a backend session again. This is the intended prototype design, not an application error.

The Ordering Backend remains authoritative for sessions, contact data, catalog data, cart contents and totals, cart lifecycle, orders, and order status.

---

# Testing

Run the complete suite from the project root:

```bash
docker compose exec telegram-bot php artisan test --compact
```

The current test suite contains:

```text
102 tests
632 assertions
```

The suite covers:

* Ordering Backend HTTP contracts, headers, response normalization, malformed responses, and safe exceptions;
* backend session initialization, in-process token reuse, missing-token recovery, and `401` recovery;
* contact ownership validation and backend contact onboarding;
* catalog category, product-list, and product-card navigation;
* cart rendering, add, increment, decrement, remove, clear confirmation, and authoritative refresh behavior;
* checkout confirmation and backend-provided totals;
* order creation payloads, UUID idempotency keys, repeated confirmation behavior, and current-order refresh;
* post-order creation of the next active cart through the backend;
* safe handling of backend failures, validation failures, conflicts, ambiguous order results, and restaurant-hours rejection;
* centralized callback acknowledgement, stale callbacks, and idempotent Telegram message editing;
* inline keyboards and formatted Telegram messages exercised through the user flows.

Laravel HTTP fakes prevent real backend requests, and Nutgram fakes prevent real Telegram calls during automated tests.

Run one test file when working on a focused area:

```bash
docker compose exec telegram-bot \
php artisan test --compact tests/Feature/TelegramCartTest.php
```

---

# Code Style

Laravel Pint formats the PHP codebase:

```bash
docker compose exec --user "$(id -u):$(id -g)" telegram-bot \
vendor/bin/pint
```

Before submitting changes, run Pint followed by the complete test suite.

---

# Verification

Useful non-polling verification commands are:

```bash
docker compose exec telegram-bot php artisan about
docker compose exec telegram-bot php artisan route:list
docker compose exec telegram-bot php artisan nutgram:list
docker compose exec telegram-bot php artisan test --compact
docker compose exec telegram-bot vendor/bin/pint
```

`php artisan route:list` shows Laravel HTTP routes, including the health endpoint. `php artisan nutgram:list` separately shows the registered Telegram command, contact, and callback handlers.

---

# Useful Commands

Start all Compose services:

```bash
docker compose up -d
```

Stop all Compose services:

```bash
docker compose down
```

Check container state:

```bash
docker compose ps
```

Open a shell inside the Telegram container:

```bash
docker compose exec telegram-bot sh
```

Clear Laravel's generated caches:

```bash
docker compose exec telegram-bot php artisan optimize:clear
```

List Telegram handlers without starting polling:

```bash
docker compose exec telegram-bot php artisan nutgram:list
```

---

# Development Notes

* Telegram routes are registered in `routes/telegram.php`; Laravel HTTP routing remains separate.
* All Ordering Backend HTTP calls are contained in `OrderingBackendClient`.
* Catalog requests use the configured restaurant slug and internal API token but do not require a backend session token.
* Contact, cart, and order requests additionally send `X-Session-Token`.
* Order creation additionally sends the UUID from the confirmation callback as `Idempotency-Key`.
* Telegram sends only user intent. It never calculates or submits trusted product prices, item totals, cart totals, order totals, restaurant IDs, or Dots identifiers.
* Callback queries are acknowledged before backend work. Expired callback queries stop safely before any mutation.
* Automated tests use fake Telegram and backend transports; manual polling is required for live bot verification.
