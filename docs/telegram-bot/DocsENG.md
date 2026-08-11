# Telegram Bot Service Overview

## English

### What this service is

`telegram-bot` is the Laravel service that implements the Telegram interface for the AI Food Ordering system through Nutgram.

The service receives Telegram commands, contacts, and callback queries, builds keyboards and messages, and translates user actions into requests to the internal Ordering Backend REST API.

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

The Telegram service never communicates with Dots directly. The Ordering Backend isolates Telegram from the external API and remains the only food-ordering business-logic layer.

---

### Responsibility boundary

The Telegram service is responsible for:

* the `/start` command and Telegram callbacks;
* inline and reply keyboards;
* collecting a contact through Telegram;
* formatting categories, products, carts, and orders;
* forwarding user actions to the Ordering Backend;
* safe navigation without locally stored screen state;
* backend session recovery and returning users to contact onboarding;
* safe callback acknowledgement and message editing.

The Telegram service is not responsible for:

* catalog truth or product availability;
* prices, promotions, line totals, subtotals, or totals;
* restaurant selection or restaurant lifecycle;
* cart creation, storage, or lifecycle rules;
* persistent contact storage;
* Dots identifiers;
* receiving and payment settings, apart from the allowed `delivery_time` input;
* final order totals or order status;
* communication with Dots.

These data and rules belong to the Ordering Backend. Telegram only renders normalized backend responses and submits an allowed user intent.

---

### Architecture

The main callback path is:

```text
Telegram callback
    ↓
CallbackAcknowledger
    ↓
CatalogHandler / CartHandler / CheckoutHandler
    ↓
TelegramSessionRecovery for session-bound requests
    ↓
OrderingBackendClient
    ↓
Ordering Backend REST API
```

Components have separate responsibilities:

* handlers coordinate user flows and remain thin;
* keyboard classes build callback data and Telegram markup;
* formatter classes build text only from normalized backend data;
* `OrderingBackendClient` contains every HTTP request, header, timeout, and response validation rule;
* `TelegramSessionManager`, `TelegramSessionStore`, and `TelegramSessionRecovery` manage the only local runtime value: the backend session token;
* `CallbackAcknowledger` stops stale callbacks before business operations;
* `TelegramMessageEditor` centralizes idempotent inline-message editing.

There are no catalog, cart, or order repositories or local models because Telegram does not store that data.

---

### Main user flow

```text
/start
  ↓
Backend session
  ↓
Share contact
  ↓
Main menu
  ├── Catalog
  │     ↓
  │   Category
  │     ↓
  │   Product
  │     ↓
  │   Add to cart
  └── Cart
        ↓
      Checkout
        ↓
      Order confirmation
        ↓
      Result / status refresh
```

The main menu contains only the implemented `🍕 Каталог` and `🛒 Корзина` actions.

---

### Telegram routes and callbacks

Nutgram loads `routes/telegram.php`, which registers:

| Telegram data | Purpose |
| --- | --- |
| `/start` | Create or reuse a backend session and request the contact. |
| Telegram contact | Verify contact ownership and submit it to the backend. |
| `catalog` | Display categories. |
| `category:{categoryId}` | Display products from a backend category. |
| `product:{categoryId}:{productId}` | Display a product; the category ID is retained for back navigation. |
| `main_menu` | Return to the main menu without a backend request. |
| `menu:cart` | Get or create the current active cart. |
| `cart:add:{productId}` | Add a backend product or increment an existing item. |
| `cart:inc:{itemId}` / `cart:dec:{itemId}` | Change a backend cart item's quantity. |
| `cart:remove:{itemId}` | Remove a backend cart item. |
| `cart:clear` | Display clear confirmation without a mutation. |
| `cart:clear:confirm` / `cart:clear:cancel` | Confirm clearing or return to the authoritative cart. |
| `cart:noop:{itemId}` | Acknowledge an informational quantity/name button without mutation. |
| `checkout` | Validate the current cart and show order confirmation. |
| `order:confirm:{uuid}` | Create or resolve an idempotent order. |
| `order:cancel` | Return to the authoritative cart without creating an order. |
| `order:refresh` | Retrieve the current order from the backend. |

Navigation context is carried in callback data. The selected category, product, cart item, clear confirmation, and checkout state are not stored locally.

---

### Ordering Backend integration

Every HTTP call is contained in `OrderingBackendClient`. Every request receives `X-Internal-Api-Token`; session-bound requests additionally receive `X-Session-Token`; order creation also receives `Idempotency-Key`.

