# Phase 7 results: load check and EXPLAIN review (QA work package Q3)

Scope of this file: gate item 7 (catalog-search load check, design 6.3) and the EXPLAIN review (design 6.2). Other QA items of design 10.3 are recorded elsewhere. Run date 2026-11-28, branch `phase-7-hardening`, nothing committed.

## 1. Host and rig

| Item | Value |
| --- | --- |
| Host | Windows 11 laptop, Intel Core i5-9300H (4 cores / 8 threads), Docker Desktop (WSL2 VM, 8 CPUs, 7.68 GiB visible to Docker) |
| Rig | compose project `portal-loadtest`: nginx 1.27 (127.0.0.1:8200) -> php-fpm 8.3 (`pm=static`, `max_children=16`, opcache on, `validate_timestamps=0`, config/route/event cache) -> Postgres 16 (`shared_buffers=256MB`, auto_explain loaded) |
| Container limits | app: 4 CPUs, 2 GiB (compose `deploy.resources`); db and web: no limit. The load script (Node) runs on the same host, outside Docker, so the generator and the server share the same 4 physical cores. |
| Node (script) | v26.13.1 (the script needs only `fetch` and `Headers.getSetCookie()`, available since Node 20) |
| Machine quiet? | Mostly. Two vitest processes of another agent (`vitest run src/LazyRoutes.test.tsx`, node PIDs 7160, 15808, 26220) were hung for hours with zero CPU delta over 5 s, so they were ignored. Host CPU measured 11.8 % (before the pure run) and 14.6 % (before the mixed run), the baseline being msedge/WSL (`vmmemwsl` about 40 % of one core) and the other dev containers. The checks were made with a PowerShell probe (process list for vitest/pest/playwright/artisan plus 4 CPU samples). Numbers are therefore good for a go/no-go gate but not for fine-grained comparison. |
| Limitation | Docker Desktop on a laptop is not a Linux CI runner or the production server. Absolute latencies will differ; the gate margin (168 ms against 500 ms) is what matters. |

## 2. Data generator (`php artisan portal:loadtest-seed`, default gate scale)

- Seed time: 97 s wall clock (including the slow-query warnings it prints on stderr; ANALYZE 2.1 s, audit insert 9.1 s).
- Row counts (verified with psql): companies 20, users 102 (100 customers `load001..load100@load.test`, `loadstaff@load.test`, `loadadmin@load.test`), products 5,000 (4,900 active), orders 50,000, order_items 150,000, quote_requests 10,000, invoices 20,000 (open 8,342, overdue 3,658, paid 6,000, void 2,000), audit_log 200,000. 95 indexes in schema `public`.
- Invoices belong to 14 of the 20 companies (companies 1, 6, 9, 11, 16, 19 have none). The PDF users of the mixed run are `load002..load004` (companies 2 to 4, 1,429 invoices each).

## 3. Load check

Tool: `e2e/loadtest/catalog-search.mjs` (no dependencies). Run: `node e2e/loadtest/catalog-search.mjs [--pdf-users=3] [--baseline=<results.json>]`; env `LOADTEST_BASE_URL`, `LOADTEST_USERS`, `LOADTEST_PASSWORD`, `LOADTEST_DURATION`, `LOADTEST_P95_MS`. `node e2e/loadtest/catalog-search.mjs --self-test` needs no rig and passes (statistics with known percentiles, acceptance rules including p95 equal to the threshold failing, XSRF/Origin/X-Forwarded-For login flow against an in-process stub, body validation, burst probe at request 41 and at a wrong position, PDF users).

Method as in design 6.3: 50 virtual users (`load001..load050`), real login per user with distinct `X-Forwarded-For: 203.0.113.<n>`, ramp 10 s, warm-up 30 s (discarded), measured 120 s, think time uniform 1.5 to 2.5 s (about 28 requests per user per minute, below the 40 and 60 per minute limiters), request mix 35/25/15/10/5/5/5 with the 30 fixed fragments, every 200 body validated (`data` array, integer `meta.total`; category, brand, seal type and ranges are discovered from `/categories` and `/products/filters`), then the burst probe by `load100` (60 requests in 10 s). No limiter was changed.

