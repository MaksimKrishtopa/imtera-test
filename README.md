# Яндекс.Карты — Сбор отзывов

Приложение для сбора и отображения отзывов с карточки организации в Яндекс.Картах.

**Стек:** Laravel 11 + Vue 3 · PostgreSQL/SQLite · Playwright (Node.js) · Laravel Sanctum · Pinia

---

## Быстрый старт (Docker Compose)

```bash
git clone <repo-url> && cd imtera-test
cp .env.example .env
# Отредактируйте .env: APP_KEY, DB_* переменные
docker compose up --build -d
```

После запуска:
- **Frontend:** http://localhost:8080
- **API:** http://localhost:8080/api
- **Логин:** `admin@imtera.test` / `password`

> **Примечание:** Docker Compose файл для самостоятельной настройки (см. ниже пример).

---

## Локальный запуск без Docker

### Требования
- PHP 8.3+ с расширениями: `pdo_sqlite`, `tokenizer`, `mbstring`, `xml`, `ctype`, `json`, `curl`
- Node.js 18+ и npm
- Composer

### Установка

```bash
# 1. Зависимости
composer install
npm install

# 2. Playwright Chromium
npx playwright install chromium

# 3. Окружение
cp .env.example .env.local
# Установите: APP_KEY (php artisan key:generate), DB_CONNECTION=sqlite

php artisan key:generate
php artisan migrate --seed    # создаёт пользователя admin@imtera.test / password

# 4. Сборка фронтенда
npm run build

# 5. Запуск
php artisan serve &           # порт 8000
php artisan queue:work --timeout=360 --tries=1  # обработчик задач
```

---

## Переменные окружения

| Переменная | Описание | По умолчанию |
|---|---|---|
| `APP_KEY` | Ключ шифрования Laravel | — |
| `APP_URL` | Базовый URL приложения | `http://localhost:8000` |
| `DB_CONNECTION` | Тип БД: `sqlite` или `mysql`/`pgsql` | `sqlite` |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Параметры MySQL/PostgreSQL | — |
| `QUEUE_CONNECTION` | Драйвер очереди: `database` | `database` |
| `SESSION_DRIVER` | Хранилище сессий | `database` |

---

## Docker Compose (пример)

```yaml
version: '3.9'
services:
  app:
    build: .
    ports: ["8080:8000"]
    environment:
      APP_KEY: ${APP_KEY}
      DB_CONNECTION: pgsql
      DB_HOST: db
      DB_DATABASE: imtera
      DB_USERNAME: imtera
      DB_PASSWORD: secret
      QUEUE_CONNECTION: database
    depends_on: [db]

  worker:
    build: .
    command: php artisan queue:work --timeout=360 --tries=1
    environment: *app_env
    depends_on: [db]

  db:
    image: postgres:16-alpine
    environment:
      POSTGRES_DB: imtera
      POSTGRES_USER: imtera
      POSTGRES_PASSWORD: secret
    volumes: [pgdata:/var/lib/postgresql/data]

volumes:
  pgdata:
```

---

## Подход к парсингу

### Почему Playwright, а не прямые HTTP-запросы

Яндекс.Карты применяют несколько уровней защиты от ботов:

1. **TLS Fingerprinting** — сервер проверяет TLS handshake и сигнатуры JA3/JA4 запроса. Guzzle/curl имеют характерный fingerprint, отличный от Chrome.
2. **JavaScript Challenge** — страница требует выполнения JS-кода для получения CSRF-токена. Без реального JS-движка токен не вычислить.
3. **Lazy Loading** — отзывы подгружаются по мере скролла страницы (Intersection Observer). При одном HTTP-запросе доступны только ~15 первых отзывов из DOM.
4. **Поведенческий анализ** — `fetchReviews` API возвращает `{"csrfToken":"..."}` вместо данных при нехарактерных паттернах запросов.

**Решение:** Запуск headless Chromium через [Playwright](https://playwright.dev/) — реальный браузер с корректным TLS fingerprint, поддержкой JS и полноценным DOM. PHP-сервис запускает Node.js скрипт как дочерний процесс.

### Архитектура парсинга

```
OrganizationController
    └── ParseOrganizationJob (Queue)
            └── YandexMapsParser::parse()
                    └── runNodeScraper() → proc_open → node scrape-reviews.cjs
                                                            │
                                                            ├── Playwright Chromium
                                                            ├── Навигация на /reviews/
                                                            ├── Schema.org aggregateRating → рейтинг, счётчики
                                                            ├── [itemprop="review"] → отзывы из DOM
                                                            └── Скроллинг контейнера → +50 отзывов каждые 2.5с
```

### Почему очередь (Queue)

Парсинг 600 отзывов занимает **2–4 минуты** (запуск Chromium + скроллинг). HTTP-запрос с таким таймаутом неприемлем. Поэтому:

- `POST /api/organization/parse` → диспатчит `ParseOrganizationJob` → возвращает `202 Accepted`
- Фронтенд получает `parse_status: "processing"` и начинает **polling** каждые 5 секунд
- Когда статус меняется на `"done"` — загружает отзывы

### Что парсится

- **Организация:** название, средний рейтинг, количество оценок и отзывов (из Schema.org `aggregateRating`)
- **Отзывы:** автор, аватар, оценка (из `aria-label` звёзд), текст, дата (конвертация из русских названий месяцев в ISO 8601)
- **Объём:** до 600 отзывов через автоскроллинг (6 попыток без новых отзывов = стоп)

### Кэширование vs. реалтайм

Все отзывы сохраняются в БД при первом парсинге. Фронтенд запрашивает **только 50 отзывов** текущей страницы через `GET /api/reviews?page=N`. Повторный вызов «Обновить» перезаписывает данные в БД.

---

## Структура БД

```sql
users           -- id, name, email, password
organizations   -- id, user_id, url, yandex_id, name, rating,
                --   reviews_count, ratings_count,
                --   parse_status (pending|processing|done|error),
                --   parse_error, parsed_at
reviews         -- id, organization_id, author_name, author_avatar,
                --   rating, text, reviewed_at, yandex_review_id
jobs            -- Laravel queue jobs table
```

---

## API Endpoints

| Метод | URL | Описание |
|---|---|---|
| POST | `/api/auth/login` | Авторизация (email + password) |
| POST | `/api/auth/logout` | Выход |
| GET | `/api/auth/me` | Текущий пользователь |
| GET | `/api/organization` | Данные организации |
| POST | `/api/organization` | Сохранить URL организации |
| POST | `/api/organization/parse` | Запустить парсинг (async, 202) |
| GET | `/api/reviews?page=N` | Отзывы, 50 на страницу |

---

## Что можно улучшить

1. **yandex_review_id** — Яндекс не отдаёт уникальные ID в DOM; можно попробовать перехват XHR-ответов `fetchReviews` через Playwright Network API
2. **Websockets / SSE** — вместо polling уведомлять фронтенд о завершении парсинга в реальном времени
3. **Расписание** — `php artisan schedule:run` для автообновления отзывов раз в сутки
4. **Регрессионные тесты** — мокировать Playwright-вывод для unit-тестов парсера
5. **Горизонтальное масштабирование** — вынести queue worker в отдельный сервис (уже есть в Procfile)
6. **Обход блокировки** — ротация proxy/userAgent, `playwright-extra` с `puppeteer-extra-plugin-stealth`
7. **Множество организаций** — расширить модель под несколько организаций на пользователя