The current contract used by the Telegram service is:

| Method and endpoint | Usage |
| --- | --- |
| `POST /api/sessions` | Create or resolve a Telegram backend session. |
| `PUT /api/sessions/current/contact` | Store the current session's name and phone. |
| `GET /api/restaurants/{restaurant}/categories` | Retrieve categories for the configured restaurant. |
| `GET /api/restaurants/{restaurant}/categories/{category}/products` | Retrieve category products. |
| `GET /api/restaurants/{restaurant}/products/{product}` | Retrieve a product card. |
| `POST /api/carts` | Ensure the current active cart exists. |
| `GET /api/carts/current` | Retrieve the authoritative current cart. |
| `POST /api/carts/current/items` | Add a product with quantity `1`. |
| `PATCH /api/carts/current/items/{item}` | Change a cart item's quantity. |
| `DELETE /api/carts/current/items/{item}` | Remove a cart item. |
| `DELETE /api/carts/current/items` | Clear the cart. |
| `POST /api/orders` | Create or retrieve an order by idempotency key. |
| `GET /api/orders/current` | Retrieve the authoritative current order. |

The URL, internal API token, restaurant slug, and timeout are read through `config('services.ordering_backend.*')`.

The client unwraps the single top-level `data` object, normalizes flat cart/order items, and validates required types and fields. A successful but malformed response is an integration failure rather than an empty catalog or cart.

Catalog endpoints do not use `X-Session-Token`. Contact, cart, and order endpoints are session-bound.

---

### Session lifecycle

The `/start` flow is:

```text
Telegram chat
    ↓
telegram-chat-{chat_id}
    ↓
POST /api/sessions
    ↓
data.session_token
    ↓
TelegramSessionStore
```

The stable external session ID has this format:

```text
telegram-chat-{chat_id}
```

`TelegramSessionManager` checks the in-memory store first. If a token already exists, it does not make another backend request. If the token is missing, the manager calls the backend and stores the returned token in the singleton `TelegramSessionStore` for the current PHP process.

`TelegramSessionRecovery` provides centralized recovery for session-bound actions.

When the token is missing:

1. a backend session is created or resolved;
2. contact onboarding is shown again;
3. the original action stops.

When the backend returns `401`:

1. the stale token is removed from process memory;
2. a new backend session is created or resolved;
3. the contact-sharing button is shown;
4. the interrupted operation is not retried automatically.

If replacement-session creation is unavailable, the user receives a safe temporary-failure message with the same contact keyboard.

In particular, PATCH, DELETE, and `POST /api/orders` are never blindly repeated after session recovery.

---

### Contact onboarding

After a successful `/start`, the user receives a reply keyboard containing:

```text
📱 Поделиться контактом
```

The button uses Telegram `request_contact=true`.

Before a backend request, the handler verifies:

```text
message.contact.user_id == message.from.id
```

A foreign or unowned Telegram contact is rejected without calling the backend.

For the user's own contact, Telegram combines `first_name` and `last_name`, normalizes whitespace, and submits only:

```json
{
  "name": "...",
  "phone": "..."
}
```

Telegram does not submit or set `phone_verified`. Contact normalization, storage, and the backend contact representation belong to the Ordering Backend.

The main menu is shown only after a successful backend response. A `422` asks the user to check the phone safely; a `401` starts session recovery; transport and malformed-response details are not exposed.

---

### Catalog

Catalog browsing does not require a backend session token:

```text
catalog
  ↓
Categories
  ↓ category:{categoryId}
Category products
  ↓ product:{categoryId}:{productId}
Product card
```

Categories, products, descriptions, prices, promotion prices, currencies, and availability come exclusively from the Ordering Backend.

The product list displays `promotion_price` when the backend returns a non-null value; otherwise it uses `price`. Telegram performs no price calculation.

The product card is text-only and displays the available normalized backend fields. Telegram does not download or send a product image.

Category and product callbacks contain local backend IDs. The category ID in a product callback exists only to reconstruct the back button; the selected category is not stored.

An empty category or product list is rendered as a valid empty state. `404`, malformed responses, and transport failures are mapped to safe messages.

---

### Cart

The Ordering Backend is the only source of cart state.

Opening the cart performs:

```text
POST /api/carts
    ↓
GET /api/carts/current
    ↓
Render authoritative cart
```

`POST /api/carts` is the backend-owned get-or-create operation for the active cart. Telegram then reads `GET /api/carts/current` for authoritative items and totals.

#### Adding a product

`cart:add:{productId}` contains a backend product ID.

