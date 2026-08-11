# Telegram Bot Service Overview

## Русский

### Что это за сервис

`telegram-bot` — Laravel-сервис, который реализует Telegram-интерфейс системы AI Food Ordering через Nutgram.

Сервис принимает команды, контакты и callback-запросы Telegram, формирует клавиатуры и сообщения, а пользовательские действия преобразует в запросы к внутреннему REST API Ordering Backend.

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

Telegram-сервис никогда не обращается к Dots напрямую. Ordering Backend изолирует Telegram от внешнего API и остаётся единственным слоем бизнес-логики заказа.

---

### Граница ответственности

Telegram-сервис отвечает за:

* команду `/start` и Telegram callbacks;
* inline- и reply-клавиатуры;
* получение контакта через Telegram;
* форматирование категорий, продуктов, корзины и заказа;
* передачу действий пользователя в Ordering Backend;
* безопасную навигацию без локального состояния экрана;
* восстановление backend-сессии и возврат к contact onboarding;
* безопасное подтверждение callback-запросов и редактирование сообщений.

Telegram-сервис не отвечает за:

* источник истины каталога и доступности продуктов;
* цены, акции, стоимость позиции, subtotal и total;
* выбор и жизненный цикл ресторана;
* создание, хранение и жизненный цикл корзины;
* контактные данные как постоянное хранилище;
* Dots identifiers;
* параметры получения и оплаты, кроме разрешённого `delivery_time`;
* итоговую стоимость и статус заказа;
* взаимодействие с Dots.

Эти данные и правила принадлежат Ordering Backend. Telegram только показывает нормализованный backend-ответ и отправляет разрешённое пользовательское намерение.

---

### Архитектура

Основной путь callback-запроса выглядит так:

```text
Telegram callback
    ↓
CallbackAcknowledger
    ↓
CatalogHandler / CartHandler / CheckoutHandler
    ↓
TelegramSessionRecovery, если запрос session-bound
    ↓
OrderingBackendClient
    ↓
Ordering Backend REST API
```

Отдельные компоненты отвечают за одну роль:

* handlers управляют пользовательским сценарием и остаются тонкими;
* keyboard-классы создают callback data и Telegram markup;
* formatter-классы создают текст только из нормализованных backend-данных;
* `OrderingBackendClient` содержит все HTTP-запросы, заголовки, timeout и проверку ответов;
* `TelegramSessionManager`, `TelegramSessionStore` и `TelegramSessionRecovery` управляют единственным локальным runtime-значением — backend session token;
* `CallbackAcknowledger` останавливает устаревшие callbacks до бизнес-операции;
* `TelegramMessageEditor` централизует идемпотентное редактирование inline-сообщений.

Репозитории и локальные модели каталога, корзины или заказа отсутствуют, потому что Telegram не хранит эти данные.

---

### Основной пользовательский поток

```text
/start
  ↓
Backend session
  ↓
Поделиться контактом
  ↓
Главное меню
  ├── Каталог
  │     ↓
  │   Категория
  │     ↓
  │   Продукт
  │     ↓
  │   Добавить в корзину
  └── Корзина
        ↓
      Оформление
        ↓
      Подтверждение заказа
        ↓
      Результат / обновление статуса
```

Главное меню содержит только реализованные действия `🍕 Каталог` и `🛒 Корзина`.

---

### Telegram routes и callbacks

Nutgram загружает `routes/telegram.php`. В нём зарегистрированы:

