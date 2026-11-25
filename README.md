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

## Database, test database and demo users (Phase 1 on)
```bash
# test database (only needed if your Docker volume existed before the init script was added)
docker compose exec db psql -U portal -d portal -c "CREATE DATABASE portal_test;"

cd api
php artisan migrate:fresh --seed   # creates tables, roles and demo users
php artisan queue:listen           # needed for password-reset emails (read them in Mailpit)
```
Tests run against `portal_test`, never the dev `portal` database.

**Demo users (local development only, never create these in staging or production):**
`admin@portal.test`, `staff@portal.test`, `alice@northwind.test`, `bob@northwind.test`, `carol@globex.test`, all with password `Demo-Passw0rd!`.

## Product catalog (Phase 2 on)
`migrate:fresh --seed` creates 7 categories and about 250 demo bearings (local and testing only).
```bash
cd api
# bigger demo catalog for performance checks (5,000 products)
CATALOG_SEED_COUNT=5000 php artisan db:seed --class=ProductSeeder   # PowerShell: $env:CATALOG_SEED_COUNT=5000; php artisan db:seed --class=ProductSeeder

# import products from a CSV (column spec: docs/phase-2-design.md section 6)
php artisan products:import path/to/products.csv --dry-run   # validate only, writes nothing
php artisan products:import path/to/products.csv             # upsert by part number
```
Import exit codes: `0` ok, `1` some rows were bad (the rest are imported; bad rows are listed with line numbers), `2` fatal error (missing file or header). Prices are `USD` unless `CATALOG_CURRENCY` is set.

## Checks (run before finishing any task)
```bash
cd api && ./vendor/bin/pest && ./vendor/bin/pint --test
cd web && npm run lint && npm test && npm run build
```
GitHub Actions (`.github/workflows/ci.yml`) runs the same checks on every push.

## Production note
`api/.env.example` is for local development (`APP_ENV=local`, `APP_DEBUG=true`, default `portal`/`secret` database credentials). Production must set `APP_ENV=production`, `APP_DEBUG=false` and its own secrets.