1. Telegram ensures an active cart and retrieves its current state.
2. It finds an existing item using `item.product_id == productId`.
3. If no item exists, it sends `POST /api/carts/current/items` with `product_id` and `quantity: 1`.
4. If an item exists, Telegram uses that cart item's `id` and PATCHes the current backend quantity plus one.
5. It renders the cart response returned by the backend.

#### Updating and removing items

The identifier distinction is important:

* `product_id` identifies a product when adding it;
* `item.id` identifies an existing cart item for PATCH and DELETE.

The `cart:inc:{itemId}`, `cart:dec:{itemId}`, and `cart:remove:{itemId}` callbacks contain the cart-item ID.

Before increment, decrement, or explicit removal, Telegram retrieves a fresh cart and finds the item by `item.id`. Quantity is not stored in callback data or process memory.

When quantity is greater than `1`, decrement PATCHes the current backend quantity minus one. When quantity equals `1`, decrement deletes the item and never sends quantity `0`.

After deleting one item and after clearing all items, `OrderingBackendClient` retrieves the authoritative cart through `GET /api/carts/current`. Telegram never constructs a deletion result locally.

Cart clearing is a two-step flow:

```text
cart:clear
  ↓ no mutation
Confirmation keyboard
  ├── cart:clear:confirm → DELETE all → GET current cart
  └── cart:clear:cancel  → GET current cart without mutation
```

Confirmation state is not stored; the choice is represented entirely by callback data.

Telegram displays backend `unit_price`, line `total`, `subtotal`, total, and currency without recalculation. An empty cart does not contain update, remove, clear, or checkout buttons.

#### Cart lifecycle after checkout

After successful checkout, the backend moves the old cart to historical `checked_out` state. Telegram then calls `POST /api/carts` so the backend can create or return the next active cart.

Telegram never clears, reactivates, or modifies historical carts. Normal cart opening uses the same get-or-create operation as defensive recovery if post-order initialization previously failed.

---

### Checkout and order creation

Checkout is available only for a non-empty cart with `active` status.

```text
Cart
  ↓ checkout
GET /api/carts/current
  ↓
Checkout confirmation
  ↓ order:confirm:{uuid}
POST /api/orders
  ↓
Ordering Backend
  ↓
Dots
  ↓
Order response
```

The confirmation screen uses backend `total` and `currency`. The current MVP displays:

* pickup;
* cash payment;
* as-soon-as-possible time.

Telegram does not submit a receiving type or payment type. The only JSON input on confirmation is:

```json
{
  "delivery_time": 0
}
```

The value `0` means ASAP in the current backend contract.

#### Idempotency

When building the confirmation keyboard, Telegram generates a UUID and places it directly into:

```text
order:confirm:{uuid}
```

The key is not stored in a database, Redis, cache, or process state. The confirm handler validates the UUID and forwards it unchanged in the `Idempotency-Key` header.

Handling the same button again forwards the same key. Duplicate-order protection belongs to backend idempotency; Telegram does not generate a new key inside the confirm handler.

#### Successful order

Responses `200` and `201` are successful. After a confirmed successful response, Telegram:

1. holds the order response only in a local variable for the current invocation;
2. calls `POST /api/carts` with the same session token for the next active cart;
3. renders the original authoritative order response.

If next-cart initialization fails, the successful order is still rendered. `POST /api/orders` is not repeated; normal cart opening can retry the safe backend get-or-create operation later.

#### Rejected or ambiguous order

A `401` starts session recovery, does not retry the order, and does not ensure a new cart.

After a timeout, connection failure, or another ambiguous result, Telegram does not retry `POST /api/orders`, does not create the next cart, and offers a current-order check through `GET /api/orders/current`.

Definite `404`, `409`, and `422` responses receive separate safe messages and actions. A rejected order also does not trigger post-order cart ensure.

---

### Order status

The order response is normalized from backend-provided fields:

* local order ID;
* status;
* receiving type;
* total and currency;
* items with name, quantity, unit price, and total.

Telegram does not calculate order-item values or create an independent order lifecycle.

Known backend statuses, including `creating`, `created`, `failed`, `paid`, and `cancelled`, are displayed with readable Russian labels. An unknown status remains the original backend value.

For `creating`, Telegram shows:

```text
🔄 Обновить статус
```

The refresh flow is:

```text
order:refresh
  ↓
GET /api/orders/current
  ↓
Render authoritative order
```

Automatic order-status polling is not implemented.

The backend `failure_message` is not rendered directly. A failed order uses a safe generic message.