| Telegram data | Назначение |
| --- | --- |
| `/start` | Создать или переиспользовать backend session и запросить контакт. |
| Telegram contact | Проверить владельца контакта и отправить его в backend. |
| `catalog` | Показать категории. |
| `category:{categoryId}` | Показать продукты backend-категории. |
| `product:{categoryId}:{productId}` | Показать продукт; category ID нужен для кнопки назад. |
| `main_menu` | Вернуться в главное меню без backend-запроса. |
| `menu:cart` | Получить или создать текущую активную корзину. |
| `cart:add:{productId}` | Добавить backend-продукт или увеличить существующую позицию. |
| `cart:inc:{itemId}` / `cart:dec:{itemId}` | Изменить количество backend cart item. |
| `cart:remove:{itemId}` | Удалить backend cart item. |
| `cart:clear` | Показать подтверждение очистки без мутации. |
| `cart:clear:confirm` / `cart:clear:cancel` | Подтвердить очистку или вернуться к актуальной корзине. |
| `cart:noop:{itemId}` | Подтвердить нажатие на информационную кнопку без мутации. |
| `checkout` | Проверить актуальную корзину и показать подтверждение заказа. |
| `order:confirm:{uuid}` | Создать или получить идемпотентный заказ. |
| `order:cancel` | Вернуться к актуальной корзине без создания заказа. |
| `order:refresh` | Получить текущий заказ из backend. |

Навигационный контекст передаётся в callback data. Выбранная категория, продукт, позиция корзины, подтверждение очистки и checkout state локально не сохраняются.

---

### Интеграция с Ordering Backend

Все HTTP-вызовы находятся в `OrderingBackendClient`. Каждый запрос получает `X-Internal-Api-Token`; session-bound запросы дополнительно получают `X-Session-Token`; создание заказа также получает `Idempotency-Key`.

Текущий контракт, используемый Telegram-сервисом:

| Метод и endpoint | Использование |
| --- | --- |
| `POST /api/sessions` | Создать или разрешить Telegram backend session. |
| `PUT /api/sessions/current/contact` | Сохранить имя и телефон текущей сессии. |
| `GET /api/restaurants/{restaurant}/categories` | Получить категории настроенного ресторана. |
| `GET /api/restaurants/{restaurant}/categories/{category}/products` | Получить продукты категории. |
| `GET /api/restaurants/{restaurant}/products/{product}` | Получить карточку продукта. |
| `POST /api/carts` | Обеспечить существование текущей активной корзины. |
| `GET /api/carts/current` | Получить авторитетное текущее состояние корзины. |
| `POST /api/carts/current/items` | Добавить продукт с количеством `1`. |
| `PATCH /api/carts/current/items/{item}` | Изменить количество cart item. |
| `DELETE /api/carts/current/items/{item}` | Удалить cart item. |
| `DELETE /api/carts/current/items` | Очистить корзину. |
| `POST /api/orders` | Создать или получить заказ по idempotency key. |
| `GET /api/orders/current` | Получить актуальный текущий заказ. |

URL, internal API token, restaurant slug и timeout читаются через `config('services.ordering_backend.*')`.

Клиент снимает единственную верхнеуровневую обёртку `data`, нормализует плоские cart/order items и проверяет обязательные типы и поля. Успешный, но malformed ответ считается ошибкой интеграции, а не пустым каталогом или корзиной.

Каталожные endpoints не используют `X-Session-Token`. Contact, cart и order endpoints являются session-bound.

---

### Жизненный цикл сессии

Для `/start` используется следующий поток:

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

Стабильный внешний session ID формируется как:

```text
telegram-chat-{chat_id}
```

`TelegramSessionManager` сначала проверяет in-memory store. Если token уже существует, новый backend-запрос не выполняется. Если token отсутствует, manager вызывает backend и сохраняет возвращённый token в singleton `TelegramSessionStore` текущего PHP-процесса.

Для session-bound действий `TelegramSessionRecovery` выполняет централизованное восстановление.

Если token отсутствует:

1. создаётся или разрешается backend session;
2. пользователю снова показывается contact onboarding;
3. исходное действие останавливается.

Если backend возвращает `401`:

1. устаревший token удаляется из process memory;
2. создаётся или разрешается новая backend session;
3. пользователю показывается кнопка отправки контакта;
4. прерванная операция не повторяется автоматически.

Если создание replacement session недоступно, пользователь получает безопасное сообщение о временной недоступности и ту же contact-клавиатуру.

Особенно важно, что PATCH, DELETE и `POST /api/orders` после восстановления сессии не повторяются вслепую.

---

### Contact onboarding

