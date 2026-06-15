Веб-приложение для парсинга отзывов и рейтинга организаций из Яндекс.Карт.

**Сайт:** https://web-production-48ec5.up.railway.app  
**Репозиторий:** https://github.com/MaksimKrishtopa/imtera-test  
**Логин / пароль:** `admin@imtera.test` / `password`


### Поддерживаемые форматы URL

| Формат | Пример |
|--------|--------|
| `/org/{slug}/{id}/` | `https://yandex.ru/maps/org/ges_2/216491468916/` |
| `/org/{slug}/{id}/reviews/` | `https://yandex.ru/maps/org/.../21117108341/reviews/` |
| `poi[uri]=ymapsbm1://org?oid=...` | Ссылка из попапа при клике на карте |

Парсер нормализует URL к виду `/org/{slug}/{id}/reviews/` (с сохранением слага), что позволяет избежать редиректов Яндекса, приводящих к закрытию страницы в Playwright.

## Локальный запуск

### Требования
- PHP 8.3+, Composer
- Node.js 22+, npm
- SQLite (встроен в PHP)
- Playwright: `npx playwright install chromium --with-deps`

### Установка

```bash
git clone https://github.com/MaksimKrishtopa/imtera-test.git
cd imtera-test
cp .env.example .env
```

Отредактируйте `.env`:
```env
APP_KEY=          # сгенерируется ниже
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
             npx playwright install chromium --with-deps &&
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
  author_name, rating (1-5), text, date (YYYY-MM-DD),
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
