# B2B Customer Portal

Portal where industrial customers browse a bearings/parts catalog, request quotes, track orders and download invoices, and staff manage everything. See `docs/PLAN.md` for the phased build plan and `CLAUDE.md` for project rules.

Layout: `api/` (Laravel 12, PHP 8.3) · `web/` (React + TypeScript + Vite) · `docs/` · `docker-compose.yml` (PostgreSQL 16, Mailpit).

## Prerequisites
Git, PHP 8.3 (extensions: openssl, mbstring, curl, fileinfo, zip, pdo_pgsql), Composer 2, Node 22.22+ or 24+ (CI uses 24), Docker.

## Setup and run
```bash
# 1. Services (PostgreSQL on 5433, Mailpit SMTP 1025 / UI http://localhost:8025)
docker compose up -d

# 2. API  ->  http://127.0.0.1:8000/api/v1/health
cd api
composer install
cp .env.example .env
php artisan key:generate
php artisan serve

# 3. Web (new terminal)  ->  http://localhost:5173 (proxies /api to :8000)
cd web
npm ci
npm run dev
```
Phase 0 needs no database connection; the services in step 1 are used from Phase 1 on.

## Checks (run before finishing any task)
```bash
cd api && ./vendor/bin/pest && ./vendor/bin/pint --test
cd web && npm run lint && npm test && npm run build
```
GitHub Actions (`.github/workflows/ci.yml`) runs the same checks on every push.

## Production note
`api/.env.example` is for local development (`APP_ENV=local`, `APP_DEBUG=true`, default `portal`/`secret` database credentials). Production must set `APP_ENV=production`, `APP_DEBUG=false` and its own secrets.