После успешного `/start` пользователь получает reply-клавиатуру с кнопкой:

```text
📱 Поделиться контактом
```

Кнопка использует Telegram `request_contact=true`.

До backend-запроса handler проверяет:

```text
message.contact.user_id == message.from.id
```

Чужой или неподтверждённый Telegram contact отклоняется, и backend не вызывается.

Для собственного контакта Telegram формирует имя из `first_name` и `last_name`, нормализует пробелы и отправляет в backend только:

```json
{
  "name": "...",
  "phone": "..."
}
```

Telegram не отправляет и не устанавливает `phone_verified`. Нормализация, хранение и backend-представление контакта принадлежат Ordering Backend.

Главное меню показывается только после успешного ответа backend. При `422` пользователь получает безопасную просьбу проверить номер; при `401` запускается восстановление сессии; transport или malformed response не раскрывается пользователю.

---

### Каталог

Каталог доступен без backend session token:

```text
catalog
  ↓
Categories
  ↓ category:{categoryId}
Category products
  ↓ product:{categoryId}:{productId}
Product card
```

Категории, продукты, описания, цены, promotion price, валюта и доступность приходят только из Ordering Backend.

В списке продуктов Telegram показывает `promotion_price`, если backend вернул непустое значение; иначе используется `price`. Никаких вычислений цены не выполняется.

Карточка продукта является текстовой и показывает доступные backend-поля. Telegram не загружает и не отправляет изображение продукта.

Category и product callbacks содержат локальные backend IDs. Category ID в product callback используется только для восстановления кнопки назад; состояние выбранной категории не сохраняется.

Пустой список категорий или продуктов показывается как валидное пустое состояние. `404`, malformed response и transport failures преобразуются в безопасные сообщения.

---

### Корзина

Ordering Backend является единственным источником состояния корзины.

Открытие корзины выполняет:

```text
POST /api/carts
    ↓
GET /api/carts/current
    ↓
Render authoritative cart
```

`POST /api/carts` — backend-owned get-or-create для активной корзины. После него Telegram читает `GET /api/carts/current`, чтобы получить актуальные items и totals.

#### Добавление продукта

`cart:add:{productId}` содержит backend product ID.

1. Telegram обеспечивает активную корзину и получает её текущее состояние.
2. Существующая позиция ищется по `item.product_id == productId`.
3. Если позиции нет, отправляется `POST /api/carts/current/items` с `product_id` и `quantity: 1`.
4. Если позиция есть, Telegram использует её cart-item `id` и отправляет PATCH с текущим backend quantity плюс один.
5. Показывается cart response, возвращённый backend.

#### Изменение и удаление

Важное различие идентификаторов:

* `product_id` идентифицирует продукт при добавлении;
* `item.id` идентифицирует существующую позицию корзины при PATCH и DELETE.

Callbacks `cart:inc:{itemId}`, `cart:dec:{itemId}` и `cart:remove:{itemId}` содержат именно cart-item ID.

Перед increment, decrement или explicit remove Telegram получает свежую корзину и ищет позицию по `item.id`. Количество не хранится в callback data или process memory.

Если quantity больше `1`, decrement отправляет PATCH с текущим backend quantity минус один. Если quantity равен `1`, decrement удаляет позицию через DELETE и никогда не отправляет quantity `0`.

После удаления одной позиции и после полной очистки `OrderingBackendClient` получает авторитетную корзину через `GET /api/carts/current`. Telegram не строит результат удаления локально.

Очистка корзины двухшаговая:

```text
cart:clear
  ↓ no mutation
Confirmation keyboard
  ├── cart:clear:confirm → DELETE all → GET current cart
  └── cart:clear:cancel  → GET current cart without mutation
```

Confirmation state не сохраняется: выбор целиком представлен callback data.

Telegram показывает backend `unit_price`, line `total`, `subtotal`, общий `total` и currency без пересчёта. Пустая корзина не содержит кнопок изменения, удаления, очистки или checkout.

#### Жизненный цикл корзины после checkout

