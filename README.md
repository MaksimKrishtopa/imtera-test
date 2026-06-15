# Yandex Maps Reviews — Laravel + Vue 3

Веб-приложение для парсинга отзывов и рейтинга организаций из Яндекс.Карт.

🌐 **Демо:** https://web-production-48ec5.up.railway.app  
📦 **Репозиторий:** https://github.com/MaksimKrishtopa/imtera-test  
**Логин:** `admin@imtera.test` / `password`

---

## Стек

| Слой | Технологии |
|---|---|
| Backend | Laravel 11, PHP 8.3, Laravel Sanctum |
| Frontend | Vue 3 (Composition API), Pinia, Axios, Vite |
| БД | SQLite (локально), PostgreSQL (Railway) |
| Парсинг | Node.js + Playwright (headless Chromium) |
| Хостинг | Railway (free tier) |

---

## Локальный запуск

### Требования
- PHP 8.3+, Composer
- Node.js 22+, npm
- SQLite (встроен в PHP)

### Установка

```bash
git clone https://github.com/MaksimKrishtopa/imtera-test.git
cd imtera-test
cp .env.example .env
```

Отредактируйте `.env`:
```env
APP_KEY=       # будет сгенерирован ниже
DB_CONNECTION=sqlite
QUEUE_CONNECTION=database
```

```bash
composer install
php artisan key:generate
php artisan migrate
php artisan db:seed

npm install
npm run build
php artisan serve
```

Откройте http://localhost:8000

### Docker Compose (опционально)

```yaml
version: "3.8"
services:
  app:
    image: php:8.3-cli
    working_dir: /app
    volumes: [.:/app]
    ports: ["8000:8000"]
    command: >
      sh -c "composer install && npm ci && npm run build &&
             php artisan migrate && php artisan db:seed &&
             php artisan serve --host=0.0.0.0"
    environment:
      - DB_CONNECTION=sqlite
      - QUEUE_CONNECTION=database
```

---

## Переменные окружения

| Переменная | Описание | Пример |
|---|---|---|
| `APP_KEY` | Laravel application key | `base64:...` |
| `APP_URL` | URL приложения | `https://...` |
| `DB_CONNECTION` | Драйвер БД | `pgsql` / `sqlite` |
| `DATABASE_URL` | PostgreSQL URL (Railway авто) | `postgresql://...` |
| `SANCTUM_STATEFUL_DOMAINS` | Домены для SPA-авторизации | `yourdomain.com` |
| `ASSET_URL` | HTTPS URL для ассетов | `https://...` |

---

## Подход к парсингу

### Проблема

Яндекс.Карты не предоставляют публичного API. Прямые HTTP-запросы блокируются:
- CSRF-защита (все запросы возвращают `{"csrfToken":"..."}`)
- Bot-детекция по заголовкам и поведению
- Динамическая подгрузка отзывов при скролле (lazy loading)

### Решение — Playwright (headless Chromium)

Парсер (`scripts/scrape-reviews.cjs`) запускает **настоящий браузер** (Chromium) без интерфейса и симулирует поведение пользователя:

1. **Навигация** — открывает страницу отзывов организации
2. **Извлечение метаданных** — ищет `aggregateRating` в Schema.org microdata (`<meta itemprop="...">`)
3. **Прокрутка** — повторяет скролл вниз с паузами, пока новые отзывы перестают подгружаться
4. **Извлечение отзывов** — парсит DOM-элементы `.business-review-view`, извлекает автора, рейтинг, дату, текст
5. **Дедупликация** — убирает дубли по ID отзыва

### Асинхронность

Парсинг занимает 3–8 минут. Чтобы HTTP-запрос не висел:
- `POST /api/organization/parse` немедленно возвращает `202 Accepted`
- Через `exec()` в фоне запускается `php artisan app:parse-org {id}`
- Фронтенд **поллит** `GET /api/organization` каждые 5 секунд, пока `parse_status !== 'done'`

### Ограничения и возможные улучшения

- Яндекс периодически меняет DOM-структуру → нужно мониторить CSS-классы
- Playwright использует ~500 MB RAM → на free-tier Railway может упасть при нагрузке
- Playwright устанавливается при каждом деплое (~8 мин), что медленно
- Для высокой нагрузки: вынести парсинг в отдельный микросервис с Redis-очередью

---

## Структура БД

```sql
-- Организации (по одной на пользователя)
organizations:
  id, user_id, url, yandex_id, name,
  rating, reviews_count, ratings_count,
  parse_status (pending|processing|done|error),
  parse_error, parsed_at, created_at, updated_at

-- Отзывы
reviews:
  id, organization_id, yandex_review_id,
  author, rating (1-5), text, date (YYYY-MM-DD),
  created_at, updated_at
```

---

## API-эндпоинты

| Метод | URL | Описание |
|---|---|---|
| `POST` | `/api/auth/login` | Авторизация |
| `POST` | `/api/auth/logout` | Выход |
| `GET` | `/api/organization` | Текущая организация |
| `POST` | `/api/organization` | Сохранить URL |
| `POST` | `/api/organization/parse` | Запустить парсинг (202 async) |
| `GET` | `/api/reviews?page=N` | Отзывы (50 на страницу) |
