# Phase 5 design - Invoices and PDF download

HTTP contract: `docs/api-contract.md` (Phase 5 section). This file holds decisions, schema, numbering, VAT rounding, state machine, PDF design, seeders, frontend design, test plan and open questions. Conventions come from Phases 1 to 4 (Sanctum cookie auth, role middleware before limiters and lookups, `denyAsNotFound()` policies plus `visibleTo` scopes, exact 404 body `{"message":"Not found."}`, integer cents with the 10^9 per-unit cap and JS-safe totals, `TrimStrings`/NUL/control-character rules, status-machine service with a history table, effective status at read time plus a scheduled command that only materialises it, private storage, database queue + scheduler, Postgres 16 `portal_test`, Linux CI hygiene).

Follows the Order/Quote pattern on purpose: `Invoice` + `InvoicePolicy` + `InvoiceWorkflow` + `invoice_status_history`. What is NEW in this phase: a gap-free counter, a snapshot of seller/buyer data, VAT arithmetic, a PDF renderer (new dependency), and DB triggers that make issued invoices immutable.

IMPORTANT: no VAT rate, seller detail, legal wording or retention rule in this phase is legally verified. Every such value is a configurable placeholder (section 2) and an open question (section 15). Do not go live on the defaults.

## 1. Decisions

| # | Topic | Decision | Why |
| --- | --- | --- | --- |
| 1 | Creation | Staff/admin create an invoice manually from ONE order: `POST /orders/{number}/invoice`. No automatic creation, no `POST /invoices`. | The order is the identity of the relationship (one active invoice per order), the order number is already a public random id with a route pattern, and the endpoint needs no body beyond an optional `due_date`. A `POST /invoices` with `order_number` would only add a second way to say the same thing |
| 2 | Eligible orders | `confirmed`, `shipped`, `delivered` (`config('invoicing.eligible_order_statuses')`). `pending` and `cancelled` are refused (422 `order`). | Pending is not yet accepted by staff; cancelled never gets an invoice. Adjustable in config (open question 5) |
| 3 | One invoice per order | At most ONE NON-VOID invoice per order: partial UNIQUE index `invoices_one_active_per_order` (`UNIQUE (order_id) WHERE status <> 'void'`) plus a row lock on the order inside the creating transaction. A voided invoice does not block a new one. | The requirement says "exactly one invoice per order, UNIQUE". A plain UNIQUE(order_id) would make "void and re-issue" impossible, which is the only mistake-handling path without credit notes (decision 9). A partial unique index is still a DB-level guarantee |
| 4 | Numbers | `INV-YYYY-NNNNNN` (`INV-2026-000123`), counter per year, resets on 1 January, year = year of the issue date in UTC. Allocated from the counter table `invoice_number_counters` by a one-statement upsert inside the same transaction that inserts the invoice. Section 4. | Postgres sequences burn values on rollback; a counter row changed in the same transaction is rolled back with it, so no gap can appear |
| 5 | Public id in URLs | The URL identifier is a separate random `public_id` (UUID v4, JSON key `id`), NOT the invoice number. The sequential number is display and search only. | Numbers are sequential and therefore guessable and leak volume. Authorization (visibleTo + policy, 404 for foreign) is still the only real protection and the tests prove it; the random id makes enumeration impractical and is defence in depth. Cost: URLs are not human-readable; staff and customers find invoices by number through `q` |
| 6 | VAT | One standard rate per invoice, basis points (`invoicing.vat_rate_bp`, default 0 = PLACEHOLDER). Rate and tax amount are snapshotted. Tax is rounded ONCE on the invoice subtotal, half up, integer arithmetic only. Section 5. | Smallest model that is exactly reproducible by hand; no float anywhere. Per-line rounding is rejected (drifts from the invoice total and needs per-line tax storage) |
| 7 | Order prices are NET | Order and quote prices (`unit_price_cents`, order subtotal) are treated as net (excl. VAT). `invoice.total = order.subtotal + tax`. Orders have `tax_cents = shipping_cents = 0` (Phases 3 and 4), so invoice tax is computed fresh from the order's snapshot lines, never copied from the order and never from live prices. | The portal never computed VAT before; the invoice is where it appears. Visible consequence (open question 2): with a rate above 0 the invoice total is higher than the order total |
| 8 | Seller and buyer | Seller details come from `config/invoicing.php` (env), buyer details from the `companies` row, both SNAPSHOTTED as two `jsonb` columns on the invoice. Section 3.2. | Old invoices must never change when config or the company is edited. jsonb because these blocks are never filtered or sorted, are shown as a whole, and may gain keys (one column per field would be 15 columns of text) |
| 9 | Mistakes | Status `void` (staff/admin, reason required, only while `open`/`overdue`). The number stays consumed and visible as VOID, so numbering stays gap-free; the order can be invoiced again. NO credit notes, NO partial payments. A PAID invoice cannot be voided and mark-paid cannot be undone in v1: it is corrected outside the portal (open question 8). | Deleting would create a gap. Credit-note flow is explicitly out of scope |
| 10 | Overdue | `overdue` is an EFFECTIVE status at read time (stored `open` and `due_date < today UTC`) AND is stored by the scheduled command `invoices:mark-overdue` with a system history row. Due today is NOT overdue. | Same pattern as quote expiry (Phase 4 3c): correct even if the scheduler never ran, no write on GET |
| 11 | PDF | Rendered on the fly from the immutable snapshot with `barryvdh/laravel-dompdf` (NEW dependency, needs approval). Not stored. Section 9. | Deterministic from the snapshot, no files to back up or orphan, no stale copy. Legal archiving rules may require storing the issued document (open question 10) |
| 12 | Immutability | DB triggers: no DELETE on `invoices`, `invoice_items`, `invoice_status_history`; no UPDATE of immutable columns. Section 3.6. | "Gap-free" and "never changes" are legal promises; the database is the last line of defence against a future bug or a manual `DELETE` |
| 13 | Language, currency | English only. Currency = `orders.currency` (itself snapshotted from `config('catalog.currency')` at order creation), copied to the invoice. | Single currency in v1 |
| 14 | Dates | `issue_date` is always today (UTC), read inside the transaction after the counter lock; never client-supplied. `due_date = issue_date + payment_terms_days` (default 30), optional staff override within bounds. All invoice dates are plain `date` columns (`YYYY-MM-DD`), not timestamps. | A fixed server-side issue date keeps dates non-decreasing in number order; a date has no per-viewer timezone ambiguity |
| 15 | Staff UI | MINIMAL staff UI inside the same pages (create button on order detail, mark paid / void on invoice detail, Company column and filter in the list). Full back office is Phase 6. | Same reasoning as Phase 4 decision 2 |

## 2. Dependency, config, env, CI, PHP

### 2.1 New dependency (needs the user's approval, CLAUDE.md "ask before adding a dependency")

`composer require barryvdh/laravel-dompdf` (v3 line, which supports Laravel 12; composer picks the exact version, the backend agent records it and confirms Laravel 12 support in `composer show`). Why: the project's approved stack names "barryvdh/laravel-dompdf" and PLAN.md says "PDF: dompdf"; generating a PDF by hand is not realistic. Alternatives rejected: wkhtmltopdf and headless Chrome (need a binary on every environment and in CI), TCPDF/FPDF (no HTML/CSS, more code), a SaaS PDF API (sends customer data to a third party).

What it pulls in (runtime only; no dev deps). INSTALLED versions as implemented (verified by the backend agent with `composer show`; Laravel 12 is supported by the wrapper v3.1.2):
- `barryvdh/laravel-dompdf` v3.1.2 (the Laravel wrapper, facade and config)
- `dompdf/dompdf` v3.1.6 (HTML to PDF renderer, pure PHP)
- `dompdf/php-font-lib` 1.0.2 (reads TrueType fonts, subsetting)
- `dompdf/php-svg-lib` 1.0.2 (SVG support; we use no SVG, but it is a hard dependency)
- `masterminds/html5` 2.12.0 (HTML5 parser, needs `ext-dom`)
- `sabberworm/php-css-parser` v9.5.0 (CSS parser)

PHP extensions: `ext-dom` and `ext-mbstring` are required (composer refuses to install without them). `ext-iconv` is a transitive platform requirement of `sabberworm/php-css-parser`; it is NOT listed in the project's `composer.json` (composer resolves it from the dependency), and the CI `setup-php` extensions line now includes `iconv` explicitly so a runner build without it cannot surprise us. `ext-gd` is NOT needed because the template contains no images (no logo in v1; a local-file logo would need gd for PNG with alpha). `ext-zlib` is only for compression and is present everywhere. Licences (confirmed with `composer licenses`): the six packages are licensed LGPL-2.1, LGPL-3.0 and MIT (no other licence; per-package mapping: see `composer licenses`). LGPL is acceptable for use as an unmodified library dependency; a legal review of that is not part of this phase.