После успешного заказа backend переводит старую корзину в историческое `checked_out` состояние. Telegram затем вызывает `POST /api/carts`, чтобы backend создал или вернул следующую активную корзину.

Исторические корзины Telegram не очищает, не реактивирует и не изменяет. При обычном открытии корзины тот же get-or-create вызов является дополнительным восстановлением, если post-order инициализация ранее не удалась.

---

### Checkout и создание заказа

Checkout доступен только для непустой корзины со статусом `active`.

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

Экран подтверждения использует backend `total` и `currency`. Текущий MVP показывает:

* самовывоз;
* оплату наличными;
* время «как можно скорее».

Telegram не отправляет receiving type или payment type. При подтверждении единственный JSON-параметр:

```json
{
  "delivery_time": 0
}
```

Значение `0` означает ASAP в текущем backend-контракте.

#### Idempotency

При формировании confirmation keyboard Telegram создаёт UUID и помещает его непосредственно в:

```text
order:confirm:{uuid}
```

Ключ не хранится в базе, Redis, cache или process state. Confirm handler проверяет UUID и передаёт его без изменений в заголовке `Idempotency-Key`.

Повторная обработка той же кнопки передаёт тот же key. Защита от повторного заказа обеспечивается backend idempotency; Telegram не создаёт новый ключ внутри confirm handler.

#### Успешный заказ

Ответы `200` и `201` считаются успешными. После подтверждённого успешного ответа Telegram:

1. сохраняет order response только в локальной переменной текущего вызова;
2. вызывает `POST /api/carts` с тем же session token для следующей активной корзины;
3. показывает исходный авторитетный order response.

Если инициализация следующей корзины завершается ошибкой, успешный заказ всё равно показывается. `POST /api/orders` не повторяется; следующее открытие корзины снова использует безопасный backend get-or-create.

#### Неуспешный или неоднозначный заказ

При `401` запускается session recovery, заказ не повторяется и новая корзина не создаётся.

При timeout, connection failure или другом неоднозначном результате Telegram не повторяет `POST /api/orders`, не создаёт следующую корзину и предлагает проверить текущий заказ через `GET /api/orders/current`.

Определённые `404`, `409` и `422` обрабатываются отдельными безопасными сообщениями и действиями. Rejected order также не запускает post-order cart ensure.

---

### Статус заказа

Order response нормализуется из backend-полей:

* локальный order ID;
* status;
* receiving type;
* total и currency;
* items с name, quantity, unit price и total.

Telegram не вычисляет значения order items и не создаёт собственный lifecycle.

Backend status отображается человекочитаемым русским текстом для известных значений, включая `creating`, `created`, `failed`, `paid` и `cancelled`. Неизвестное значение остаётся backend-значением.

Для `creating` показывается кнопка:

```text
🔄 Обновить статус
```

Поток обновления:

```text
order:refresh
  ↓
GET /api/orders/current
  ↓
Render authoritative order
```

Автоматический polling статуса заказа не реализован.

Backend `failure_message` не выводится напрямую. Для failed order Telegram использует безопасный общий текст.

---

### Runtime state

`TelegramSessionStore` зарегистрирован как Laravel singleton и содержит map:

```text
telegram-chat-{chat_id} → X-Session-Token
```

Map существует только в памяти текущего долгоживущего PHP polling-процесса.

Telegram-сервис не сохраняет локально:

* пользователей или контакты;
* категории и продукты;
* выбранный экран, категорию или продукт;
* cart ID, items, quantity или totals;
* подтверждение очистки;
* checkout snapshot;
* order ID, status или idempotency key.

`CACHE_STORE=array` также оставляет Laravel/Nutgram cache внутри процесса. Это намеренный stateless prototype design. После перезапуска process-memory token теряется, и пользователь может снова пройти contact onboarding.

Авторитетное состояние продолжает храниться в Ordering Backend.

---

### Обработка ошибок

`OrderingBackendClient` преобразует HTTP и connection failures в `OrderingBackendException` с безопасным внутренним сообщением и, для HTTP, status code.

