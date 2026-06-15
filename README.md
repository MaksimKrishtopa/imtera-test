# Yandex Maps Reviews — Laravel + Vue 3

Веб-приложение для парсинга отзывов и рейтинга организаций из Яндекс.Карт.

🌐 **Сайт:** https://web-production-48ec5.up.railway.app  
📦 **Репозиторий:** https://github.com/MaksimKrishtopa/imtera-test  
**Логин / пароль:** `admin@imtera.test` / `password`

---

## Подход к парсингу

Яндекс.Карты не имеют публичного API для получения отзывов и активно защищаются от ботов. Выбранное решение — headless-браузер через **Node.js + Playwright (Chromium)**, который имитирует реального пользователя.

### Почему именно Playwright?

- Отзывы подгружаются динамически через WebSocket/XHR по мере прокрутки — обычный HTTP-запрос получит только первые ~10 отзывов.
- Яндекс проверяет заголовки, fingerprint браузера и поведение мыши.
- Playwright запускает полноценный браузер, который обходит базовые защиты.

### Алгоритм скрапера (`scripts/scrape-reviews.cjs`)

1. **Запуск браузера** с флагами для Docker/малой памяти (`--no-sandbox`, `--disable-dev-shm-usage`, `--single-process`).
2. **Блокировка тяжёлых ресурсов** (`image`, `font`, `media`, рекламные домены Яндекса) — ускоряет загрузку в 2–3 раза.
3. **Навигация** к URL отзывов с `waitUntil: 'domcontentloaded'` (не `networkidle` — Яндекс постоянно делает фоновые запросы, и networkidle никогда не срабатывает).
4. **Прокрутка** через `page.mouse.wheel(0, 3000)` — симуляция физической прокрутки мышью. Это единственный надёжный способ триггерить lazy-loading при `--single-process`.
5. **Извлечение** отзывов из DOM после каждого скролла; остановка при 6 последовательных итерациях без новых отзывов или достижении лимита (600).
6. **Метаданные** (рейтинг, число оценок, число отзывов) — из Schema.org `<script type="application/ld+json">` и DOM-элементов карточки.

### Поддерживаемые форматы URL

| Формат | Пример |
|--------|--------|
| `/org/{slug}/{id}/` | `https://yandex.ru/maps/org/ges_2/216491468916/` |
| `/org/{slug}/{id}/reviews/` | `https://yandex.ru/maps/org/.../21117108341/reviews/` |
| `poi[uri]=ymapsbm1://org?oid=...` | Ссылка из попапа при клике на карте |

Парсер нормализует URL к виду `/org/{slug}/{id}/reviews/` (с сохранением слага), что позволяет избежать редиректов Яндекса, приводящих к закрытию страницы в Playwright.

### Асинхронность

Парсинг занимает 40–120 секунд. HTTP-запрос немедленно возвращает `202 Accepted`, а PHP запускает фоновый Artisan-процесс (`exec('php artisan app:parse-org {id} &')`). Фронтенд поллит `/api/organization` каждые 3 секунды пока `parse_status == 'processing'`. Если контейнер перезапускался во время парсинга, статус автоматически сбрасывается в `error` через 10 минут.

### Результаты на тестовых URL

| Организация | Отзывов в Яндексе | Спарсено | Время |
|-------------|-------------------|----------|-------|
| Третьяковская галерея (26 118 отзывов) | 26 118 | 199 | ~80с |
| ГЭС-2 (9 417 отзывов) | 9 417 | 200 | ~80с |
| Малая организация (48 отзывов) | 48 | 48 | ~40с |

> Лимит в ~200 отзывов на Railway Free Tier обусловлен 512 МБ RAM: Chromium занимает ~300 МБ, и при более длительной прокрутке процесс убивается OOM-killer'ом. На сервере с 1+ ГБ RAM можно получить все 600.

### Потенциальные улучшения

- Перехват внутренних API-запросов Яндекса (XHR/Fetch) вместо DOM-скрапинга — быстрее и надёжнее.
- Ротация User-Agent и прокси для обхода более агрессивных защит.
- Увеличение лимита памяти Railway → получение полных 600 отзывов.
- Кэширование на уровне Redis с TTL, чтобы не перепарсивать свежие данные.

---

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
