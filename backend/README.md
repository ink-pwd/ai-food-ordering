# AI Food Ordering Backend

Laravel backend service for the AI Food Ordering project.

The backend integrates with the Dots API, synchronizes and stores restaurant catalog data, manages sessions, carts and orders, and exposes an internal REST API for the Telegram bot and MCP server.

Business logic remains inside this service. External clients do not communicate with Dots directly.

## Stack

* PHP 8.5
* Laravel 13
* PostgreSQL 17
* Redis 8
* RabbitMQ 4
* Docker Compose
* Pest / PHPUnit

## Requirements

* Docker
* Docker Compose
* Git

PHP, Composer, PostgreSQL, Redis and RabbitMQ do not need to be installed locally.

---

# Setup

All commands below are executed from the project root unless stated otherwise.

## 1. Clone the repository

```bash
git clone <repository-url>
cd ai-food-ordering
```

The backend application is located in:

```text
backend/
```

The Docker Compose configuration is located in the project root:

```text
docker-compose.yml
```

## 2. Create the environment file

```bash
cp backend/.env.example backend/.env
```

Configure the required values in:

```text
backend/.env
```

Important Dots configuration:

```env
DOTS_API_VERSION=2.1.0
DOTS_API_BASE_URL=
DOTS_API_ACCOUNT_TOKEN=
DOTS_API_TOKEN=
DOTS_API_AUTH_TOKEN=
DOTS_CATALOG_CACHE_TTL_SECONDS=300

DOTS_CITY_ID=
DOTS_COMPANY_ID=
DOTS_COMPANY_ADDRESS_ID=
```

Internal API configuration:

```env
INTERNAL_API_TOKEN=
INTERNAL_SESSION_STORE=redis
INTERNAL_SESSION_TTL_SECONDS=
INTERNAL_SESSION_KEY_PREFIX=internal-session
INTERNAL_RESTAURANT_SLUG=
```

Database configuration:

```env
DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=food_ordering
DB_USERNAME=food_ordering
DB_PASSWORD=root
```

Redis:

```env
REDIS_HOST=redis
REDIS_PORT=6379
```

RabbitMQ:

```env
RABBITMQ_HOST=rabbitmq
RABBITMQ_PORT=5672
RABBITMQ_USER=food_ordering
RABBITMQ_PASSWORD=root
```

Do not commit the real `.env` file or API tokens to Git.

## 3. Start Docker services

```bash
docker compose up -d
```

Check the containers:

```bash
docker compose ps
```

## 4. Install PHP dependencies

```bash
docker compose exec --user "$(id -u):$(id -g)" app \
composer install
```

## 5. Generate the Laravel application key

```bash
docker compose exec --user "$(id -u):$(id -g)" app \
php artisan key:generate
```

## 6. Run database migrations

```bash
docker compose exec --user "$(id -u):$(id -g)" app \
php artisan migrate
```

## 7. Create the local restaurant

Catalog synchronization requires a local Restaurant record.

`DOTS_COMPANY_ID` and `INTERNAL_RESTAURANT_SLUG` are read from `backend/.env`:

```bash
docker compose exec --user "$(id -u):$(id -g)" app \
php artisan restaurant:create \
"$(grep '^DOTS_COMPANY_ID=' backend/.env | cut -d= -f2- | tr -d "\"'")" \
"Papa Jon" \
"$(grep '^INTERNAL_RESTAURANT_SLUG=' backend/.env | cut -d= -f2- | tr -d "\"'")"
```

If the database is recreated with `migrate:fresh`, this command must be executed again before catalog synchronization.

## 8. Synchronize the catalog

Dispatch the initial catalog synchronization job:

```bash
docker compose exec --user "$(id -u):$(id -g)" app \
php artisan catalog:sync \
"$(grep '^INTERNAL_RESTAURANT_SLUG=' backend/.env | cut -d= -f2- | tr -d "\"'")"
```

Process the job:

```bash
docker compose exec --user "$(id -u):$(id -g)" app \
php artisan queue:work rabbitmq --queue=catalog-sync --once -v
```

The synchronization process:

1. Resolves the local Restaurant.
2. Fetches the catalog from Dots.
3. Reconciles categories and products.
4. Stores catalog data in PostgreSQL.
5. Uses Redis for external API caching.

---

# Running the Application

The backend is available at:

```text
http://localhost:8080
```

Laravel health endpoint:

```text
http://localhost:8080/up
```

## Catalog queue worker

During normal operation, keep the catalog worker running:

```bash
docker compose exec --user "$(id -u):$(id -g)" app \
php artisan queue:work rabbitmq --queue=catalog-sync
```

To trigger catalog synchronization manually:

```bash
docker compose exec --user "$(id -u):$(id -g)" app \
php artisan catalog:sync \
"$(grep '^INTERNAL_RESTAURANT_SLUG=' backend/.env | cut -d= -f2- | tr -d "\"'")"
```

Production scheduling is deployment-specific and may use Laravel Scheduler or an external scheduler.

---

# Internal API

The REST API is intended for internal clients such as:

* Telegram bot
* MCP server

