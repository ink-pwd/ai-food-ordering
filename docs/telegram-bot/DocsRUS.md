# AI Food Ordering — Telegram Bot — документация разработчика

## 1. Назначение и архитектура

Telegram Bot — это тонкий Laravel/Nutgram-клиент для backend-сервиса заказов.

```text
Telegram user -> Telegram bot -> Backend REST API -> Dots
```

Telegram-сервис отвечает за интерфейс в Telegram: сообщения, кнопки, callback-и и передачу действий пользователя в backend. Backend владеет бизнес-логикой и состоянием. Telegram Bot не обращается к Dots напрямую.

Пользовательский интерфейс бота — только украинский. Этот документ написан на русском только как developer-документация.

## 2. Границы сервисов

Telegram Bot делает:

- создает/переиспользует backend session token;
- отправляет contact/OTP/city/restaurant/fulfillment/cart/order/payment действия в backend;
- рендерит ответы backend в Telegram;
- загружает backend-generated QR PNG в Telegram.

Backend делает:

- хранит session/contact/city/restaurant/fulfillment/cart/order/payment состояние;
- валидирует и нормализует телефон;
- проверяет OTP;
- валидирует доставку, зону и цену;
- создает заказ и платеж;
- получает платежный URL;
- генерирует QR через свой endpoint;
- взаимодействует с Dots.

## 3. Полный пользовательский flow

```text
/start
  -> отправка контакта
  -> OTP код
  -> выбор города
  -> выбор ресторана
  -> выбор способа получения
     -> доставка: тип адреса -> ForceReply адрес -> backend validation
     OR
     -> самовывоз: выбор pickup address
  -> главное меню
  -> каталог -> категория -> товар
  -> корзина
  -> checkout confirmation
  -> POST /api/orders
  -> экран заказа/оплаты
  -> payment URL button
  -> backend QR PNG, если доступен
  -> ручное обновление заказа/оплаты
  -> /exit или 🚪 Вийти
```

Автоматический E2E-тест полного пути не поддерживается намеренно. Полный путь проверяется вручную на реальных сервисах.

## 4. Жизненный цикл backend session

`/start` вызывает `POST /api/sessions` через `OrderingBackendClient::createTelegramSession()`.

Backend возвращает `session_token`. Telegram Bot сохраняет только соответствие:

```text
telegram-chat-{chatId} -> X-Session-Token
```

Хранилище — in-memory service в текущем PHP-процессе. Перезапуск `nutgram:run` может потерять token map. Это ожидаемое поведение прототипа.

При 401 `TelegramSessionRecovery` создает новую backend session и возвращает пользователя к contact onboarding. Прерванные write-операции автоматически не повторяются.

## 5. Contact + OTP

Telegram contact принимается только если `contact.user_id` совпадает с Telegram user id отправителя. Номер телефона без собственной проверки страны передается в backend contact API:

```http
PUT /api/sessions/current/contact
```

Backend остается authoritative validator. После успешного contact update бот вызывает:

```http
POST /api/sessions/current/otp
```

Пользователь вводит код обычным текстом. Маршрут `onText {code}` принимает числовой код и вызывает:

```http
POST /api/sessions/current/otp/verify
```

После успешной проверки бот показывает выбор города.

## 6. Нормализация телефона

Нормализация находится в backend `UpdateSessionContactRequest`.

Поддерживается international `+` формат в generic E.164-style виде:

```text
+[country code + subscriber digits]
```

Требуемые валидные примеры:

```text
+380931234567
+34123456789
+14155552671
```

Автоматическая нормализация без `+` поддерживает однозначные префиксы:

```text
380931234567 -> +380931234567
34123456789  -> +34123456789
14155552671  -> +14155552671
```

Префикс `00` нормализуется в `+`:

```text
00380931234567 -> +380931234567
0034123456789  -> +34123456789
0014155552671  -> +14155552671
```

Украинский local format сохраняется:

```text
0931234567 -> +380931234567
```

Форматирование пробелами, дефисами и скобками удаляется:

```text
+380 (93) 123-45-67 -> +380931234567
+34 612 34 56 78    -> +34612345678
+1 (415) 555-2671   -> +14155552671
```

Неоднозначные local numbers не превращаются в international автоматически.

## 7. City/restaurant immutability

Город и ресторан выбираются через backend session endpoints. Если backend отвечает conflict на повторный выбор, бот не создает новую session и продолжает безопасный flow к следующему шагу.

Ресторан в дальнейших callback-ах представлен backend-local `restaurantId`, а не slug.

## 8. Exit semantics

`/exit` и callback `exit` пытаются вызвать:

```http
DELETE /api/sessions/current
```