### 3.1 Pure run (gate)

Results file: `e2e/loadtest/results/2026-11-28T06-43-37-203Z.json` (git-ignored).

| Group | n | rps | p50 ms | p90 ms | p95 ms | p99 ms | max ms |
| --- | --- | --- | --- | --- | --- | --- | --- |
| catalog search (all `GET /products` lists) | 2,437 | 20.3 | 98 | 160 | **168** | 187 | 233 |
| all requests | 2,849 | 23.7 | 96 | 158 | 166 | 185 | 233 |
| `/products/filters` | 122 | 1.0 | 81 | 120 | 123 | 147 | 173 |
| `/categories` | 160 | 1.3 | 75 | 119 | 126 | 137 | 143 |
| `/products/{id}` | 130 | 1.1 | 76 | 130 | 138 | 149 | 163 |

Failed requests 0, unintended 429 0, burst probe: first 429 at request 41 with `Retry-After`. Exit code 0.

Gate: p95 168 ms is below 500 ms (CI relaxed value 1,000 ms): **met**, with a 3x margin. No "if it fails" procedure was needed.

### 3.2 Mixed run (3 of the 50 users download an invoice PDF every 30 s)

Which users: `load002`, `load003`, `load004` (customers whose companies own invoices). Each takes the first invoice of its own `GET /invoices?per_page=5` list and downloads `/invoices/{public_id}/pdf` every 30 s (response checked to start with `%PDF`). Results file: `e2e/loadtest/results/2026-11-28T06-46-53-772Z-mixed.json`.

| Group | n | rps | p50 ms | p90 ms | p95 ms | p99 ms | max ms |
| --- | --- | --- | --- | --- | --- | --- | --- |
| catalog search | 2,421 | 20.2 | 93 | 158 | **168** | 186 | 236 |
| all requests (excl. PDF) | 2,845 | 23.7 | 90 | 155 | 166 | 183 | 236 |
| invoice PDF | 12 | 0.1 | 265 | 484 | 495 | 495 | 495 |

Failed 0, unintended 429 0, burst probe passed. Catalog-search p95 168 ms against 168 ms of the pure run: -0.1 %, within the 20 % rule.

Decision input for A11 (dedicated FPM pool or semaphore): at this load (about 0.1 PDF per second, 12 PDFs in the window) the PDF render does not hurt catalog search, so no dedicated pool is needed on this evidence. Caveat: the PDF load is deliberately the designed one; a heavier PDF mix (for example 20 per minute per user, the limiter ceiling, with more users) was not measured. A PDF takes about 265 ms median and up to about 500 ms of one FPM worker; with 16 static workers a burst of PDFs would need on the order of 16 simultaneous renders before catalog search queues, which the limiter (20 per minute per user, 60 per minute per IP) makes unlikely but not impossible with many users.

Where the time goes: the DB part of a catalog request is 1 to 4 ms (section 4); the rest of the roughly 90 ms is PHP/Laravel (bootstrap, session lookup, Sanctum, resource building) on a container limited to 4 CPUs. The database is not the bottleneck.

## 4. EXPLAIN review at gate scale

Method: `AUTO_EXPLAIN_MS=0` with `docker compose up -d db` (volume kept), every endpoint called once to warm and once measured through nginx with a customer (`load002`), staff and admin session, DB markers around the measured call, plans read from `docker compose logs db`. Times are Postgres execution times (warm cache) per statement; "DB total" is the sum of all statements of the request (including session, user, role, limiter-cache lookups, each 0.01 to 0.05 ms).

Data note: `orders.po_reference` is NULL on every generated order (see defect D2). For the PO branches I set `po_reference = 'PO-' || lpad(id,6,'0')` on 35,000 of the 50,000 orders in the rig database (scratch change, rig is destroyed afterwards) and ran `VACUUM ANALYZE`.

