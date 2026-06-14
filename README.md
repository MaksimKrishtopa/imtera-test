# Яндекс.Карты — Сервис отзывов

Приложение для парсинга отзывов с Яндекс.Карт. Позволяет вставить ссылку на организацию и получить все доступные отзывы, средний рейтинг и счётчики.

## Стек

- **Backend**: Laravel 11, PHP 8.3, MySQL, Laravel Sanctum (SPA auth)
- **Frontend**: Vue 3, Composition API, Pinia, Vue Router, Axios
- **Парсинг**: Guzzle HTTP (reverse-engineered Yandex internal API)
- **Деплой**: Railway (nixpacks, без Docker)

---

## Локальный запуск

### Требования

- PHP 8.2+
- Composer
- Node.js 18+
- MySQL 8.0+

### Шаги

```bash
git clone https://github.com/MaksimKrishtopa/imtera-test.git
cd imtera-test

# Зависимости
composer install
npm install

# Конфигурация
cp .env.example .env
php artisan key:generate
```

Заполни `.env`:
```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=yandex_reviews
DB_USERNAME=root
DB_PASSWORD=your_password
```

```bash
# Создать БД и запустить миграции
php artisan migrate --seed

# Собрать фронтенд
npm run build

# Запустить сервер
php artisan serve
```

Открой http://localhost:8000

**Credentials:** `admin@imtera.test` / `password`

---

## Docker Compose (опционально)

```bash
docker compose up -d
docker compose exec app php artisan migrate --seed
```

---

## Переменные окружения

| Переменная | Описание |
|-----------|----------|
| `APP_KEY` | Ключ шифрования (генерируется `php artisan key:generate`) |
| `APP_URL` | URL приложения |
| `DB_*` | Параметры подключения к MySQL |
| `SESSION_DRIVER` | `file` (рекомендуется для простоты) |

---

## Подход к парсингу и обходу защиты

### Почему не headless browser

Puppeteer/Playwright требуют Chromium (~150MB+), медленны (5-15 сек на страницу), сложны в деплое на бесплатном хостинге и агрессивно детектируются Яндексом через поведенческие метрики.

### Выбранный подход: reverse engineering внутреннего API

Яндекс.Карты — SPA-приложение. При открытии карточки организации браузер делает XHR-запросы к внутренним эндпоинтам:

```
GET https://yandex.ru/maps/api/business/fetchReviews?businessId={id}&from={offset}&limit=50
```

Сервис `YandexMapsParser`:
1. Извлекает ID организации из URL (regex по паттернам `/org/{name}/{id}/` или `?oid=`)
2. Делает HTTP-запросы через Guzzle с реальными браузерными заголовками
3. Пагинирует по 50 отзывов с задержкой 0.5с между запросами
4. Нормализует ответ в единый формат
5. Сохраняет результат в БД

### Кэширование

Результат парсинга хранится в БД (`organizations` + `reviews`). Повторный парсинг запускается вручную или автоматически если данные старше 6 часов (`Organization::needsRefresh()`). Это значит:
- Страница отзывов загружается мгновенно (из БД)
- Парсинг ~600 отзывов занимает ~6 секунд (12 запросов × 0.5с)

### Обработка ошибок

- Недоступная страница → понятное сообщение пользователю
- Изменилась разметка / пустой ответ → fallback на второй стратегии парсинга
- Яндекс заблокировал → `parse_error` с описанием

---

## Структура БД

```
users
  id, name, email, password

organizations
  id, user_id, url, yandex_id
  name, rating, reviews_count, ratings_count
  parse_status (pending/processing/done/error)
  parse_error, parsed_at

reviews
  id, organization_id
  author_name, author_avatar
  rating (1-5), text, reviewed_at
  yandex_review_id
```

---

## Что доделал бы при большем времени

1. **Queue Jobs** — вынести парсинг в фоновую очередь (Laravel Queue + Redis), чтобы HTTP-запрос не висел 6 секунд
2. **WebSocket / SSE** — real-time прогресс парсинга на фронте
3. **Rotate proxies** — при блокировке Яндексом использовать пул прокси
4. **Тесты** — unit-тесты для парсера, feature-тесты для API
5. **Автообновление** — cron-job для регулярного обновления отзывов
6. **Фильтрация** — фильтр по рейтингу, сортировка, поиск по тексту
7. **Multi-org** — поддержка нескольких организаций на пользователя