Затем локальный token забывается, создается новая backend session и пользователь возвращается к contact onboarding. Exit не отменяет уже созданный Dots/backend order.

## 9. Fulfillment

### Delivery

Callback-и:

```text
fulfillment:delivery:{restaurantId}:{fp}
delivery:type:{type}:{restaurantId}:{fp}
delivery:retry:{restaurantId}:{fp}
```

Типы адреса:

```text
0 — 🏢 Квартира
1 — 🏠 Приватний будинок
2 — 🏢 Офіс
3 — 📍 Інше
```

После выбора типа бот отправляет ForceReply с marker:

```text
#delivery-address:{type}:{restaurantId}:{fp}
```

Marker позволяет обработать адрес stateless: бот не сохраняет черновик адреса локально.

Адрес парсится как comma-separated input:

```text
Вулиця, будинок, квартира
```

Backend получает:

```http
POST /api/sessions/current/delivery-address
```

и возвращает availability, reason, delivery price, dots delivery type и fulfillment. Бот показывает украинский результат: доставка доступна, цена, invalid address или outside delivery zone.

### Pickup

Callback-и:

```text
fulfillment:pickup:{restaurantId}:{fp}
pickup:{restaurantAddressId}:{restaurantId}:{fp}
```

Бот получает pickup addresses из backend и сохраняет выбранный адрес через backend endpoint. Локально pickup state не хранится.

## 10. Restaurant navigation context

Контекст callback-а:

```text
{restaurantId}:{fingerprint}
```

`fingerprint` — первые 12 символов SHA-256 от текущего backend session token. Это защита от stale callbacks, а не секрет и не авторизация сама по себе.

Каждый session-bound callback проверяет fingerprint относительно текущего token и, где нужно, проверяет что restaurant belongs to current session через backend restaurants endpoint.

## 11. Callback formats

Основные форматы:

```text
otp:resend
exit
city:{cityId}
restaurant:{restaurantId}
fulfillment:menu:{restaurantId}:{fp}
fulfillment:delivery:{restaurantId}:{fp}
fulfillment:pickup:{restaurantId}:{fp}
delivery:retry:{restaurantId}:{fp}
delivery:type:{type}:{restaurantId}:{fp}
pickup:{restaurantAddressId}:{restaurantId}:{fp}
catalog:{restaurantId}:{fp}
category:{categoryId}:{restaurantId}:{fp}
product:{categoryId}:{productId}:{restaurantId}:{fp}
main_menu:{restaurantId}:{fp}
menu:cart:{restaurantId}:{fp}
cart:add:{productId}:{restaurantId}:{fp}
cart:inc:{itemId}:{restaurantId}:{fp}
cart:dec:{itemId}:{restaurantId}:{fp}
cart:remove:{itemId}:{restaurantId}:{fp}
cart:clear:{restaurantId}:{fp}
cart:clear:confirm:{restaurantId}:{fp}
cart:clear:cancel:{restaurantId}:{fp}
cart:noop:{itemId}:{restaurantId}:{fp}
checkout:{restaurantId}:{fp}
oc:{uuid}:{restaurantId}:{fp}
order:cancel:{restaurantId}:{fp}
order:refresh:{restaurantId}:{fp}
payment:refresh:{restaurantId}:{fp}
```

`oc:` используется вместо длинного `order:confirm:` чтобы Telegram `callback_data` оставался до 64 bytes.

## 12. Catalog

Catalog callbacks валидируют текущий restaurant context, получают restaurant slug из backend current session restaurants и затем вызывают public catalog endpoints backend с internal API token. Slug не хранится в callback.

## 13. Cart

Cart operations session-bound и context-aware. Mutations выполняются ровно один раз после successful callback acknowledgement. После delete/clear backend client загружает authoritative current cart.

Telegram не считает цены и totals локально.

## 14. Checkout

Checkout загружает current cart, показывает backend totals и создает UUID idempotency key один раз при генерации confirmation keyboard.

Кнопки:

```text
✅ Підтвердити -> oc:{uuid}:{restaurantId}:{fp}
❌ Скасувати  -> order:cancel:{restaurantId}:{fp}
```

Cancel — только pre-submit возврат к корзине. Он не отменяет уже созданный backend/Dots order.

## 15. Idempotency key

UUID из confirmation callback передается unchanged в:

```http
POST /api/orders
Idempotency-Key: {uuid}
```

`confirm()` не генерирует новый UUID и не повторяет POST автоматически после 401 recovery или ambiguous failure.

## 16. Order states

- `creating`: `⏳ Замовлення створюється.` и кнопка `🔄 Оновити замовлення`.
- `created`: `✅ Замовлення створено.` и переход к payment state.
- `failed`: `❌ Не вдалося створити замовлення.`.