Availability to check (the architect cannot run PHP; this is a verify list, not a claim):
- User's Windows PHP 8.3 (WinGet): `dom`, `libxml`, `xml`, `xmlwriter` are compiled into the official Windows build (no `php.ini` line). Pest/PHPUnit already require `ext-dom`, `ext-libxml`, `ext-mbstring`, `ext-xml`, so the existing test run proves them. Verify with `php -m` that `dom` and `mbstring` are listed. `gd` is a DLL (`extension=gd` in `php.ini`) and is optional here. `php.ini`: set `memory_limit = 256M` (the PDF route needs it, section 9.7).
- GitHub `shivammathur/setup-php` on `ubuntu-latest`: its default build includes `dom`, `mbstring`, `fileinfo`, `libxml`, `xml`. Make it explicit and robust by changing the workflow line to `extensions: pdo_pgsql, mbstring, dom, fileinfo, iconv` (as implemented, `iconv` included, see below; one-line change in `.github/workflows/ci.yml`, outside the agents' scope: main session). No `gd`, no system fonts, no apt packages.
- Clean `php:8.3-cli` container (QA check 13.3): the official image has `dom`, `mbstring`, `xml`, `libxml`, `fileinfo` built in; it lacks `pdo_pgsql` (install with `docker-php-ext-install pdo_pgsql`), which every test needs anyway. The PDF tests must pass there with no other change.

### 2.2 Config and env

New `api/config/invoicing.php` (all env values are read ONLY here; tests use `config([...])`):

| Key | Env | Default | Notes |
| --- | --- | --- | --- |
| `vat_rate_bp` | `INVOICING_VAT_RATE_BP` | `0` | PLACEHOLDER. Integer 0 to 5000 (50.00 %). Out of range or non-integer: issuing refuses (422 `invoice`), never silently clamps |
| `vat_label` | `INVOICING_VAT_LABEL` | `VAT` | Label printed on the PDF ("VAT 19%") |
| `payment_terms_days` | `INVOICING_PAYMENT_TERMS_DAYS` | `30` | Integer 0 to 120 |
| `max_due_days` | (config only) | `120` | Upper bound for the staff `due_date` override, days after the issue date |
| `eligible_order_statuses` | (config only) | `['confirmed','shipped','delivered']` | |
| `seller.name` | `INVOICING_SELLER_NAME` | `PLACEHOLDER SELLER NAME` | Required |
| `seller.address_lines` | `INVOICING_SELLER_ADDRESS` | `PLACEHOLDER Street 1\|00000 PLACEHOLDER City\|PLACEHOLDER Country` | Lines separated by `\|`, max 6 lines of max 80 characters |
| `seller.tax_id` | `INVOICING_SELLER_TAX_ID` | `PLACEHOLDER-VAT-ID` | |
| `seller.email`, `seller.phone` | `INVOICING_SELLER_EMAIL`, `INVOICING_SELLER_PHONE` | `billing@example.invalid`, `+00 000 000000` | |
| `seller.bank.account_holder`, `.bank_name`, `.iban`, `.bic` | `INVOICING_BANK_HOLDER`, `INVOICING_BANK_NAME`, `INVOICING_BANK_IBAN`, `INVOICING_BANK_BIC` | `PLACEHOLDER`, `PLACEHOLDER BANK`, `XX00 PLACEHOLDER IBAN`, `PLACEHOLDER` | Deliberately not a valid-looking IBAN |
| `seller.payment_terms_text` | `INVOICING_PAYMENT_TERMS_TEXT` | `Please pay by bank transfer and quote the invoice number. (PLACEHOLDER TEXT)` | No day count in the text: the due date is printed separately and may be overridden |
| `seller.footer` | `INVOICING_FOOTER` | `PLACEHOLDER legal footer: registered office, registration number, managing directors.` | One line of text, max 300 characters |

`.env.example` gets all `INVOICING_*` keys with the placeholder values and a comment "PLACEHOLDERS, set real values before production". Values with spaces are quoted.

Seller guard (as implemented after the security review; replaces the earlier "production and seller name only" guard): `IssueInvoice` refuses (422 `invoice`, message "Invoicing is not configured.") in EVERY environment except `local` and `testing` (so staging, production and any custom environment name are covered; the environment is not compared with `isProduction()`). Inside the issuing transaction, BEFORE the number is allocated (nothing written, no number burned), it refuses when:
1. any of seller `name`, each `address_lines` entry, `tax_id`, `email`, `bank.iban`, `bank.bic`, `bank.account_holder`, `bank.bank_name`, `footer`, `payment_terms_text` or `phone` contains `PLACEHOLDER` (case-insensitive, after trimming);
2. `email` ends in `.invalid` (case-insensitive; the default `billing@example.invalid`);
3. a required field is empty after trimming: `name`, `tax_id`, `email`, `footer`, `payment_terms_text`, `bank.iban`, `bank.bic`, `bank.account_holder`, and at least one non-blank address line. `phone` and `bank.bank_name` are optional (but still checked for the placeholder text when set).

A VAT rate of 0 is ALLOWED in those environments (it can be legitimate: exempt supplies, reverse charge) but every issue there logs one warning so a forgotten placeholder rate is visible in the logs. The basic checks (`vat_rate_bp` integer 0 to 5000, `payment_terms_days` integer 0 to 120, seller name present, address line count and length, footer size) apply in EVERY environment, including `local` and `testing`. In `local` and `testing` the placeholders are allowed so the demo and the tests work. This is a Phase 8 checklist item too (section 16).

Other config: `config/dompdf.php` (published, section 9.2). No new queue or mail.

### 2.3 Scheduler and limits

- `routes/console.php`: `Schedule::command('invoices:mark-overdue')->hourly()->withoutOverlapping()`. Correctness never depends on it (effective status). Local: `php artisan schedule:work` (README line). Production cron: Phase 8.
- Limiters in `AppServiceProvider`: `invoice-pdf` = 20 per minute per user id (`'invoice-pdf:'.$user->id`); `invoice-pdf-ip` = 60 per minute per client IP (`'invoice-pdf-ip:'.ClientIp::key($request->ip())`: IPv4 as is, IPv4-mapped IPv6 as IPv4, other IPv6 collapsed to its /64), added after the security review so many accounts behind one address cannot multiply the CPU-heavy rendering budget; `invoices-write` = 30 per minute per user id. The PDF route runs `api`, `invoice-pdf` and `invoice-pdf-ip`, all AFTER the role middleware (403 before 429).
- `api/storage/fonts` must exist and be writable (dompdf writes its font cache there); add it with a `.gitignore` that ignores everything but itself. `sys_get_temp_dir()` is the dompdf temp dir.

## 3. Database

Migrations (in this order, after `2026_11_28_100005`): `2026_11_29_100000_add_billing_to_companies`, `_100001_create_invoice_number_counters_table`, `_100002_create_invoices_table`, `_100003_create_invoice_items_table`, `_100004_create_invoice_status_history_table`, `_100005_add_invoice_immutability_triggers`. Conventions as before: `bigint` money, named CHECK constraints via `DB::statement`, RESTRICT foreign keys, no soft deletes.

### 3.1 `companies` (amendment)

`companies` has only `id`, `name`, timestamps. Added: `billing_address text NULL` (newline-separated lines, at most 6 lines), `tax_id varchar(32) NULL`. CHECKs: `companies_billing_address_length` (`char_length(billing_address) <= 500`), `companies_tax_id_length` (`char_length(tax_id) <= 32`). Not mass-assignable and not editable through any API in Phase 5: set by seeders (`DemoSeeder` fills Northwind and Globex with obviously fake addresses and tax ids) and by tinker/SQL until the Phase 6 company admin UI exists. `UserResource.company` is unchanged (address and tax id are never exposed there). `CompanyFactory` gets a fake address and tax id by default.

Issuing requires a non-empty `billing_address` (422 `company`); `tax_id` is optional. Buyer snapshot = `{name, address_lines[], tax_id}`.

### 3.2 `invoices`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint PK | Internal only, never in a URL or body |
| `public_id` | uuid NOT NULL | UUID v4 from `Str::uuid()` (lowercase), JSON key `id`, the only URL identifier |
| `number` | varchar(20) NOT NULL | `INV-2026-000123`; UNIQUE `invoices_number_unique` |
| `number_year` | smallint NOT NULL | |
| `number_seq` | integer NOT NULL | |
| `company_id` | FK companies RESTRICT NOT NULL | Copied from the order, server-side |
| `order_id` | FK orders RESTRICT NOT NULL | |
| `order_number` | varchar(16) NOT NULL | Snapshot of `orders.order_number` (list display and search without a join) |
| `created_by_user_id` | FK users RESTRICT NOT NULL | The staff user |
| `status` | varchar(16) NOT NULL default `open` | `open`, `overdue`, `paid`, `void` (STORED status; the API returns the effective one) |
| `issue_date` | date NOT NULL | |
| `due_date` | date NOT NULL | |
| `paid_on` | date NULL | Payment date (bank date) entered by staff. Named `paid_on`, not `paid_at`, because it is a date, and every `_at` in this API is a UTC timestamp |
| `currency` | char(3) NOT NULL | |
| `po_reference` | varchar(64) NULL | Snapshot of the order's PO reference |
| `vat_rate_bp` | integer NOT NULL | Snapshot |
| `subtotal_cents`, `tax_cents`, `total_cents` | bigint NOT NULL | |
| `seller` | jsonb NOT NULL | Snapshot of `config('invoicing.seller')` plus `vat_label`: keys `name`, `address_lines` (array), `tax_id`, `email`, `phone`, `bank` {`account_holder`, `bank_name`, `iban`, `bic`}, `payment_terms_text`, `footer`, `vat_label` |
| `buyer` | jsonb NOT NULL | `{name, address_lines, tax_id}` |
| `created_at`, `updated_at` | timestamp NOT NULL | |

CHECK constraints:
- `invoices_number_format`: `number ~ '^INV-[0-9]{4}-[0-9]{6,9}$'`
- `invoices_number_parts` (CORRECTED as implemented): `number = 'INV-' || number_year::text || '-' || CASE WHEN number_seq < 1000000 THEN lpad(number_seq::text, 6, '0') ELSE number_seq::text END`. The original form `lpad(number_seq::text, 6, '0')` is wrong: `lpad` TRUNCATES when the input is longer than the target length, so a 7-digit sequence (1,000,000 and above) would be cut to 6 characters and the CHECK would reject (or worse, wrongly accept) the real number. The CASE pads below 1,000,000 and leaves 7 to 9 digit sequences untouched. A test sets the counter to 999,999 and issues two invoices (`INV-2026-999999`, `INV-2026-1000000`).
- `invoices_number_year_matches_issue_date`: `number_year = EXTRACT(YEAR FROM issue_date)`
- `invoices_number_seq_positive`: `number_seq > 0`
- `invoices_status_check`: status in the four values
- `invoices_due_after_issue`: `due_date >= issue_date`
- `invoices_paid_consistent`: `(status = 'paid') = (paid_on IS NOT NULL)`; `invoices_paid_after_issue`: `paid_on IS NULL OR paid_on >= issue_date`
- `invoices_currency_check`: `currency ~ '^[A-Z]{3}$'`; `invoices_po_reference_length`: `char_length(po_reference) <= 64` (see "Column length versus CHECK" below: the `varchar(64)` type rejects a longer value first)
- `invoices_vat_rate_range`: `vat_rate_bp BETWEEN 0 AND 5000`
- `invoices_subtotal_nonnegative`, `invoices_tax_nonnegative`, `invoices_total_nonnegative`
- `invoices_subtotal_max`: `subtotal_cents <= 999900000000000` (100 lines x 9999 x 10^9, the largest Phase 3 cart; it also keeps `subtotal * rate` inside bigint)
- `invoices_tax_consistent`: `tax_cents = (subtotal_cents * vat_rate_bp + 5000) / 10000` (bigint integer division truncates; for non-negative values this is exactly "round half up", so the DB enforces the rounding rule of section 5)
- `invoices_total_consistent`: `total_cents = subtotal_cents + tax_cents`
- `invoices_total_js_safe`: `total_cents <= 9007199254740991`
- `invoices_seller_object`, `invoices_buyer_object`: `jsonb_typeof(...) = 'object'`

Indexes: UNIQUE `invoices_public_id_unique`; UNIQUE `invoices_number_unique`; UNIQUE `invoices_number_year_seq_unique (number_year, number_seq)`; UNIQUE partial `invoices_one_active_per_order` on `(order_id) WHERE status <> 'void'`; plain `(order_id)` (FK lookups, order detail); `(company_id, issue_date)` (customer list default sort); `(company_id, status)`; `(status, due_date)` (overdue command and effective-status filters); `(issue_date)` (staff list); sorting by `total` and `number` is served by the scoped set (acceptable at the expected volume).

Column length versus CHECK (applies to `invoices.po_reference` varchar(64), `companies.tax_id` varchar(32), `invoice_items.name` varchar(255), and the other varchar columns): Postgres checks the column TYPE length before any CHECK constraint runs, so a too-long value fails with SQLSTATE `22001` "value too long for type character varying(N)", never with the CHECK's `23514`. The length CHECKs (`invoices_po_reference_length`, `companies_tax_id_length`, and the name length if present) are therefore redundant and are KEPT as documentation of the intended limit (and as a guard if the column type is ever widened). Tests for these limits assert the message `value too long` (not the constraint name).

Relations: `Invoice belongsTo Company, Order, User(createdBy)`; `hasMany InvoiceItem, InvoiceStatusHistory`; `Order hasOne activeInvoice` (`where status <> 'void'`) and `hasMany invoices`; `Company hasMany invoices`.

### 3.3 `invoice_items` (snapshot of the ORDER's snapshot)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint PK | |
| `invoice_id` | FK invoices RESTRICT | |
| `position` | smallint NOT NULL | 1..n in `order_items.id` order |
| `part_number` | varchar(64) NULL | NULL for free-text quote lines (Phase 4) |
| `name` | varchar(255) NOT NULL | |
| `brand` | varchar(64) NULL | NULL for free-text lines |
| `quantity` | integer NOT NULL | CHECK 1..9999 |
| `unit_price_cents` | bigint NOT NULL | CHECK >= 0 and <= 1000000000 |
| `line_total_cents` | bigint NOT NULL | CHECK `= unit_price_cents * quantity` |
| `created_at` | timestamp NOT NULL | No `updated_at` (never updated) |

Length note: `name varchar(255)`, `part_number varchar(64)` and `brand varchar(64)` reject too-long values at the type level before any CHECK (same rule as "Column length versus CHECK" in 3.2); tests assert `value too long`. The same applies to `companies.tax_id varchar(32)` and `companies.name varchar(255)` in 3.1: `companies_tax_id_length` stays as documentation.

`part_number` and `brand` are both NULL or both set (CHECK `invoice_items_line_kind`, same shape as `order_items_line_kind`). UNIQUE `(invoice_id, position)`. There is no `product_id`: an invoice is a document, not a link to the live catalog. The invoice `subtotal_cents = SUM(line_total_cents)` cannot be a plain CHECK; it is guaranteed by the single service that writes both (section 8.1) and asserted in tests.

### 3.4 `invoice_status_history` (append-only)

`id`, `invoice_id` FK RESTRICT, `from_status` varchar(16) NULL, `to_status` varchar(16) NOT NULL, `actor_user_id` FK users RESTRICT NULL (NULL = system), `note` varchar(500) NULL (void reason), `created_at` timestamp NOT NULL. Index `(invoice_id, id)`. CHECKs: both statuses in the four values; `from_status IS NULL OR from_status <> to_status`; `invoice_status_history_system_actor`: `actor_user_id IS NOT NULL OR to_status = 'overdue'` (only the overdue transition is made by the system). The first row is `null -> open` with the creating staff user.

### 3.5 `invoice_number_counters`

| Column | Type | Notes |
| --- | --- | --- |
| `year` | smallint PK | CHECK `year BETWEEN 2000 AND 2999` |
| `last_value` | integer NOT NULL default 0 | CHECK `last_value >= 0 AND last_value <= 999999999` |
| `updated_at` | timestamp NOT NULL | |

One row per year, created on first use. Not mass-assignable, not exposed, not touched by anything except `InvoiceNumberAllocator`.

### 3.6 Immutability triggers (`_100005_add_invoice_immutability_triggers`)

PL/pgSQL, small and explicit:
- `BEFORE DELETE` on `invoices`, `invoice_items`, `invoice_status_history`: `RAISE EXCEPTION 'invoices are append-only'`.
- `BEFORE UPDATE` on `invoice_items` and `invoice_status_history`: always raise (rows are written once).
- `BEFORE UPDATE` on `invoices`: raise when any of `public_id, number, number_year, number_seq, company_id, order_id, order_number, created_by_user_id, issue_date, due_date, currency, po_reference, vat_rate_bp, subtotal_cents, tax_cents, total_cents, seller, buyer, created_at` changes (`IS DISTINCT FROM`). Mutable columns: `status`, `paid_on`, `updated_at`.
- Final states (added after the security review): in the same `BEFORE UPDATE` function on `invoices`, once `OLD.status` is `paid` or `void`, any change of `status` or `paid_on` raises (`IS DISTINCT FROM`); only `updated_at` may change. The legal transitions still pass: `open` to `paid`, `open` to `overdue`, `overdue` to `paid`, `open` to `void`, `overdue` to `void`. So `paid` and `void` are terminal in the database as well as in `InvoiceWorkflow` (a bug or a manual `UPDATE` cannot reopen a paid invoice or move a void one to paid).
- `TRUNCATE` is not blocked (needed by `migrate:fresh`/test cleanup; a production operator with TRUNCATE rights is outside this threat model; the follow-up is to run the application's DB role WITHOUT ownership, TRIGGER and TRUNCATE privileges on the invoice tables, so the app cannot drop or disable its own triggers: section 15a). Migration `down()` drops the triggers and functions.
- `migrate:fresh` drops all TABLES (and with them their triggers) but NOT PL/pgSQL functions, which are schema objects of their own. So the migration creates the functions with `CREATE OR REPLACE FUNCTION` (re-running `migrate:fresh` or `migrate` after a half-failed run does not fail with "function already exists"), and `down()` explicitly runs `DROP TRIGGER IF EXISTS ...` and `DROP FUNCTION IF EXISTS ...` for every function it created, so `migrate:rollback` leaves no orphan function.

Consequence for code and tests: factories write the final state in ONE insert (a `paid()` state inserts `status = paid` and `paid_on` together); nothing in tests updates immutable columns; tests that need a "wrong" row use `DB::unprepared` and expect the exception.

## 4. Gap-free invoice numbers

### 4.1 Why not a sequence, and the mechanism

Postgres `SEQUENCE`/`IDENTITY` values are non-transactional: `nextval` is never rolled back, so every failed or rolled-back transaction burns a number. A counter ROW updated inside the invoice transaction is rolled back with it.

`App\Services\InvoiceNumberAllocator::allocate(?CarbonImmutable $issueDate = null): AllocatedNumber{year, seq, number, issueDate}`:

```sql
INSERT INTO invoice_number_counters (year, last_value, updated_at) VALUES (:year, 1, :now)
ON CONFLICT (year) DO UPDATE SET last_value = invoice_number_counters.last_value + 1, updated_at = :now
RETURNING last_value;
```

One atomic statement: the first invoice of a year creates the row with 1, later ones increment it. The `DO UPDATE` takes a row lock that is held until the end of the surrounding transaction, so concurrent issuers queue behind each other and a rollback releases the lock and undoes the increment. Two sessions racing on the very first invoice of a year are safe too (the second waits on the first's uncommitted insert, then updates).

Rules:
1. `allocate()` throws `LogicException` when `DB::transactionLevel() === 0` (outside a transaction the lock would be released immediately and a later failure would leave a gap). Under `RefreshDatabase` the level is never 0, so this guard is covered by a unit test that uses the separate connections (section 13.1 test 1).
2. The allocator is the ONLY writer of the counter table. `IssueInvoice`, `InvoiceDemoSeeder` and `InvoiceFactory` all call it (the seeder and factory pass an explicit `$issueDate`), so numbering is consistent everywhere.
3. It runs as LATE as possible in the issuing transaction: all validation, locking of the order, snapshot reads and arithmetic happen first, the allocation is the last step before the inserts, and the transaction commits immediately after. The global serialisation point is therefore held for a few milliseconds.
4. Constructor takes a `ConnectionInterface` (default: the app's default connection) AND an injectable clock (a `Closure` returning `CarbonImmutable`, default `now()` in UTC), so tests can run it on separate connections and drive it with a fake clock.
5. As implemented, the allocator reads the clock EXACTLY TWICE per call: once BEFORE the lock (to pick the counter row's year) and once AFTER the lock (to get the issue date). The two-read contract is what makes the year-rollover test deterministic (a fake clock returns 2026-12-31 23:59:59 then 2027-01-01 00:00:00). If the two years differ, it throws `InvoiceYearRolled`; `IssueInvoice` catches it and retries the whole transaction ONCE (nothing was consumed, it rolled back); a second `InvoiceYearRolled` propagates (500, practically impossible). When an explicit `$issueDate` is passed (seeder, factory) the clock is not read at all.
6. The guard "refuse to run at transaction level 0" is checked on the allocator's OWN connection (`$connection->transactionLevel()`), not on the default connection facade, so it is correct for the injected connection.

### 4.2 Year boundary and time zone

- "Today" is `now()->utc()->toDateString()`; `app.timezone` is already `UTC`. The number's year is the year of the issue date. The business time zone of the seller is NOT used (open question 12).
- With no explicit date (production path): `year = now()->utc()->year` selects the counter row, the upsert locks it, THEN `issue_date = now()->utc()->startOfDay()` is read. If that date's year differs from the locked row's year (the clock crossed midnight on 31 December between the two reads), `allocate()` throws `InvoiceYearRolled` and `IssueInvoice` retries the whole transaction once (nothing was consumed: it rolled back). Reading the date after the lock also guarantees that within one year numbers and issue dates are non-decreasing, because the counter lock serialises the readers.
- If the clock moves backwards across a year boundary, the old year's counter row simply continues (numbers for a past year); the `invoices_number_year_seq_unique` constraint and `invoices_number_year_matches_issue_date` stop any inconsistency. This is an operations problem, not handled specially.
- Numbering resets on 1 January (counter row for the new year starts at 1). Format `NNNNNN` is zero padded to 6 digits and simply grows to 7+ digits after 999,999 (the CHECK allows 6 to 9). Sorting by number uses `(number_year, number_seq)`, never the string.

### 4.3 What "sequential" and "gap-free" mean here, and what is not claimed

- Sequential in issue order: within a year, the invoice committed later has the higher number and a date equal or later.
- Gap-free: every consumed number belongs to a committed invoice (including voided ones). Invoices are never deleted (trigger, no endpoint, RESTRICT FKs). The count of invoices in a year equals the counter's `last_value`.
- Not claimed: protection against someone with superuser rights rewriting data, or against restoring an old database backup (numbers issued after the backup are lost; Phase 8 restore procedure must say so).

### 4.4 Testing it (what Pest can and cannot prove)

Pest runs inside `RefreshDatabase`: every test is one open transaction on one connection, so two requests inside a test never truly overlap and uncommitted data is invisible to other connections. Therefore:
- Provable in Pest, deterministic, no timing: (a) sequential allocation 1,2,3 and a reset on a new year; (b) rollback leaves no gap (service-level, with a forced failure after allocation: the next invoice gets the number that the failed attempt had); (c) the row lock really blocks a second connection, and that rollback reuses the number, using TWO extra connections `pgsql_b` and `pgsql_c` configured at runtime with `config([...])` from the default connection (no `putenv`) and `SET lock_timeout`: B begins and allocates 1 (uncommitted); C begins, sets `lock_timeout = '300ms'`, allocates, and the test expects a `QueryException` with SQLSTATE `55P03` (it waited for B's lock); C rolls back; B rolls back; C allocates and gets 1 (no gap), commits; B allocates and gets 2. This test touches ONLY the counter table on the two extra connections (they cannot see the uncommitted order rows of the main test transaction), and its `afterEach` runs `TRUNCATE invoice_number_counters` on a separate connection; (d) the UNIQUE constraints catch a duplicate even if code were wrong.
- NOT provable in Pest: real parallel HTTP workers (PHP processes), real throughput and the full create endpoint under contention. That is QA's parallel run (section 13.3, check 4): 20 confirmed orders, 20 parallel `POST /orders/{number}/invoice` against `php artisan serve` with `PHP_CLI_SERVER_WORKERS=4` (as in the Phase 3 and 4 QA), then SQL: `SELECT number_seq FROM invoices WHERE number_year = 2026 ORDER BY 1` must be exactly `1..N` with no repeat and no hole, counter `last_value = N`, and every response is 201 with a distinct number.

## 5. Money, VAT and rounding

All arithmetic is integer cents and integer basis points in PHP `int` (64-bit) and Postgres `bigint`. No float, no `round()`, no `bcmath`. `App\Support\InvoiceTotals::compute(int $subtotal, int $rateBp): array{tax, total}` is a pure class and the only place the formula lives.

### 5.1 The rule

```
subtotal_cents = SUM(line_total_cents)               (lines copied from the order's snapshot, line_total = unit * qty)
tax_cents      = intdiv(subtotal_cents * vat_rate_bp + 5000, 10000)      ROUND HALF UP, ONCE, on the subtotal
total_cents    = subtotal_cents + tax_cents
```

Per-line tax is NOT computed or stored. Rounding once on the subtotal is chosen because (1) it is the simplest rule that is exactly reproducible from the printed numbers (subtotal x rate), (2) per-line rounding makes the printed tax depend on how lines are split (fixture F7 shows a 1 cent difference), (3) with a single rate per invoice there is no per-line tax column to print. Whether the user's jurisdiction requires per-line rounding is open question 2.

Overflow proof: `subtotal <= 999,900,000,000,000` (9.999e14, CHECK), `rate <= 5000`, so `subtotal * rate + 5000 <= 4.9995e18 + 5000 < 9.223e18` (bigint and PHP int max). `total <= 1.49985e15 < 9.007e15` (`Number.MAX_SAFE_INTEGER`), so every invoice amount is an exact integer in the browser. `InvoiceTotals` asserts the bounds and throws instead of overflowing silently.

### 5.2 Fixtures (these become the test table; hand-calculated, `tax = floor((s*r + 5000) / 10000)`)

| # | subtotal | rate bp | s*r | (s*r + 5000) / 10000 | tax | total | Point |
| --- | --- | --- | --- | --- | --- | --- | --- |
| F1 | 10000 | 1900 | 19,000,000 | 1900.5 | 1900 | 11900 | exact 19% of 100.00 |
| F2 | 999 | 1900 | 1,898,100 | 190.31 | 190 | 1189 | rounds down (exact 189.81 rounds to 190: nearest) |
| F3 | 5 | 1000 | 5,000 | 1.0 (0.5 + 0.5) | 1 | 6 | exact half rounds UP |
| F4 | 25 | 1000 | 25,000 | 3.0 (2.5 + 0.5) | 3 | 28 | exact half up (banker's rounding would give 2) |
| F5a | 499 | 10 | 4,990 | 0.999 | 0 | 499 | just below half |
| F5b | 500 | 10 | 5,000 | 1.0 | 1 | 501 | exactly half |
| F6 | 12345 | 0 | 0 | 0.5 | 0 | 12345 | rate 0 |
| F7 | 107079 | 1900 | 203,450,100 | 20345.51 | 20345 | 127424 | lines 3x1250 + 10x333 + 1x99999; per-line rounding would give 713 + 633 + 19000 = 20346 (REJECTED rule) |
| F8 | 0 | 1900 | 0 | 0.5 | 0 | 0 | zero order |
| F9 | 999,900,000,000,000 | 5000 | 4,999,500,000,000,000,000 | 499,950,000,000,000.5 | 499,950,000,000,000 | 1,499,850,000,000,000 | maximum, no overflow, JS-safe |

F7 intermediate values: line totals 3750, 3330, 99999 (sum 107079); per-line: 3750x1900 = 7,125,000 gives 712.5 -> 713; 3330x1900 = 6,327,000 gives 632.7 -> 633; 99999x1900 = 189,998,100 gives 18999.81 -> 19000; sum 20346.

DB test: every fixture is also inserted through raw SQL and satisfies `invoices_tax_consistent`; a row with `tax = 1900` for F2 violates it.

### 5.3 Relation to order totals

`order.total_cents` (Phases 3 and 4) is net and has `tax = shipping = 0`. `IssueInvoice` refuses (422 `order`) any order with `tax_cents <> 0` or `shipping_cents <> 0` ("This order has tax or shipping and cannot be invoiced yet": the invoice model has no shipping line), then asserts `SUM(order_items.line_total_cents) = order.subtotal_cents` (a mismatch is a data-integrity bug: exception, 500, nothing written). The invoice subtotal equals the order subtotal; the invoice total is `order.subtotal + tax`. With the default rate 0 the invoice total equals the order total.

## 6. Authorization

Same three layers as orders and quotes:
1. Role middleware per route group (403 before any lookup): reads for `customer|staff|admin`; staff actions for `staff|admin`.
2. `Invoice::visibleTo(User)`: customer `invoices.company_id = user.company_id` (`whereRaw('false')` when the company is null); staff/admin unscoped; a user with no role matches nothing. Used by EVERY invoice query in controllers. A Pest structure test asserts that no controller calls `Invoice::query()/where/find` without `visibleTo` (same technique as Phases 3 and 4).
3. `App\Policies\InvoicePolicy` (auto-discovered):

| Method | Rule | On failure |
| --- | --- | --- |
| `view(User, Invoice)` | staff/admin true; customer: non-null `company_id` equal to the invoice's | `denyAsNotFound()` (404) |
| `download(User, Invoice)` | exactly `view` (the PDF is authorized identically to the JSON detail) | 404 |
| `create(User, Order)` | staff or admin | 403 |
| `markPaid`, `void` | staff or admin | 403 |

State rules (can it be paid or voided now?) live in `InvoiceWorkflow` so a state conflict is 422 and a missing right is 403/404. No `Gate::before`. A dual-role user (customer plus staff) is treated as staff for the staff actions and as unscoped for reads, as in Phase 4 `QuotePolicy` (only `accept`/`decline` there were customer-only, and invoices have no customer actions).

Foreign or unknown invoice (or PDF): `404 {"message":"Not found."}`, identical bodies. Numbers are guessable, so the only protection is `visibleTo` plus the policy; the random `public_id` in the URL is defence in depth, not a substitute, and no test relies on the id being unguessable.

## 7. State machine and overdue

`App\Enums\InvoiceStatus` (`open`, `overdue`, `paid`, `void`) with `effective(status, due_date, today)`: stored `open` and `due_date < today` gives `overdue`; everything else is the stored value. `today = now()->utc()->toDateString()`, passed as a bound parameter, never SQL `CURRENT_DATE`.

| From (effective) | To | Who | Endpoint | Notes |
| --- | --- | --- | --- | --- |
| (none) | open | staff, admin | `POST /orders/{number}/invoice` | history `null -> open` |
| open | overdue | system | `invoices:mark-overdue` or effective at read | history row with NULL actor |
| open, overdue | paid | staff, admin | `POST /invoices/{id}/mark-paid` | sets `paid_on` |
| open, overdue | void | staff, admin | `POST /invoices/{id}/void` | reason required |
| paid, void | anything | nobody | | terminal |

`InvoiceWorkflow::markPaid|void|markOverdue` are the only code that changes `invoices.status`. Each runs `DB::transaction`, re-reads the invoice with `lockForUpdate()`, checks the matrix on the locked row, writes exactly one history row and returns the fresh model; a refused transition throws `ValidationException::withMessages(['status' => ...])`. `from_status` in the history row is the STORED status (an effective-overdue invoice marked paid records `open -> paid`; no synthetic overdue row is invented).

SQL for list filters (inside the company scope, the whole OR group in ONE closure so it cannot escape the scope): `open` -> `status = 'open' AND due_date >= :today`; `overdue` -> `status = 'overdue' OR (status = 'open' AND due_date < :today)`; `paid` -> `status = 'paid'`; `void` -> `status = 'void'`. `due_date` is immutable (trigger), so a stored `overdue` row always has `due_date < today`.

`days_overdue`: `today - due_date` in whole days when the EFFECTIVE status is `overdue`, else `0`.

`invoices:mark-overdue`: selects ids with `status = 'open' AND due_date < :today` (uses `(status, due_date)`), `chunkById(200)`; per invoice, in a transaction, re-reads under lock and skips unless still `open` and past due; sets `overdue`, writes the history row (actor NULL, no note); prints the count; idempotent; exit 0; no email. Frozen-clock tests (section 13.1).

Order cancellation guard (Phase 3 amendment, in `OrderStatusChanger`): when the target is `cancelled`, after locking the order row, if the order has a NON-VOID invoice the change is refused with 422 `status` "This order has an invoice. Void the invoice first." Lock order everywhere is order row first, then counter row, then invoice rows, so invoice creation and order cancellation serialise on the order row and cannot interleave.

## 8. Algorithms

### 8.1 Issue (`App\Services\IssueInvoice`)

Called by `InvoiceController::storeForOrder` after `authorize('create', Order)` semantics (role middleware already ran) and `CreateInvoiceRequest` validation.

1. Lookup `Order::visibleTo($user)->where('order_number', strtoupper($number))` (staff: unscoped), 404 if missing.
2. `DB::transaction` (retried once on `InvoiceYearRolled`):
   1. Lock the order row (`lockForUpdate`). Status not in `eligible_order_statuses` -> 422 `order`. An existing non-void invoice for the order -> 422 `order` ("This order already has an invoice."). Order `tax_cents` or `shipping_cents` non-zero -> 422 `order`.
   2. Load the company; empty `billing_address` -> 422 `company`. Load the order items ordered by id; none -> 422 `order`; assert the sum equals `order.subtotal_cents`.
   3. Validate config (rate range, terms, seller basics in every environment; the seller guard of 2.2 outside local/testing) -> 422 `invoice`, before the number is allocated.
   4. Build the seller and buyer snapshots (pure function `InvoiceSnapshotBuilder`, also used by the seeder).
   5. `InvoiceNumberAllocator::allocate()` (locks the counter, yields number and `issue_date`).
   6. `due_date` = request override or `issue_date + payment_terms_days`; an override outside `[issue_date, issue_date + max_due_days]` (can only differ from the request-time check if midnight passed) -> 422 `due_date`, transaction rolls back, nothing consumed.
   7. Compute tax and total (`InvoiceTotals`), insert the invoice (`forceFill` with server values only, `public_id` generated here), bulk insert the items (ONE `insert([...])` statement for all lines, so the insert count is the same for 1 and 50 lines), insert the first history row (`null -> open`, actor = staff user). History rows (here, in `InvoiceWorkflow` and in `invoices:mark-overdue`) are written THROUGH THE `InvoiceStatusHistory` MODEL (`forceFill(...)->save()`), not through the query builder, so Eloquent model events fire: a test registers a `creating` listener on `InvoiceStatusHistory` that throws, which fails the transaction AFTER the number was allocated (test 8 in section 13.1). Items are the only rows written without the model (bulk insert, no events needed).
3. Commit. Response 201 with the detail resource. No email in Phase 5.

Idempotency: none by header. A double click gives one 201 and one 422 `order` (the second waits on the order row lock, then sees the active invoice).

### 8.2 Mark paid, void

Body-only rule (all three write endpoints, trait `ReadsBodyOnly` used by the Form Requests): `due_date`, `paid_on` and `reason` are read from the request BODY only (JSON or form); the query string is ignored (Laravel's `input()` merges query and body, so the trait reads the body bag only). A void without a body `reason` is therefore 422 `reason` even when `?reason=x` is present; a query `due_date` or `paid_on` is simply not seen. Reason: a value in a URL ends up in access logs, proxies and browser history, and the contract says exactly where each field lives.

`mark-paid`: validate body (`paid_on` optional date; default today UTC; `issue_date <= paid_on <= today`, the lower bound is checked under the lock against the real `issue_date`), lookup with `visibleTo`, authorize, `InvoiceWorkflow::markPaid` (lock, effective status must be `open` or `overdue`, set `status = paid`, `paid_on`, history row). `void`: `reason` required (1 to 500 characters, the Phase 4 text rules: newline and tab allowed, other `Cc`/`Cf`, NUL and invalid UTF-8 rejected), same lock, stored `open`/`overdue` only, history `note = reason`. The void reason is staff-only (hidden from customers).

### 8.2a Decisions made during implementation (also in the contract, "Details decided by the backend")

- Mark-paid on a `void` invoice: 422 `status` "Only open or overdue invoices can be marked as paid." (a `paid` invoice keeps "This invoice is already paid.").
- A company billing address with more than 6 lines: 422 `company` at issue time (the DB CHECK limits only the length, 500 characters; the line count is checked by the backend before anything is written).
- List filter `status=` with an EMPTY value is treated as no filter (same as orders); empty CSV ITEMS (`open,,paid`, `,open`) are 422 `status`.
- `HEAD` on the PDF route: 405 with `Allow: GET`, checked before any lookup or render (changed after the security review; Laravel serves HEAD through the GET route, so without this a HEAD would render the whole PDF and throw the body away). Auth, role and the limiters `api`, `invoice-pdf`, `invoice-pdf-ip` still run first, and HEAD counts against the limiters.
- `activeInvoice` is eager-loaded in the SHARED order `detail()` helper of `OrderController`, so the create, cancel and status-change responses on orders also carry `invoice` and `can_invoice` (not just `GET /orders/{number}`).
- Placeholder or incomplete seller data in any environment except `local` and `testing`: issuing returns 422 `invoice` "Invoicing is not configured." (seller guard, section 2.2; replaces the earlier production-only seller-name check).
- Body-only inputs (`ReadsBodyOnly`) and database-enforced final states (section 3.6): see 8.2 and 3.6.

### 8.3 Number and snapshot immutability in the service layer

`Invoice` model: no mass assignment (`$guarded = ['*']`-style, `forceFill` only in the services), no `updating` path for immutable columns (the trigger enforces it anyway).

## 9. PDF

### 9.1 Endpoint and headers

`GET /api/v1/invoices/{invoice}/pdf`, role middleware, then limiters `api`, `invoice-pdf` (20/min per user) and `invoice-pdf-ip` (60/min per IP), then (for `HEAD` only) the 405 answer with `Allow: GET` BEFORE any lookup or render, then lookup `Invoice::visibleTo($user)` with `items` eager loaded (history is not needed), `authorize('download')`, render, respond with:

```
Content-Type: application/pdf
Content-Disposition: attachment; filename="INV-2026-000123.pdf"
Content-Length: <bytes>
X-Content-Type-Options: nosniff
Cache-Control: no-store, private
Content-Security-Policy: default-src 'none'; sandbox
```

The filename is built ONLY from the stored `number` (which the CHECK `invoices_number_format` limits to `INV-` + digits), plus `.pdf`; no user text, no company name, no PO ever enters a header. A test asserts the regex `^attachment; filename="INV-\d{4}-\d{6,9}\.pdf"$` and that a hostile company name never appears in any header. `attachment`, never `inline`. Errors on this route (401, 404, 429) are the usual JSON bodies. A voided invoice still downloads (watermarked, section 9.5); a foreign or unknown id is 404 with the exact body.

Why on the fly and not stored (v1): the snapshot is immutable, so the render is a pure function of the row; storing would need a private disk path, backup coupling (Phase 4 already has that cost for attachments), orphan handling and a stale copy when the template is fixed. The risk is that a template change alters how an OLD invoice looks, and some jurisdictions require the issued document to be kept as issued: open question 10. The upgrade path is cheap (render once at issue time, store under `storage/app/private/invoices/{public_id}.pdf`, serve through the same endpoint).

### 9.2 dompdf settings (`config/dompdf.php`, published; names follow the installed version, verify against the published file)

- CONFIRMED option names in the installed wrapper v3.1.2 / dompdf v3.1.6 (the spike verified each against the published `config/dompdf.php`): `enable_remote`, `enable_php`, `enable_javascript`, `enable_html5_parser`, `enable_font_subsetting`, `chroot`, `allowed_protocols`, `font_dir`, `font_cache`, `temp_dir`, `default_font`.
- `enable_remote = false` (no http/https fetch, so no SSRF), `enable_php = false` (no inline `<?php` or `<script type="text/php">`), `enable_javascript = false`, `enable_html5_parser = true`.
- `allowed_protocols` is restricted to `data://` ONLY (the dompdf default also allows `file://`, `http://` and `https://`). The template has no resources anyway; this is defence in depth, and a config test asserts the array has exactly that one key.
- `chroot` = `resource_path('pdf')` (a directory the template may read from; empty in v1, there is no local asset). NEVER `base_path()`. CONFIRMED in the spike: the narrow chroot works. DejaVu Sans still loads from `vendor/dompdf/dompdf/lib/fonts` regardless of `chroot` (dompdf's bundled fonts are resolved from its own lib directory, not through the chroot check), so no widening is needed. Only the `*.ufm.json` font-metric caches are written to `storage/fonts`, which is git-ignored (`storage/fonts/.gitignore`). If a future dompdf update breaks the bundled font with this chroot, widen only to the dompdf font directory, never to the project root.
- `font_dir` = `font_cache` = `storage_path('fonts')`, `temp_dir` = `sys_get_temp_dir()`, `default_font = 'DejaVu Sans'`, `default_paper_size = 'a4'`, `default_media_type = 'print'`, `enable_font_subsetting = true`, `log_output_file = null`, all debug flags false.
- The template has NO `<img>`, `<link>`, `@import`, `url(...)`, `@font-face` or external references. A structure test greps the Blade file for `{!!`, `@php`, `<img`, `<link`, `<script`, `@import`, `url(`, `http` and fails on any hit.
- Fonts: DejaVu Sans is bundled with dompdf and covers Latin (incl. extended), Greek, Cyrillic and many symbols, so no system fonts are needed on any runner. The backend spike must confirm in the first test run that `ä ö ü ß é ñ ł ž € ≥` render (visual check once; automated: PDF generates and the HTML contains them) and that nothing needs to be installed or copied into `storage/fonts` beyond the writable directory.
- Metadata (via the dompdf canvas `add_info` before output): `Title` = `Invoice INV-2026-000123`, `Author` = sanitised seller name, `Subject` = `Invoice`, `Creator` = `B2B Customer Portal`. No server path, no user name, no hostname, no `.env` value. `CreationDate` is set from the invoice `created_at` so repeated downloads look identical: CONFIRMED that `addInfo(['CreationDate' => 'D:YYYYMMDDHHmmSSZ'])` works (for example `D:20261126101500Z`); byte-equality of two renders is NOT asserted anywhere. Note for tests: dompdf stores `Title` and `Producer` (and the other text entries) as UTF-16BE with a BOM, so a plain-text regex such as `/Title \(Invoice INV/` on the PDF bytes FAILS; a metadata test must either compare the UTF-16BE encoding (`mb_convert_encoding($s, 'UTF-16BE', 'UTF-8')`, prefixed with `\xFE\xFF`) or not assert on those fields.

### 9.3 Template (`resources/views/invoices/pdf.blade.php`) and escaping

- Every dynamic value is printed with `{{ }}` only (HTML-escaped with quotes). Inputs through `App\Support\PdfText::clean(string)` first: removes Unicode `Cc` (except it turns `\n` into a line break handled by the template per line), removes `Cf` including bidi controls (U+200B to U+200F, U+202A to U+202E, U+2066 to U+2069, U+FEFF, U+00AD), replaces any code point above U+FFFF (emoji) with U+FFFD (dompdf mishandles supplementary-plane characters and DejaVu has no emoji), normalises invalid UTF-8 to U+FFFD, and breaks any run of more than 28 non-space characters by inserting a plain space every 28 characters (dompdf only wraps at spaces and hyphens; this guarantees nothing overflows the page edge or the column). The cleaned text is DISPLAY ONLY; the database keeps the original.
- Free text that reaches the PDF: company (buyer) name and address, seller config values, order line `name`/`brand`/`part_number`, `po_reference`. Part numbers, names and PO references come from the catalog/CSV import, customers (quote descriptions, which already reject `Cc`/`Cf`) and strict PO rules, but the template treats ALL of them as hostile: `<`, `>`, `&`, quotes, backticks, `{{`, `@php`, `{!!` inside data are inert because Blade output is escaped and the template is compiled before data is inserted.
- Right-to-left scripts (Arabic, Hebrew) are not shaped or reordered by dompdf: known limitation, English-only v1.
- Money in the PDF: `App\Support\Money::format(int $cents, string $currency)` using integer `intdiv`/`%` and thousands separators: `USD 1,234.56`; ISO code, not a symbol (no glyph risk). Dates `YYYY-MM-DD`. Percent: `vat_rate_bp` formatted as `19%` or `7.5%` (integer math: `intdiv(bp, 100)` and a remainder).

### 9.4 Layout (A4 portrait, margins about 18 mm)

1. Header: seller name and address block left; right: "INVOICE", number, issue date, due date, order number, PO reference (only when set).
2. Billed-to block: buyer name, address lines, tax id (when set). Seller tax id near the header.
3. Items table: `#`, `Part no.`, `Description` (name; brand on a second line when set), `Qty`, `Unit price`, `Line total`; money columns right-aligned. Free-text lines have an empty part number cell. Column widths (as implemented): dompdf IGNORES `<col>` / `<colgroup>` widths and fixed `mm` widths on cells, so the widths are PERCENTAGES set on the `<th>` cells of the header row (summing to 100%); `table-layout: fixed` is kept. The PO reference in the header block is cleaned and wrapped in runs of 20 characters (narrower than the general 28-character rule of 9.3, because the header cell is narrow).
4. Totals block (right aligned, its own table): Subtotal, `VAT 19%` (label and rate from the snapshot), Total, in the invoice currency.
5. Payment block: payment terms text, due date, bank holder, bank name, IBAN, BIC, "Please quote invoice INV-2026-000123".
   Unsplittable tail (as implemented, replaces the earlier "page-break-inside: avoid and accept an orphaned payment block"): the LAST 4 ITEM ROWS, the totals block and the payment block are rendered as ONE table row (one `<tr>` that cannot be split), so the totals and the payment block can never sit alone on a trailing page: whenever the tail does not fit, the last 4 item rows move to the next page together with it. Accepted trade-off: a small invoice with a VOID banner (banner plus the tail row) can leave a mostly empty first page. Verified by rendering PDFs to PNG and looking at them for invoices of 1, 24 to 31 and 100 items, a 300-character footer and a 59-character PO reference.
6. Footer (fixed on every page): seller footer text limited to 72% of the page width, so a long footer (300 characters) wraps inside that area and never collides with "INV-2026-000123 - Page X of Y", which sits in the right-hand area. Bottom page margin is 30 mm (large enough for a wrapped footer of several lines).

### 9.5 Page breaks, repeated headers, page numbers, void

- `thead { display: table-header-group; }` so the column header repeats on every page; `tr { page-break-inside: avoid; }` so a row is never split; the totals and payment blocks are NOT protected with `page-break-inside: avoid` (the least reliable dompdf feature: it moved the whole payment block to a lonely last page); instead the last 4 item rows, the totals and the payment block are ONE unsplittable table row (9.4 item 5), which dompdf honours reliably. The manual QA check on a long invoice (13.3 check 5) remains part of the gate.
- "Page X of Y": `isPhpEnabled` stays false, so the inline-script approach is NOT used. After `render()`, the service draws the text with `$canvas->page_text($x, $y, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 8)` using the DejaVu font from the font metrics (`$dompdf->getFontMetrics()->getFont('DejaVu Sans')`; CONFIRMED in the spike that this works after `render()` and before `output()`), then outputs the PDF.
- Voided invoice: a prominent "VOID" banner at the top of page 1 and in the footer text ("VOID - this invoice has been cancelled"); no diagonal watermark (rotation support in dompdf is limited). Paid and overdue are portal states and are NOT printed on the PDF.

### 9.6 Testing without a PDF reader

- Content assertions are made on the RENDERED HTML (`InvoicePdf::html(Invoice): string`, the same view the PDF is built from): numbers, escaped hostile strings, absence of `<script>`, absence of bidi controls, wrapped long words, totals text, VOID banner.
- PDF assertions use only what is robust without a text extractor (dompdf compresses content streams and uses subset glyph ids, so there is no readable text in the bytes): starts with `%PDF-`, contains `%%EOF` near the end, `Content-Length` equals the body length, size between 2 KB and 5 MB, page count from `/Count N` (a 200-line invoice has at least 3 pages, a 3-line invoice exactly 1). CONFIRMED against the installed dompdf: the file contains more than one `/Count`; the FIRST is the outline's `/Count 0`, and the page count is the LAST `/Count N` in the file (`preg_match_all('/\/Count (\d+)/', $pdf, $m)` and take `end($m[1])`). Using the first match returns 0. Title and Producer are UTF-16BE (see 9.2), so do not regex them as plain text. No test depends on `pdftotext`, `pdfinfo`, Ghostscript or any system binary.

### 9.7 Performance and limits

Realistic maximum is 100 lines (the cart cap; quotes allow 50). The stress test uses 200 lines, 2x the real maximum. Assert on CI: render completes in under 30 s and peak memory grows by less than 200 MB (generous guards against accidental O(n^2); they are not performance targets). MEASURED (200-line invoice, real HTTP request): about 2.1 s and about 56 MB extra peak memory, well inside the guards. Stock PHP `memory_limit` 128M fits ONE such request but thinly; RECOMMENDED production `memory_limit` is 256M (Phase 8 note, section 16). The full Pest suite itself needs more than 128M, so `api/phpunit.xml` sets `memory_limit` to `256M` (`<ini name="memory_limit" value="256M"/>`): tests must not depend on the machine's `php.ini`. The endpoint is rate limited (`invoice-pdf` 20/min per user, `invoice-pdf-ip` 60/min per IP, plus `api` 60/min) because rendering is CPU heavy; HEAD is refused with 405 before any rendering and counts against the same limiters. Optional follow-up: cache the rendered PDF per (invoice, status, `updated_at`) (section 15a).

## 10. Query-count guarantee

MEASURED (as implemented, EXCLUDING the session-user query that every authenticated request runs): list 3, detail 6, PDF 3, order detail 9. The bounds below (6 / 8 / 6 / 11) still hold with margin and stay the asserted upper limits (the tests also assert equality between small and large data sets).

`preventLazyLoading` is on outside production. Upper bounds (the implementation measures them and each bound is the measured value plus at most one, as in Phase 4; the tests ALSO assert equality between a small and a large data set):
- `GET /invoices`: user and roles, count, rows with `withCount items` (no company join, the company name is read from the `buyer` snapshot): bound 6.
- `GET /invoices/{id}`: user and roles, invoice, items, history, history actors and their roles: bound 8.
- `GET /invoices/{id}/pdf`: user and roles, invoice, items: bound 6, independent of the number of lines.
- `GET /orders/{number}`: one more query for `activeInvoice` (Phase 3/4 detail bound 10 becomes 11). `GET /orders` is unchanged.
- `POST /orders/{number}/invoice`: the INSERT count is equal for 1 and 50 lines (bulk item insert).

## 11. Seeders and factories

Factories (tests use these, not the seeder): `InvoiceFactory` builds a fully consistent invoice through `InvoiceSnapshotBuilder` and `InvoiceNumberAllocator` (so numbers are real and sequential), from a given or newly created order; states `open()`, `overdue()` (stored overdue plus the system history row), `paid()`, `void()`, `issuedOn($date)`, `dueOn($date)`, `forCompany($c)`, `withLines($n)`, `rate($bp)`; the final status is written in ONE insert (the immutability triggers forbid later edits). `InvoiceItemFactory`. `CompanyFactory` gains `billing_address` and `tax_id`.

`InvoiceDemoSeeder` (local and testing only, aborts otherwise like the other demo seeders; called from `DatabaseSeeder` after `OrderDemoSeeder` and `QuoteDemoSeeder`; requires them and `DemoSeeder` companies, calling them if missing; idempotent: skips an order that already has any invoice; deterministic fixed dates, no randomness). It does NOT call `IssueInvoice` (it needs fixed past dates and fixed history timestamps) but it uses the SAME `InvoiceNumberAllocator` (explicit `$issueDate`) and `InvoiceSnapshotBuilder`, inside one transaction per invoice, in ascending issue-date order, so numbering is consistent with real use. Rate and terms come from config (rate 0 by default). `DemoSeeder` is amended to set a fake billing address and tax id on both companies (`firstOrCreate` then `forceFill` and save).

| Seeded number | Order (company) | Issued | Due | Stored status | Notes |
| --- | --- | --- | --- | --- | --- |
| `INV-2026-000001` | `ORD-NWAAA-AAAA5` (Northwind, delivered) | 2026-09-16 | 2026-10-16 | paid | `paid_on` 2026-10-10, history open -> paid (staff) |
| `INV-2026-000002` | `ORD-GXAAA-AAAA3` (Globex, delivered) | 2026-10-06 | 2026-11-05 | overdue | history open -> overdue (system) |
| `INV-2026-000003` | `ORD-NWAAA-AAAA4` (Northwind, shipped) | 2026-10-29 | 2026-11-12 | open | stored `open` with a past due date on purpose: effective status is overdue, and QA runs `invoices:mark-overdue` to see it materialise |
| `INV-2026-000004` | `ORD-NWAAA-AAAA3` (Northwind, confirmed) | 2026-11-13 | 2026-12-13 | void | reason "Wrong billing address (demo)"; history open -> void (staff) |
| `INV-2026-000005` | `ORD-NWAAA-AAAA3` | 2026-11-14 | seeding day (UTC) + 14 | open | re-issued after the void; the due date is relative so this one stays open |

Visibility: Alice and Bob see the 4 Northwind invoices (`000001`, `000003`, `000004`, `000005`), Carol sees 1 (`000002`), staff see 5. Effective statuses with the clock after 2026-11-12: Northwind has paid, overdue, void, open; Globex overdue. Tests assert counts, statuses and invariants (totals, tax formula, counter `last_value = 5` and `number_seq` 1..5), never the relative due date. Staff `status=overdue` shows 2 rows; after `invoices:mark-overdue`, `000003` has a system history row. The seeded orders have no new rows; pending and cancelled demo orders are not invoiced.

## 12. Frontend design (`web/src/features/invoices/`)

API shape note for consumers: Postgres `jsonb` does not keep object key order (it stores keys sorted by length, then bytewise), so `seller` and `buyer` come out of the database in a different key order than they were written. `InvoiceDetailResource` therefore RE-EMITS both objects explicitly in the contract order (`seller`: name, address_lines, tax_id, email, phone, bank {account_holder, bank_name, iban, bic}, payment_terms_text, footer, vat_label; `buyer`: name, address_lines, tax_id) and does not pass the decoded jsonb through. The frontend must not depend on key order anyway (Zod objects), and backend tests assert key SETS, not order (13.5).

Suggested layout: `api.ts`, `schemas.ts` (Zod for responses), `urlState.ts`, `InvoicesPage.tsx`, `InvoiceDetailPage.tsx`, `StatusBadge.tsx`, `StatusTimeline.tsx`, `DownloadPdfButton.tsx`, `downloadPdf.ts`, `CreateInvoiceDialog.tsx`, `MarkPaidDialog.tsx`, `VoidDialog.tsx`, `format.ts` (money via `Intl.NumberFormat('en-US', { style: 'currency', currency })` with the body's `currency`; VAT rate `bp / 100`). Plus a small amendment to the orders detail page.

Routes (inside `ProtectedRoute`): `/invoices` and `/invoices/:id` any logged-in role. Nav: "Invoices" for ALL roles (single nav config). `:id` not matching the lowercase UUID pattern, or a 404 from the API (unknown OR foreign), shows one "Invoice not found" page (no request for the malformed case). Staff and customers see the same pages; staff controls come only from `allowed_actions`; hiding is a convenience, the server decides.

### 12.1 List (`/invoices`)

URL is the source of truth: `status` (CSV checkboxes in a `<fieldset>`: Open, Overdue, Paid, Void), `from`, `to` (issue date), `q` ("Invoice number, order number or PO reference", 300 ms debounce, max 32), `sort` (Newest, Oldest, Due date, Number, Total), `page`. `per_page` and `company_id` are not in the URL. Defaults omitted, invalid values dropped, `from` after `to` shows an inline alert and sends nothing. Staff also get a Company column and a "Overdue" shortcut that sets `status=overdue`.

Table columns: Number (link to `/invoices/:id`), Order (link to `/orders/:number`), PO reference (text node, em dash when null), Issued, Due, Status, Total, plus Company for staff. Overdue rows are highlighted WITHOUT relying on colour: a left border plus a tinted background, a `StatusBadge` with the text "Overdue" and a warning icon (`aria-hidden`), and a visible text "12 days overdue" next to the due date (from `days_overdue`; the browser clock is never used). Void rows are shown with the "Void" badge and muted text (not struck through, so the number stays readable). Status badges are text first, colour second (contrast at least 4.5:1). States: skeleton, empty (customer without filters: "You have no invoices yet."; with filters: "No invoices match your filters." plus Clear; staff: "No invoices."), error with retry, 429 seconds message, result count in an `aria-live="polite"` region. Dates are shown as the `YYYY-MM-DD` string from the API without `new Date(...)` parsing (a date-only string parsed as UTC shifts to the previous day in negative-offset time zones).

### 12.2 Detail (`/invoices/:id`)

Heading with number and `StatusBadge`; when `days_overdue > 0` a `role="status"` banner "Payment is N days overdue (due YYYY-MM-DD)". Blocks: Seller, Billed to, dates (issued, due, paid on), PO reference, Order link ("Order ORD-...", to `/orders/:number`), items table with caption, totals (Subtotal, "VAT 19%" or "VAT 0%", Total), status timeline `<ol>` (system shown as "System", staff names masked for customers by the API as "Portal staff"), void reason visible to staff only. All text as text nodes, never HTML.

Download: the "Download PDF" button (a `<button>`, label "Download PDF (INV-2026-000123)"). RECOMMENDED: `fetch` + blob, not window navigation. `fetch('/api/v1/invoices/{id}/pdf', { credentials: 'same-origin', headers: { Accept: 'application/pdf' } })` on the same origin (Vite proxy in dev, same host in production), cookie session sends the session cookie (the GET needs no CSRF header; SameSite=Lax is irrelevant on same-origin requests). Then: check `response.ok` and `Content-Type` starts with `application/pdf`, read `blob()`, take the filename from `Content-Disposition` (accept it only if it matches `^INV-\d{4}-\d{6,9}\.pdf$`, otherwise fall back to `<number>.pdf` from the loaded invoice), create an object URL, click a temporary `<a download>`, revoke the URL. Why not plain navigation (`<a href>` / `window.location`): an error response (401, 404, 429) would replace the SPA page with raw JSON or a failed-download toast, with no way to show "try again in N seconds"; with fetch the errors become inline messages. The server's `Content-Disposition` filename is still the source of the saved name. Behaviours: button disabled and `aria-busy` while pending (double click sends once); 401 goes through the standard login redirect; 404 shows "Invoice not found"; 429 shows "Too many downloads. Try again in N seconds." from `Retry-After`; network error shows a retry message; the success message is announced in an `aria-live` region. The shared API client needs a small `fetchBlob` helper that reuses its 401 handling.

Staff actions (from `allowed_actions`): "Mark as paid" (dialog with a date input defaulting to today, `max` today, label "Payment received on", sends `{ "paid_on": "YYYY-MM-DD" }`) and "Void invoice" (dialog with a required reason textarea, warning text "The invoice number stays reserved and this cannot be undone."). Dialogs follow the Phase 4 pattern (native `<dialog>` or `role="dialog" aria-modal`, focus trap, Esc, focus returns, buttons disabled while pending). 422 `status` shows the message and refetches; 422 `paid_on`/`reason` are shown on the field.

### 12.3 Orders amendment

Order detail shows "Invoice INV-2026-000123 (status)" linking to `/invoices/:id` when `invoice` is set. For staff and admin, a "Create invoice" button when `can_invoice` is true: dialog with an optional "Due date" (min today, max today + 120 days; empty means the default terms), a confirmation sentence, sends `{}` or `{ "due_date": "..." }`, then navigates to `/invoices/:id`. 422 `order`/`company`/`invoice` messages are shown in the dialog; after a 422 `order` the order is refetched (an invoice may already exist). Customers never see the button (`can_invoice` is always false for them).

## 13. Test plan

Order: the gate items first (in PLAN.md order), then extras. Every new endpoint is tested for guest 401, customer, staff and admin, plus a foreign company.

### 13.1 Backend (Pest) - `backend-engineer`

Gate items:
1. Numbers unique and sequential, also under concurrency: (a) 5 sequential issues give `INV-2026-000001..5`, `number_year_seq` unique; (b) the allocator on two extra connections (section 4.4 c) proves the row lock and the reuse after rollback; (c) `allocate()` outside a transaction throws (runs on a separate connection with no transaction); (d) direct SQL duplicate `number` or `(number_year, number_seq)` violates the unique constraints.
2. Totals, VAT and rounding match the fixture table F1 to F9 (section 5.2): unit test `InvoiceTotals` on every row; feature test issuing an order with the F7 lines at rate 1900 and asserting the stored and returned `subtotal`, `tax`, `total`; DB test that a wrong `tax_cents` violates `invoices_tax_consistent`; invoice subtotal equals the order subtotal and the sum of the item rows; with the default rate 0 the invoice total equals the order total; rate and tax are snapshotted (changing `config('invoicing.vat_rate_bp')` afterwards does not alter an existing invoice or its PDF HTML).
3. PDF endpoint: 200 with `Content-Type: application/pdf`, body starts with `%PDF-`, non-empty, `Content-Length` matches, `Content-Disposition` is exactly `attachment; filename="INV-YYYY-NNNNNN.pdf"` for that invoice, `nosniff`, `Cache-Control` contains `no-store` and `private`, CSP header present; `HEAD` on the same URL is 405 with `Allow: GET` and an empty/JSON error body (never `%PDF-`), no PDF render happens (assert with a spy on the `InvoicePdf` service or by query count: no invoice query runs), HEAD for a guest is 401, for an unknown or foreign id is still 405 (no existence leak), and a HEAD request increments both PDF limiters.
4. Other company's invoice PDF: 404 with the exact body (customer of company B on company A's invoice and also unknown id); guest 401; malformed id (not a UUID, an invoice NUMBER such as `INV-2026-000001`) 404 for every role; staff and admin can download any company's PDF.
5. Overdue set by the scheduled command with a frozen clock: invoice due 2026-12-26; at `2026-12-26 23:59:59 UTC` the command changes nothing; at `2026-12-27 00:00:01 UTC` it sets `overdue` and writes one history row with a NULL actor; second run changes nothing (idempotent, still one row); `paid` and `void` untouched; a not-yet-due `open` untouched; 450 invoices exercise the chunking; the command is registered in the schedule (`Schedule` events list contains `invoices:mark-overdue`).

Extras:
6. Authorization and IDOR for EVERY endpoint (list, detail, pdf, create, mark-paid, void): guest 401; customer of company B on company A's invoice 404 (detail, pdf) with the exact body; customer on staff endpoints 403 even for an unknown well-formed id; list never contains a foreign invoice (also through `q`, `status`, `company_id` filters); a customer with `company_id = null` sees nothing; staff and admin see all companies; `company_id` filter works for staff and is ignored for customers; structure test that controllers never call the Invoice model without `visibleTo`; role 403 comes before 429 (a customer hammering a staff `invoices-write` route gets 403, never 429).
7. Create rules: allowed for `confirmed`, `shipped`, `delivered`; 422 `order` for `pending` and `cancelled`; second create for the same order (sequential) is 422 `order` and creates no row and consumes no number; after `void` a new invoice for the same order works and gets the next number; DB test that two non-void invoices for one order violate `invoices_one_active_per_order` while void plus open is allowed; company without billing address 422 `company`; order with `tax_cents` or `shipping_cents` non-zero 422; order with free-text quote lines (null part number and brand) invoices and snapshots `null`s; items are copies (changing the product or the order item afterwards, or the company name, or the seller config does not change the invoice or its HTML); mass assignment: a body containing `company_id`, `order_id`, `number`, `status`, `issue_date`, `subtotal_cents`, `tax_cents`, `total_cents`, `vat_rate_bp`, `seller`, `buyer`, `items`, `currency`, `po_reference`, `id` is ignored (only `due_date` is read); malformed JSON 422 `body`.
8. Failed transaction leaves no gap: force a failure AFTER allocation (a `creating` listener on `InvoiceStatusHistory` that throws), assert the exception, no invoice/item/history row, counter `last_value` unchanged, and the next successful issue gets the number the failed attempt would have had.
9. Year boundary with a frozen clock: issue at `2026-12-31 23:59:59 UTC` gives `INV-2026-00000N`; at `2027-01-01 00:00:00 UTC` gives `INV-2027-000001` (reset); issue date is the UTC date even when the app or PHP default timezone differs (`config(['app.timezone' => 'America/Los_Angeles'])` does not change the number year); allocator with a fake clock that crosses midnight between its two reads throws `InvoiceYearRolled` and `IssueInvoice` retries once and succeeds with consistent year and date.
10. Due date math: default `issue_date + 30`; config terms 14 gives +14; override accepted at `today` and at `today + 120`; rejected (422 `due_date`) for yesterday, `today + 121`, `2026-02-30`, a non-string, an array, a datetime string; a null override means default.
11. Effective status and filters: an `open` invoice due yesterday is returned as `overdue` with `days_overdue = 1` before the command runs; due TODAY is `open`, `days_overdue = 0`; `status=overdue` filter includes effective-overdue and stored-overdue, `status=open` excludes them; the OR group cannot leak another company's rows; boundary at exactly midnight UTC with a frozen clock.
12. Mark paid: sets `paid` and `paid_on`, history row with actor; default `paid_on` today; rejects a future date, a date before `issue_date`, an invalid date (422 `paid_on`); works on overdue (stored and effective); second call 422 `status`; on `void` 422; customer 403 (even for an unknown well-formed id); an unknown well-formed id is 404 for staff.
13. Void: reason required (422 `reason` for missing, empty, 501 characters, control characters); works on `open` and `overdue`; refused on `paid` and `void` (422 `status`); number remains and the invoice remains listed; history `note` visible to staff and `null` for customers; the order becomes invoiceable again; order cancel with a non-void invoice is 422 `status`, and succeeds after the void.
14. Schema and triggers: every CHECK rejects its bad value (number format, number/year/seq mismatch, due before issue, paid without `paid_on`, `paid_on` on an open row, tax and total formulas, rate 5001, subtotal above the max, jsonb not an object, history system actor on a non-overdue transition); `DELETE` on invoices, items and history raises; `UPDATE` of `number`, `total_cents`, `seller` raises while `status`/`paid_on` updates work; `TRUNCATE` is not tested.
15. Number format and listing: regex `^INV-\d{4}-\d{6}$` for the first 999,999; numbers past 999,999 widen to 7 digits and sort correctly by `(number_year, number_seq)` (set the counter to 999,999 directly in the test).
16. List: pagination and `meta` (six keys), sorts (all 8 values, default `-issue_date`, id tie-break), `status` CSV, `from`/`to` inclusive and `from > to` 422, `q` by number (`inv-2026-1`, `INV2026000123`), by order number, by PO reference with `%`, `_`, `\` literal, a `q` that normalises to empty matches nothing by number, array syntax and interior NUL 422, leading/trailing NUL trimmed, 101 per page 422, unknown params ignored.
17. Resources: exact key sets for list, detail, items, history; no numeric id, no `company_id`, no `user_id` anywhere; `id` is a lowercase UUID; money fields are integers; `seller`/`buyer` snapshot shape; customer `allowed_actions = ["download"]`; staff gets `mark_paid` and `void` only while open/overdue; system actor `{name: null, role: "system"}`; staff actors masked for customers.
18. PDF content and security (HTML level): hostile strings (`<script>alert(1)</script>`, `"><img src=x onerror=1>`, `& < > " '`, `{{ 7*7 }}`, `{!! x !!}`, `@php`, U+202E and other bidi controls, U+200B, an emoji, `Müller & Söhne GmbH`, `Zażółć gęślą jaźń`, a Cyrillic and Greek name, a 300-character unbroken string, newlines in address lines) in company name, address, item names, PO reference and seller config: the HTML contains the escaped forms, no raw `<script>`/`<img`, no bidi controls, emoji replaced by U+FFFD, no run of more than 28 non-space characters, one line per address line; Blade structure test (no `{!!`, `@php`, `<img`, `<link`, `<script`, `@import`, `url(`, `http`); the PDF still renders (200, `%PDF-`) for every hostile case; a long invoice (200 lines, 1 free-text line) renders under the time and memory bounds with at least 3 pages; 1-line invoice has 1 page; VOID banner present in the HTML only for void invoices; paid/overdue states are not printed; no header ever contains user text.
19. Rate limits: `invoice-pdf` 21st request in a minute is 429 with `Retry-After` and the standard body; the limit is per user (another user is unaffected) and shared across invoices; `invoice-pdf-ip`: 61 requests from ONE IP spread over several users (each under 20) give 429 on the 61st, an IPv6 address in the same /64 shares the budget, another IP is unaffected; HEAD requests count against both limiters; role 403 comes before 429 on the PDF route and on staff routes (a customer hammering a staff `invoices-write` route gets 403, never 429); `invoices-write` 31st write is 429; `api` limiter still applies.
20. Query counts: list (bound 6), detail (8), pdf (6) are constant between 3 and 60 rows and between 1 and 100 lines; order detail adds exactly one query; create has an equal INSERT count for 1 and 50 lines.
21. Config guards (seller guard, section 2.2): for each of `production`, `staging` and an arbitrary name such as `qa` (with `withoutMiddleware(ValidateCsrfToken::class)`, see 13.5): the default placeholder config gives 422 `invoice`; a dataset with one field at a time set to a value containing `placeholder` in lower case or mixed case with surrounding spaces (name, each address line, tax_id, email, bank iban, bic, account_holder, bank_name, footer, payment text, phone) gives 422 `invoice`; email `billing@example.invalid` and `x@Y.INVALID` give 422; each required field empty or whitespace-only (name, tax_id, email, footer, payment text, iban, bic, account_holder, all address lines blank) gives 422; empty `phone` and empty `bank_name` do NOT refuse; a fully real-looking config issues successfully (201); VAT rate 0 issues successfully and writes exactly one warning log record per issue (Monolog `TestHandler`); in EVERY refusal nothing is written, `invoice_number_counters` is unchanged and the next valid issue gets the number the refused attempt would have had; in `local` and `testing` the placeholders are accepted and no warning is logged; the basic checks (invalid `vat_rate_bp` -1, 5001, "abc"; terms out of 0 to 120) give 422 `invoice` in every environment including `testing`, nothing consumed; `config('invoicing.eligible_order_statuses')` change is honoured.
22. Order amendments: `GET /orders/{number}` exposes `invoice` (null, or `{id, number, status}` of the non-void invoice) and `can_invoice` (staff/admin only, true only when eligible and not invoiced, always false for customers); order list unchanged; Phase 3 and 4 exact-key tests updated.
23. Seeder: `InvoiceDemoSeeder` twice gives 5 invoices with the numbers of section 11, counter `last_value = 5`, `number_seq` 1..5 contiguous, every invariant holds, Alice/Bob see 4, Carol 1, staff 5; aborts in `production` and `staging`; `DatabaseSeeder` seeds it in `testing`; `invoices:mark-overdue` after seeding materialises `INV-2026-000003`.
24. Dependency sanity: `Barryvdh\DomPDF\Facade\Pdf` resolves; the published config has remote, PHP and JavaScript disabled and a narrow chroot (config assertions).
25. Body-only rule (`ReadsBodyOnly`): `POST .../void?reason=x` with no body reason is 422 `reason`; with a body reason AND a different query reason the body value is stored; `POST .../mark-paid?paid_on=2020-01-01` (no body) marks paid today and `?paid_on=garbage` does not cause a 422; `POST /orders/{number}/invoice?due_date=2030-01-01` and `?due_date=garbage` use the default due date (no 422); the same values in a JSON body and in a form-encoded body ARE honoured; malformed JSON is still 422 `body`.
26. Final states in the database (extends test 14): with raw SQL (`DB::unprepared`) a `paid` row cannot change `status` (to `open`, `void`, `overdue`) or `paid_on` (to NULL or another date) and a `void` row cannot change `status` to `paid`/`open`/`overdue`; each raises; updating only `updated_at` on a `paid` and a `void` row works; the legal transitions `open->paid`, `open->overdue`, `overdue->paid`, `open->void`, `overdue->void` work through both raw SQL and `InvoiceWorkflow`; the workflow itself refuses `paid->void` with 422 `status` before the trigger is reached.
27. Database error handling and logging (`tests/Feature/ErrorLeakTest.php`, global rule, section 13.5 and contract Phase 1 conventions, 500): inside the test transaction add a temporary constraint (Postgres DDL is transactional, so it rolls back with the test; for example a CHECK on the target table that rejects a distinctive marker value) and trigger it through a real API request whose input contains a distinctive marker (an address line, PO reference, tax id or IBAN-like string); assert (a) status 500 and body exactly `{"message":"Server Error"}` with `APP_DEBUG` true AND false, (b) the response body contains no SQL, no marker, no constraint or table name, (c) a Monolog `TestHandler` attached to `Log::getLogger()` recorded exactly one `Database query failed.` record whose context keys are exactly `sqlstate`, `sql`, `connection`, `code`, with `?` placeholders in `sql`, and (d) no record (message or context, any level) contains the marker, the bound values or the driver message text (`SQLSTATE[...]`, `DETAIL:`, `Key (`). Repeat for a non-invoice `api/*` endpoint (for example orders or quotes) to prove the rule is global.
28. Layout (HTML and PDF level, no pdf reader): the header cells carry percentage widths (HTML assertion on the `<th>` style, no `<col>` or `mm` widths); the template emits the last 4 item rows, the totals and the payment block inside ONE row (HTML structure assertion; for an invoice with fewer than 4 items all items are in that row); the footer text container is limited to 72% width and the page-number text is drawn in the right area; the PO reference is cleaned and split into runs of at most 20 non-space characters (59-character PO yields no run above 20); a 300-character footer renders; PDFs for 1, 24 to 31, 100 and 200 items and a VOID invoice render (200, `%PDF-`, page count by the LAST `/Count`). Visual verification is manual (13.3 check 5, PNG render).

### 13.2 Frontend (Vitest + RTL) - `frontend-engineer`

1. Invoice list renders all four statuses with text labels; overdue row shows "Overdue", the warning icon is `aria-hidden`, and "N days overdue" is visible text (not colour only); void is labelled; money formatted from the body currency.
2. Filters update the URL and restore from the URL (`status` CSV, `from`, `to`, `q`, `sort`, `page`); defaults omitted; invalid values dropped; `from` after `to` shows an alert and sends nothing; staff-only Company column and "Overdue" shortcut; customers do not see them.
3. Loading skeleton, empty (with and without filters), error with retry, 429 message with seconds.
4. Download button: click calls fetch with `credentials: 'same-origin'` and `Accept: application/pdf`; on success creates an object URL, clicks an anchor with the filename from `Content-Disposition`, revokes the URL (mock `fetch`, `URL.createObjectURL`, `URL.revokeObjectURL`, anchor click); an unsafe or missing filename falls back to `<number>.pdf`; a non-PDF content type is an error; 404 shows "Invoice not found"; 429 shows the seconds from `Retry-After`; 401 goes to `/login?next=...`; double click sends one request; button disabled while pending; success announced in the live region.
5. Detail page: totals and VAT line, history timeline, order link to `/orders/:number`, hostile strings (`<script>`, `<img onerror>`) rendered as text, buyer and seller blocks, date strings shown unchanged (run with a negative-offset `TZ` and assert `2026-12-26` stays `2026-12-26`).
6. Staff controls come only from `allowed_actions`: customer has no mark-paid or void; staff on a paid or void invoice has neither; Mark paid dialog sends only `{ paid_on }` (date `max` is today); Void dialog requires a reason, shows the warning, sends only `{ reason }`; 422 `status` message shown and refetch; buttons disabled while pending.
7. Order detail: invoice link when `invoice` is set; "Create invoice" only when `can_invoice` is true (never for customers); dialog sends `{}` or `{ due_date }` only; navigates to the new invoice; 422 `order` message shown.
8. Route and nav: "Invoices" is in the nav for all roles; guest is redirected to login; malformed `:id` shows "Invoice not found" without a request; a 404 shows the same page.
9. a11y: labelled filters, dialog focus trap and focus return, table caption, `aria-live` regions.

### 13.3 QA - `qa-engineer`

Run `cd api && ./vendor/bin/pest && ./vendor/bin/pint --test`; `cd web && npm run lint && npm test && npm run build`; confirm CI is green (with the one-line extensions change). Manual, after `php artisan migrate:fresh --seed` (local, so the demo seeders run), `php artisan serve` with `PHP_CLI_SERVER_WORKERS=4`, `npm run dev`:
1. Isolation: Alice sees 4 Northwind invoices, Bob the same 4, Carol 1, staff 5. Alice opens Carol's invoice URL (copy the `id` from staff) -> "Invoice not found"; `curl` with Alice's cookie: detail and PDF of Carol's invoice return 404 with the exact body; `POST .../mark-paid`, `.../void`, `POST /orders/{number}/invoice` as Alice return 403; a guest gets 401 on all six.
2. Real PDF with `curl` (cookie jar from login): `curl -i -b jar .../invoices/{id}/pdf -o out.pdf`; check the five headers, `file out.pdf` says PDF, the filename is `INV-....pdf`, and it opens in a viewer. Repeat for a void invoice (VOID banner) and a paid one.
3. Browser: the Download button saves `INV-....pdf` with a real PDF; in Chrome and Firefox; 429 message after 21 quick downloads (script or repeated click via curl loop for the limit); the button recovers.
4. Parallel creation: create 20 confirmed orders (tinker/factory), fire 20 parallel `POST /orders/{number}/invoice` with staff cookie and XSRF header (`&`), then SQL: 20 distinct invoices, `number_seq` contiguous, counter `last_value` equals the count, every response 201. Repeat with 2 parallel posts for the SAME order: one 201, one 422, one invoice row. Kill the worker mid-run is not required (rollback is covered by the Pest test).
5. PDF layout (the manual gate item): a normal invoice and a 100-line invoice (create via tinker from a factory order with 100 lines), plus invoices of 1 and 24 to 31 items (the page-boundary band), a 300-character footer and a 59-character PO reference, each rendered to PNG and looked at: header, bill-to block, column header repeats on every page, no row split across pages, totals and payment blocks never alone on a trailing page (they stay with the last 4 item rows), "Page X of Y" in the right area and correct on every page, footer text within 72% of the width and never overlapping the page number, nothing overflows the margins. A small VOID invoice may show a mostly empty first page (accepted trade-off).
6. Special characters: a company named `Müller & Söhne <GmbH> "Test" 'x'`, an address with 6 lines, item names with `ä ö ü ß é ñ ł ž € ≥ ≤ ± µ`, Cyrillic and Greek, a 120-character unbroken name, a name with emoji and a right-to-left override, a free-text quote line, a PO `PO-4711/A#1`: no raw HTML shown, no missing-glyph boxes for Latin/Greek/Cyrillic, long strings wrap, emoji appear as a replacement character, nothing breaks the layout.
7. VAT: set `INVOICING_VAT_RATE_BP=1900` locally, issue an invoice for a known order, check subtotal, tax, total on screen and in the PDF against a hand calculation (use F7: lines 3x12.50, 10x3.33, 1x999.99 gives subtotal 1070.79, tax 203.45, total 1274.24); change the rate again and confirm old invoices do not change.
8. Overdue: `INV-2026-000003` is stored `open`: the UI and API show Overdue with days overdue immediately; run `php artisan invoices:mark-overdue`: stored status flips, one system history row; run again: nothing; `schedule:list` shows the command; a due-today invoice is not overdue (create one with a due date of today via the override).
9. Lifecycle: staff creates an invoice from a confirmed order (UI), marks it paid with a date, tries to void a paid one (422 message), voids another with a reason (number stays, customer sees VOID and no reason), cancels an order that has an open invoice (422) and after voiding (works), re-creates an invoice after a void (next number, no gap).
10. Tampering with curl: create with a body full of forged fields, mark-paid with `status`/`total_cents`, void without a reason, `due_date` out of range, malformed JSON: nothing forged is accepted and every failure is 422, never 500.
11. Rolled-back creation: temporarily make the history insert fail (for example run with a DB user lacking INSERT on `invoice_status_history`, or a deliberate throw in a local branch), issue, confirm 500/failure, then restore and issue: the number has no gap.
12. Config: with placeholder seller values and `APP_ENV=production` and again `APP_ENV=staging` locally (APP_DEBUG=false) creation answers 422 `invoice`; with one placeholder field left (for example only the bank BIC) it still answers 422; with real-looking values it works (VAT rate 0 works and logs a warning). Also: `curl -I` on a PDF URL gives 405 with `Allow: GET`; `curl -X POST '.../void?reason=x'` without a body gives 422 `reason`.
13. Clean container: `docker run --rm -v <repo>/api:/app -w /app php:8.3-cli` (after `docker-php-ext-install pdo_pgsql`, Postgres reachable on 5433) runs the PDF tests green with no system fonts.
14. Keyboard-only run through list, filters, download, order create-invoice dialog, mark paid, void; axe scan no serious violations; 375 px layout; screen-reader names.
15. Query count spot check: `/invoices` with 8 vs 200 invoices for one company.
16. Report only; do not fix feature code.

### 13.4 security-reviewer focus

Company scoping on every invoice query (scope + policy), PDF authorized exactly like detail, exact 404 body for foreign/unknown, role middleware before lookup and before limiters, random `public_id` is defence in depth only (numbers are guessable and appear in lists), no numeric ids in URLs or bodies, mass assignment (`forceFill` only with server values; client sends only `due_date`, `paid_on`, `reason`), prices/tax/totals never from the client, snapshot immutability (triggers, no update path), gap-free allocation inside the transaction and its lock order (order row, then counter), double-create race (row lock plus partial unique index), dompdf hardening (remote, PHP, JavaScript off, narrow chroot, no external resources, fonts local), output escaping in the template (no `{!!`), bidi and control-character stripping, filename built only from the validated number, PDF headers (`attachment`, `nosniff`, `no-store`, CSP), PDF rate limit and memory/time bounds (DoS with a 100-line invoice), void reason staff-only, order-cancel guard, effective-status SQL not leaking across companies, the global database-error rule (below, 13.5 and contract Phase 1 conventions, 500): a failed query is logged only as `Database query failed.` with `sqlstate`, placeholder SQL, `connection` and `code`, never bound values or the driver message, and the API answers `500 {"message":"Server Error"}` for any `QueryException` on `api/*` even with `APP_DEBUG=true`; the seller guard (all environments except local/testing); HEAD 405 before render; the body-only rule; the DB trigger for final states.

Guarantee that logs contain no invoice contents (replaces the earlier bare claim "log lines contain no invoice contents or addresses", which nothing enforced): invoice data (names, addresses, PO reference, amounts, void reasons) can reach a log only through (a) a `QueryException` message or its bound values, which the handler never logs (only sqlstate, placeholder SQL, connection and code are; `tests/Feature/ErrorLeakTest.php` proves it with a Monolog `TestHandler` attached to `Log::getLogger()` after a deliberately provoked constraint failure, asserting that neither the bound values nor the driver message appear in any record or in the response), and (b) code that logs request or model data, of which there is none in the invoice code (the only invoice log lines are the seller-guard VAT-rate-0 warning, which names no invoice data, and the generic handler record). The security reviewer re-greps the invoice code for `Log::` and `logger(` calls.

### 13.5 Linux CI hygiene

- No `putenv` or `$_ENV` mutation: config changes use `config([...])` (`invoicing.*`, `app.timezone`, the extra DB connections, `app.env` for the seller guard via `app()->detectEnvironment(fn () => 'production')` or `'staging'`, restored in `afterEach`).
- Tests that provoke database errors (test 27, `ErrorLeakTest`, and the trigger/CHECK tests): they may add a TEMPORARY constraint inside the test transaction; Postgres DDL is transactional, so `RefreshDatabase` rolls it back and no other test sees it. To inspect logs, attach a Monolog `TestHandler` to `Log::getLogger()` (the underlying Monolog logger) in the test and remove it afterwards. Never `putenv` or write to the real log file.
- PHP extensions: `composer.json` does not list `ext-iconv` (it is a transitive platform requirement of `sabberworm/php-css-parser`); the CI `setup-php` extensions line now includes `iconv` next to `pdo_pgsql, mbstring, dom, fileinfo`.
- Production-guard tests (seller guard, test 21) and CSRF: Laravel's `ValidateCsrfToken` skips validation only when the environment is `testing` (`runningUnitTests()`). A test that flips `app.env` to `production` therefore ALSO turns CSRF validation on for its POST, and the request fails with 419 before reaching the guard. The production-guard test (test 21) calls `->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)` on that request (the test is about the 422 `invoice` guard, not CSRF; CSRF itself is covered by the Phase 1 tests). It restores the environment in `afterEach`.
- Global function names in test files: Pest/PHPUnit load every test file into one PHP process, and a plain `function helperName()` declared at file level is a GLOBAL function. Two test files declaring the same name cause a fatal "Cannot redeclare" error for the whole suite. Every file-level helper function must have a name that is unique across the WHOLE suite (prefix it with the feature, for example `invoicePdfMakeInvoice()`, never `makeInvoice()`), or be a closure in a `beforeEach`/`$this` helper, or live in `tests/Pest.php`/a support class once. A quick check before finishing: `grep -rhn "^function " api/tests | sort` shows no duplicate names.
- Key order: `seller`/`buyer` come out of jsonb in a different order (section 12); tests compare key sets or use `assertSame` on a normalised array, never on raw JSON text.
- Time: only `travelTo`/`Carbon::setTestNow` with explicit UTC instants; reset in `afterEach` (`travelBack`); no assertion on "now", no SQL `NOW()`/`CURRENT_DATE`.
- No random-data-dependent assertions: faker output is never asserted; invoice numbers come from the allocator only (sequential and deterministic per test because each test starts from an empty counter table via the transaction rollback); the `public_id` is asserted only by pattern.
- PDF tests need no system binary and no system font: DejaVu from dompdf's own package, `storage/fonts` created by the test bootstrap or the repository (`.gitkeep`), temp dir `sys_get_temp_dir()`. Assertions are on headers, `%PDF-`, `%%EOF`, sizes and rendered HTML, never on exact bytes, never on pdftotext.
- The two-connection concurrency test uses only the counter table and cleans it with `TRUNCATE` in `afterEach`; it must not run in parallel with other DB tests (Pest runs serially here; do not add `--parallel` without making that test's table isolated).
- The performance bounds (30 s, 200 MB) are generous on purpose; they must not be tightened without measuring on a CI runner.
- No test depends on locale (`number_format` is not used; money formatting is integer based), on array order of unsorted queries, on JSON key order, on file name case, or on the PHP default timezone (assert dates as `YYYY-MM-DD` and timestamps as ISO with `Z`).

## 14. File plan (for plan-mode approval)

- api: `composer.json`/`composer.lock` (dependency, after approval); `config/invoicing.php`; `config/dompdf.php` (published, hardened); `.env.example` (`INVOICING_*`); `storage/fonts/.gitignore`; migrations (6, section 3); `app/Enums/InvoiceStatus.php`; `app/Models/{Invoice,InvoiceItem,InvoiceStatusHistory}.php` (+ `Order::activeInvoice/invoices`, `Company::invoices`); `app/Policies/InvoicePolicy.php`; `app/Services/{IssueInvoice,InvoiceNumberAllocator,InvoiceSnapshotBuilder,InvoiceWorkflow,InvoicePdf}.php` (+ `app/Services/AllocatedNumber.php`, `app/Exceptions/InvoiceYearRolled.php`); `app/Support/{InvoiceTotals,Money,PdfText}.php`; `app/Console/Commands/MarkInvoicesOverdue.php` + schedule in `routes/console.php`; `app/Http/Controllers/Api/V1/InvoiceController.php` (index, show, pdf, storeForOrder, markPaid, void); Form Requests `ListInvoicesRequest` (extends the Phase 3/4 list request), `CreateInvoiceRequest`, `MarkInvoicePaidRequest`, `VoidInvoiceRequest` (shared malformed-JSON check from Phase 4); Resources `InvoiceResource`, `InvoiceDetailResource`, `InvoiceItemResource`, `InvoiceHistoryResource`; `OrderDetailResource` (+ `invoice`, `can_invoice`), `OrderController` eager load, `OrderStatusChanger` (cancel guard); `AppServiceProvider` limiters `invoice-pdf` and `invoices-write`; `routes/api.php` (new `{invoice}` UUID route pattern, role groups); `resources/views/invoices/pdf.blade.php`; `resources/pdf/` (empty chroot directory with `.gitkeep`); factories (`InvoiceFactory`, `InvoiceItemFactory`, `CompanyFactory` update); seeders (`InvoiceDemoSeeder`, `DemoSeeder` addresses, `DatabaseSeeder`); tests under `tests/Feature/Invoices/*`, `tests/Unit/{InvoiceTotalsTest,PdfTextTest,MoneyTest,InvoicePolicyTest}`, `tests/Feature/Database/InvoicesSchemaTest`, updated Phase 3/4 tests (order exact keys, detail query bound 11).
- web: `src/features/invoices/*`, orders detail amendments (`invoice`, `can_invoice`, create dialog), nav config, routes and guards, `fetchBlob` helper, tests beside the features.
- outside agent scope (main session or user): `.github/workflows/ci.yml` extensions line, README (placeholder warning, `INVOICING_*`, `schedule:work`, demo invoice numbers, PHP `memory_limit`), `docs/PLAN.md` ticks, `php.ini` on the dev machine, approval of the new dependency.

## 15. Open questions for the user (defaults in brackets; every default is adjustable by config or env and is a PLACEHOLDER until you answer)

1. APPROVE the new dependency `barryvdh/laravel-dompdf` and its transitive packages (section 2.1)? [Yes, needed; the project's approved stack names it.]
2. VAT: what rate, one rate or several, and are catalog and quote prices NET or GROSS? [One rate per invoice, `INVOICING_VAT_RATE_BP=0` (placeholder, NOT a legal rate), prices treated as net, so a non-zero rate makes the invoice total higher than the order total.] Is rounding once on the invoice subtotal, half up, acceptable, or does your jurisdiction require per-line rounding? [Once on the subtotal.] What must the invoice say when the rate is 0 (exemption or reverse-charge wording)? [Nothing beyond the configurable footer.]
3. Seller details: legal name, address, tax/VAT id, bank details, payment terms text, legal footer. [Obvious placeholders; in every environment except local and testing creation is refused while any seller value is a placeholder or a required one is empty (seller guard, section 2.2).]
4. Buyer data: companies get `billing_address` and `tax_id` (migration), set by seeders now and by the Phase 6 admin UI later. Is a billing address REQUIRED to issue an invoice? [Required; tax id optional.] Should Phase 5 include a staff endpoint to edit them? [No.]
5. Which order statuses can be invoiced, and when? [`confirmed`, `shipped`, `delivered`; manual only; no automatic creation.]
6. Payment terms: default 30 days, staff override up to 120 days after the issue date. [As stated.]
7. Number format `INV-YYYY-NNNNNN`, yearly reset, year taken from the UTC issue date. OK, or a different prefix or one continuous series? Does your tax authority impose specific numbering rules? [As stated.]
8. Mistakes: `void` with a reason while open/overdue, number stays consumed, order can be re-invoiced; no credit notes; a PAID invoice cannot be voided and a wrong mark-paid cannot be undone in the portal. Acceptable for v1, or should admins be able to revert a payment? [As stated; correct outside the portal.]
9. Public id: random UUID in URLs, sequential number for display (recommended), versus the number in URLs. [UUID.]
10. PDF generated on demand (default) or stored at issue time? Is a regenerated copy legally acceptable as "the invoice as issued" for you (some jurisdictions require the issued document to be retained unchanged)? [On demand.]
11. Email: no invoice-created email and no overdue reminders in Phase 5. Wanted later? [No.]
12. Time zone for invoice dates: UTC (a date can differ by one day from the seller's local date around midnight). [UTC.]
13. Language and currency: English only; currency copied from the order (single catalog currency). [As stated.]
14. Payments: mark-paid means paid in full on a given date; no partial payments, no payment gateway, no amount stored. [As stated.]
15. DB triggers forbidding DELETE/UPDATE of issued invoices (section 3.6): OK? [Yes; they enforce the legal promises.]
16. The void reason is hidden from customers. OK? [Hidden.]
17. CI: add `mbstring, dom, fileinfo, iconv` explicitly to the `setup-php` extensions line (one-line workflow change, main session). [Yes; done, `iconv` included.]

### 15a. Follow-ups for Phase 7 and Phase 8 (not part of the Phase 5 gate)

1. Database role: run the application's DB role WITHOUT ownership of, and without TRIGGER and TRUNCATE privileges on, `invoices`, `invoice_items`, `invoice_status_history` and `invoice_number_counters` (migrations run under a separate owner role). Otherwise the app could drop or disable the immutability triggers (section 3.6) or truncate the legal record. Phase 8 checklist.
2. Optional PDF caching per (invoice id, status, `updated_at`) (for example in the cache store) to cut rendering cost; only if Phase 7 load tests or the PDF limiters show a need. Never cache across invoices or users, and keep the authorization check before the cache read.
3. Licences: dompdf (LGPL-2.1), `php-font-lib` and `php-svg-lib` (LGPL) require notices and source/relinking terms if the application is ever DISTRIBUTED (shipped to customers rather than run as a hosted service). Add the notices then; legal review.
4. Legal review of the invoice wording, the VAT rate and label, and the numbering format and yearly reset (open questions 2, 3 and 7) before go-live.
5. Phase 7: `composer audit` for dompdf and its dependencies; confirm the DB-error handler (500 `Server Error`, log only sqlstate, placeholder SQL, connection and code) is still in place and still tested.

## 16. Deployment notes (Phase 8)

Checklist (Phase 8 must tick every line):
1. `INVOICING_*` real seller details (`INVOICING_SELLER_NAME`, `INVOICING_SELLER_ADDRESS`, `INVOICING_SELLER_TAX_ID`, `INVOICING_SELLER_EMAIL`, `INVOICING_SELLER_PHONE`, `INVOICING_BANK_HOLDER`, `INVOICING_BANK_NAME`, `INVOICING_BANK_IBAN`, `INVOICING_BANK_BIC`, `INVOICING_PAYMENT_TERMS_TEXT`, `INVOICING_FOOTER`); the seller guard (section 2.2) refuses issuing in production AND staging (every environment except local/testing) while any value is a placeholder, ends in `.invalid`, or a required field is empty; after setting them, issue one test invoice and check the PDF. Legal review of the template, footer and VAT wording before launch.
2. VAT rate: set `INVOICING_VAT_RATE_BP` (and `INVOICING_VAT_LABEL`) to the legally correct value; the default 0 is a placeholder (rate 0 is allowed but logs a warning on every issue outside local/testing).
3. Scheduler: cron entry `* * * * * php artisan schedule:run` so `invoices:mark-overdue` runs hourly (not required for correctness, the API computes the effective status).
4. PHP `memory_limit = 256M` (measured 200-line invoice: about 56 MB extra peak and about 2.1 s; stock 128M fits one request only thinly).
5. `storage/fonts` writable by the PHP user (dompdf writes `*.ufm.json` metric caches there; the directory is git-ignored). Also `extension=mbstring` and `dom` present (gd optional).
6. Backups: the invoice tables are the legal record; a restore from an older backup loses numbers issued after it (document the procedure). Never delete invoice rows; the triggers will refuse.
7. DB role without ownership, TRIGGER and TRUNCATE on the invoice tables (15a item 1); the logs must show only `Database query failed.` records without values (15a item 5).
8. Migrations: `migrate:fresh` does not drop the PL/pgSQL trigger functions (3.6); the trigger migration uses `CREATE OR REPLACE FUNCTION` so re-running is safe.

Other notes:
- Monitor 5xx on `/invoices/*/pdf` (memory/time) and the `invoice-pdf` 429 rate.
- Phase 7 reminders: dedicated limiter review for PDF, `composer audit` for dompdf and its dependencies (dompdf has a history of security advisories: keep it updated), confirm `enable_remote` and `enable_php` stay false.

## 17. Known limitations (accepted for v1)

- No credit notes, partial payments, payment reminders, invoice emails, multiple VAT rates, discounts, shipping lines or multi-currency.
- Right-to-left scripts and emoji are not rendered by the PDF (RTL unshaped, emoji replaced); CJK is not covered by DejaVu Sans.
- The PDF is regenerated on every download; a template change alters how old invoices look (open question 10).
- A mistaken mark-paid cannot be reverted in the portal (also blocked by the final-state trigger).
- A small invoice with a VOID banner can leave a mostly empty first page (consequence of the unsplittable tail row, 9.4 item 5).
- `HEAD` on the PDF route is 405 (use GET).
- A superuser or an app DB role with ownership/TRIGGER/TRUNCATE can still bypass the triggers (15a item 1).
- Parallel creation is proven by the two-connection Pest test for the lock semantics, and by the QA parallel run for real HTTP workers.