| Endpoint / query | Plan node (main statements) | Time (ms) | DB total (ms) | Threshold | Verdict |
| --- | --- | --- | --- | --- | --- |
| `GET /products?q=6205` | count and page: Bitmap Index Scan `products_part_number_search_trgm` | 0.04 / 0.11 | 0.85 | trgm used for 3+ chars | PASS |
| `q=62` (2 chars) | Seq Scan on products (668 rows match) | 1.2 / 1.6 | 3.3 | under 50 ms at 5,000 | PASS (seq scan allowed for 1 to 2 chars) |
| `q=6` (1 char) | Seq Scan on products (2,242 match) | 1.1 / 2.4 | 3.8 | under 50 ms | PASS |
| `q=6205&page=5` | trgm bitmap scan, empty page | 0.04 / 0.09 | 0.6 | | PASS |
| products category + brand + seal + `sort=-price` page 3 | Bitmap Index Scan `products_brand_category_idx` | 0.10 / 0.09 | 0.7 | under 40 ms | PASS |
| products size ranges, page 5 | count: Index Only Scan `products_size_idx`; page: Index Scan `products_part_number_search_unique` + Incremental Sort | 0.38 / 0.56 | 1.3 | under 40 ms | PASS |
| products `sort=part_number` / `-brand` / category only | Index Only Scan `products_stock_status_idx` (count), `products_part_number_search_unique` or `products_brand_category_idx` (page) + Incremental Sort | 0.2 to 0.9 | 1.0 to 1.9 | under 40 ms | PASS |
| `catalog:filters` rebuild (cache cleared) | min/max: Seq Scan on products 5.3 ms; four `select distinct` Index Only Scans 1.8 to 2.2 ms each | 5.3 + 4 x 2 | 14.2 (incl. lock) | under 30 ms at 5,000 | PASS |
| `GET /categories` rebuild (7 counts) | Seq Scan categories + Index Only Scan `products_category_size_idx` per row | 1.1 | 1.4 | | PASS |
| `GET /orders` customer, default | count: Index Only Scan `orders_company_id_status_index`; page: Index Scan Backward `orders_company_id_created_at_index` + Incremental Sort | 0.48 / 0.39 | 1.2 | index on (company_id, created_at) | PASS |
| orders customer `status=shipped,delivered` | count via `orders_company_id_status_index` | 0.04 | 0.4 | | PASS |
| orders customer date range, sort `-total` | count: Index Only Scan `orders_company_id_created_at_index`; page: Bitmap Heap Scan (2,500 rows) + Sort | 0.49 / 2.3 | 3.2 | | PASS |
| orders customer `q` (PO ILIKE + order number) | Bitmap Index Scan `orders_company_id_status_index` (2,500 rows of the company), filter on 5 rows | 3.3 / 2.9 | 6.7 | A9 | PASS (bounded by the company's rows) |
| orders staff `status=pending` / `pending,confirmed` | Index Only Scan `orders_status_created_at_index` (count 2.1 / 4.2 ms); page: Index Scan Backward `orders_created_at_index` + Incremental Sort | 0.3 to 0.4 | 2.7 / 4.9 | index on (status, created_at) | PASS (the page uses `orders_created_at_index`, cheap because the status matches 20 to 40 % of the table) |
| orders staff, no filter | count: Index Only Scan (6.8 ms for 50,000 rows); page: `orders_created_at_index` | 6.8 / 0.5 | 7.7 | | PASS |
| orders staff `q` PO branch (`PO-0001`) | **Seq Scan on orders** (70 rows), count and page each | 43.2 / 30.8 | **74.5** | under 100 ms at 50,000 | PASS, but close (see 5) |
| orders staff `q` order-number branch (`ORD-AAAAA-AAB`) | **Seq Scan on orders** (256 rows) + Sort | 38.3 / 51.6 | **90.5** | under 100 ms at 50,000 | PASS, 10 % margin (see 5) |
| orders staff `company_id` + status | index | 0.02 | 0.3 | | PASS |
| `GET /quotes` customer / staff (default, status, `expiring_within_days`) | `quote_requests_company_id_created_at_index`, `quote_requests_created_at_index`, `quote_requests_status_created_at_index`, partial `quote_requests_quoted_valid_until_index` (BitmapAnd with the company index for the customer); counts are Index Only Scans | 0.1 to 1.4 | 0.4 to 2.2 | all four indexes used | PASS |
| `GET /invoices` customer default | count: Index Only Scan `invoices_company_id_status_index`; page: `invoices_issue_date_index` backward | 0.22 / 0.48 | 1.0 | | PASS |
| invoices customer `status=open,overdue&sort=due_date` | Bitmap Heap Scan via `invoices_company_id_status_index` (858 of 1,429 rows) + Sort | 3.6 / 2.2 | 6.3 | `(status, due_date)` used | PASS on time; the effective-status predicate (open and due date passed counts as overdue) makes the planner use the company/status index rather than `(status, due_date)` |
| invoices staff `status=open,overdue&sort=due_date` | count: Index Only Scan `invoices_status_due_date_index` (5.0 ms); **page: Seq Scan on invoices (12,000 rows) + Sort** | 5.0 / 13.6 | 19.1 | `(status, due_date)` used, no seq scan on 20,000 rows | PASS on time, not on the stated expectation (see 5) |
| invoices `due_within_days` (customer, staff) | BitmapAnd `invoices_status_due_date_index` + company index | 0.1 to 1.0 | 1.3 | | PASS |
| `GET /admin/dashboard` | 3 aggregates: Seq Scan quote_requests 4.0 ms, Bitmap on orders (`orders_status_created_at_index` + `orders_created_at_index`) 6.3 ms, Seq Scan invoices 11.0 ms; inactive products Index Only Scan 0.03 ms | 4.0 / 6.3 / 11.0 | 21.8 | each under 100 ms | PASS |
| `GET /admin/companies` (20 rows, 4 count subqueries) | per-company Index Only Scans on `orders_company_id_status_index`, `invoices_company_id_status_index`, Bitmap on quote_requests | 12.4 | 12.7 | under 100 ms | PASS |
| `GET /admin/products?q=6205` | not inspected node by node (count 0.8 ms, page 0.7 ms) | 0.8 / 0.7 | 1.8 | | PASS |
| `GET /admin/users` with and without `q` (102 users) | Seq Scan / Hash Join, tiny table | 0.15 / 0.2 | 0.6 to 0.8 | | PASS |
| `GET /admin/audit-log` default | count: Parallel Index Only Scan `audit_log_pkey` 24.8 ms over 200,000 rows; page: `audit_log_occurred_idx` + Incremental Sort 0.1 ms | 24.8 / 0.12 | 25.5 | uses `audit_log_occurred_idx`; count under 150 ms | PASS (the page query uses the index; the count is a parallel index-only scan of the primary key) |
| audit page 50 (offset 1,225) | count 16.5 ms; page `audit_log_occurred_idx` 1.4 ms | 16.5 / 1.4 | 18.3 | | PASS |
| audit by actor | count: **Parallel Seq Scan audit_log** (49,000 matching rows, 25.5 ms); page: `audit_log_actor_idx` 0.13 ms | 25.5 / 0.13 | 26.0 | | PASS (a 25 % match; seq scan is the right choice) |
| audit by subject / company / action / date range | `audit_log_subject_idx` 0.05 ms, `audit_log_company_idx` 1.3 ms, `audit_log_action_idx` 4.1 ms, `audit_log_occurred_idx` 3.2 ms (all Index Only Scans for the count; page via the same index) | 0.05 to 4.1 | 0.45 to 4.7 | | PASS |
| order, quote and invoice detail (header, items, history) | primary key / unique / FK index lookups | 0.01 to 0.1 each | 0.4 to 0.6 | under 10 ms | PASS |
| `sessions` GC delete (`last_activity <= now - 2 h`) | 111 live rows: Seq Scan (correct for a tiny table). With 50,000 synthetic sessions of which 1 % are expired (rolled-back transaction): Bitmap Index Scan `sessions_last_activity_index`, 2.3 ms | 0.25 / 2.3 | | no seq scan on sessions | PASS |
| limiter increments and cache reads (`cache` table) | Index Scan `cache_pkey` (select, `for update`, update) | 0.02 to 0.05 each | | under 5 ms | PASS |
| `cache` GC (`delete where expiration <= now`) | **Seq Scan on cache** (no index on `expiration`); 12 ms at 50,000 synthetic rows | 12 | | none stated | Observation only (see 5) |
| `jobs` poll | empty table, Seq Scan + Sort, 0.1 ms | 0.1 | | | not measurable on an empty table |

Everything the design lists passes its threshold at gate scale.

### 4.1 Growth probe (50,000 products, 500,000 orders)

`portal:loadtest-seed --products=50000 --orders=500000` did not finish: the `order_items` insert was still running after 19 minutes and was cancelled (the seed runs in one transaction, so the gate-scale data stayed intact), see defect D1 (since FIXED: the same seed now takes about 2 m 42 s; the growth numbers below were not re-measured on the seeded data). Instead the rig database was grown by SQL (scratch only): 45,000 products copied with a `-G<k>` suffix (50,000 total) and 450,000 order rows copied without items or history (500,000 total, 350,000 with a PO). Quotes, invoices and audit stayed at gate size. This covers the product and order list queries but not order detail.

| Query | Plan | Time (ms) | Threshold | Verdict |
| --- | --- | --- | --- | --- |
| products `q=6205` count / page | trgm Bitmap Index Scan | 0.07 / 0.12 | | PASS |
| products `q=62` count / page | Seq Scan on products (6,680 match) | 10.4 / 18.2 | under 150 ms at 50,000 | PASS |
| products `q=6` count / page | Seq Scan on products (25,078 match) | 15.5 / 26.9 | under 150 ms | PASS |
| products category + brand + seal, sort price | Bitmap Index Scan `products_brand_category_idx` | 0.2 / 0.4 | | PASS |
| products size ranges | Index Only Scan `products_size_idx` | 3.5 | | PASS |
| `catalog:filters` min/max (Seq Scan on 49,000 rows) / `distinct brand` | 21.1 / 6.5 | rebuild total about 50 ms | if above 100 ms the single-flight is justified | below 100 ms: not decisive. The single-flight still protects against a stampede at TTL expiry. |
| orders staff `q` PO count / page | Parallel Seq Scan on orders, page via `orders_created_at_index` backward with a filter removing 166,434 rows | 139 / 131 | under 100 ms at 50,000 orders | **FAIL at 500,000** (total about 270 ms), expected |
| orders staff `q` order number count | Parallel Seq Scan | 129 (page about the same) | | **FAIL at 500,000** (total about 260 ms) |
| orders staff `status` count / page | Parallel Index Only Scan `orders_status_created_at_index` / `orders_created_at_index` | 21.5 / 0.09 | | PASS |
| orders staff unfiltered count | Parallel Index Only Scan (on `orders_quote_request_id_unique`) | 26.8 | | PASS |
| orders customer default / filtered by statuses with no match | `orders_company_id_created_at_index` 0.06 ms / the same index with a filter removing 25,000 rows | 0.06 / 13.5 | | PASS |
| orders customer `q` | Bitmap Index Scan `orders_company_id_status_index` (25,000 rows of the company) + heap filter | 24.7 | | PASS (bounded by the company's rows; grows linearly) |

Restoring gate scale after the probe was not done: the rig was destroyed right afterwards.

## 5. Index proposals (only where a plan shows a problem)

None are needed at gate scale (rule: no index just in case). Conditional items, for the architect:

1. MONITORED, no index now. Staff order search `q` (candidate 1, A9). At gate scale 74 ms and 90 ms (limit 100) leave a small margin; at 10x orders both branches reach about 260 to 270 ms. Important: the design's candidate (`gin (po_reference gin_trgm_ops) WHERE po_reference IS NOT NULL`) would not remove the Seq Scan on its own, because the filter is `replace(order_number, '-', '') LIKE ... OR po_reference ILIKE ...`; Postgres can use a BitmapOr only when both branches are indexable. A working fix needs a trigram index on the expression `replace(order_number, '-', '')` as well as on `po_reference` (or an application change such as searching the order number by prefix on `orders_order_number_unique`). I could not test this: creating a trial index (even inside a rolled-back transaction) was refused by the permission system, so the effect is untested. Decide with a measurement on a rig where the architect permits it, only if the 100 ms limit at 50,000 orders is considered binding or orders are expected to grow far beyond 50,000.
2. Customer status-filtered lists (candidate 2: `(company_id, status, created_at DESC)` etc.): all measured plans are under 15 ms at both scales (the customer order list with a non-matching status takes 13.5 ms at 500,000 orders). Not justified; no index.
3. Staff invoice list `status=open,overdue&sort=due_date` page query: Seq Scan on 12,000 of 20,000 rows plus sort, 13.6 ms (count uses `invoices_status_due_date_index`). Under the 100 ms rule, so no index; it is the plan the design said should not happen, so watch it if invoices grow tenfold (about 130 ms expected). If it becomes a problem, the cause is the effective-status OR predicate, not a missing index.
4. MONITOR. `cache.expiration` has no index, so the lottery GC (`delete from cache where expiration <= ?`) scans the table: 12 ms at 50,000 rows. The limiter keys are few; no action unless the cache table grows large.

## 6. Unused indexes (`pg_stat_user_indexes`, `idx_scan = 0`)

Statistics were reset (`pg_stat_reset()`) right before the pure run and read after the pure run, the mixed run and the full endpoint sweep of section 4 (customer, staff and admin reads; no write endpoints). Indexes with 0 scans after all of that, primary keys excluded:

| Table | Index | Size | Comment |
| --- | --- | --- | --- |
| products | `products_price_idx` | 176 kB | not used even though 25 % of the load mix sorts by `-price`: the planner prefers `products_brand_category_idx` and sorts the small result. Drop candidate for the user; default keep (small). |
| products | `products_updated_at_idx` | 48 kB | no read endpoint filters on it. Drop candidate; default keep. |
| invoices | `invoices_number_unique`, `invoices_number_year_seq_unique` | 632 kB, 456 kB | unique constraints (numbering integrity): keep |
| invoices | `invoices_order_id_index` | 600 kB | FK index; `invoices_one_active_per_order` serves the lookups: keep for FK deletes/joins, droppable only if that partial unique index fully covers FK checks |
| users | `users_company_id_index`, `users_email_unique` | 16 kB | 102-row table (seq scan is correct); keep |
| sessions | `sessions_user_id_index` | 672 kB | used by logout-all or user-deactivation flows, not read endpoints: keep |
| cart_items, categories, failed_jobs, jobs, model_has_*, permissions, roles, product_imports (3), quote_attachments (3) | various | 8 to 16 kB | tiny tables or constraints, write-path or admin features not exercised by the reads: keep |

Decision (architect): `products_price_idx` and `products_updated_at_idx` are the two unused-index candidates; both are KEEP (176 kB and 48 kB: the saving is negligible, and the statistics come from read traffic on generated data only; re-read `pg_stat_user_indexes` on production data before dropping anything). `cache.expiration` has no index: MONITOR (12 ms at 50,000 rows, no action unless the `cache` table grows large).

The Phase 2 low-cardinality product indexes the design asked about are not at 0: `products_material_idx` 3 scans, `products_seal_type_idx` 3, `products_stock_status_idx` 9 (used as covering Index Only Scans for the filter-option `distinct` queries and the active count). Default keep.

## 7. Defects and findings (no feature code was changed by QA)

| Id | Finding | Evidence | Owner |
| --- | --- | --- | --- |
| D1 (FIXED) | FIXED after this run (the `order_items` and `quote_items` joins no longer nest-loop; `--products=50000 --orders=500000` now seeds in about 2 m 42 s). Original finding: the load generator does not scale to 10x products: with `--products=50000 --orders=500000` the `INSERT INTO order_items ... CROSS JOIN generate_series(0,2) JOIN (select ... row_number() ... count(*) over () AS total ...) p ON p.rn = 1 + (o.id*3 + k) % p.total` was a nested loop (join condition uses `p.total`, so no hash or merge join) and ran for 19 minutes without finishing, versus about 97 s for the whole default seed. Fix: compute the product count once (scalar subquery or `WITH n AS (select count(*) ...)`) and join on `rn`, or use a temporary table with an index on `rn`. | `SeedLoadTestData.php` lines 154 to 158; seed cancelled with `pg_cancel_backend` after 19 min 12 s, transaction rolled back | backend-engineer |
| D2 (FIXED) | FIXED after this run: about 70% of generated orders now get a valid PO reference (four realistic shapes). Original finding: the generator leaves `orders.po_reference` NULL on every order, so the PO branch of order search cannot be reviewed on generated data (and the planner statistics for it are meaningless). Populate about 70 % of orders with realistic PO strings. | `select count(*) from orders where po_reference is not null` returns 0 right after the seed | backend-engineer |
| D3 (FIXED) | FIXED: `docs/phase-7-design.md` 6.1 no longer says quotes, invoices and audit rows are "not built yet"; it describes what the generator builds (defaults 10,000 quotes / 20,000 invoices / 200,000 audit rows, `--scale` multiplies, admin user `loadadmin@load.test`, quotes have no attachments by design, invoices only for invoiceable orders). | seed output | architect (docs) |
| F1 (MONITORED) | Staff order `q` search is a Seq Scan (74 to 90 ms at 50,000 orders against the 100 ms budget, about 260 to 270 ms at 500,000). Within the gate threshold at gate scale, so NO index is added now. The design's candidate 1 alone will not remove the seq scan: both branches of the filter (order number with dashes stripped, and PO `ILIKE`) must be indexable for a BitmapOr. Revisit when real order volume approaches 50,000 or the staff search p95 exceeds 100 ms (see section 5, item 1). | section 4 and 4.1 | architect decides, backend implements when triggered |
| F2 | Staff invoice list of open/overdue invoices: page query is a Seq Scan with a sort (13.6 ms at 20,000). Watch item. | section 4 | architect |
| F3 | Catalog-search latency is dominated by PHP, not Postgres (DB total 1 to 4 ms of a median of about 95 ms), so the p95 headroom depends on CPU for PHP (limit 4 CPUs here). If production serves with fewer cores, rerun the load check there. | sections 3 and 4 | main session (Phase 8 sizing) |

## 8. Caveats

- The machine was not perfectly quiet (see section 1): background load was 12 to 15 % CPU, and the load generator shared the host with the rig. This biases latency upwards, not downwards.
- One pure and one mixed run only (the pure run passed, so no rerun was required). Run-to-run variance was not measured; the two runs agree to the millisecond at p95 and within 5 ms at p50.
- PDF load in the mixed variant is the designed light one (12 PDFs in 120 s).
- EXPLAIN capture used auto_explain on top-level statements only; timings include auto_explain's own overhead (ANALYZE timing per node), which inflates short statements by a few microseconds. Execution times above 5 ms are reliable; sub-millisecond values are indicative.
- Growth probe was built by SQL copy (no order items, history or realistic distribution of statuses by company), so the customer-side order numbers at 500,000 rows are indicative only.
- The unused-index list comes from read traffic only (no writes, imports, uploads, invoices creation or cart usage), which is why write-path and admin indexes show 0.
- `LOADTEST_PASSWORD` was a throwaway value for the run (the rig now requires one and the script refuses remote targets); no real data was used.
