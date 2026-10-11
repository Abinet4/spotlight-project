# Furniture Marketplace (working name: Betoch Furniture)

A multi-vendor furniture marketplace for Addis Ababa. Customers browse and compare furniture from verified local sellers; sellers apply, get approved, and manage listings; administrators approve sellers, moderate listings and manage categories.

- `backend/` Laravel 13 REST API (PHP 8.3+, MySQL 8 / MariaDB 10.6+, Sanctum token auth)
- `frontend/` React 19 + Vite 8 + Tailwind CSS 4 single-page app
- `docs/ARCHITECTURE.md` schema, relationship diagram, business rules, security notes, roadmap

The brand name is configurable (`MARKETPLACE_NAME` in `backend/.env`). Nothing else in the code depends on it.

## Status

Milestone 1 (foundation and catalog), milestone 2 (in-app inquiries, custom quotes, customer addresses, delivery zones) and milestone 3 (cart, checkout with one order per seller, stock reservation, cash on delivery, seller and admin order handling, commission settings) are implemented. See `docs/ARCHITECTURE.md` for what is done and what comes next. Online payments, seller settlements, reviews and disputes are **not built yet**.

## Requirements

- PHP 8.3+ with `pdo_mysql`, `mbstring`, `gd`, `fileinfo`, `intl`
- Composer 2
- Node 22+ and npm
- MySQL 8 or MariaDB 10.6+ (SQLite works for quick local tries and is used by the test suite)

## Local setup

```bash
# 1. Backend
cd backend
composer install
cp .env.example .env
php artisan key:generate
# Create an empty database and user, then fill DB_* in .env, for example:
#   CREATE DATABASE marketplace CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
php artisan migrate
php artisan db:seed                       # Addis Ababa sub-cities and furniture categories
php artisan storage:link                  # serves uploaded photos from /storage
php artisan marketplace:create-admin you@example.com --name="Your Name"   # prompts for a password
php artisan db:seed --class=DemoSeeder    # optional sample sellers and listings (refuses to run in production)
php artisan serve                         # http://localhost:8000

# 2. Frontend (new terminal)
cd frontend
cp .env.example .env
npm install
npm run dev                               # http://localhost:5173, proxies /api and /storage to :8000
```

Demo accounts created by `DemoSeeder`: `seller1@demo.local` to `seller4@demo.local` and `customer@demo.local`. The password is `DEMO_USER_PASSWORD` from `.env`, or a random one printed once by the seeder.

## Checks

```bash
cd backend
php artisan test                 # 79 feature and unit tests (SQLite in memory by default)
DB_CONNECTION=mysql DB_DATABASE=marketplace_test DB_USERNAME=... DB_PASSWORD=... php artisan test   # same suite on MySQL
./vendor/bin/pint --test         # code style

cd ../frontend
npm run lint                     # oxlint
npm run build                    # production build into dist/
```

## Deployment notes

- Build the frontend (`npm run build`) and serve `frontend/dist` as static files, with every unknown path falling back to `index.html`.
- Serve the API from the same domain under `/api` (recommended), or set `VITE_API_URL` at build time and list the frontend origin in `CORS_ALLOWED_ORIGINS`.
- Production `.env`: `APP_ENV=production`, `APP_DEBUG=false`, a real `APP_URL`, strong DB credentials, HTTPS only.
- Run `php artisan migrate --force`, `php artisan config:cache`, `php artisan route:cache` on each deploy.
- `storage/app/public` holds seller photos: put it on persistent storage (or switch the `public` disk to S3-compatible storage) and include it in backups.

## Backups and recovery

- Database: nightly `mysqldump --single-transaction --routines marketplace | gzip` to off-server storage, keeping at least 14 daily and 8 weekly copies. Test a restore into a scratch database monthly.
- Files: back up `backend/storage/app/public` (product photos) on the same schedule.
- Restore: create an empty database, `gunzip < backup.sql.gz | mysql marketplace`, restore the photo folder, then `php artisan migrate --force` to apply any newer migrations.

## Troubleshooting

- **Images do not load**: run `php artisan storage:link` and make sure `APP_URL` matches the URL you open in the browser.
- **401 after login in production**: the frontend and API are on different origins and `VITE_API_URL` or `CORS_ALLOWED_ORIGINS` is not set.
- **429 Too Many Requests**: login is limited to 5 attempts per minute per email and IP; wait a minute.
- **"Specified key was too long" on old MySQL**: use MySQL 5.7.7+ / MariaDB 10.2+ with utf8mb4.