---

### Runtime state

`TelegramSessionStore` is registered as a Laravel singleton and contains a map:

```text
telegram-chat-{chat_id} → X-Session-Token
```

The map exists only in the memory of the current long-running PHP polling process.

The Telegram service does not locally store:

* users or contacts;
* categories or products;
* the selected screen, category, or product;
* cart IDs, items, quantities, or totals;
* clear confirmation;
* a checkout snapshot;
* order IDs, statuses, or idempotency keys.

`CACHE_STORE=array` also keeps Laravel/Nutgram cache inside the process. This is the intentional stateless prototype design. Restarting the process loses the process-memory token, and a user may be returned to contact onboarding.

The authoritative state remains in the Ordering Backend.

---

### Error handling

`OrderingBackendClient` maps HTTP and connection failures to `OrderingBackendException` with a safe internal message and, for HTTP failures, a status code.

Important categories are:

* session-bound `401` — forget the token, create or resolve a session, show contact onboarding, and stop the original operation;
* `404` — show a safe missing-cart, missing-item, missing-resource, or missing-current-order message;
* `409` — show a safe conflict or changed-state message;
* `422` — show a safe validation or checkout message;
* timeout, connection failure, `5xx`, or malformed response — show a generic message without a backend stack trace;
* ambiguous `POST /api/orders` — never retry creation and offer an order-status check.

The integration client's explicit error-log context does not include backend response bodies or tokens. It contains only safe operation metadata, status, and exception class.

For order-creation `422`, Telegram checks only an explicitly allowed set of known restaurant-hours messages. A recognized condition is rendered as:

```text
Сейчас ресторан не принимает заказы. Попробуйте оформить заказ в рабочее время.
```

Any other backend or Dots message is not displayed directly and receives the generic checkout text. Telegram does not treat every `422` as a closed restaurant.

---

### Callback and message safety

Every inline callback is acknowledged through `CallbackAcknowledger` exactly once and before session resolution, backend GET, or mutation.

Telegram errors indicating a stale callback — `query is too old`, `response timeout expired`, or `query ID is invalid` — are logged safely at debug level. The handler stops immediately, so an old callback cannot mutate the current cart or create an order.

Unexpected `TelegramException` instances are not hidden and preserve normal exception/reporting behavior.

Inline messages are edited through `TelegramMessageEditor`. Telegram's `message is not modified` error is a successful no-op: no fallback message is sent and no backend operation is repeated. Other Telegram errors continue to propagate.

---

### Security boundaries

The following values are secrets:

* `TELEGRAM_BOT_TOKEN`;
* `BACKEND_INTERNAL_API_TOKEN`;
* the backend `X-Session-Token`.

They must not appear in source code, callback data, user-facing messages, or logs.

`OrderingBackendClient` centrally attaches `X-Internal-Api-Token`. `X-Session-Token` is attached only to session-bound requests.

Telegram accepts only limited action identifiers from its callbacks: a backend product ID for adding and a backend cart-item ID for updating or removing. It does not submit client-controlled cart IDs, restaurant IDs, prices, totals, statuses, or external Dots IDs. The Ordering Backend validates ownership and business invariants again.

A contact is accepted only from the same Telegram user who sent the message. Arbitrary backend or Dots error messages are not forwarded to users.

---

### Important implementation decisions

* Telegram is a presentation client, not a second backend.
* All HTTP requests are isolated in `OrderingBackendClient`.
* Catalog browsing does not depend on a backend session token.
* Navigation context and the idempotency UUID travel in callback data rather than persistence.
* Cart and order responses are authoritative; monetary values are not recalculated.
* Cart-item IDs and product IDs have different roles and are not interchangeable.
* A fresh cart is read before quantity or remove mutations.
* DELETE results are confirmed with a subsequent `GET /api/carts/current`.
* State-changing requests are not automatically retried after session recovery.
* An ambiguous order POST is not automatically retried.
* The next cart is ensured only after a confirmed successful order response.
* Stale callbacks stop before backend work.
* Editing a Telegram message to identical content is a safe no-op.

---

### Testing

Automated feature tests use Laravel HTTP fakes and Nutgram's fake transport, so they make no real requests to the Ordering Backend, Telegram, or Dots.

Coverage includes integration headers and payloads, response normalization, session lifecycle, contact ownership, catalog navigation, cart mutations, checkout, idempotency, next-cart initialization, order refresh, safe backend errors, stale callbacks, Telegram message editing, keyboards, and user-facing formatting.
