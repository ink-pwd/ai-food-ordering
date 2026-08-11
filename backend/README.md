# AI Food Ordering Backend

Laravel backend service for the AI Food Ordering project.

The service integrates with the Dots API, synchronizes restaurant catalog data, stores it in PostgreSQL, exposes an internal REST API, manages user sessions and carts, validates order prices through Dots, and creates orders.

## Stack

* PHP 8.5
* Laravel 13
* PostgreSQL 17
* Redis 8
* RabbitMQ 4
* Docker Compose
* Pest / PHPUnit

## Requirements

The project requires:

* Docker
* Docker Compose
* Git

PHP, Composer, PostgreSQL, Redis, and RabbitMQ do not need to be installed locally.

---

# Project Setup

## 1. Clone the repository

```bash
git clone <repository-url>
cd ai-food-ordering
```

The repository contains the Laravel application inside the `backend` directory.

The `docker-compose.yml` file is located in the project root.

---

## 2. Create the environment file

```bash
cp backend/.env.example backend/.env
```

Open:

```text
backend/.env
```

and configure the required application and Dots API credentials.

The Docker services use the following database configuration:

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

The application also requires the Dots API credentials and internal API configuration defined in `.env.example`.

Important variables include:

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

INTERNAL_API_TOKEN=
INTERNAL_SESSION_STORE=redis
INTERNAL_SESSION_TTL_SECONDS=
INTERNAL_SESSION_KEY_PREFIX=internal-session
INTERNAL_RESTAURANT_SLUG=

```

Do not commit the real `.env` file or API tokens to Git.

---

## 3. Start Docker services

From the project root:

```bash
docker compose up -d
```

This starts:

* Laravel / Nginx application
* PostgreSQL
* Redis
* RabbitMQ
* Adminer

Check the containers:

```bash
docker compose ps
```

---

## 4. Install PHP dependencies

```bash
docker compose exec --user "$(id -u):$(id -g)" app composer install
```

---

## 5. Generate the Laravel application key

```bash
docker compose exec --user "$(id -u):$(id -g)" app \
php artisan key:generate
```

---

## 6. Run database migrations

```bash
docker compose exec --user "$(id -u):$(id -g)" app \
php artisan migrate
```

The application database will be created in the PostgreSQL `db` container.

---

# Running the Application

After startup, the backend is available at:

```text
http://localhost:8080
```

Laravel health endpoint:

```text
http://localhost:8080/up
```

Adminer:

```text
http://localhost:8081
```

Adminer connection:

```text
System: PostgreSQL
Server: db
Database: food_ordering
Username: food_ordering
Password: root
```

RabbitMQ Management UI:

```text
http://localhost:15672
```

Credentials:

```text
Username: food_ordering
Password: root
```

---

# Catalog Synchronization

Catalog synchronization is performed asynchronously through RabbitMQ.

## Start the catalog queue worker

Run:

```bash
docker compose exec --user "$(id -u):$(id -g)" app \
php artisan queue:work rabbitmq --queue=catalog-sync
```

The worker should remain running while catalog synchronization jobs are being processed.

For a single job:

```bash
docker compose exec --user "$(id -u):$(id -g)" app \
php artisan queue:work rabbitmq --queue=catalog-sync --once -v
```

## Trigger catalog synchronization

For the configured restaurant:

```bash
docker compose exec --user "$(id -u):$(id -g)" app \
php artisan catalog:sync papa-jon
```

Replace `papa-jon` with another configured restaurant slug if necessary.

The synchronization process:

1. Finds the restaurant by its local slug.
2. Uses its Dots company identifier.
3. Dispatches a synchronization job to RabbitMQ.
4. Fetches the catalog from Dots.
5. Reconciles categories and products.
6. Stores the resulting catalog in PostgreSQL.
7. Uses Redis for external API caching.

## Production scheduling

Local cron configuration is intentionally not included.

In a production environment, the catalog synchronization command should be executed periodically using Laravel Scheduler or an external cron/system scheduler.

Example command to schedule:

```bash
php artisan catalog:sync papa-jon
```

The exact synchronization interval depends on the deployment requirements.

---

# Queue Worker

The application uses RabbitMQ for asynchronous jobs.

Start the worker:

```bash
docker compose exec --user "$(id -u):$(id -g)" app \
php artisan queue:work rabbitmq
```

Catalog-only worker:

```bash
docker compose exec --user "$(id -u):$(id -g)" app \
php artisan queue:work rabbitmq --queue=catalog-sync
```

---

# Testing

The project uses a separate PostgreSQL database for automated tests.

The test database runs in the `db_test` Docker service and is enabled through the Docker Compose `test` profile.

It uses an in-memory `tmpfs` volume, so test data is not persisted between container recreations.

## Start the project with the test database

From the project root:

```bash
docker compose --profile test up -d
```

Check that the test database is running:

```bash
docker compose ps
```

You should see:

```text
food-ordering-db-test
```

along with the regular application services.

The testing database configuration is:

```text
Host: db_test
Port: 5432
Database: food_ordering_testing
Username: food_ordering
Password: root
```

Testing environment values are configured by the project's PHPUnit/Pest configuration.

---

## Run all tests

```bash
docker compose exec --user "$(id -u):$(id -g)" app \
php artisan test --compact
```

The current test suite contains:

```text
375 tests
1243 assertions
```

The test suite covers the application's main business flows, including:

* catalog synchronization;
* catalog reconciliation;
* products and categories;
* product search;
* sessions;
* session contact data;
* carts;
* cart items;
* price validation;
* order creation;
* order idempotency;
* order lifecycle;
* Dots API failures;
* Redis integration;
* RabbitMQ integration;
* internal REST API behavior.

---

## Run a specific test

Example:

```bash
docker compose exec --user "$(id -u):$(id -g)" app \
php artisan test tests/Feature/OrderApiTest.php
```

---

## Run E2E tests

```bash
docker compose exec --user "$(id -u):$(id -g)" app \
php artisan test tests/E2E/DotsOrderE2ETest.php
```

The automated E2E test executes the complete Laravel order flow inside the application:

```text
session
  ↓