Основные категории:

* `401` для session-bound запроса — забыть token, создать/разрешить session, показать contact onboarding и остановить исходную операцию;
* `404` — безопасное сообщение о недоступной корзине, товаре, ресурсе или текущем заказе;
* `409` — безопасное сообщение о конфликте или изменившемся состоянии;
* `422` — безопасное validation/checkout сообщение;
* timeout, connection failure, `5xx` или malformed response — общее сообщение без backend stack trace;
* неоднозначный `POST /api/orders` — не повторять создание и предложить проверить заказ.

Явный error-log context интеграционного клиента не содержит backend response body или tokens. Он включает только безопасные метаданные операции, status и класс exception.

Для `422` при создании заказа Telegram проверяет только явно разрешённый набор известных сообщений о нерабочем времени ресторана. Такое сообщение отображается как:

```text
Сейчас ресторан не принимает заказы. Попробуйте оформить заказ в рабочее время.
```

Любой другой backend/Dots message напрямую пользователю не показывается и получает общий checkout-текст. Telegram не считает каждый `422` признаком закрытого ресторана.

---

### Безопасность callbacks и сообщений

Каждый inline callback подтверждается через `CallbackAcknowledger` ровно один раз и до session resolution, backend GET или мутации.

Telegram errors с признаками устаревшего callback — `query is too old`, `response timeout expired` или `query ID is invalid` — безопасно логируются на debug-уровне. Handler немедленно завершается, поэтому старый callback не может изменить актуальную корзину или создать заказ.

Неожиданные `TelegramException` не скрываются и сохраняют стандартное exception/reporting поведение.

Редактирование inline-сообщений проходит через `TelegramMessageEditor`. Ошибка Telegram `message is not modified` считается успешным no-op: fallback message не отправляется, backend-операция не повторяется. Другие Telegram errors продолжают выбрасываться.

---

### Границы безопасности

Секретами являются:

* `TELEGRAM_BOT_TOKEN`;
* `BACKEND_INTERNAL_API_TOKEN`;
* backend `X-Session-Token`.

Они не должны попадать в исходный код, callback data, пользовательские сообщения или логи.

`OrderingBackendClient` централизованно добавляет `X-Internal-Api-Token`. `X-Session-Token` добавляется только к session-bound запросам.

Telegram принимает только ограниченные идентификаторы действий из своих callbacks: backend product ID для добавления и backend cart-item ID для изменения или удаления. Он не отправляет client-controlled cart ID, restaurant ID, price, total, status или external Dots ID. Ordering Backend повторно проверяет ownership и бизнес-инварианты.

Контакт принимается только от того же Telegram user, который отправил сообщение. Произвольные backend/Dots error messages не пересылаются пользователю.

---

### Важные решения реализации

* Telegram — presentation client, а не второй backend.
* Все HTTP-запросы изолированы в `OrderingBackendClient`.
* Catalog flow не зависит от backend session token.
* Navigation context и idempotency UUID передаются через callback data, а не persistence.
* Cart и order responses считаются авторитетными; monetary values не пересчитываются.
* Cart item ID и product ID имеют разные роли и не взаимозаменяемы.
* Fresh cart читается перед quantity/remove mutation.
* DELETE-result подтверждается последующим `GET /api/carts/current`.
* State-changing запросы не повторяются автоматически после session recovery.
* Неоднозначный order POST не повторяется автоматически.
* Следующая корзина обеспечивается только после подтверждённого успешного order response.
* Stale callbacks останавливаются до backend work.
* Идентичное редактирование Telegram message является безопасным no-op.

---

### Тестирование

Автоматические feature tests используют Laravel HTTP fakes и Nutgram fake transport, поэтому не выполняют реальные запросы в Ordering Backend, Telegram или Dots.

Тестами покрыты integration headers и payloads, response normalization, session lifecycle, contact ownership, catalog navigation, cart mutations, checkout, idempotency, next-cart initialization, order refresh, safe backend errors, stale callbacks, Telegram message editing, keyboards и пользовательское форматирование.
