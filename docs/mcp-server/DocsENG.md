# MCP Server Overview

## English

### What this service is

The MCP server is the ChatGPT-facing adapter of the AI Food Ordering system.

Its responsibility is to expose the ordering flow as MCP tools while keeping all food-ordering business logic inside the main Laravel backend.

The service does not communicate with Dots Platform directly and does not maintain its own business catalog, cart or order database.

The architecture is:

```text
ChatGPT / MCP Client
        ↓
     MCP Server
        ↓
  Laravel Backend
        ↓
    Dots API
```

The MCP server translates model actions into trusted backend REST API calls and converts backend responses into safe structured MCP results.

---

### Main application flow

A typical ChatGPT ordering flow is:

```text
get_restaurant_context
        ↓
set_customer
        ↓
Catalog / Search
        ↓
get_or_create_cart
        ↓
Cart operations
        ↓
get_cart
        ↓
Explicit user confirmation
        ↓
create_order
        ↓
get_order_status
```

The model must treat backend-provided values as the factual source of truth throughout this flow.

---

#### 1. Ordering context

Every new ordering flow starts with:

```text
get_restaurant_context
```

The tool creates a new backend session with the `chatgpt` channel and a generated external session identifier.

The backend selects the restaurant and returns the trusted session data. The MCP server does not allow the model to choose or inject a restaurant ID, account ID or backend session token.

The MCP response contains safe restaurant information and an opaque:

```text
session_handle
```

The client must preserve this value and pass it unchanged to all session-bound tools.

---

#### 2. Session handle

`session_handle` is the MCP representation of the trusted backend ordering context.

Internally it contains:

* backend session ID;
* backend session token;
* trusted restaurant slug;
* session expiration time;
* payload version.

The payload is protected using Laravel authenticated encryption and the MCP application's `APP_KEY`.

Conceptually:

```text
Backend session
      ↓
session ID + secret token + restaurant + expiration
      ↓
Laravel authenticated encryption
      ↓
opaque session_handle
      ↓
MCP Client
```

The model must never interpret, modify or construct this value.

A malformed, modified or expired handle is rejected before the requested backend operation is executed.

Because handles are encrypted with `APP_KEY`, changing the application key invalidates previously issued handles.

---

#### 3. Customer information

Customer data is saved through:

```text
set_customer
```

The tool accepts only:

* `session_handle`;
* customer `name`;
* customer `phone`.

Phone validation and normalization remain backend responsibilities.

The MCP server does not mark a phone as verified itself and does not expose the raw normalized phone in the tool result. The safe response contains the customer name and the backend-provided `phone_verified` state.

---

#### 4. Catalog and search

The MCP server exposes backend catalog data through:

```text
get_categories
get_category_products
search_products
get_product
```

The restaurant is always resolved from the trusted session context. The model cannot provide a different restaurant identifier to these tools.

Catalog values are returned from the backend without recalculation or invention.

This includes factual data such as:

* category names;
* product names;
* descriptions;
* prices;
* promotion prices;
* currency;
* image URLs;
* availability.

Product and category identifiers exposed by MCP are local backend identifiers, not Dots identifiers.

The MCP server never invents missing ingredients, modifiers, dietary information, prices or availability.

---

#### 5. Cart

Cart operations are exposed through:

```text
get_or_create_cart
get_cart
add_cart_item
update_cart_item
remove_cart_item
clear_cart
```

The backend remains fully responsible for cart lifecycle, product validation, prices and totals.

The MCP client can only express user actions such as:

* create or obtain the current cart;
* add a product;
* change a cart-line quantity;
* remove a cart line;
* clear the cart.

The MCP service never calculates `unit_price`, `subtotal` or `total` itself.

`add_cart_item` accepts a local backend `product_id`.

`update_cart_item` and `remove_cart_item` accept an `item_id`, which is the backend `CartItem.id` returned in the cart `items` array.

These identifiers are intentionally different:

```text
product_id → Product.id
item_id    → CartItem.id
```

They must not be interchanged.

`add_cart_item` does not create a cart implicitly. A new ordering context should call `get_or_create_cart` before adding products if no active cart exists.

---

#### 6. Checkout confirmation

Order creation is intentionally protected by an MCP-specific confirmation rule.

Before calling `create_order`, the client should call:

```text
get_cart
```

The user must be shown the current cart items, quantities and the backend-provided total.

The user must then explicitly confirm creation of the order.

Only after explicit confirmation may the MCP client call:

```json
{
  "session_handle": "...",
  "confirmation": true,
  "delivery_time": 0
}
```

Viewing the cart, asking about the price or discussing checkout is not considered confirmation.

If `confirmation` is missing, false or not the literal boolean `true`, the tool rejects the operation before restoring the session or making an HTTP request.

---

#### 7. Order creation

`create_order` does not accept prices, totals, cart IDs, restaurant IDs, Dots IDs or an idempotency key from the model.

The tool performs the following flow:

```text
MCP confirmation
      ↓
Restore trusted session_handle
      ↓
GET current backend cart
      ↓
Generate internal Idempotency-Key
      ↓
POST delivery_time to backend
      ↓
Backend price validation / order flow
      ↓
Safe MCP order response
```

The internal idempotency key is derived from trusted backend data:

```text
SHA-256("mcp-order:{backend-session-id}:{cart-id}")
```

The key is never accepted from the model and is never returned to it.