Requests are protected with:

```text
X-Internal-Api-Token
```

Session-bound requests additionally require:

```text
X-Session-Token
```

Order creation additionally requires:

```text
Idempotency-Key
```

The backend owns and validates:

* restaurant selection;
* sessions;
* catalog data;
* cart state;
* product prices;
* cart totals;
* order totals;
* Dots identifiers;
* order lifecycle.

External clients act only as thin interfaces over the internal REST API.

A session may retain multiple historical carts, while only one cart can be active for the selected restaurant at a time.

---

# Order Flow

```text
Client
  ↓
Internal REST API
  ↓
Session
  ↓
Cart
  ↓
Dots price validation
  ↓
Local order creation
  ↓
Dots order creation
  ↓
Local order lifecycle update
```

Dots is the authoritative source for the final order price.

The locally stored catalog price is not assumed to be the final checkout price because promotions or other Dots-side pricing rules may change the total.

The backend validates the cart through Dots immediately before order creation.

## Idempotency

Order creation requires an `Idempotency-Key` header.

Repeating the same order request with the same idempotency key returns the existing local order instead of creating another Dots order.

This protects against duplicate orders caused by retries or network failures.

---

# Testing

Automated tests use a separate PostgreSQL database in the `db_test` Docker service.

The test database is enabled through the Docker Compose `test` profile and uses an in-memory `tmpfs` volume.

## Start the test environment

```bash
docker compose --profile test up -d
```

## Run all tests

```bash
docker compose exec --user "$(id -u):$(id -g)" app \
php artisan test --compact
```

Current test suite:

```text
375 tests
1243 assertions
```

The tests cover:

* catalog synchronization;
* catalog reconciliation;
* categories and products;
* product search;
* sessions;
* contact data;
* carts and cart items;
* price validation;
* order creation;
* order idempotency;
* order lifecycle;
* Dots API failures;
* Redis integration;
* RabbitMQ integration;
* internal REST API behavior.

## Run a specific test

```bash
docker compose exec --user "$(id -u):$(id -g)" app \
php artisan test tests/Feature/OrderApiTest.php
```

## Run the E2E test

```bash
docker compose exec --user "$(id -u):$(id -g)" app \
php artisan test tests/E2E/DotsOrderE2ETest.php
```

Automated E2E tests mock external Dots HTTP requests and therefore do not create real Dots orders.

The Laravel application, database operations, repositories, handlers, session logic, cart logic and order lifecycle remain real.

Real Dots API verification should be performed separately as a manual integration or smoke test.

## Recreate the test database

Normally this is handled by the test suite.

If necessary:

```bash
docker compose exec --user "$(id -u):$(id -g)" app \
php artisan migrate:fresh --env=testing
```

---

# Code Style

Laravel Pint is used for formatting.

Format the project:

```bash
docker compose exec --user "$(id -u):$(id -g)" app \
vendor/bin/pint
```

Before committing changes:

```bash
docker compose exec --user "$(id -u):$(id -g)" app \
vendor/bin/pint --test
```

```bash
docker compose exec --user "$(id -u):$(id -g)" app \
php artisan test --compact
```

---

# Docker Services

| Service    | Purpose                   |     Host port |
| ---------- | ------------------------- | ------------: |
| `app`      | Laravel + Nginx           |        `8080` |
| `db`       | PostgreSQL                |        `5433` |
| `db_test`  | PostgreSQL test database  | internal only |
| `redis`    | Cache and runtime storage |        `6379` |
| `rabbitmq` | RabbitMQ                  |        `5672` |
| `rabbitmq` | Management UI             |       `15672` |
| `adminer`  | Database administration   |        `8081` |

Adminer:

```text
http://localhost:8081
```

RabbitMQ Management UI:

```text
http://localhost:15672
```

---

# Useful Commands

Start the project:

```bash
docker compose up -d
```

Start with the test database:

```bash
docker compose --profile test up -d
```

Stop containers:

```bash
docker compose down
```

Stop containers and remove persistent volumes:

```bash
docker compose down -v
```

View backend logs:

```bash
docker compose logs -f app
```

View RabbitMQ logs:

```bash
docker compose logs -f rabbitmq
```

Open a shell inside the backend container:

```bash
docker compose exec --user "$(id -u):$(id -g)" app sh
```

Run an Artisan command:

```bash
docker compose exec --user "$(id -u):$(id -g)" app \
php artisan <command>
```

Clear Laravel caches:

```bash
docker compose exec --user "$(id -u):$(id -g)" app \
php artisan optimize:clear
```

---

# Development Notes

* PostgreSQL is used in both development and automated tests.
* SQLite is intentionally not used because the application relies on PostgreSQL-specific behavior.
* Redis is used for caching and internal runtime data.
* RabbitMQ is used for asynchronous catalog synchronization.
* External Dots API access is isolated behind the backend integration layer.
* Automated tests mock Dots HTTP requests where appropriate.
* Telegram and MCP are separate thin clients that communicate with this backend through its internal REST API.
* Business logic must not be duplicated in Telegram or MCP services.
