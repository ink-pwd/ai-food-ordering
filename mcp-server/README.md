# AI Food Ordering MCP Server

Laravel MCP service for the AI Food Ordering project.

The MCP server is a thin adapter between MCP clients such as ChatGPT and the main AI Food Ordering backend. It exposes the restaurant catalog, customer, cart and order flows as MCP tools while keeping all business logic inside the backend service.

The MCP server never communicates with Dots Platform directly and does not maintain its own business database, cart or order state.

## Stack

* PHP 8.5
* Laravel 13
* Laravel MCP 0.9
* Docker Compose
* Pest / PHPUnit

## Requirements

* Docker
* Docker Compose
* Git
* Node.js / `npx` only for running MCP Inspector on the host

PHP and Composer do not need to be installed locally when the service is run through Docker.

---

# Architecture

```text
MCP Client / ChatGPT
        ↓
AI Food Ordering MCP Server
        ↓
AI Food Ordering Backend REST API
        ↓
Dots Platform API
```

The MCP server is intentionally thin. It is responsible for:

* exposing MCP tools;
* validating MCP tool arguments;
* preserving an opaque ordering session context;
* calling the internal backend REST API;
* mapping backend responses into MCP-safe structured responses;
* mapping backend failures into safe user-facing errors;
* enforcing MCP-specific safety rules such as explicit confirmation before order creation.

The backend remains the source of truth for restaurant selection, catalog data, product availability, cart state, prices, totals, customer data and order lifecycle.

---

# Setup

All commands below are executed from the project root unless stated otherwise.

## 1. Clone the repository

```bash
git clone <repository-url>
cd ai-food-ordering
```

The MCP application is located in:

```text
mcp-server/
```

The Docker Compose configuration is located in the project root:

```text
docker-compose.yml
```

## 2. Create the environment file

```bash
cp mcp-server/.env.example mcp-server/.env
```

Configure the required values in:

```text
mcp-server/.env
```

Important MCP configuration:

```env
APP_KEY=

BACKEND_URL=http://app
BACKEND_INTERNAL_API_TOKEN=
BACKEND_TIMEOUT=10

CACHE_STORE=array
```

`BACKEND_INTERNAL_API_TOKEN` must match the `INTERNAL_API_TOKEN` configured in the backend service.

The MCP container reaches the backend through the Docker Compose service name `app`, therefore the default internal URL is:

```text
http://app
```

Do not commit the real `.env`, backend token or application key to Git.

## 3. Start the MCP service

```bash
docker compose up -d mcp-server
```

The backend service must also be available because all ordering operations are delegated to it.

Check the containers:

```bash
docker compose ps
```

## 4. Install PHP dependencies

```bash
docker compose exec --user "$(id -u):$(id -g)" mcp-server \
composer install
```

## 5. Generate the Laravel application key

```bash
docker compose exec --user "$(id -u):$(id -g)" mcp-server \
php artisan key:generate
```

A stable `APP_KEY` is important because the MCP server uses Laravel authenticated encryption for the opaque `session_handle` returned to the MCP client.

Changing `APP_KEY` invalidates previously issued handles.

## 6. Verify the application

```bash
docker compose exec --user "$(id -u):$(id -g)" mcp-server \
php artisan about
```

Health endpoint:

```text
http://localhost:8082/up
```

---

# Running the MCP Server

The web MCP endpoint is available at:

```text
http://localhost:8082/mcp/food-ordering
```

The MCP server is registered in two modes:

```php
Mcp::web('/mcp/food-ordering', FoodOrderingServer::class);
Mcp::local('food-ordering', FoodOrderingServer::class);
```

The web transport is used for HTTP MCP clients.

The local handle is:

```text
food-ordering
```

Available MCP routes can be verified with:

```bash
docker compose exec --user "$(id -u):$(id -g)" mcp-server \
php artisan route:list | grep mcp
```

Expected web routes include `GET`, `POST` and `DELETE` for:

```text
mcp/food-ordering
```

---

# MCP Inspector

For local development, run MCP Inspector on the host machine.

```bash
npx @modelcontextprotocol/inspector
```

In Inspector select:

```text
Transport: Streamable HTTP
URL:       http://localhost:8082/mcp/food-ordering
```

Then press **Connect**.

After initialization, Inspector should be able to request `tools/list` and invoke the ordering tools.