The MCP server sends only `delivery_time` as the order request body. The backend remains responsible for customer data, restaurant/Dots identifiers, payment and receiving configuration, cart items, price validation, final totals and Dots order creation.

`delivery_time = 0` means as soon as possible. A positive value is a future Unix timestamp for scheduled pickup.

The order POST is performed only once per tool invocation. The MCP server does not automatically retry order creation after a timeout, connection failure or ambiguous checkout result because a blind retry could create a duplicate external order.

---

#### 8. Order status

The current authoritative order state is obtained through:

```text
get_order_status
```

The MCP server asks the backend for the current order and returns only a safe whitelisted representation.

The backend may contact Dots and persist a refreshed status during this request. For this reason, the tool is not marked as a purely read-only operation even though it does not perform a destructive user action.

The model must not invent an order state or communicate with Dots directly.

---

### MCP tools

The server currently exposes 14 tools:

| Tool | Responsibility |
| --- | --- |
| `get_restaurant_context` | Initialize a trusted ordering context |
| `set_customer` | Save customer name and phone |
| `get_categories` | Return current restaurant categories |
| `get_category_products` | Return products from one category |
| `search_products` | Search the backend catalog |
| `get_product` | Return one current product |
| `get_or_create_cart` | Get the active cart or create a new one |
| `get_cart` | Read the current active cart |
| `add_cart_item` | Add a product to the cart |
| `update_cart_item` | Change cart-line quantity |
| `remove_cart_item` | Remove one cart line |
| `clear_cart` | Remove all cart lines |
| `create_order` | Create an order after explicit confirmation |
| `get_order_status` | Return the current authoritative order state |

The server exposes no MCP resources or prompts; the ordering interface is implemented entirely through tools.

---

### Backend communication

All HTTP communication with the ordering backend is isolated behind:

```text
App\Integrations\OrderingBackend\OrderingBackendClient
```

Individual MCP tools do not make direct Laravel HTTP client calls.

Every backend request includes:

```text
X-Internal-Api-Token
```

Session-bound requests additionally include the trusted backend token as:

```text
X-Session-Token
```

Order creation also includes the internally generated:

```text
Idempotency-Key
```

These values are infrastructure secrets and are never model-controlled MCP arguments.

The backend URL, internal API token and request timeout are configured through the MCP service environment.

---

### Source of truth and response safety

The MCP server treats the backend as the factual source for:

* restaurant selection;
* catalog data;
* product availability;
* prices and promotion prices;
* cart state;
* cart totals;
* customer verification state;
* order totals;
* order status.

The MCP layer validates backend response shapes before exposing structured data to the model.

Responses are explicitly whitelisted. Internal or external fields that are not needed by the model are removed.

For example:

* backend session tokens are never returned as separate fields;
* Dots product identifiers are removed from cart/order tool output;
* raw backend/Dots order `failure_message` is not exposed;
* internal idempotency keys are not returned;
* unexpected backend response shapes are rejected as invalid responses.

This prevents external implementation details and secrets from leaking into the model context.

---

### Error handling

Backend and transport failures are converted into safe MCP errors.

The MCP layer distinguishes cases such as:

* authentication failure;
* resource not found;
* state conflict;
* validation or checkout rejection;
* invalid backend response;
* connection failure;
* backend service failure.

Raw backend response bodies, tokens and transport details are not exposed to the model.

For order checkout, a backend `422` is converted into a user-facing checkout message indicating that the restaurant may currently not accept orders or that the selected pickup time may be unavailable, without exposing raw Dots error content.

Order creation failures are not automatically retried.

---

### Language policy

Human-facing and model-facing MCP communication is written in Russian.

This includes:

* server instructions;
* tool titles;
* tool descriptions;
* input schema descriptions;
* validation messages;
* safe error messages;
* confirmation guidance.

Technical identifiers remain unchanged, including:

* MCP tool names;
* structured output keys;
* HTTP headers;
* REST paths;
* status values.

Restaurant, category and product names/descriptions received from the backend are preserved exactly. The MCP server does not translate, rewrite or invent these factual values.

---

### Persistent state

The MCP service does not maintain its own business persistence for restaurants, products, carts or orders.

Trusted ordering state remains in the backend.

The MCP-side session context is carried by the encrypted `session_handle`, allowing the adapter itself to remain effectively stateless between tool calls as long as the client preserves the handle and the application's `APP_KEY` remains stable.

---

### Testing

The MCP service is covered by automated tests at the backend HTTP boundary and MCP tool layer.

Current test suite:

```text
163 tests
911 assertions
```

The tests cover:

* MCP server discovery and tool registration;
* backend HTTP headers and timeouts;
* safe backend error mapping;
* malformed backend responses;
* connection and timeout failures;
* encrypted `session_handle` creation and restoration;
* handle tampering and expiration;
* restaurant context initialization;
* customer data handling;
* catalog categories and products;
* product search;
* cart creation and reads;
* add/update/remove/clear cart operations;
* trusted product and cart-item identifiers;
* explicit order confirmation;
* internal order idempotency keys;
* duplicate-order safety behavior;
* order creation;
* order status retrieval;
* output whitelisting;
* suppression of backend tokens, Dots identifiers and raw failure details.

External backend/Dots requests are mocked in the automated MCP tests.

A real integration flow is therefore verified separately through MCP Inspector or another MCP client against the running backend service.
