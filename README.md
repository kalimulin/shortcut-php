# shortcut-php — единый PHP-бэкенд сервиса «Короткие ссылки»

Автономный PHP 8.1+ монолит для обычного PHP-хостинга (Apache / Nginx + PHP-FPM + MySQL/MariaDB), объединяющий функциональность:

- `shortcut-core` (ссылки, кампании, теги, `claim` гостевых ссылок, статистика, экспорт CSV/XLSX);
- `url-check` (проверка безопасности URL, чёрный список доменов);
- публичные гостевые ручки (`/api/public/*`, генерация QR-кодов в SVG);
- редирект `/s/{code}` с синхронной записью кликов в MySQL;
- собственную JWT-авторизацию и профиль пользователя (`/api/auth/*`, `/api/profile`).

Не требует Docker, фоновых воркеров, `cron`, `nginx njs`, Composer на сервере и расширений GD/Imagick.

## Требования хостинга

- PHP **8.1+** с расширениями `pdo_mysql`, `json`, `mbstring`, `zip` (для XLSX)
- MySQL **5.7+** или MariaDB **10.2+** (InnoDB, `utf8mb4`)
- Apache с `mod_rewrite` (`.htaccess` в комплекте) либо Nginx + PHP-FPM (`config/nginx.example.conf`)

## Развёртывание на PHP-хостинге (один домен)

1. Создайте базу данных MySQL в панели хостинга и импортируйте:
   - `database/migrations/001_init.sql`
   - `database/seeds/001_seed.sql` *(опционально: создаёт админа `admin@example.com` / `password` — сразу смените пароль!)*
2. Загрузите файлы:
   - Папку `backend/` (`src/`, `database/`, `.env`) разместите **выше** `public_html/` (либо внутри `public_html/backend/` с `backend/.htaccess`: `Require all denied`).
   - В `public_html/` положите сборку `cherry-frontend/dist/*`, а также `public/.htaccess`, `public/api.php` и `public/redirect.php`.
3. Скопируйте `.env.example` в `.env` рядом с `src/` и заполните:
   - `APP_URL`, `SHORTCUT_BASE_HOST` — ваш домен;
   - `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`;
   - `JWT_SECRET` — случайная строка длиной от 32 символов.
4. Проверьте работу: `GET https://ваш-домен/api/info` → `{"version":"1.0.0","status":"ok"}`.

## Локальный запуск (PHP built-in server)

```bash
cp .env.example .env
# настройте DB_* и CORS_ALLOWED_ORIGIN=http://localhost:5173
php -S 127.0.0.1:8000 router_dev.php
```