The Laravel command also exposes an Inspector integration:

```bash
php artisan mcp:inspector food-ordering
```

However, this command requires `npx`. The current PHP Alpine Docker container does not include Node.js by default, so running the standalone Inspector on the host is the recommended local workflow.

---

# MCP Tools

The server exposes 14 tools.

| Tool | Purpose | Main arguments |
| --- | --- | --- |
| `get_restaurant_context` | Start a new ordering context and obtain restaurant data | none |
| `set_customer` | Save customer name and phone | `session_handle`, `name`, `phone` |
| `get_categories` | Get active restaurant categories | `session_handle` |
| `get_category_products` | Get products for a category | `session_handle`, `category_id` |
| `search_products` | Search the backend catalog | `session_handle`, `query`, optional `limit` |
| `get_product` | Get one current product | `session_handle`, `product_id` |
| `get_or_create_cart` | Return the active cart or create an empty one | `session_handle` |
| `get_cart` | Read the current active cart | `session_handle` |
| `add_cart_item` | Add a product to an existing cart | `session_handle`, `product_id`, `quantity` |
| `update_cart_item` | Change quantity of an existing cart line | `session_handle`, `item_id`, `quantity` |
| `remove_cart_item` | Remove one cart line | `session_handle`, `item_id` |
| `clear_cart` | Remove all items from the active cart | `session_handle` |
| `create_order` | Create an order after explicit user confirmation | `session_handle`, `confirmation`, `delivery_time` |
| `get_order_status` | Get the current authoritative order state | `session_handle` |

## Important identifiers

`product_id` is the local backend `Product.id` returned by catalog tools.

`item_id` is the local backend `CartItem.id` returned inside the cart `items` array.

They are not interchangeable and neither value is a Dots identifier.

---

# Session Flow

The first call in a new ordering flow should be:

```text
get_restaurant_context
```

The backend creates the ordering session and selects the restaurant. The MCP server then returns safe restaurant data together with an opaque:

```text
session_handle
```

The client must preserve this value and pass it unchanged to subsequent session-bound tools.

Conceptually:

```text
MCP Client
    ↓ get_restaurant_context
MCP Server
    ↓ POST /api/sessions
Backend
    ↓
backend session ID + secret session token
    ↓
MCP authenticated encryption
    ↓
session_handle
    ↓
MCP Client
```

The encrypted handle contains trusted backend session context and its expiration time. The raw backend session token is never exposed as a separate MCP argument or output field.

Because the handle is encrypted using the Laravel application key, `APP_KEY` must remain stable between requests and deployments where existing sessions are expected to continue working.

---

# Catalog and Cart Flow

Typical flow:

```text
get_restaurant_context
        ↓
get_or_create_cart
        ↓
get_categories / search_products
        ↓
get_product
        ↓
add_cart_item
        ↓
get_cart
        ↓
update_cart_item / remove_cart_item / clear_cart
```

The MCP server does not calculate product prices, subtotals or totals.

Every factual price and cart total comes from the backend.

`add_cart_item` does not create a cart implicitly. Call `get_or_create_cart` first when a new ordering context has no active cart.

---

# Order Flow

Before creating an order, the client should obtain the latest cart state:

```text
get_cart
```

The user should be shown the current items, quantities and backend-provided total and must explicitly confirm order creation.

Only after explicit confirmation should the client call:

```text
create_order
```

with:

```json
{
  "session_handle": "...",
  "confirmation": true,
  "delivery_time": 0
}
```

`delivery_time = 0` means as soon as possible.

A positive `delivery_time` is a future Unix timestamp for scheduled pickup.

The MCP service:

1. restores the trusted session context;
2. gets the current cart from the backend;
3. generates an internal idempotency key from trusted session/cart data;
4. sends the order request to the backend exactly once;
5. returns only the safe order representation.

The MCP server does not retry order creation automatically after connection, timeout or checkout failures.

The backend remains responsible for Dots price validation, final totals, Dots identifiers and order lifecycle.

If the backend returns a checkout rejection, `create_order` exposes a safe user-facing error instead of leaking raw external API details.

---

# Backend Communication

All backend HTTP communication is isolated in:

```text
app/Integrations/OrderingBackend/OrderingBackendClient.php
```

MCP tools do not call Laravel `Http::` directly.

The client automatically sends:

```text
X-Internal-Api-Token
```

Session-bound requests additionally send the trusted backend token as:

```text
X-Session-Token
```

Order creation additionally sends an internally generated:

```text
Idempotency-Key
```

These secret values are not model-controlled MCP tool arguments.

---

# Language Policy

Human-facing and model-facing MCP communication is written in Russian, including:

* server instructions;
* tool titles and descriptions;
* schema descriptions;
* validation errors;
* safe backend error mappings;
* confirmation and destructive-action guidance.

Technical identifiers remain unchanged, including tool names, structured response keys, endpoint paths, HTTP headers and backend status values.

Restaurant, category and product names/descriptions returned by the backend are preserved exactly and are not translated or invented by the MCP server.

---

# Testing

The MCP automated tests mock the backend HTTP boundary. They do not create real Dots orders.

Run all tests:

```bash
docker compose exec --user "$(id -u):$(id -g)" mcp-server \
php artisan test --compact
```

Current test suite:

```text
163 tests
911 assertions
```

The tests cover:

* backend HTTP client behavior;
* safe backend error mapping;
* encrypted session handle creation and restoration;
* session expiration and malformed handles;
* MCP server discovery;
* restaurant context initialization;
* customer data;
* catalog categories and products;
* product search;
* cart creation and reads;
* cart item add/update/remove/clear operations;
* order confirmation safety;
* internal order idempotency keys;
* order creation;
* order status;
* output whitelisting and secret suppression;
* malformed backend responses;
* connection and timeout failures.

Run a specific test:

```bash
docker compose exec --user "$(id -u):$(id -g)" mcp-server \
php artisan test --compact tests/Feature/Mcp/Tools/CreateOrderToolTest.php
```

Real backend and Dots verification should be performed separately as a manual integration or smoke test through MCP Inspector or another MCP client.

---

# Code Style

Laravel Pint is used for formatting.

Format the project:

```bash
docker compose exec --user "$(id -u):$(id -g)" mcp-server \
vendor/bin/pint --format agent
```

Before committing changes:

```bash
docker compose exec --user "$(id -u):$(id -g)" mcp-server \
vendor/bin/pint --format agent
```

```bash
docker compose exec --user "$(id -u):$(id -g)" mcp-server \
php artisan test --compact
```

---

# Docker Service

The MCP service is exposed by the root Docker Compose configuration.

| Service | Purpose | Host port |
| --- | --- | ---: |
| `mcp-server` | Laravel MCP + Nginx | `8082` |

MCP endpoint:

```text
http://localhost:8082/mcp/food-ordering
```

Health endpoint:

```text
http://localhost:8082/up
```

The backend is accessed internally through the Docker network using:

```text
http://app
```

---

# Useful Commands

Start the MCP service:

```bash
docker compose up -d mcp-server
```

Check service status:

```bash
docker compose ps
```

View MCP logs:

```bash
docker compose logs -f mcp-server
```

Open a shell inside the MCP container:

```bash
docker compose exec --user "$(id -u):$(id -g)" mcp-server sh
```

Run an Artisan command:

```bash
docker compose exec --user "$(id -u):$(id -g)" mcp-server \
php artisan <command>
```

List MCP routes:

```bash
docker compose exec --user "$(id -u):$(id -g)" mcp-server \
php artisan route:list | grep mcp
```

Clear Laravel caches:

```bash
docker compose exec --user "$(id -u):$(id -g)" mcp-server \
php artisan optimize:clear
```

Recreate only the MCP container:

```bash
docker compose up -d --force-recreate mcp-server
```

---

# Development Notes

* The MCP server is a thin adapter over the ordering backend.
* Business logic must not be duplicated in MCP tools.
* The MCP server never communicates directly with Dots Platform.
* The backend is the factual source for restaurant, catalog, cart, prices, totals and orders.
* The MCP service does not maintain its own product catalog, cart or order persistence.
* `session_handle` is opaque and must never be interpreted or modified by the model/client.
* Raw backend session tokens and the internal API token must never be exposed in MCP responses or logs.
* MCP tools must use `OrderingBackendClient` rather than direct HTTP calls.
* Product prices and cart/order totals must never be calculated by MCP.
* `create_order` requires explicit user confirmation and is never automatically retried.
* Backend-provided restaurant, category and product data is preserved without translation or invention.