contact
  ↓
cart
  ↓
cart item
  ↓
price validation
  ↓
order creation
  ↓
idempotency
  ↓
cart checkout
  ↓
order status refresh
```

External Dots HTTP requests are mocked during automated E2E tests.

This means automated tests never create real orders in the Dots environment.

The Laravel application itself, database operations, repositories, handlers, session logic, cart logic, and order lifecycle remain real during the test.

Real Dots API verification should be performed separately as a manual integration/smoke test when necessary.

---

## Recreate the test database manually

Normally this is handled automatically by the test suite.

If necessary:

```bash
docker compose exec --user "$(id -u):$(id -g)" app \
php artisan migrate:fresh --env=testing
```

---

# Code Style

Laravel Pint is used for PHP code formatting.

Check and automatically format the project:

```bash
docker compose exec --user "$(id -u):$(id -g)" app \
vendor/bin/pint
```

Before submitting changes, it is recommended to run:

```bash
docker compose exec --user "$(id -u):$(id -g)" app \
vendor/bin/pint
```

followed by:

```bash
docker compose exec --user "$(id -u):$(id -g)" app \
php artisan test --compact
```

---

# Internal API

The REST API is intended for internal clients such as the MCP service and Telegram integration.

Internal requests are protected with:

```text
X-Internal-Api-Token
```

Session-specific requests additionally require:

```text
X-Session-Token
```

A session owns the selected restaurant and cart context, so internal clients do not directly control sensitive order state such as:

* restaurant ownership;
* calculated prices;
* cart totals;
* order totals;
* order status;
* external product identifiers.

These values are resolved and validated by the backend.

A session may retain multiple historical carts, while only one cart can
be active for the selected restaurant at a time.

---

# Order Flow

The order flow follows this sequence:

```text
Client
  ↓
Internal REST API
  ↓
Session
  ↓
Cart
  ↓
Price validation through Dots
  ↓
Local order creation
  ↓
Dots order creation
  ↓
Local order lifecycle update
```

Dots is the authoritative source for the final order price.

The locally stored catalog price is not assumed to be the final checkout price because promotions or other Dots-side pricing rules may change the total.

The backend therefore validates the cart through Dots immediately before creating an order.

---

# Idempotency

Order creation requires an:

```text
Idempotency-Key
```

header.

Repeating the same order request with the same idempotency key returns the existing local order and does not create another Dots order.

This protects against duplicate orders caused by retries or network failures.

---

# Docker Services

The Docker Compose environment contains:

| Service    | Purpose                              |     Host port |
| ---------- | ------------------------------------ | ------------: |
| `app`      | Laravel + Nginx                      |        `8080` |
| `db`       | PostgreSQL production/local database |        `5433` |
| `db_test`  | PostgreSQL test database             | internal only |
| `redis`    | Cache and runtime storage            |        `6379` |
| `rabbitmq` | Message broker                       |        `5672` |
| `rabbitmq` | Management UI                        |       `15672` |
| `adminer`  | Database administration              |        `8081` |

---

# Useful Commands

Start the project:

```bash
docker compose up -d
```

Start including the test database:

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

View application logs:

```bash
docker compose logs -f app
```

View RabbitMQ logs:

```bash
docker compose logs -f rabbitmq
```

Open a shell inside the application container:

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

* PostgreSQL is used both in development and automated tests.
* SQLite is intentionally not used because application behavior relies on PostgreSQL-specific functionality.
* Redis is used for caching and internal runtime data.
* RabbitMQ is used for asynchronous processing.
* External Dots API calls are isolated behind the backend integration layer.
* Automated tests mock external Dots HTTP requests where appropriate.
* The MCP service and Telegram client are separate services and should communicate with this backend through its internal REST API.
* Business logic remains inside the Laravel backend rather than being duplicated in external clients.