После успешного createOrder бот не вызывает ensureNextCart/ensureCurrentCart для следующей корзины. Пользователь остается в order/payment flow.

## 17. Online payment

Payment refresh:

```text
payment:refresh:{restaurantId}:{fp}
```

Это read-only GET-style операция. Она не создает заказ, не генерирует idempotency key и не мутирует cart.

## 18. Pending / ready / received

- `pending` или HTTP 202: `⏳ Платіжні дані ще готуються.` и `🔄 Оновити оплату`.
- `ready` + trusted HTTPS checkout URL: `💳 Оплата готова.` и URL button `💳 Оплатити`.
- `ready` не означает paid.
- Только `payment_received_at != null` показывает `✅ Оплату отримано.`.

Checkout URL находится только в Telegram URL button, не в callback_data.

## 19. QR behavior

QR загружается только из backend:

```http
GET /api/orders/current/payment/qr
```

Backend ready response: HTTP 200 `image/png` raw bytes. Telegram Bot отправляет их через Nutgram `sendPhoto()` с ephemeral `php://temp` stream.

Backend pending response: HTTP 202 JSON. Это не ошибка; бот показывает что QR еще готовится.

Если checkout URL валиден, но QR временно недоступен, бот оставляет `💳 Оплатити` и показывает безопасное предупреждение.

Telegram Bot не генерирует QR локально и не сохраняет его.

## 20. Session recovery / 401

На 401 session-bound handlers используют `TelegramSessionRecovery`. Recovery возвращает пользователя к contact onboarding. Прерванные write операции не повторяются автоматически.

## 21. Ошибки и статусы

Callback handlers вызывают `CallbackAcknowledger` ровно один раз до backend work. Stale/expired callback query безопасно останавливает обработчик без backend mutation. Unexpected Telegram exceptions не скрываются.

Backend validation details не показываются пользователю напрямую. Пользовательские ошибки украинские и безопасные.

## 22. Localization and emoji rules

Telegram UI — Ukrainian only. Все видимые кнопки имеют emoji. Backend names/content считаются data и не переводятся.

## 23. Security

Callback data не содержит:

- backend `X-Session-Token`;
- `X-Internal-Api-Token`;
- checkout URL;
- QR bytes;
- Dots credentials;
- full address JSON;
- coordinates;
- payment secrets.

Fingerprint защищает от stale context, но не заменяет backend session authorization. Backend остается authoritative.

## 24. Local state / persistence policy

Telegram Bot не хранит локально:

- city;
- restaurant;
- fulfillment;
- address;
- cart;
- order;
- payment;
- QR;
- idempotency key.

Нет Telegram business DB models, Redis/cache business state или local price cache.

## 25. Configuration

Основные переменные `telegram-bot/.env.example`:

```env
TELEGRAM_BOT_TOKEN=
BACKEND_URL=http://app
BACKEND_INTERNAL_API_TOKEN=
BACKEND_TIMEOUT=10
CACHE_STORE=array
```

Secrets не коммитятся.

## 26. Local development / run commands

Из repository root:

```bash
cp telegram-bot/.env.example telegram-bot/.env
docker compose up -d
docker compose exec --user "$(id -u):$(id -g)" telegram-bot composer install
docker compose exec --user "$(id -u):$(id -g)" telegram-bot php artisan key:generate
docker compose exec telegram-bot php artisan nutgram:run
```

Проверка handlers:

```bash
docker compose exec telegram-bot php artisan nutgram:list
```

## 27. Testing strategy

Автотесты должны быть focused и cheap-maintenance:

- backend client contracts;
- phone normalization;
- callback context/security;
- idempotency key;
- payment/QR focused behavior;
- formatters/helpers and small handler contracts.

Obsolete historical tests and expensive pseudo-E2E should be deleted rather than migrated. Full Telegram journey is manually verified against actual Telegram/backend services.

Полезные команды:

```bash
php artisan test --compact tests/Feature/TelegramStartTest.php tests/Feature/TelegramContactTest.php tests/Feature/TelegramOnboardingTest.php tests/Feature/TelegramFulfillmentTest.php tests/Feature/TelegramCatalogTest.php tests/Feature/TelegramCartTest.php tests/Feature/TelegramCheckoutTest.php tests/Feature/TelegramCallbackAcknowledgementTest.php tests/Feature/TelegramMessageEditorTest.php tests/Feature/TelegramSessionManagerTest.php tests/Feature/OrderingBackendClientTest.php

cd ../backend
php artisan test --compact tests/Feature/SessionContactPhoneNormalizationTest.php
```
