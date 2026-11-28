# B2B Customer Portal

Portal where industrial customers browse a bearings/parts catalog, request quotes, track orders and download invoices, and staff manage everything. See `docs/PLAN.md` for the phased build plan and `CLAUDE.md` for project rules.

Layout: `api/` (Laravel 12, PHP 8.3) · `web/` (React + TypeScript + Vite) · `docs/` · `docker-compose.yml` (PostgreSQL 16, Mailpit).

## Prerequisites
Git, PHP 8.3 (extensions: openssl, mbstring, curl, fileinfo, zip, pdo_pgsql, dom, xml), Composer 2, Node 22.22+ or 24+ (CI uses 24), Docker.

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
docker compose exec db psql -U portal -d portal -c "CREATE DATABASE portal_e2e;"   # end-to-end tests (Phase 6 on)

cd api
php artisan migrate:fresh --seed   # creates tables, roles and demo users
php artisan queue:listen           # needed for password-reset emails (read them in Mailpit)
```
Tests run against `portal_test`, never the dev `portal` database.

**Demo users (local development only, never create these in staging or production):**
`admin@portal.test`, `staff@portal.test`, `alice@northwind.test`, `bob@northwind.test`, `carol@globex.test`, all with password `Demo-Passw0rd!`.
Phase 6 adds `admin2@portal.test`, `staff2@portal.test`, `dave@northwind.test` (deactivated) and `erin@initech.test` (pending invitation: use Resend in Users to get the mail), company Initech Bearings (no billing address yet) and inactive products `DEMO-INACTIVE-1` to `-3`.

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

## Cart and orders (Phase 3 on)
`migrate:fresh --seed` also creates 8 demo orders (local and testing only): Northwind (Alice, Bob) `ORD-NWAAA-AAAA2` to `AAAA6`, Globex (Carol) `ORD-GXAAA-AAAA2` to `AAAA4`. Seeded PO references: `NW-PO-1001`, `NW-PO-1002`, `GX-PO-2001`.

- Customers see only their own company's orders. Staff and admin see all orders but cannot use the cart.
- Statuses: `pending` -> `confirmed` -> `shipped` -> `delivered`; `cancelled` from pending (customer or staff) or confirmed (staff only).
- Limits: 10 orders per minute per user, at most 100 cart lines, quantity 1 to 9999. Tax and shipping are 0 for now.
- Staff change an order's status in the back office (`/admin/orders`, open the order, then the status button).

## Quotes and attachments (Phase 4 on)
`migrate:fresh --seed` also creates 8 demo quotes (local and testing only): Northwind `QUO-NWAAA-AAAA2` to `AAAA7`, Globex `QUO-GXAAA-AAAA2` and `AAAA3`. Customers see only their company's quotes; staff and admin see all and use the same `/quotes` pages to price and send.

- Limits: 50 lines per quote, 10 files per quote, 10 MiB per file, 25 MiB per quote. Allowed attachment types: PDF, PNG, JPEG, TXT, CSV (checked by file content).
- **PHP upload limits (required for files over about 2 MB).** PHP's defaults (`upload_max_filesize=2M`, `post_max_size=8M`) reject legal uploads. In your `php.ini` set `file_uploads = On`, `upload_max_filesize = 11M`, `post_max_size = 12M`, then restart `php artisan serve`. Check with `php -r "echo ini_get('upload_max_filesize'), ' ', ini_get('post_max_size');"`. (Production also needs Nginx `client_max_body_size 12m`.)
- **Emails need the queue worker:** run `php artisan queue:listen` (also used for password resets). Read the mails at http://localhost:8025.
- `QUOTES_NOTIFY_ADDRESS` in `api/.env` is the mailbox that gets "new quote" mails; if empty, all active staff and admin users do.
- Quotes expire at the end of their `valid_until` date (UTC). The API shows the expired state immediately; `php artisan schedule:work` runs `quotes:expire` every 5 minutes to store it.
- Uploaded files are stored privately under `api/storage/app/private/quotes` (never in `public/`) and are only served through the permission-checked download endpoint.

## Invoices and PDFs (Phase 5 on)
Staff create an invoice with the **Create invoice** button on a confirmed, shipped or delivered order. Customers see and download their own company's invoices as PDF. Invoice numbers (`INV-2026-000123`) are gap-free, are never reused or deleted, and a voided invoice keeps its number.

- **Placeholders, do not go live on the defaults.** The `INVOICING_*` keys in `api/.env.example` (seller name, address, tax id, bank details, footer, payment terms) and the VAT rate are placeholders, not legal advice. In production, issuing an invoice is refused until `INVOICING_SELLER_NAME` is set. Set `INVOICING_VAT_RATE_BP` in basis points (1900 = 19%); catalog prices are treated as net. Have the legal wording and numbering format checked before launch.
- Customers' billing address and tax id are stored on the company (demo companies have them; edit it in the back office under Companies). An invoice cannot be issued without a billing address.
- `php artisan schedule:work` runs `invoices:mark-overdue` hourly (and `quotes:expire` every 5 minutes). Overdue status is also computed when read, so nothing breaks if the job is not running.
- PDFs need PHP `memory_limit = 256M` for very long invoices, and a writable `api/storage/fonts`.
- Demo invoices after `migrate:fresh --seed`: `INV-2026-000001` (paid), `000002` (overdue, Globex), `000003` (stored open but past due, shows Overdue), `000004` (void), `000005` (open, re-issued). Alice and Bob see 000001, 000003, 000004 and 000005; Carol sees 000002; staff and admin see all.

## Back office and end-to-end tests (Phase 6 on)
Staff and admin use the **Back office** (`/admin`): dashboard, orders, quotes, invoices, products (create, edit, deactivate, CSV import), and companies (billing address, tax id). **Admins only** also get Users (invite by email, change role or company, deactivate) and the Audit log (who changed what; it cannot be edited).

- **Invitations:** the email link goes to `APP_URL_WEB` (`/accept-invitation`), works once and expires after 7 days. Mail is queued, so run `php artisan queue:listen` and read it in Mailpit (http://localhost:8025).
- **CSV import** in the browser is two steps: upload and preview (line-numbered errors), then confirm. Limits: 5 MiB and 5,000 rows. It needs the PHP upload settings from the quotes section (`upload_max_filesize = 11M`, `post_max_size = 12M`). Previews are kept privately under `api/storage/app/private/imports` and removed by `imports:prune` (scheduled) or after applying.
- **Deactivating a user or changing their role/company ends their session on the next request.** Sessions created before this release end once at deploy; everyone logs in again.
- **Config keys** in `api/.env.example`: `INVITATION_EXPIRE_MINUTES`, `ADMIN_IMPORT_MAX_ROWS`, `ADMIN_IMPORT_MAX_BYTES`.
- Staff can edit prices and run imports; every change is written to the audit log.

**End-to-end tests (Playwright)** live in `e2e/` and use their own database `portal_e2e` and ports 8100 (API) and 5273 (web), so they never touch your dev data:
```bash
docker compose exec db psql -U portal -d portal -c "CREATE DATABASE portal_e2e;"   # once, if missing
cd e2e
npm ci
npx playwright install chromium     # once, about 300 MB
npx playwright test                 # starts the API and web servers itself and reseeds portal_e2e
```
`E2E_CHANNEL=msedge` uses Edge instead of the downloaded Chromium. See `e2e/README.md`.

## Security, operations and quality tooling (Phase 7 on)
See `docs/SECURITY.md` for the controls and `docs/phase-7-design.md` for the full design.

- **Dependency audit:** `node ops/audit/check-audit.mjs --self-test`, and in CI (`audit.yml`, also weekly) `composer audit` and `npm audit` fail on high or critical advisories unless `.audit-allowlist.json` has a valid entry (reason, owner, at most 60 days).
- **Backups and restore drill** (`ops/backup/`, guide in `docs/ops/backup.md`): `backup.sh`, `restore.sh`, and `restore-drill.sh`, which restores into a scratch database and proves tables, row contents, triggers, indexes and files are identical and the app boots against it. Without PostgreSQL client tools on the host: `USE_DOCKER=1 PGUSER=portal PGPASSWORD=secret SOURCE_DB=portal_e2e bash ops/backup/restore-drill.sh` (on Windows use Git Bash). Record runs in `docs/ops/restore-log.md`.
- **Database roles** (`ops/db/roles.sql`, check with `ops/db/verify-roles.sh`): the runtime role cannot truncate, create triggers, alter tables or touch audit rows.
- **Load check** (`ops/loadtest/`): nginx + php-fpm with opcache + Postgres in Docker, seeded with 5,000 products and realistic order, quote, invoice and audit volumes. set a throwaway password first (`export LOADTEST_PASSWORD="$(openssl rand -hex 16)"`; the rig refuses to start without it, and the script and seed command use the same value), then `docker compose -f ops/loadtest/compose.yml up -d --build`, then `exec app php artisan migrate --force` and `exec app php artisan portal:loadtest-seed`, then `node e2e/loadtest/catalog-search.mjs` (local addresses only: it refuses anything else without `LOADTEST_ALLOW_REMOTE=1`) (gate: p95 under 500 ms with 50 virtual users and rate limits left on). Stop with `docker compose -f ops/loadtest/compose.yml down -v`. Throwaway credentials only: never expose the rig to a network.
- **Production configuration:** `docs/ops/env.production.example` and `docs/ops/nginx-spa-headers.conf` are the Phase 8 starting points; `php artisan portal:check-config` fails when unsafe settings are left in place.

## Checks (run before finishing any task)
```bash
cd api && ./vendor/bin/pest && ./vendor/bin/pint --test
cd web && npm run lint && npm test && npm run build
cd e2e && npx playwright test
```
GitHub Actions (`.github/workflows/ci.yml`) runs the same checks on every push.

## Production note
`api/.env.example` is for local development (`APP_ENV=local`, `APP_DEBUG=true`, default `portal`/`secret` database credentials). Production must set `APP_ENV=production`, `APP_DEBUG=false` and its own secrets.
