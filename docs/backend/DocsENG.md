# Backend Service Overview

## English

### What this service is

The backend is the central service of the AI Food Ordering system.

Its main responsibility is to isolate clients from the Dots API and keep the core food-ordering business logic in one place.

Different clients can communicate with this API, for example:

* an MCP service used by ChatGPT;
* a Telegram bot;
* other internal services.

Clients do not need to understand the Dots API, calculate order prices, or maintain cart state themselves. They communicate only with the backend REST API.

---

### Main application flow

A typical user flow is:

```text
Client
  ↓
Session
  ↓
Catalog / Search
  ↓
Cart
  ↓
Contact information
  ↓
Price validation
  ↓
Order
  ↓
Dots API
```

#### 1. Session

An internal session is created before starting the ordering flow.

The session connects a particular user or conversation with the selected restaurant and preserves context between requests.

After creation, the backend returns an `X-Session-Token`, which is used by subsequent requests.

---

#### 2. Catalog

The backend retrieves the restaurant catalog from Dots and stores it locally in PostgreSQL.

The local database contains:

* restaurants;
* categories;
* products;
* prices;
* product availability;
* category-product relationships.

Catalog synchronization is performed asynchronously through RabbitMQ.

Redis is used to cache external API data and reduce unnecessary requests to Dots.

Clients read catalog information from the internal REST API instead of accessing Dots directly.

---

#### 3. Search

Product search is performed against the local PostgreSQL database.

This avoids external API requests for every search operation and provides clients with a fast and consistent search interface.

---

#### 4. Cart

The cart is fully controlled by the backend.

The client sends only user actions such as:

* add a product;
* change quantity;
* remove a product.

Item prices and cart totals are calculated by the backend using trusted stored data.

Clients cannot directly provide internal prices, totals, restaurant identifiers, or cart status.

---

#### 5. Contact information

The customer's name and phone number are stored in the current session.

Before order creation, the backend verifies that the required contact information exists and normalizes it before sending it to the external API.

---

#### 6. Price validation

The locally stored product price is not considered the final checkout price.

Before creating an order, the backend sends the cart to the Dots Price Validation API.

Dots may apply:

* promotions;
* discounts;
* special pricing rules;
* other price adjustments.

Therefore, the total returned by Dots is treated as the authoritative final order price.

---

#### 7. Order

After successful price validation, the backend creates a local order and submits it to the Dots API.

The order stores a snapshot of its products at checkout time, so later catalog changes do not modify an existing order.

Order creation is protected by an `Idempotency-Key`.

Repeating a request with the same key does not create another Dots order. The backend returns the already existing local order instead.

---

### Order lifecycle

The backend stores the order state locally after submission.

A simplified lifecycle is:

```text
creating
   ↓
created
```

Failure and other internal states are also supported.

If the result of an external request is ambiguous, the backend does not blindly retry order creation because this could create duplicate orders.

The order state can instead be reconciled with Dots using an order status request.

---

### Data storage

#### PostgreSQL

Used as the primary persistent storage for:

* restaurants;
* categories;
* products;
* carts;
* cart items;
* orders;
* order items;
* catalog synchronization logs.

#### Redis

Used for fast temporary storage and caching.

In particular, Redis caches external Dots API data and stores runtime application data.

#### RabbitMQ

Used for asynchronous operations.

Currently, restaurant catalog synchronization is processed through the message queue.

---

### Internal API security

The API is designed for trusted internal clients rather than direct public user access.

Internal requests are protected by:

```text
X-Internal-Api-Token
```

Requests requiring user context additionally use:

```text
X-Session-Token
```

Order creation also requires:

```text
Idempotency-Key
```

This prevents clients from directly controlling internal identifiers, trusted prices, or order state.

---

### Role of the Dots API

Dots remains the external system where the actual order is created.

The backend acts as an intermediary:

```text
ChatGPT / MCP / Telegram
          ↓
     Laravel Backend
          ↓
       Dots API
```

This architecture prevents business logic from being duplicated across different clients.

MCP and Telegram clients only translate user actions into calls to the internal API.

Cart calculations, price validation, session state, idempotency, and order processing remain the responsibility of the backend service.

---

### Testing

The main business scenarios are covered by automated tests.

Tests use a dedicated PostgreSQL database.

The automated E2E test executes the complete internal application flow:

```text
Session
  ↓
Contact
  ↓
Cart
  ↓
Product
  ↓
Price validation
  ↓
Order creation
  ↓
Idempotency check
  ↓
Order status update
```

External Dots HTTP requests are mocked during this test.

This allows the real Laravel application layers to be tested together without creating actual orders in the external system.
