# Phase 6 design - Staff back office (`/admin`)

HTTP contract: `docs/api-contract.md` (Phase 6 section, the single source of truth for backend and frontend). This file holds decisions, schema, algorithms, the frontend and Playwright design, seeders, the test plan, file plan and open questions. Conventions come from Phases 1 to 5 (Sanctum cookie auth, one role per user, role middleware before limiters and lookups, `Model::visibleTo` plus policies, exact 404 body, `TrimStrings`/NUL/control-character rules, status-machine services with history tables, DB triggers for immutability, effective statuses, integer cents with the 10^9 cap, queue + scheduler, Mailpit, private storage, Postgres 16 on 127.0.0.1:5433 with `portal_test`, Linux CI hygiene).

What is NEW in this phase: the `active` middleware and `users` lifecycle (deactivate, invite), an append-only `audit_log` written from the services, optimistic concurrency, a two-step CSV import, a dashboard, the admin SPA area, and the first Playwright package.

## 1. Decisions

| # | Topic | Decision | Why |
| --- | --- | --- | --- |
| 1 | API structure | New `/api/v1/admin/*` ONLY for capabilities that do not exist yet: dashboard, products CRUD/import, companies, users/invitations, audit log. Orders, quotes and invoices REUSE the existing staff endpoints unchanged. | The state machines (`OrderStatusChanger`, `QuoteWorkflow`, `InvoiceWorkflow`, `IssueInvoice`) are the only code allowed to change status. A second `/admin/orders/{n}/status` would be a second door to the same room and would double every test. Wrapping adds nothing. |
| 2 | SPA structure | `/admin/*` layout with its own nav. `/admin/orders`, `/admin/quotes`, `/admin/invoices` (+ detail) mount the EXISTING page components under the admin layout (`basePath` prop so links stay inside `/admin`). Staff controls inside the shared detail pages are KEPT (they come from `allowed_actions` / `allowed_transitions`). Staff/admin top nav becomes Catalog + Back office; `/staff` redirects to `/admin`. Old `/orders`, `/quotes`, `/invoices` URLs keep working for everyone (emails, bookmarks). | One implementation of every detail page, no duplicate UI to keep in sync, no API change. The admin layer only adds navigation context and the lists' company filter. |
| 3 | Roles | Staff: dashboard, products (CRUD, import, activate), orders, quotes, invoices, companies read + billing edit. Admin only: users and invitations, company create/rename, audit log. No hard delete anywhere. | The gate says "only admins manage users and roles". Staff run daily operations and must be able to fix a billing address to issue an invoice (Phase 5 needs it), but renaming companies and the audit log (which records staff behaviour) are management functions. |
| 4 | Audit writes | An explicit `AuditLog::record()` call inside the transaction of each service. NOT model observers. | Observers miss `upsert` (the importer), fire twice on `save()` + `touch()`, run for seeders/factories, and have no actor. An explicit call in the same transaction guarantees "no change without a row, no row without a change" and is testable by rollback. |
| 5 | Invitations | Laravel's password broker, a SECOND broker named `invitations` with its own table `user_invitation_tokens` and a 7-day expiry. No custom token table. | The broker already hashes tokens, enforces expiry, deletes on use and supports "recently created". Reusing it removes a security-sensitive hand-rolled component. A separate broker keeps the 7-day invitation lifetime away from the 60-minute reset lifetime and keeps the two token sets from cross-working. |
| 6 | Deactivation | `users.active` + `users.session_epoch` + an `active` middleware on every `auth:sanctum` route. | The session driver is `file` (no way to delete "all sessions of user X"). A per-request check of two columns on the already-loaded user costs no query and kills sessions immediately with any driver. |
| 7 | Concurrency | `version` integer on `products` and `companies`, bumped by a DB trigger; `PUT` must echo it; stale = 409. | `updated_at` is second-precision (`timestamp(0)`); two edits in one second would not conflict. A trigger also covers the importer's `upsert`. |
| 8 | Dashboard | One endpoint, 4 aggregate queries, no cache. | Live numbers are what staff expect after changing a status (the Playwright flow and daily use); the queries are indexed aggregates over tens of thousands of rows. |
| 9 | Import | Two steps (preview token, apply by token), synchronous, 5 MiB / 5,000 rows via HTTP; larger files use the CLI. | Two steps stop a typo from changing 5,000 prices. Synchronous keeps the design small; the real catalog load is a one-off CLI job. |
| 10 | e2e | Playwright package in `e2e/`, dedicated ports and database `portal_e2e`, `php -S` for the API, Vite dev server for the SPA. | The dev servers use 8000/5173 and the dev database; e2e must never touch them. |

## 2. Authorization

### 2.1 Matrix

| Capability | Guest | Customer | Staff | Admin |
| --- | --- | --- | --- | --- |
| Dashboard | 401 | 403 | yes | yes |
| Products list/create/update/activate/deactivate/import | 401 | 403 | yes | yes |
| Orders: list all, change status (existing endpoints) | 401 | own company only (existing) | yes | yes |
| Quotes: queue and every staff action (existing) | 401 | own company only (existing) | yes | yes |
| Invoices: list, create, mark-paid, void (existing) | 401 | own company, read only (existing) | yes | yes |
| Companies: list, detail, billing edit | 401 | 403 | yes | yes |
| Companies: create, rename | 401 | 403 | 403 | yes |
| Users, roles, invitations, deactivation, reset trigger | 401 | 403 | 403 | yes |
| Audit log | 401 | 403 | 403 | yes |
| Hard delete of products, companies, users, audit rows | - | - | - | does not exist |

### 2.2 Layers

1. Route groups: `role:staff|admin` (tier S) and `role:admin` (tier A), placed AFTER `auth:sanctum` and `active`, BEFORE throttles and bindings. `bootstrap/app.php` priority list becomes: authenticate, `EnsureUserIsActive`, `RoleMiddleware`, then the framework default (throttles, bindings). Route-level `->middleware('throttle:admin-writes')` on every write.
2. Policies (defence in depth for non-HTTP callers and a single place for rules): `ProductPolicy` (`viewAny`, `view`, `create`, `update`, `activate`, `import`: staff or admin), `CompanyPolicy` (`viewAny`, `view`, `updateBilling`: staff or admin; `create`, `update`: admin), `UserPolicy` (everything admin; it does NOT refuse the admin's own row: the own-row and last-admin guards are 422 from `UserAdmin`, see 4.5), `AuditLogPolicy` (`viewAny`: admin). Failure is a plain 403 (`Response::deny()`): staff may see every row, so there is nothing to hide with `denyAsNotFound`; 404 only comes from a missing row. `Gate::policy` registrations are explicit where the name cannot be guessed. No `Gate::before`.
3. Controllers call `$this->authorize()` for every action; Form Requests have `authorize()` returning true (the policy is called in the controller, as in Phases 3 to 5).
4. Mass assignment: `Product::$fillable = []` (already), `Company::$fillable` becomes `[]` (today `['name']`; the admin service uses `forceFill`), `User::$fillable` stays `name, email, password` but NO admin code path calls `fill`, `create` or `update` with request data. A structure test greps `app/Http/Controllers/Api/V1/Admin` and `app/Services/{ProductAdmin,CompanyAdmin,UserAdmin,UserInvitations,ProductImportService}` for `->fill(`, `::create(`, `->update(`, `$request->all()`, `$this->request->get(` and `$request->input()` passed whole to a model.

### 2.3 Route-audit test (cannot forget a route)

`tests/Feature/Admin/RouteAuditTest.php` plus a hand-written matrix `tests/Support/RouteMatrix.php` (a plain array `'METHOD uri' => access`, access one of `public`, `session`, `auth`, `customer`, `staff`, `admin`; `session` means `auth:sanctum` only without `active` (only `POST /auth/logout`, so a stale session can still log out with 204); `auth` means any logged-in role, `customer` means `role:customer`, `staff` means `role:staff|admin`, `admin` means `role:admin`).

1. Enumerate `app('router')->getRoutes()->getRoutes()`, keep URIs starting with `api/`, drop `HEAD`, collect `"{$method} {$uri}"`. Test A: the set equals the matrix keys. A new route that is not in the matrix FAILS the suite, which forces a conscious access decision. A removed route fails too.
2. For every route derive the actual access from `gatherMiddleware()` (`role:staff|admin`, `role:admin`, `role:customer`, `role:customer|staff|admin`, `auth:sanctum`, `active`) and assert it equals the matrix entry. This catches `role:staff|admin` on a route the matrix says is admin-only, and a missing `active`; `session` is `auth:sanctum` without `active`.
3. Every route whose URI starts with `api/v1/admin` must have access `staff` or `admin` (never `auth` or `public`).
4. Request loop with a URI parameter substitution map (`number` to `ORD-AAAAA-AAAAA`, `invoice`, `attachment`, `import` to `00000000-0000-4000-8000-000000000000`, `product`, `company`, `user`, `productId` to `1`; an UNKNOWN parameter name fails the test so the author must extend the map). Send an empty JSON body with the Origin and CSRF-exempt test conventions used by the other feature tests. Expected: guest 401 on everything except `public`; customer 403 on `staff` and `admin` routes; staff 403 on `admin` routes and not 401/403 on `staff` routes; admin not 401/403 on everything except `customer` routes (403); a DEACTIVATED user and a user with a stale `session_epoch` get 401 on every non-`public` route except `POST /auth/logout`; a malformed id (`abc`) is 404 for every role on routes with a path parameter.
5. The loop covers `/staff/ping`, `/admin/ping`, the Phase 3 to 5 staff routes and every Phase 6 route, which satisfies the gate "every /admin endpoint 403 for customers, 401 for guests (loop over all admin routes)" and anticipates the Phase 7 route-audit gate.

## 3. Database

Migrations, in this order, after `2026_11_29_100005`: conventions as before (named CHECK constraints via `DB::statement`, RESTRICT foreign keys, no soft deletes, `bigint` money, UTC).

### 3.1 `2026_11_30_100000_add_admin_columns_to_users`

| Column | Type | Notes |
| --- | --- | --- |
| `active` | boolean NOT NULL DEFAULT true | existing rows become active |
| `session_epoch` | integer NOT NULL DEFAULT 1 | CHECK `session_epoch >= 1`; bumped by deactivate, reactivate, assignment, invitation accept and a completed password reset (same save as the password) |
| `invited_at` | timestamptz NULL | set by invite and resend |
| `invitation_accepted_at` | timestamptz NULL | CHECK `invitation_accepted_at IS NULL OR invited_at IS NOT NULL` (`users_invitation_consistent`) |
| `invited_by_user_id` | bigint NULL | FK `users(id)` ON DELETE RESTRICT |

Derived status (one method `User::status()`): `inactive` if `NOT active`; else `pending` if `invited_at IS NOT NULL AND invitation_accepted_at IS NULL`; else `active`. `User::canSignIn()` = status `active`. Pending users have a random 64-byte password nobody knows, so even a bug cannot let them in through the password path. Indexes: none new (`users` stays small; `email` unique and `company_id` index exist). Last-admin count: `active AND NOT pending` users with role admin.

### 3.2 `2026_11_30_100001_create_user_invitation_tokens_table`

Same shape as `password_reset_tokens`: `email` varchar PRIMARY KEY, `token` varchar (hashed by the broker), `created_at` timestamp NULL. `config/auth.php`: `passwords.invitations = ['provider' => 'users', 'table' => 'user_invitation_tokens', 'expire' => env('INVITATION_EXPIRE_MINUTES', 10080), 'throttle' => 60]`.

### 3.3 `2026_11_30_100002_add_version_to_products_and_companies`

`version integer NOT NULL DEFAULT 1` with CHECK `version >= 1` on both tables, plus one function and two triggers:

```
CREATE OR REPLACE FUNCTION bump_row_version() RETURNS trigger ... NEW.version := OLD.version + 1; RETURN NEW;
CREATE TRIGGER products_bump_version  BEFORE UPDATE ON products  FOR EACH ROW EXECUTE FUNCTION bump_row_version();
CREATE TRIGGER companies_bump_version BEFORE UPDATE ON companies FOR EACH ROW EXECUTE FUNCTION bump_row_version();
```

Every UPDATE bumps, including the importer's `upsert` (an unchanged row re-imported also bumps; the cost is a false conflict for an editor who loaded the form before the import, which is correct: the row was rewritten). The service skips the UPDATE entirely when nothing changed (Eloquent `isDirty()` false), so a no-op `PUT` does not bump. `down()` drops the triggers, the function and the columns. Also here: `CREATE INDEX products_updated_at_idx ON products (updated_at)` and `CREATE INDEX products_inactive_part_idx ON products (part_number_search) WHERE NOT active` (the Phase 2 trigram and filter indexes are partial `WHERE active`, so inactive rows are scanned; the second index keeps the "inactive only" filter and the dashboard count cheap).

### 3.4 `2026_11_30_100003_create_audit_log_table`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigserial PK | |
| `occurred_at` | timestamptz(6) NOT NULL | set by the application from `now()` (so frozen test clocks work), UTC |
| `actor_user_id` | bigint NULL | FK `users(id)` ON DELETE RESTRICT; NULL = system |
| `actor_role` | varchar(16) NOT NULL | `customer|staff|admin|system`; role AT THE TIME; CHECK `(actor_user_id IS NULL) = (actor_role = 'system')` |
| `action` | varchar(64) NOT NULL | CHECK `action ~ '^[a-z_]+\.[a-z_]+$'`; values from the vocabulary in the contract |
| `subject_type` | varchar(16) NOT NULL | CHECK in (`order`,`quote`,`invoice`,`product`,`company`,`user`,`import`) |
| `subject_id` | varchar(64) NOT NULL | the PUBLIC identifier: order number, quote number, invoice `public_id` UUID, import UUID; numeric id as text for product, company, user (these have no public id and are only addressed by staff) |
| `subject_label` | varchar(120) NULL | display snapshot (number, part number, company name, user email); truncated |
| `company_id` | bigint NULL | FK `companies(id)` ON DELETE RESTRICT; the subject's company when it has one |
| `changes` | jsonb NOT NULL DEFAULT '{}' | `{"before":{...},"after":{...}}`, allowlisted keys only; CHECK `jsonb_typeof(changes) = 'object'` and `octet_length(changes::text) <= 16384` |
| `metadata` | jsonb NOT NULL DEFAULT '{}' | allowlisted keys only; same two CHECKs |

Indexes: `(occurred_at DESC, id DESC)` default list; `(actor_user_id, occurred_at DESC)`; `(subject_type, subject_id, occurred_at DESC)`; `(company_id, occurred_at DESC) WHERE company_id IS NOT NULL`; `(action, occurred_at DESC)`.

Trigger (pattern of the invoice triggers): `audit_log_forbid_change()` raising an exception, `BEFORE UPDATE OR DELETE ON audit_log FOR EACH ROW`. TRUNCATE is not blocked (migrate:fresh and test cleanup). Privacy: the table stores a user email as `subject_label` for user rows and NO IP address or user agent (decision, open question 7). Retention: kept indefinitely in v1; the DELETE trigger means a future retention purge needs a privileged role (Phase 8 decision, noted in section 14).

Redaction is an ALLOWLIST per subject type in `App\Support\AuditRedactor` (the table in the contract). Anything not on it is dropped silently before the insert and again when the resource is built, so a future developer who passes a model's `getAttributes()` cannot leak `password`, a token or a note.

### 3.5 `2026_11_30_100004_create_product_imports_table`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | uuid PK | the public token (UUID v4, generated in PHP) |
| `user_id` | bigint NOT NULL | FK `users(id)` RESTRICT; only this user can apply |
| `original_name` | varchar(120) NOT NULL | sanitised display name |
| `storage_path` | varchar(255) NULL UNIQUE | random name on disk `imports`; NULL once applied or expired |
| `size_bytes` | integer NOT NULL | |
| `sha256` | char(64) NOT NULL | |
| `status` | varchar(10) NOT NULL | CHECK in (`previewed`,`applied`,`expired`) |
| `rows_read`, `would_create`, `would_update`, `bad_rows` | integer NOT NULL | |
| `errors` | jsonb NOT NULL DEFAULT '[]' | first 100 `{line, messages[]}`; CHECK array |
| `warnings` | jsonb NOT NULL DEFAULT '[]' | |
| `result` | jsonb NULL | `{created, updated, bad_rows}` after apply |
| `expires_at` | timestamptz NOT NULL | created + 1 hour |
| `applied_at` | timestamptz NULL | CHECK `(status = 'applied') = (applied_at IS NOT NULL)` |
| `created_at`, `updated_at` | timestamps | |

Indexes: `(user_id, created_at)`, `(status, expires_at)` for the prune command. `config/filesystems.php`: disk `imports`, root `storage/app/private/imports`, `serve => false`, `visibility => private`. Command `imports:prune` (hourly in `routes/console.php`, `withoutOverlapping`): previewed rows past `expires_at` get status `expired`, their file is deleted, `storage_path` NULL. Not audited.

### 3.6 Not changed

`companies` gets no `active` column and no unique name (decision 6 in section 12). `orders`, `quote_requests`, `invoices` already have the indexes the lists and the dashboard need: `orders (status, created_at)`, `quote_requests (status, created_at)` and the partial `quote_requests (valid_until) WHERE status = 'quoted'`, `invoices (status, due_date)`. The backend agent runs `EXPLAIN` on the four dashboard queries and the three company count subqueries with 5,000 rows and records the result in the PR; add an index only if a sequential scan shows up there.

## 4. Services and algorithms

### 4.1 `App\Services\AuditLog`

`record(string $action, string $subjectType, string $subjectId, ?string $label, ?User $actor, array $before = [], array $after = [], array $metadata = [], ?int $companyId = null, bool $always = false): void`. The service is `AuditLog`; the Eloquent model is `App\Models\AuditLogEntry` (table `audit_log`).

1. Runs `AuditRedactor::filter($subjectType, ...)` on before, after and metadata (allowlist, scalar or null values, strings cut at 200).
2. For updates only the keys whose value changed are kept in both `before` and `after`; creates have `before = {}`; because unchanged keys are dropped, `quote.sent` has `before` = `{status, revision}` only and `valid_until`/`total_cents` appear only in `after`; a call with empty changes AND empty metadata writes nothing (a no-op change has no row) unless `$always` is true, which events with no changes by nature pass (`user.password_reset_requested`, `user.invitation_resent`, `user.invitation_accepted`).
3. `actor_role` comes from `$actor?->primaryRole()` (new `User` helper reading the single loaded role) or `system` when there is no actor. The actor is ALWAYS passed explicitly by the calling service (never read from `auth()`), so queue jobs and tests behave the same.
4. Inserts with `occurred_at = now()->utc()`. It must be called inside an open `DB::transaction` (it asserts `DB::transactionLevel() > 0` and throws otherwise, so a future caller cannot write an orphan row). A failing insert fails the whole change on purpose. The static `AuditLog::$baseTransactionLevel` (default 0) is a TEST SEAM: tests that run inside the wrapping test transaction set it to that level so the "must be inside a transaction" assertion still means "inside the service's own transaction". Tests log in through the overridden `TestCase::actingAs()`, which also stores the session epoch and role in the session (as login does), so `active` and the `api` limiter see the same state as a real session.

Call sites (each inside the existing transaction, after the change is saved and, where there is one, after the history row): `OrderStatusChanger::change` (`order.status_changed`); `QuoteWorkflow::run` (a map from `QuoteStatus::ACTIONS` action to audit action for every action with a target status: review, send, reject, reopen, accept, decline; `price` is audited separately as `quote.priced` by `QuotePricing`; `attach` and `note` are not audited); `IssueInvoice` (`invoice.created`); `InvoiceWorkflow::markPaid` and `void` (`markOverdue` is system and not audited); the new `ProductAdmin`, `CompanyAdmin`, `UserAdmin`, `UserInvitations`, `ProductImportService`. The backend agent verifies where `AcceptQuote` and the customer decline path call the workflow so each of them is audited exactly once.

Customer-visible timelines (`order_status_history`, `quote_status_history`, `invoice_status_history`) are unchanged and remain the customer's view. The audit log is the staff and admin record and also holds things the history tables do not (price edits, user management, imports).

### 4.2 Products (`ProductAdmin`, `ProductRules`)

- Validation lives in `StoreProductRequest` and `UpdateProductRequest` (the latter adds `version`), both built from one `ProductRules` class that the importer also uses (part number pattern and normalisation via `PartNumber::normalise`, name/description formula-prefix rule, `TEXT_PATTERN` for brand and material, mm bounds via `Mm`, seal and stock enums, price cap `Product::MAX_PRICE_CENTS`, XOR of `price_cents` and `price_on_request`). One rule set means a value accepted by the form is accepted by the CSV and vice versa.
- Uniqueness: the Form Request checks `part_number_search` (computed in PHP, ignoring the row itself on update) for a friendly 422; the unique index is the backstop and the service catches SQLSTATE `23505` on `products_part_number_search_unique` and turns it into the same 422 `part_number` (a race must not become the global 500).
- Update: `DB::transaction`, `lockForUpdate`, compare `version` (409), `forceFill` the allowlisted fields, save only when dirty, audit with the diff (price changes are visible as `price_cents` before/after), `DB::afterCommit(CatalogCache::flush)` (flush after commit so a concurrent request cannot re-cache pre-change data, same rule as the importer).
- Activate/deactivate: same transaction pattern, no version input, idempotent.
- Catalog behaviour after deactivation (all already implemented in Phases 2 to 5, now tested end to end): list, detail (404), filters and category counts omit it (cache flushed); `POST /cart/items` and `POST /quotes` lines refuse it with 422 "The selected product is not available."; cart lines already holding it stay and report `unavailable_reason: "inactive"`; checkout refuses them with the per-line message; reorder skips them (`inactive`); orders, quotes and invoices keep their snapshot lines (`product_id` stays a RESTRICT foreign key, so a referenced product could not be deleted anyway: there is no delete endpoint).
- Staff product list: `GET /admin/products` over all products; `q` reuses `ProductSearch` normalisation; default sort `part_number` (uses the unique index); eager load `category` (3 queries).

### 4.3 CSV import (`ProductImporter`, `ProductImportService`)

Refactor, behaviour-preserving: move the logic of `App\Console\Commands\ImportProducts` into `App\Services\ProductImporter::run(resource $handle, bool $dry, int $maxRows): ImportReport` (a readonly value object: `read`, `created`, `updated`, `bad`, `errors` as a list of `{line, messages[]}` capped at 100, `warnings`) and move `ImportFatal` next to it. `ImportProducts` becomes a thin command that opens the file, calls the service (inside `DB::transaction` unless `--dry-run`), flushes the cache after commit and prints EXACTLY the current output (bad rows as `line N: a; b`, the warning lines, the `Rows read: ...` summary, exit codes 0/1/2). The Phase 2 tests for the command must pass unchanged; that is the refactor's safety net. The CLI keeps its 100,000-row limit; the HTTP path passes `config('admin.imports.max_rows')` (5,000).

Preview (`POST /admin/products/imports`): `UploadProductImportRequest` checks the file part and extension; `AttachmentInspector`-style content check (a `text()` mode that allows `text/csv`, `text/plain`, `application/csv`, valid UTF-8 with BOM allowed, no NUL); size cap; then `ProductImportService::preview` stores the file on disk `imports` under a random name, computes SHA-256, runs `ProductImporter` with `dry = true` (no product write), inserts the `product_imports` row (`status = previewed`, `expires_at = now + 1 h`) and returns the report. Any `ImportFatal` or row-limit problem is 422 `file` and nothing is stored.

Apply (`POST .../{import}/apply`): look up `ProductImport::where('user_id', $user->id)->find($id)` (404 otherwise); inside `DB::transaction`: `lockForUpdate` the import row; refuse (422 `import`) unless `status = previewed` and `expires_at` is in the future; verify `sha256` of the stored file; refuse `skip_invalid = false` when `bad_rows > 0` (422 `skip_invalid`); run the importer with `dry = false`; set `status = applied`, `result`, `applied_at`, `storage_path = NULL`; audit `product.imported` with metadata `rows_read`, `created`, `updated`, `bad_rows`, `file_name` (the sanitised display name), `sha256`, `applied_rows`, `skipped_rows` (allowlist in `AuditRedactor`; file contents are never stored). After commit: flush the catalog cache, delete the file. Idempotency / double apply: the row lock plus the `previewed` check mean a double click or replay is serialised and the second one is 422 `import`; if the transaction fails the status stays `previewed` and the user can retry. Re-importing the same CSV through a NEW preview is still idempotent by the upsert (Phase 2 rule).

`active` column bug fix (CLI and HTTP, same importer): an import whose CSV header has no `active` column never changes `active` on existing products; inserts default to true. When the column exists it is honoured as before. The CLI output is unchanged except for this fix.

Audit gap (known, Phase 7 follow-up): the import writes ONE `product.imported` row and NO per-product audit rows for the products it created or changed, because that is unbounded (up to `max_rows` rows in the transaction). Price changes made by an import are therefore visible only in aggregate. Phase 7 decides between a bounded sample, a per-import detail table or a chunked audit job.

Formula safety: the importer's existing rules stay (name and description must not start with `= + - @ tab CR`; part number, brand and material patterns exclude those characters). Messages in the preview use the importer's `show()` sanitising (printable ASCII, 64 characters) and the SPA renders them as text. Phase 7 still owns CSV EXPORT escaping (there is no export in this phase).

### 4.4 Companies (`CompanyAdmin`)

`create`, `update` (admin), `updateBilling` (staff and admin) all run in a transaction with `lockForUpdate`, compare `version`, `forceFill` the allowlisted columns (`name`, `billing_address`, `tax_id`), audit (`company.created` or `company.updated`; billing address recorded as `billing_address_changed` only), and never touch invoices (Phase 5 `buyer` snapshots are independent; a test renames a company and edits its address and asserts an existing invoice, its PDF HTML and its list `company.name` are unchanged). `BillingAddress` support class normalises (CRLF to LF, trim lines, drop blanks, max 6 lines of 80, no control or format characters; the "500 total" rule is kept as documentation only: 6 x 80 plus 5 line breaks is at most 485 characters, so it is unreachable) and is the same rule the invoice issuing check already expects (more than 6 lines is refused there too). A company is never deleted or deactivated (to cut a company off, deactivate its users).

List and detail counts are correlated subqueries (`withCount`-style raw selects with the effective-status predicates from the contract), so the list is 3 queries however many rows it has. `recent` is three small `limit 5` queries.

### 4.5 Users, sessions, invitations (`UserAdmin`, `UserInvitations`, `EnsureUserIsActive`)

`EnsureUserIsActive` (alias `active`): after authentication, if `! $user->canSignIn()` or (the request HAS a session and `$request->session()->get('session_epoch') !== $user->session_epoch`; a request without a session skips the epoch comparison and only the active/pending check applies), then `Auth::guard('web')->logout()`, `session()->invalidate()` and throw `AuthenticationException` (401 JSON, the existing handler). `AuthController::login` stores `session_epoch` after `regenerate()` and refuses non-`active` users with the same wrong-credentials error after the same hash check (and counts the failure in both limiters). The one-time effect of the missing stored value on sessions created before this phase: everyone signs in again once after deployment (accepted, documented). The login also stores the user's role in the session (used by the `api` limiter, 4.7). `POST /auth/logout` is `auth:sanctum` only, WITHOUT `active`, so a stale session can still log out with 204; the RouteMatrix labels it `session`.

`UserInvitations::invite(User $admin, array $data)`: in one transaction create the user with `forceFill` (name, lowercase email, `password => Str::random(64)` hashed by the cast, `active` true, `invited_at`, `invited_by_user_id`) and set role and company through a new `User::assign(string $role, ?Company $company)` (extracted from `User::provision`, which now calls it, so the invariant "customer needs a company, staff and admin have none, exactly one role" lives in one method), create the token with `Password::broker('invitations')->createToken($user)`, audit `user.invited`. After commit queue `UserInvitationMail` (queued, `ShouldBeEncrypted` so the token is not readable in the `jobs` table; VERIFIED: `ShouldBeEncrypted` works on queued mailables in the installed Laravel 12.74.0, no fallback is needed, and a test asserts the `jobs` payload contains no `token=`. The mail text does not include the invited user's name, which avoids markdown injection into the mail template; the Phase 1 `ResetPasswordNotification` is now `ShouldBeEncrypted` as well, so no queued payload in `jobs` contains a token; this closes the Phase 7 item "encrypted reset notification"). Link: `config('app.web_url').'/accept-invitation?token='.$token.'&email='.urlencode($email)`. `resend` checks `getRepository()->recentlyCreatedToken($user)` (60 s throttle, 422 `user`), calls `createToken` again (it deletes the old token), sets `active` true and `invited_at` now, audits. `revoke` sets `active` false, `deleteToken`, bumps the epoch, audits.

Accept (`AcceptInvitationController`, request class mirrors `ResetPasswordRequest`): `Password::broker('invitations')->reset($credentials, $callback)`. Single use under concurrency: the callback calls `App\Services\SingleUseToken::consume()`, which runs INSIDE the broker callback in one transaction: lock the user row (`lockForUpdate`), re-check the token under the lock, delete it, then apply the change. `pending` is re-checked under the same lock, so accept is serialised with revoke and deactivate (a revoke that commits first makes the accept fail; an accept that commits first makes the revoke refuse). The same `SingleUseToken::consume()` wraps the Phase 1 password reset (broker `users`), whose callback additionally bumps `session_epoch` in the same save as the password. The callback refuses with the dedicated exception `App\Exceptions\InvitationNotPending` when the user is not pending and active; the controller maps that exception (and only that one, plus the broker's non-success statuses) to the generic 422. Otherwise it `forceFill`s password, `invitation_accepted_at`, `email_verified_at`, `session_epoch + 1`, and audits `user.invitation_accepted` with the user as actor. Database and audit failures are NOT swallowed: they propagate as the global generic 500 and roll the transaction back (the token is not consumed, so the user can retry). Of N parallel requests with the same token exactly one gets 200 and every other gets the identical generic 422. Every expected failure maps to the single generic message so a caller cannot tell unknown, expired, used, revoked or deactivated apart. Because the broker status is the only difference between outcomes and all non-success statuses are mapped to one 422, response bodies are byte-identical; a test compares them.

Forgot-password parity: `ResetPasswordNotification::shouldSend()` returns false for users that are not `active`, so deactivated and pending users receive nothing while the endpoint still answers its constant 200 without an early return (timing unchanged). Deactivation deletes the user's rows in `password_reset_tokens` and `user_invitation_tokens`. `ResetPasswordNotification` is queued AND `ShouldBeEncrypted` (like `UserInvitationMail`), so the `jobs` payload holds no token. Completing a password reset (self-service, or after an admin-triggered link) bumps `session_epoch` in the same `save()` as the password: every old session dies on its next request, and the user logs in again with the new password (the reset itself does not log in).

Input shape (every admin write endpoint, 4.8 below): all requests read input through `bodyInput()`; none calls `$this->request->get()`, which throws `BadRequestException` (400) for array values. Every string field answers an array, object, nested array, number, boolean or null with 422 keyed by that field. The apply endpoint's route binding returns 404 before validation runs.

Last-admin lock. Only `UserAdmin::assign` (moving a user's role away from admin) and `UserAdmin::deactivate` can reduce the number of active admins; reactivate, invitation accept, invite, revoke, resend, rename, password reset and company changes cannot, so they take no part in it. Both operations serialise on ONE transaction-level advisory lock, in this order inside the transaction: (1) `SELECT pg_advisory_xact_lock(UserAdmin::ADMIN_SET_LOCK)` with `ADMIN_SET_LOCK = 7206001`; (2) re-read the admin set (`active AND NOT pending` users with role admin) in a SEPARATE statement, so the read sees everything committed before the lock was granted (a row-lock-and-count in one statement would use a snapshot taken before the wait); (3) lock the target row (`lockForUpdate`) and re-read it. There is no per-row lock on the admin set: one advisory lock guards the whole set. Guarantee: under concurrency a role change or deactivation can never leave zero active admins; of two simultaneous mutual demotions (or deactivations) exactly one succeeds and the other gets the 422. The lock is released at commit or rollback.

`UserAdmin` actions, all in a transaction:

- Self protection: acting on your own row for assignment or deactivation is 422 `user`, raised by `UserAdmin` (the policy does not refuse it). The last-admin rule (422) is unreachable over HTTP for ONE acting admin (the actor is never the target, so another active admin remains), but reachable by two admins at once, which is exactly what the advisory lock serialises. It is tested at service level (an actor who is not in the admin set, or a reduced set) AND with real parallel processes (9.1 item 11). Both rules are tested separately.
- `assign`: invariants through `User::assign`, `session_epoch + 1`, `cart_items` of that user deleted, audit with before/after `role` and `company_id`. Existing orders and quotes are left alone.
- `deactivate`/`reactivate`/`resetPassword`/`update` as in the contract. Password-reset trigger: `Password::broker('users')->sendResetLink(['email' => ...])`, status `RESET_THROTTLED` maps to 422 `user`; it never sets a password.
- Email is immutable (open question 5): avoids the account-takeover class "change the email, then reset the password" and removes the verified-state question. To fix a typo while pending: revoke, then invite the right address.

### 4.6 Dashboard (`DashboardStats`)

Four queries, each a single row of conditional aggregates (`count(*) FILTER (WHERE ...)`, `coalesce(sum(...) FILTER (...), 0)`), bound parameters only, `today` and `week_start` computed in PHP from `now()->utc()` (`startOfWeek(Carbon::MONDAY)`), never `CURRENT_DATE`/`NOW()`:

1. `quote_requests`: open (`status IN ('submitted','in_review')`), awaiting (`status = 'quoted' AND valid_until >= :today`), expiring (the same AND `valid_until <= :today7`).
2. `orders`: `this_week` (`created_at >= :week_start`), `pending`.
3. `invoices`: overdue count and sum (`status = 'overdue' OR (status = 'open' AND due_date < :today)`), due soon count and sum (`status = 'open' AND due_date BETWEEN :today AND :today7`).
4. `products`: `count(*) FILTER (WHERE NOT active)`.

Staff and admin get the same payload. No cache (a cache would show a stale count right after a status change and would need invalidation on 15 write paths). The query-count test fixes the bound at 4 plus the session user and asserts it is equal for 3 and 300 rows.

### 4.7 Rate limiters (`AppServiceProvider`)

`api` becomes `role in (staff, admin) ? 240 : 60` (decision 4, open question 4). The role is read from the value stored in the session at login, NOT from the role middleware (which does not run on catalog or `/me` routes) and not from a role query. A role change bumps `session_epoch`, which kills the session, so the stored role cannot go stale. A request without a session (guest) gets 60. New: `admin-writes` (60/min per user id), `admin-import` (5/min), `admin-import-hourly` (20/h), `admin-invites` (20/h), all via the existing `limit()` helper so 429 keeps the standard body and `Retry-After`.

### 4.8 Input shape and request reading

Every string field of every admin write endpoint (products, imports apply, companies, users, invitations, assignment) answers an array, object, nested array, number, boolean or null (when not nullable) with 422 keyed by the field name, never 400 and never 500. Mechanism: Form Requests and services read input only through the shared `bodyInput()` helper; `$this->request->get()` is banned because Symfony throws `BadRequestException` for non-scalar values (a 400). The structure test of 2.2 item 4 also greps for `->request->get(`. Routing: the `{import}` binding on the apply endpoint is resolved (404) before validation, so a foreign or unknown import never produces a 422 oracle.

## 5. Config, env, CI, docker

- New `api/config/admin.php`: `imports.max_rows` (env `ADMIN_IMPORT_MAX_ROWS`, 5000), `imports.max_bytes` (env `ADMIN_IMPORT_MAX_BYTES`, 5242880), `imports.preview_minutes` (60). `.env.example` gains three keys: `INVITATION_EXPIRE_MINUTES=10080`, `ADMIN_IMPORT_MAX_ROWS` and `ADMIN_IMPORT_MAX_BYTES`.
- `config/auth.php`: second password broker `invitations` (3.2). `config/filesystems.php`: private disk `imports` (3.5).
- `config/database.php`: the `pgsql` connection sets `'timezone' => 'UTC'` because the new `timestamptz` columns are written without an offset; the session time zone must be UTC so stored instants are correct.
- `Company::$fillable` is `[]` and `DemoSeeder` uses `forceFill` for companies (it relied on mass assignment of `name` before).
- Importer refactor: `ImportProducts` keeps its own per-row message texts so the CLI output stays byte-identical; the validation patterns and the formula-prefix rule are shared through `App\Support\ProductRules` (used by the Form Requests and the importer).
- Scheduler: `imports:prune` hourly (`routes/console.php`).
- Docker: `docker/postgres/init/01-create-test-db.sql` also creates `portal_e2e`; volumes created earlier need `docker compose exec db psql -U portal -d portal -c "CREATE DATABASE portal_e2e;"` (README line). Main session task.
- `phpunit.xml`: no change (`memory_limit` stays 256M; the route-audit loop issues about 80 requests per role, each cheap).
- php.ini: no new requirement (upload limits from Phase 4 cover the 5 MiB CSV; `memory_limit = 256M` from Phase 5).
- CI: the `api` and `web` jobs are unchanged and stay fast; a new parallel `e2e` job (section 8.8).
- New dependencies: `@playwright/test` in `e2e/package.json` only (needs the user's approval, CLAUDE.md "ask before adding a dependency", open question 1). Nothing in `api/` or `web/`.

## 6. Frontend design (`web/src/features/admin/`)

Owner: `frontend-engineer`. Only `web/` changes (plus one line in `vite.config.ts`, section 8.3).

### 6.1 Routing, guards, navigation

- `AppRoutes.tsx`: inside `ProtectedRoute`, `<Route path="/admin" element={<RoleRoute roles={['staff','admin']} />}>` containing `AdminLayout` and the staff-level children; a nested `<RoleRoute roles={['admin']} />` holds `users` and `audit-log`. `/staff` becomes `<Navigate to="/admin" replace />`; `StaffPage` and the old `AdminPage` are deleted (the dashboard replaces them). `PingStatus` use may stay in the dashboard footer for the Phase 1 demo or be removed.
- `nav.ts`: top nav for staff and admin = Home, Catalog, Back office (`/admin`); Orders, Quotes, Invoices, Cart become customer items. New `ADMIN_NAV_ITEMS` (Dashboard, Orders, Quotes, Invoices, Products, Companies, Users with `roles: ['admin']`, Audit log with `roles: ['admin']`) rendered in a `nav` with `aria-label="Back office"`.
- The existing `OrdersPage`, `QuotesPage`, `InvoicesPage` and their detail pages accept a `basePath` (default `''`) so row links go to `${basePath}/orders/${number}`; `AdminLayout` mounts them at `/admin/orders`, `/admin/quotes`, `/admin/invoices` (+ detail routes). Admin defaults when the URL has no filter: orders `status=pending&sort=created_at` (oldest pending first, applied as a redirect to the explicit URL, not as hidden state), quotes `status=submitted,in_review&sort=created_at`, invoices `status=open,overdue&sort=due_date` (overdue first, because overdue invoices have the oldest due dates). A "Company" filter (combobox backed by `GET /admin/companies?q=`) sets `company_id` in the URL (the three existing pages already send `company_id` for staff; the URL parameter becomes visible for the admin variant only).
- Order detail status control (new, shared page): for staff and admin, one button per `allowed_transitions` entry ("Mark as confirmed", "Mark as shipped", "Mark as delivered", "Cancel order") opening the shared `ConfirmDialog` (button "Confirm"); sends `PATCH /orders/{number}/status` with `{ status }` only; 422 `status` text shown in the dialog and the order refetched; on success the detail query is updated from the response. Quote and invoice detail already have their staff controls (Phases 4 and 5) and gain only the `basePath` links, plus links between records (order to quote and invoice, invoice to order, quote to order) that already exist.
- Accept-invitation page `/accept-invitation` (guest-only), a sibling of `ResetPasswordPage` sharing the password form component; posts to `/auth/accept-invitation`; success sends to `/login` with a status message; the generic 422 message is shown.
- Token hygiene (accept-invitation AND reset-password pages): the page reads `token` and `email` from the query string once, copies them into component state and replaces the URL (history `replace`, path without query) so no token or email stays in the address bar or in the history entry; submitting uses the state values. `web/index.html` has `<meta name="referrer" content="origin">` so the link cannot leak through Referer before the replace. The real response header `Referrer-Policy: origin` is a Phase 7/8 item (section 14).

### 6.2 Reusable table and URL state

- `components/DataTable.tsx` (generic, controlled): props `caption` (required, rendered as `<caption>`), `columns` (`{ key, header, sortKey?, render }`), `rows`, `rowKey`, `sort` (`'name' | '-name' | ...`), `onSortChange`, `page`, `lastPage`, `total`, `onPageChange`, `isLoading`, `error`, `onRetry`, `emptyMessage`. A sortable header is a `<button>` inside the `<th>` with `aria-sort` (`ascending`, `descending`, `none` on the others); the first click sorts ascending, the second descending (`-key`), the third returns to the default; the current sort is announced by `aria-sort`, not by colour. Pagination is a `<nav aria-label="Pagination">` with Previous and Next buttons (disabled at the ends, never removed), "Page X of Y" and the total in an `aria-live="polite"` region. States: skeleton rows (`aria-busy`), empty message with a "Clear filters" action when filters are active, error with Retry. The page resets to 1 when a filter or the sort changes.
- `lib/listUrlState.ts`: `useListUrlState(spec)` where `spec` lists `{ name, parse, default }` per parameter; reads `useSearchParams`, drops invalid values, omits defaults when writing, never writes `per_page`, replaces (not pushes) history for typing in `q` (300 ms debounce, the catalog's `useDebouncedCallback`), pushes for sort and page. Used by products, companies, users, audit and the admin company filter.
- Vitest gate "tables sort and paginate": click a sortable header, assert `aria-sort`, the URL (`sort=-name`) and the request; click Next, assert `page=2` in the URL and the request; restore state from a URL; the page returns to 1 after a filter change.

### 6.3 Pages

- Dashboard: eight cards (open quotes, awaiting customer, expiring soon, orders this week, pending orders, overdue invoices with the formatted total, due soon with total, inactive products) each a link to the filtered list from the contract; skeleton, error with retry; `currency` from the body.
- Products: `DataTable` with filters (`q`, `active`, `category`, `brand`, `stock_status`), inactive rows marked with a text badge "Inactive" (not colour only), row action links; `ProductFormPage` (create and edit) with React Hook Form + Zod mirroring the server rules (part number uppercased on blur, price as dollars with the `^\d{1,7}(\.\d{1,2})?$` pattern converted to cents, "Price on request" checkbox that clears and disables the price field, outer greater than inner); server 422 keys map to fields; edit sends `version`; on 409 a `role="alert"` banner with "Reload latest" (refetch, reset the form, keep the user's unsaved values in a collapsed "Your changes" list so they can re-apply them); Activate/Deactivate buttons with a confirm dialog; deactivate text explains what customers will see.
- Import wizard `/admin/products/import`: step 1 file input (`accept=".csv,text/csv"`, label, size hint) and "Upload and check"; step 2 preview: counts (rows, would create, would update, bad rows), a table of line-numbered errors with a caption (first 100 plus "and N more"), warnings, an `expires_at` note; if `bad_rows > 0` a checkbox "Skip the N invalid rows" must be ticked before "Apply import" is enabled; the apply button opens a confirm dialog showing the counts; step 3 result with created/updated/bad and a link to the product list. Expired or already-applied (422 `import`) shows the message with "Upload again". The upload is a `FormData` POST with the `X-XSRF-TOKEN` header and no manual `Content-Type` (Phase 4 pattern); 413 shows "The file is too large for the server."; focus moves to the step heading on every step change.
- Companies: list (`q`, sort name/created, counts columns, "Billing missing" text badge when `has_billing_address` is false); detail with the billing form (`PUT .../billing`, `version`, 409 banner), the users table and recent activity links; admins also see an "Edit company" form (name) and a "New company" page.
- Users (admin only): `DataTable` with `q`, `role`, `status`, company filter; "Invite user" dialog (name, email, role, company select shown only for `customer`); per-row actions from `allowed_actions` (Assign role/company dialog, Deactivate and Revoke with confirm dialogs, Reactivate, Resend invitation, Send password reset); the invite response `meta.email_queued = false` shows a warning "Saved, but the email could not be queued. Use Resend."; a pending user shows a "Pending invitation" badge.
- Audit log (admin only): `DataTable` (caption "Audit log"), filters (action multi-select from the vocabulary, subject type, subject id, company, actor, from, to), each row shows time (UTC, ISO string formatted without `new Date` timezone shifts), actor name and role, action label, subject (linked to the admin page of the order, quote, invoice, product, company or user when it exists), and a "Details" disclosure rendering `changes` as a before/after list (text nodes).

### 6.4 Accessibility and quality

Every page has one `h1` that receives focus on route change (`tabIndex={-1}`); forms have visible labels and `aria-describedby` errors; tables have captions; sort buttons expose `aria-sort`; dialogs reuse `ConfirmDialog` (focus trap, Esc, focus returns); contrast at least 4.5:1; keyboard-only operable; status and counts are text first, colour second. TypeScript strict, no `any`, Zod schemas for every response in `features/admin/schemas.ts`.

## 7. Seeders (local and testing only)

`AdminDemoSeeder` (called by `DatabaseSeeder` after the invoice seeder; aborts outside `local` and `testing` like `DemoSeeder`; idempotent):

- Users (password `Demo-Passw0rd!`): `admin2@portal.test` (admin) and `staff2@portal.test` (staff) so the last-admin rule never blocks demos; `dave@northwind.test` (customer, Northwind, `active = false`, an INACTIVE user); `erin@initech.test` (customer, Initech, PENDING: `invited_at` set, `invitation_accepted_at` null, a token created through the `invitations` broker whose plaintext is not shown; use Resend in the UI to get a mail).
- Company `Initech Bearings` WITHOUT billing address (shows the "Billing missing" badge and the 422 `company` path when invoicing).
- Three INACTIVE products with part numbers `DEMO-INACTIVE-1` to `DEMO-INACTIVE-3` (created with the factory-free `Product::forceCreate`; one of them added to the existing Alice demo order is NOT done: deactivated-but-ordered is built inside the Pest test).
- NO audit rows are seeded: they come from actions. Seeders never call `AuditLog`.

Test fallout the backend agent must handle: tests that assert the exact user, company or product counts after `DatabaseSeeder` (Phases 2 to 5 seeder tests) are updated; the catalog tests assert on ACTIVE counts, which the inactive products do not change.

## 8. Playwright end-to-end (`e2e/`)

Ownership: `qa-engineer` writes everything under `e2e/` (its instructions: Playwright tests only in `e2e/`). Wiring outside `e2e/` is split: the main session edits `.github/workflows/ci.yml`, `docker/postgres/init/`, `README.md` and `.gitignore`; `frontend-engineer` makes the small `web/vite.config.ts` change (8.3); the user approves the new dependency first (open question 1).

### 8.1 Package and layout

```
e2e/
  package.json            devDependencies: @playwright/test (pinned minor); scripts: test, test:headed, report
  package-lock.json
  tsconfig.json
  playwright.config.ts
  global-setup.ts
  helpers/env.ts          ports, URLs, DB name, demo credentials (one place)
  helpers/api.ts          apiLogin(request, email), csrf helpers, createProduct()
  tests/order-status.spec.ts
  tests/admin-guards.spec.ts
  .gitignore              node_modules, playwright-report, test-results, .auth, .data
  README.md               how to run, prerequisites
```

`cd e2e && npx playwright test` (CLAUDE.md) works once `npm ci` and `npx playwright install chromium` have been run in `e2e/`; the config and `webServer` entries start everything.

### 8.2 `playwright.config.ts`

- `testDir: './tests'`, `fullyParallel: false`, `workers: 1` (one database shared by all tests), `retries: process.env.CI ? 1 : 0`, `forbidOnly: !!process.env.CI`, `timeout: 30_000`, `expect.timeout: 7_000`.
- `use: { baseURL: 'http://localhost:5273', trace: 'retain-on-failure', screenshot: 'only-on-failure', video: 'off', channel: process.env.E2E_CHANNEL || undefined }`. One project `chromium` (headless is the default; no firefox/webkit). `E2E_CHANNEL=msedge` runs the installed Edge when the bundled browser is blocked (8.7).
- `reporter`: `[['list'], ['html', { open: 'never' }]]` locally; in CI add `['github']`.
- `globalSetup: './global-setup.ts'`.
- `webServer` (two entries, started before `globalSetup`):
  1. API: `command: 'php -S 127.0.0.1:8100 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'`, `cwd: '../api/public'` (no `-t`: the router script does `require getcwd().'/index.php'`, so it must run with cwd `api/public`; verified: `/api/v1/health` 200, dashboard 401), `url: 'http://127.0.0.1:8100/api/v1/health'`, `reuseExistingServer: !process.env.CI`, `timeout: 60_000`, `env` (below). The backend or QA agent verifies the router script path in the installed Laravel (the file `artisan serve` uses). `php -S` is used instead of `artisan serve` on purpose: `artisan serve` only forwards a fixed list of environment variables to its child, so `DB_DATABASE=portal_e2e` could be silently dropped and the tests would hit the dev database. With `php -S` the child inherits the real environment, which Laravel's immutable env loader prefers over `.env`. On Linux CI add `PHP_CLI_SERVER_WORKERS=4` (not supported on Windows; the SPA fires parallel requests but tests are serial, so one worker suffices locally).
  2. Web: `command: 'npm run dev -- --port 5273 --strictPort'`, `cwd: '../web'`, `url: 'http://localhost:5273'`, `reuseExistingServer: !process.env.CI`, `env: { API_PROXY_TARGET: 'http://127.0.0.1:8100' }`.
- API `env`: `APP_ENV=local` (the demo seeders only run in `local` and `testing`), `APP_DEBUG=false`, `APP_URL=http://127.0.0.1:8100`, `APP_URL_WEB=http://localhost:5273`, `SANCTUM_STATEFUL_DOMAINS=localhost:5273`, `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_PORT=5433`, `DB_DATABASE=portal_e2e`, `DB_USERNAME=portal`, `DB_PASSWORD=secret` (the public docker-compose dev credentials, same as CI), `SESSION_DRIVER=database` (isolated in the e2e database and persisted between `php -S` requests), `CACHE_STORE=array` (rate limiters and catalog cache are inert, so the 60/min limits cannot make the suite flaky; limits are proven by Pest), `QUEUE_CONNECTION=sync`, `MAIL_MAILER=log`. `APP_KEY` comes from `api/.env` locally (the developer has one) and from `php artisan key:generate` in CI.

### 8.3 Web change (frontend-engineer, one line)

`web/vite.config.ts`: `proxy` targets read `process.env.API_PROXY_TARGET ?? 'http://localhost:8000'` for `/api` and `/sanctum`. Default behaviour is unchanged; the e2e server gets its own proxy target and port (`--port 5273 --strictPort` on the command line).

### 8.4 Data and re-runnability

`global-setup.ts`, in order:
1. Safety guard: run `php artisan tinker --execute="echo DB::connection()->getDatabaseName();"` with the e2e env and abort unless the output is exactly `portal_e2e` (a reseed must never reach the dev database even if the environment is mis-set).
2. `php artisan migrate:fresh --seed --force` with the same env (demo users and catalog; also clears sessions and the e2e database). Every run therefore starts from the known demo state; a failed earlier run leaves nothing behind.
3. Log in as `customer`, `staff`, `admin` through the API (8.5) and save `storageState` files under `e2e/.auth/` (git-ignored). Demo accounts: `alice@northwind.test`, `staff@portal.test`, `admin@portal.test`, password `Demo-Passw0rd!` (published demo credentials, local/testing only).
4. As staff, create the test product through the new endpoint (`POST /api/v1/admin/products`: part number `E2E-0001`, in stock, price 1250, existing category) and write its id and part number to `e2e/.data/product.json`. This exercises the new API on every run and does not depend on the random catalog seed.

Tests create unique data per test (the PO reference includes `testInfo.testId` and a timestamp) and always find their records by the order number read from the UI, never by position in a list, so a retry or a second test cannot collide.

### 8.5 Login

Fast path for every test: `storageState` from step 3 (API login: `GET /sanctum/csrf-cookie`, read the `XSRF-TOKEN` cookie, URL-decode it, `POST /api/v1/auth/login` with header `X-XSRF-TOKEN` and `Origin: http://localhost:5273`, JSON body). One dedicated test (`login through the UI`) uses the real form with `getByLabel` and a role-based button, proving the SPA login path; it does not reuse `storageState`. The two-actor test creates two browser contexts from two state files.

### 8.6 Tests

`tests/order-status.spec.ts` - "staff changes order status, customer sees it" (the PLAN gate), all waits are on responses or visible state, never `waitForTimeout`:

1. Customer context (alice): `goto('/catalog?q=E2E-0001')`; click the "Add to cart" button of that row (`getByRole('row', { name: /E2E-0001/ }).getByRole('button', { name: /add to cart/i })`) while waiting for the `POST /api/v1/cart/items` response (200).
2. `goto('/checkout')`, fill the "PO reference" field with a unique value, click the place-order button while waiting for the `POST /api/v1/orders` response (201). `waitForURL(/\/orders\/ORD-[A-Z0-9-]+$/)`; capture the order number from the URL.
3. Assert in the region named "Order summary" the status text "Pending", and the list named "Status history" has one item.
4. Staff context (staff): `goto('/admin/orders?status=pending&q=<number>')`; click the link with the order number; `waitForURL('/admin/orders/<number>')`; click "Mark as confirmed", then "Confirm" in the dialog while waiting for the `PATCH .../orders/<number>/status` response (200); assert the summary now says "Confirmed".
5. Customer page: `reload()`; assert the summary says "Confirmed" and the "Status history" list has two items, the last mentioning "Confirmed" (staff name is masked for customers, so it is not asserted).
6. Audit: admin context calls `GET /api/v1/admin/audit-log?action=order.status_changed&subject_id=<number>` through `request` with its state; asserts exactly one entry, `actor.name = "Sam Staff"`, `actor.role = "staff"`, `changes.before.status = "pending"`, `changes.after.status = "confirmed"`. Then one UI assertion: admin opens `/admin/audit-log?...` and sees the row.
7. Negative checks in the same file: the customer calling `PATCH /api/v1/orders/<number>/status` through `request` gets 403, and a second confirm attempt by staff (button no longer offered; API call) is 422.

`tests/admin-guards.spec.ts` - smoke path for guards: guest opens `/admin` and lands on `/login?next=%2Fadmin`; customer opens `/admin` and sees the "Not allowed" heading (no admin nav); staff sees the dashboard, the "Back office" nav has no "Users" or "Audit log" link, and `/admin/users` shows "Not allowed"; admin sees both links and the users table; the customer's `request.get('/api/v1/admin/dashboard')` is 403, a guest's is 401; a deactivated user: the seeded inactive `dave@northwind.test` (section 7) cannot sign in through the UI and sees the same wrong-credentials message as an unknown email.

Selectors: roles, labels and accessible names only (the E2E hooks in the contract); no CSS selectors, no `nth` except on lists with a count assertion; `data-testid` is not used.

### 8.7 Running on the user's Windows machine

Prerequisites: Docker Desktop with `docker compose up -d db` and the `portal_e2e` database (README line), PHP 8.3 on `PATH` (already needed), `api/.env` with an `APP_KEY`, Node (already needed). First run: `cd e2e && npm ci && npx playwright install chromium` downloads the browser (about 150 to 300 MB) into `%USERPROFILE%\AppData\Local\ms-playwright`. Verify, as with the earlier Windows Application Control block on `pdo_sqlite`: after install run `npx playwright test --list` and then one test headed (`npx playwright test --headed -g "login through the UI"`); if the policy blocks the downloaded browser executable, set `E2E_CHANNEL=msedge` (uses the Edge that ships with Windows 11, no download) and report the result. Ports 8100 and 5273 must be free (the dev servers on 8000 and 5173 are not touched). The suite is destructive only for `portal_e2e`.

### 8.8 GitHub Actions `e2e` job

A separate job in `.github/workflows/ci.yml` (main session), parallel to `api` and `web`, so those stay fast. Pin every action to a commit SHA like the existing jobs (the main session looks up the current SHAs, including `actions/upload-artifact` v4):

1. `services.postgres`: `postgres:16`, `POSTGRES_DB: portal_e2e`, user `portal`, password `secret`, port `5433:5432`, the same health check as the `api` job.
2. `checkout`; `setup-php` (8.3, extensions `pdo_pgsql, mbstring, dom, fileinfo, iconv`); `composer install --no-interaction --prefer-dist` in `api`; `cp .env.example .env && php artisan key:generate` in `api`.
3. `setup-node` (24, `cache: npm`, `cache-dependency-path` listing `web/package-lock.json` and `e2e/package-lock.json`); `npm ci` in `web` and in `e2e`.
4. `npx playwright install --with-deps chromium` in `e2e` (installs the OS libraries on `ubuntu-latest`).
5. `npx playwright test` in `e2e` with `CI: true` (the config starts both servers, `globalSetup` migrates and seeds).
6. `if: failure()` (or `always()` for the report): `actions/upload-artifact` of `e2e/playwright-report/` and `e2e/test-results/` (traces), retention 7 days.

Flakiness rules (all in the design, enforced in review): no `waitForTimeout`/sleeps; wait on `waitForResponse`/`waitForURL`/web-first assertions (`expect(locator).toBeVisible()` retries); one worker; one retry in CI only; unique data per test; no dependency on list order; the demo data is deterministic because `migrate:fresh --seed` runs first; limiters are inert (array cache); trace on first retry or failure for diagnosis.

## 9. Test plan

Order: the PLAN gate items first (in PLAN order), then extras. Every new endpoint is tested for guest 401, customer 403, staff and admin (staff 403 on tier A), a malformed id 404, and 429 where a limiter exists. Helper function names are prefixed (`admin...`) to stay unique across the suite (section 9.5).

### 9.1 Backend (Pest) - `backend-engineer`

Gate items:
1. Route audit (2.3): the loop over ALL routes; guest 401, customer 403, staff 403 on tier A, deactivated 401, matrix equality, `/admin` routes have a role. Files: `Admin/RouteAuditTest.php`, `Support/RouteMatrix.php`.
2. Audit rows with actor, one assertion per action, each asserting `action`, `actor_user_id`, `actor_role`, `subject_type`, `subject_id`, `company_id` and the exact `changes`: order status by staff, by admin, and customer cancel (actor role `customer`) through BOTH the PATCH and the cancel endpoints; every quote action (review, price, send, reopen, reject, accept, decline); invoice create, mark-paid, void; product create, update (price before/after), activate, deactivate, import; company create, update, billing update; user invite, resend, revoke, accept, update, assignment, deactivate, reactivate, password-reset request. Refused transitions (422) and no-ops write NO row. Rollback test: force an exception after the audit call (a `creating` listener on the history model or a throwing closure in `QuoteWorkflow::run`'s `$effects`) and assert no audit row AND no change; and the converse: an audit insert that fails (temporary CHECK inside the test transaction) rolls the change back.
3. Only admins manage users and roles: every `/admin/users*`, invitation, assignment, deactivate, reactivate, reset and audit endpoint is 403 for staff and customers; admin works; staff cannot create or rename companies but CAN edit billing; a staff user cannot give themselves the admin role by any request (mass-assignment test: body with `role`, `company_id`, `active`, `session_epoch` on `PATCH /admin/users/{id}` and on every staff-reachable endpoint is ignored).
4. Part-number uniqueness and deactivation: create duplicate (`6205-2RS` vs `6205 2rs` vs `6205/2RS`, and `-` characters) is 422 `part_number` on create and update (also against an INACTIVE product); update keeping its own number is fine; the unique-index race path (insert a row between validation and save with a hook) is 422 not 500; deactivate hides the product from list, detail (404 with the exact body), filters and category counts for customers, staff and admin; the cache keys are flushed on create, update, activate, deactivate and import (prime the cache, change, assert fresh); a product on an old order, quote and invoice still shows in each with the snapshot; the cart line becomes `unavailable_reason: inactive`, checkout 422, reorder skips with `inactive`; quote creation with it is 422; reactivation restores everything.
5. Dashboard definitions: frozen clock; boundary tests at Sunday 23:59:59 UTC vs Monday 00:00:00 UTC for `orders.this_week` and `week_start`; quotes (submitted, in_review counted open; quoted past `valid_until` not awaiting; expiring exactly at today+7 and today+8); invoices (stored `open` past due counted overdue before the command ran, due today not overdue, due in 7 days counted, in 8 days not; paid and void excluded; sums); inactive products; staff and admin get identical payloads; query count bound 4 (+ session user), equal for 3 and 300 rows.

Extras:
6. Products CRUD and validation: every field rule (bounds 0, 5000.01, three decimals, outer less than or equal inner, brand/material pattern, formula prefixes on name and description, control and format characters, price 10^9 and 10^9+1, `price_on_request` XOR in both directions, enum values, unknown category, 65-character part number); optimistic concurrency (stale `version` 409 with the contract body and nothing written; missing or text `version` 422; two sequential updates; no-op PUT does not bump the version and writes no audit row; the trigger bumps on direct SQL and on the importer's upsert); snapshots unaffected (change price/name, old order, quote and invoice unchanged); `PUT` never changes `active`; list filters, `active=false`, sort whitelist (all 10 values plus an invalid one 422), pagination, query count bound 3 constant.
7. Import: the Phase 2 command tests unchanged (refactor safety net) plus service unit tests; preview endpoint: valid file counts, bad rows with line numbers (a quoted multi-line field keeps numbering), warnings for unknown columns, `errors_truncated` above 100, a preview writes NO product row and flushes nothing; rejects wrong extension, HTML/exe/zip content renamed `.csv`, NUL, invalid UTF-8, empty file, header without a required column, 5,001 rows, over 5 MiB (`UploadedFile::fake()->createWithContent`, `Storage::fake('imports')`), 413 path; apply: applies exactly the previewed valid rows, idempotent upsert (apply two different previews of the same data: same product count), `skip_invalid` required when bad rows exist, second apply 422 `import`, apply by another user 404, apply after expiry (frozen clock) 422, tampered file (change bytes on the fake disk) 422, audit row with counts and the version bump (metadata keys exactly `rows_read`, `created`, `updated`, `bad_rows`, `file_name`, `sha256`, `applied_rows`, `skipped_rows`; no file contents anywhere; NO per-product audit rows, asserted as the documented gap), a CSV without an `active` column never changes `active` of existing products (an inactive product stays inactive after re-import) and inserts default to true, a CSV with the column still sets it, the CLI test for the same case, cache flushed after commit, file deleted after apply, transaction rollback leaves the import `previewed`; `imports:prune` expires and deletes; rate limits (5 per minute and 20 per hour, every attempt counts); formula-prefixed name rejected in the preview.
8. Companies: create (admin), rename (admin only, staff 403), billing edit by staff; billing rules (7 lines, 81-character line, 501 characters, control characters, CRLF normalisation, empty to null; tax id pattern and 33 characters); 409 on stale version; editing billing address or renaming after an invoice exists leaves the invoice, its `buyer`, its PDF HTML and the invoice list `company.name` unchanged; counts (users, open orders, open quotes by effective status, unpaid invoices by effective status) with fixtures per state; detail `users` and `recent` limits (6 orders gives 5); list query count constant; list `q` with `%`/`_` literal; no delete or deactivate route exists (405/404).
9. Users: list/filter/sort/paginate (each whitelist value, `role` and `status` CSV, `q` over name and email with wildcards literal), query count constant, never any password or token field in any response (key-set test on every resource); invite: customer needs an existing company, staff/admin with a company 422, duplicate email case-insensitive 422 (including a pending and a revoked user), email lowercased, queued mail asserted with `Mail::fake` (one `UserInvitationMail` to the invited address, link host from `config('app.web_url')` even when the request has a hostile `Host`/`X-Forwarded-Host` header, token present in the link), mail failure (a throwing queue fake) still 201 with `meta.email_queued = false`; `invite` rate limit 20 per hour; accept: token works once (second use identical generic 422; and under real concurrency, see item 11), a database or audit failure inside accept is a 500 with rollback and the token still usable (not swallowed into the generic 422; `InvitationNotPending` is the only exception mapped to the generic 422), expires at `INVITATION_EXPIRE_MINUTES` (frozen clock at 10079 and 10081 minutes), wrong email, unknown email, revoked, deactivated, accepted-already all return the IDENTICAL status and body (assert equality of the JSON bodies), password rules (min 10, confirmation), accept sets `invitation_accepted_at` and `email_verified_at` and does not log in, the user can then log in, a reset token cannot be used as an invitation token and vice versa; resend invalidates the old token, 60-second throttle; revoke kills the token and the login; assignment: all role/company invariants (customer to staff clears the company and the cart lines, staff to customer needs a company, customer to customer moves company), exactly one role afterwards, no-op writes nothing, orders and quotes keep their company; the last-admin guard cannot be hit by one acting admin over HTTP (the actor is never the target), so it is tested at service level (a pending or inactive admin does not count) and under real concurrency by two admins at once (item 11); self-assignment and self-deactivation are 422 over HTTP (raised by the service); sessions: a deactivated user's existing session gets 401 on the next request (log in, deactivate through the admin, reuse the same session cookie), role change and company change bump `session_epoch` and force 401, reactivation does not resurrect old sessions (stale epoch), login of an inactive or pending user is the wrong-credentials 422 and counts toward the limiter, forgot-password for them sends nothing with the identical 200 body, `reset-password` with an old token fails after deactivation; a completed password reset (self-service and after an admin-triggered link) bumps `session_epoch` in the same save: the old session gets 401 on its next request and login with the new password works; the queued `ResetPasswordNotification` payload in `jobs` contains no token (like the invitation mail); password-reset trigger: queued reset mail for an active user, 422 for inactive/pending and within 60 s; email immutability (PATCH ignores `email`); mass assignment (`role`, `company_id`, `active`, `session_epoch`, `password`, `invited_by_user_id` ignored on PATCH and on every non-explicit field).
10. Audit viewer: admin only; filters (`actor_id`, `action` CSV, `subject_type`, `subject_id`, `company_id`, `from`/`to` inclusive UTC, `from` after `to` 422, unknown action 422), sort both ways with id tie-break, pagination `meta`, query count bound 4 constant for 3 and 300 rows; the key set of an entry; redaction: insert through `AuditLog::record` with hostile keys (`password`, `token`, `remember_token`, `internal_note`, `reason`, `description`, `billing_address`, `email_body`) and assert they are in neither the table nor the response; hand-insert a row with a forbidden key via SQL and assert the resource drops it; `JSON` never contains `password`, `token` or `session_epoch` across the full action suite (scan every audit row produced by test 2); no IP or user agent column exists; the DB immutability trigger: UPDATE and DELETE raise (raw SQL), TRUNCATE is not tested; CHECK constraints reject a bad action format, a bad actor/role pair and an oversized `changes`; `AuditLog::record` outside a transaction throws.
11. Concurrency and locks, proven with REAL parallel processes (race tests, see 9.5 for how they run): (a) last admin: two admins demote each other at the same moment (`assign`), and the same for `deactivate`: exactly one succeeds, the other gets the 422, and afterwards exactly one active admin remains (never zero); a mixed run (one assign, one deactivate) behaves the same; (b) single-use tokens: N parallel `accept-invitation` requests and N parallel `reset-password` requests with the same token give exactly one 200 and identical generic 422 bodies for the rest, the password is the one from the winner and the token rows are gone; (c) accept versus revoke and accept versus deactivate in parallel: the final state is consistent (never an accepted user who is also revoked and able to sign in); (d) `version` checks after a simulated concurrent update using a second connection (as in the Phase 5 counter test); sequential double-apply and double-deactivate idempotency. The advisory lock constant is asserted (`UserAdmin::ADMIN_SET_LOCK === 7206001`) and a second connection that tries `pg_try_advisory_xact_lock(7206001)` inside an open `assign` transaction fails to get it.
12. Rate limits: `api` 61st request is 429 for a customer and NOT for a staff user until 241; `admin-writes` 61st write 429 (every attempt counts); role 403 before 429 on `/admin` routes; import and invite limiters as above.
13. Phase 3 to 5 regression: status change, quote actions, invoice create/pay/void still return the same bodies and now write audit rows; additive filters `expiring_within_days` and `due_within_days` (boundaries, invalid 422, customers see only their own company's rows, status semantics); `PATCH /orders/{number}/status` by a customer is still 403; seeder tests updated; `AdminDemoSeeder` run twice gives the same rows, aborts in `production` and `staging`, creates no audit rows.
14. Schema tests (`Database/AdminSchemaTest`): `users` defaults and CHECKs, `products.version` and `companies.version` trigger bump on every UPDATE, `product_imports` CHECKs, `audit_log` CHECKs and triggers, indexes exist (`pg_indexes`).
14a. Input shape: for EVERY string field of EVERY admin write endpoint (products create and update, imports apply `skip_invalid`, companies create/update/billing, users invite/update/assignment, invitation actions, accept-invitation) a table-driven test sends an array, an object, a nested array, a number, a boolean and `null` (where not nullable) and asserts 422 keyed by that field, never 400 or 500; a structure test asserts no `$this->request->get(` in `app/Http/Requests` and the admin controllers (everything uses `bodyInput()`); the apply endpoint answers 404 for a foreign, unknown or malformed import even when the body is invalid (binding before validation).
15. Global rules still hold: a `QueryException` on an admin endpoint is `{"message":"Server Error"}` with nothing leaked (reuse `ErrorLeakTest` technique on `POST /admin/products` with a marker); 404 body exact on every missing id; no admin endpoint returns 500 for any input in the validation tests.

### 9.2 Frontend (Vitest + RTL) - `frontend-engineer`

1. Admin routes guarded: guest to `/login?next=/admin/...`; customer sees "Not allowed" on `/admin`, `/admin/products`, `/admin/users`; staff sees the dashboard, does NOT see Users or Audit log in the Back office nav and gets "Not allowed" on `/admin/users` and `/admin/audit-log`; admin sees both; `/staff` redirects to `/admin`; the top nav for staff has Back office and no Orders/Quotes/Invoices items, customers have no Back office item.
2. Tables sort and paginate (the gate): `DataTable` header buttons toggle `aria-sort` asc, desc, default; URL receives `sort`; Next/Previous update `page`; disabled at the ends; state restores from the URL; invalid URL values dropped; page resets on filter change; caption present; result count in a polite live region; loading, empty (with and without filters), error with retry and 429 message.
3. Product form: mirrors the server rules (price field format and conversion to integer cents, price-on-request disables price, outer greater than inner, part number rule); submits only documented fields (no `active` on edit; `version` on edit); 422 errors mapped to fields including `part_number`; 409 shows the conflict banner and "Reload latest" refetches and resets; activate/deactivate confirm dialogs.
4. Import wizard: upload sends `FormData` with `file` and no manual content type; preview renders counts and line-numbered errors as text (hostile strings stay text); apply disabled until "Skip invalid rows" is ticked when `bad_rows > 0`; confirm dialog; success summary; 422 `import` expired/applied shows the message with "Upload again"; 413 message; double click sends one request.
5. Users: list filters and actions strictly from `allowed_actions`; invite dialog (company select only for customer, validation, 422 `email` shown, `email_queued: false` warning); assignment dialog; deactivate/revoke confirm; own row has no destructive actions; `/accept-invitation` page (reads token and email, password rules, generic 422 message, success goes to login).
5a. Token hygiene (accept-invitation and reset-password pages): after render, `window.location.search` is empty and `history.state`/the current entry holds no token or email (the URL was replaced); the request still sends the token and email that were copied into state; a re-render or a failed submit (422) does not need the query string; a test reads `web/index.html` and asserts `<meta name="referrer" content="origin">`.
6. Dashboard: cards render the values and link to the exact URLs of the contract; money from the body currency; skeleton and error states.
7. Audit log page: filters sync to the URL; rows render changes as text; hostile `subject.label` rendered as text; links to subjects.
8. Order detail status control: buttons exactly from `allowed_transitions`, confirm dialog, request body `{ status }` only, 422 message shown and refetch, hidden for customers (they never get the buttons because `allowed_transitions` for them has no staff items; a customer only sees Cancel when allowed).
9. a11y: labels on every field, `aria-sort`, dialog focus trap and return, `h1` focus on navigation, captions.

### 9.3 Playwright - `qa-engineer`

Section 8.6 (gate scenario + guard smoke). QA also runs the whole gate (`pest`, `pint --test`, `npm run lint`, `npm test`, `npx playwright test`) and reports per gate item.

### 9.4 QA manual checks (report only, no feature fixes)

1. Browser walkthrough as staff, admin and customer: navigation differs; staff cannot reach Users and Audit; admin can; refresh keeps the session; keyboard-only run through a product edit, the import wizard and a status change; axe scan; 375 px layout.
2. Parallel edits: open the same product in two browsers, save in A, save in B (conflict banner, nothing overwritten), reload, re-apply; same for a company billing address.
3. Import with a bad CSV (formula-prefixed name, a 12.50 price, unknown category, duplicate part number, wrong header, 5,001 rows, a renamed `.exe`, a UTF-16 file): messages and line numbers are correct; apply with and without skipping; apply twice by double click; apply in two tabs; expiry after an hour (clock or edit `expires_at`).
4. Invitation round trip via Mailpit (http://localhost:8025): invite, open the link, set a password, log in, check the role and company; reuse the link (generic error); resend (old link dies); revoke; the link in the mail uses `APP_URL_WEB`, also when the API is called with a different Host header (curl `-H 'Host: evil.test'`).
5. Deactivate a logged-in user in another browser and watch the session die on the next click (401 to login); reactivate and log in again; change a logged-in customer to staff and see the forced re-login; deactivate the last other admin and try to demote yourself (all refused); complete a password reset in browser A and see browser B's old session get 401 on the next click while the new password logs in. The accept-invitation and reset-password pages show no token or email in the address bar or in the history after load. (The last-admin race and token single use are automated in 9.1 item 11; QA only spot-checks them.)
6. Audit: perform one action of every kind and read the log; confirm there are no secrets, tokens or notes in any `changes`; try `DELETE FROM audit_log` and an `UPDATE` in psql (refused).
7. Dashboard numbers against hand counts in psql around the week boundary and with an overdue invoice that the command has not yet materialised.
8. Customer isolation unaffected: Alice and Carol still see only their own company; Alice calling `/api/v1/admin/...` with curl gets 403 for every URL in the route list; a deactivated product is 404 for a customer by URL.
9. `curl` tampering on admin writes (forged `role`, `company_id`, `active`, huge `version`, malformed JSON): nothing forged is accepted, no 500.
10. e2e on the Windows machine (8.7) including the browser download and the `E2E_CHANNEL=msedge` fallback if blocked; CI e2e job artifact on a deliberately failing run.
11. Query-count spot check of dashboard and lists with 8 vs 5,000 rows; `EXPLAIN` of dashboard queries.

### 9.5 Linux CI hygiene (all agents)

- Race tests (9.1 item 11): they COMMIT real data (they cannot run inside the wrapping test transaction because the child processes use their own connections), so each one TRUNCATEs `users`, `companies`, `roles`, `permissions`, `audit_log` and the two token tables (`password_reset_tokens`, `user_invitation_tokens`) before and after, and re-seeds roles and permissions. They spawn `php tests/Support/race_child.php` with `PHP_BINARY`; the child REFUSES to run against any database whose name does not end in `_test` (so it can never truncate or write the dev or e2e database). They need the real Postgres test database (`portal_test`) and a usable `PHP_BINARY`; they run in the normal `pest` suite on Windows and on Linux CI (no skip flag), and must not run in parallel with other DB tests.

- No `putenv` or `$_ENV` mutation: `config([...])`, `app()->detectEnvironment(...)` restored in `afterEach`; any test that flips `app.env` to `production` also needs `withoutMiddleware(ValidateCsrfToken::class)` (Phase 5 note).
- File-level helper function names unique across the whole suite, prefixed `admin...`, `auditLog...`, `userInvite...`, `productImport...`; check `grep -rhn "^function " api/tests | sort` for duplicates.
- Time only through `travelTo`/`Carbon::setTestNow` with explicit UTC instants, reset in `afterEach`; no `NOW()`/`CURRENT_DATE` in assertions; week-boundary tests use fixed instants.
- No assertion on faker output, random tokens, UUIDs (pattern only), auto-increment ids (use returned ids), unsorted query order, JSON key order (jsonb reorders keys: compare key sets), or locale. Invitation tokens are taken from the faked mail's rendered link.
- File tests use `Storage::fake('imports')` and `UploadedFile::fake()`; no writes to the real `storage/`.
- DB-error and trigger tests add TEMPORARY constraints inside the test transaction (Postgres DDL is transactional); the two-connection test (11) cleans up explicitly and must not run in parallel with other DB tests.
- `phpunit.xml` keeps `memory_limit 256M`; the route-audit loop reuses one app instance per role and must not accumulate memory (assert it passes under the 256M limit).
- Everything must pass on Linux CI (`ubuntu-latest`, Postgres service on 5433) and on the user's Windows machine; path handling uses `DIRECTORY_SEPARATOR` or Laravel helpers.
- Vitest: set `TZ` negative-offset runs for date strings, no real timers in debounce tests (fake timers), no network.

### 9.6 security-reviewer focus

Tier checks on every admin route (the route audit), the `active` middleware and session epoch (no way to keep using a deactivated session), login/forgot/reset/accept parity (no enumeration of deactivated, pending or unknown users), invitation token hashing, single use, expiry, host-independent link, encrypted queue payload, last-admin race (advisory lock, order of lock, re-read, target lock; only assign and deactivate can reduce the admin count) and self-protection, single-use tokens under concurrency (`SingleUseToken::consume()`, accept and reset serialised with revoke/deactivate, no swallowed 500), session epoch bump on password reset, encrypted reset notification, token hygiene in the SPA (URL replace, origin-only referrer), input-shape 422 (no 400/500, `bodyInput()` only), import `active` column behaviour and the audit gap, mass assignment of `role`/`company_id`/`active` on every endpoint, audit completeness (every state change writes a row in the same transaction), redaction allowlist (no secrets, tokens, notes, reasons, descriptions), append-only triggers, import hardening (type sniffing, size and row caps, private disk, SHA check, one-time apply, ownership of the token, formula prefixes, rate limits), product concurrency 409, no hard delete, snapshots untouched by edits, global QueryException handling on new endpoints, e2e environment cannot touch the dev database (guard in `global-setup.ts`), no secrets in `e2e/` or CI (only the public docker dev credentials).

## 10. File plan (for plan-mode approval)

- api: migrations (5, section 3); `config/admin.php`, `config/auth.php` (broker), `config/filesystems.php` (disk `imports`), `.env.example`; `app/Http/Middleware/EnsureUserIsActive.php`; `bootstrap/app.php` (alias `active`, priority order); `routes/api.php` (admin groups, route patterns `product`, `company`, `user`, `import`, `accept-invitation` route, `active` on the authenticated group) and `routes/console.php` (`imports:prune`); `app/Http/Controllers/Api/V1/Admin/{Dashboard,Product,ProductImport,Company,User,UserInvitation,AuditLog}Controller.php` and `AcceptInvitationController`; Form Requests (`ListProducts`, `StoreProduct`, `UpdateProduct`, `UploadProductImport`, `ApplyProductImport`, `ListCompanies`, `StoreCompany`, `UpdateCompany`, `UpdateCompanyBilling`, `ListUsers`, `InviteUser`, `UpdateUser`, `AssignUser`, `AcceptInvitation`, `ListAuditLog`); Resources (`AdminProduct`, `AdminProductList`, `CompanyAdmin`, `UserAdmin`, `AuditEntry`, `Dashboard`, `ProductImport`); Models (`AuditLogEntry`, `ProductImport`; `User` gains `status()`, `canSignIn()`, `primaryRole()`, `assign()`; `Company::$fillable = []`); Policies (`ProductPolicy`, `CompanyPolicy`, `UserPolicy`, `AuditLogPolicy`); Services (`AuditLog`, `ProductAdmin`, `ProductImporter`, `ProductImportService`, `CompanyAdmin`, `UserAdmin`, `UserInvitations`, `DashboardStats`); Support (`AuditRedactor`, `ProductRules`, `BillingAddress`); `Mail/UserInvitationMail.php` + view; `Console/Commands/ImportProducts.php` (thin), `PruneProductImports.php`; changes to `AuthController`, `ResetPasswordNotification`, `OrderStatusChanger`, `QuoteWorkflow`, `QuotePricing`, `IssueInvoice`, `InvoiceWorkflow`, `ListQuotesRequest`/`QuoteRequest` scope, `ListInvoicesRequest`/`Invoice` scope (additive filters), `AppServiceProvider` (limiters); factories (`UserFactory` unchanged defaults, `ProductImportFactory`); `AdminDemoSeeder`, `DatabaseSeeder`; tests under `tests/Feature/Admin/*`, `tests/Feature/Auth/*`, `tests/Feature/Database/AdminSchemaTest.php`, `tests/Unit/{AuditRedactorTest,BillingAddressTest,ProductRulesTest,ProductImporterTest}`, `tests/Support/RouteMatrix.php`, updated Phase 2 to 5 tests.
- web: `src/features/admin/*` (layout, dashboard, products, import, companies, users, audit, schemas, api), `src/components/DataTable.tsx`, `src/lib/listUrlState.ts`, `src/pages/AcceptInvitationPage.tsx`, `AppRoutes.tsx`, `nav.ts`, order detail status control, `basePath` support in the Orders/Quotes/Invoices pages, removal of `StaffPage`/`AdminPage`, `vite.config.ts` proxy env, tests beside the features.
- e2e (qa-engineer): everything in 8.1.
- outside the agents (main session or the user): approval of `@playwright/test`; `.github/workflows/ci.yml` (`e2e` job); `docker/postgres/init/01-create-test-db.sql`; `README.md` (e2e section, `portal_e2e` creation, new demo accounts, invitation mail via Mailpit); `.gitignore` (nothing at the root if `e2e/.gitignore` is used); `docs/PLAN.md` ticks; Phase 1 and 7 follow-up notes.

## 11. Open questions for the user (defaults in brackets)

1. APPROVE the new dependency `@playwright/test` (and the browser download of about 150 to 300 MB on first run) for `e2e/`? It is in the approved stack, but CLAUDE.md says to ask. [Yes. Nothing else new: invitations reuse Laravel's password broker, audit and CSV use PHP and the database.]
2. May STAFF (not only admin) edit prices and run CSV imports? [Yes: "Staff: manage products". The import is two-step, rate limited and audited.]
3. Who can read the audit log? [Admin only. Staff would otherwise see each other's and the admins' actions.]
4. Raise the shared `api` limiter from 60 to 240 per minute for staff and admin only (back-office pages issue several requests per click)? [Yes; customers and guests stay at 60.]
5. Email change: immutable after creation (typo fix = revoke and re-invite)? [Yes. It removes the takeover path "change email, then reset password" and the verified-state question.]
6. Companies: no deactivate, no delete, no unique name (two branches may share a name; the id disambiguates)? [Yes. Cut a company off by deactivating its users.]
7. Store the client IP in the audit log? [No: privacy, and the IP behind a proxy needs Phase 7 trusted-proxy work first. Session rows already carry an IP.]
8. Audit retention? [Keep everything in v1. The append-only trigger means a purge needs a privileged role: decide the period (for example 24 months) and the purge procedure in Phase 8, legal review for user emails in `subject_label`.]
9. Invitation lifetime? [7 days (`INVITATION_EXPIRE_MINUTES=10080`).]
10. Forced re-login after a role or company change (and immediate session death on deactivation)? [Yes, through `session_epoch`.]
11. May staff see the emails and names of a company's users (company detail)? [Yes; they are the sales contacts. They still cannot list or manage users.]
12. Quote price saves audited as `quote.priced`? [Yes: price changes are what audits are for. Notes and attachments are not audited.]
13. Customer-originated status changes (cancel an order, accept or decline a quote) in the audit log? [Yes: they go through the same services and "every status change writes a row" is simpler and safer than special cases. Order and quote creation by customers are not audited.]
14. Bulk actions (multi-select status change, bulk activate)? [None in v1: each action is audited and validated per record; revisit with real volume.]
15. Shipping info (carrier, tracking number) and an "orders awaiting invoice" queue? [Out of scope for Phase 6; the order status machine is unchanged. Candidate for Phase 7 or a later phase.]
16. Switch `SESSION_DRIVER` from `file` to `database`? [Not needed: the epoch check works with any driver. Phase 8 may choose per deployment.]

## 12. Decision details that the table above summarises

1. Route/URL structure and what happens to staff controls inside customer pages: keep them (single implementation), add `/admin/...` mounts of the same pages, change top-nav items per role. The API keeps every existing endpoint and adds only `expiring_within_days` and `due_within_days` for the queue and dashboard links.
2. Staff versus admin: matrix in 2.1. Product delete does not exist (referenced by snapshots with RESTRICT foreign keys; deactivation is the retirement path).
3. Optimistic concurrency uses 409 (not 422) because the input is valid and the state conflicts; the body also carries `errors.version`, so the generic form error mapping and the conflict banner both work.
4. Company deactivation: not offered. Customers of a company that stops buying are deactivated user by user; a deactivated company would need a rule for every existing endpoint and every open order, quote and invoice.
5. Query-count guarantees: dashboard 4, product list 3, company list 3, user list 4, audit list 4, company detail at most 8, user detail 3 (excluding the session user), all constant in the number of rows.
6. Quote queue filters: "needs attention" is `status=submitted,in_review`; "expiring soon" is `status=quoted&expiring_within_days=7`; "quoted" is `status=quoted`. Invoice list default: `status=open,overdue&sort=due_date` (oldest due date first, which is overdue first). Orders default: `status=pending&sort=created_at`. Links: order detail shows its quote and invoice, invoice and quote detail show their order, the audit page links to every subject.
7. Shipping info: out of scope (open question 15).

## 13. Known limitations (accepted for v1)

- Sessions created before this phase are invalidated once (no stored epoch).
- Email addresses cannot be changed; a person who changes address needs a new user.
- The audit log has no IP/user agent, no system-job rows and no retention purge.
- A company cannot be deleted or deactivated; a product cannot be deleted.
- CSV import over HTTP is capped at 5,000 rows and 5 MiB and runs synchronously (use the CLI for the initial catalog load).
- The shared `api` bucket still counts reads and writes together (240 per minute for staff).
- Last-admin protection and single-use tokens (invitation and password reset) are proven with real parallel processes in the Pest suite (9.1 item 11); double-apply of an import and `version` checks are proven sequentially plus by the row-lock design, and QA still repeats them manually with two browsers.
- A CSV import writes one `product.imported` audit row and no per-product rows (unbounded); price changes by import are visible only in aggregate (Phase 7 follow-up).
- The epoch check is skipped for requests without a session (see 14).
- The e2e suite uses the Vite dev server and `php -S` (not a production build); Phase 7 may add a build-based run.

## 14. Follow-ups for Phase 7 and Phase 8 (recorded here, not part of the Phase 6 gate)

- Phase 7: route-audit gate is largely satisfied by `RouteAuditTest` (extend rather than rewrite); trusted proxies; send `Referrer-Policy: origin` as a RESPONSE HEADER in production (Phase 6 only has the `<meta name="referrer">` fallback in `web/index.html` plus the URL replace on the token pages; also Phase 8 checklist); the encrypted reset notification is DONE in Phase 6 (both mails are `ShouldBeEncrypted`); CSV export escaping if an export is added; IDOR matrix reuse; Playwright "full customer journey and full staff journey" can extend `e2e/`.
- Phase 7 open items from the Phase 6 build:
  1. Per-product audit rows for CSV import changes (currently one `product.imported` row only; unbounded otherwise): bounded sample, detail table or chunked job.
  2. If personal access tokens are ever added, `EnsureUserIsActive` must fail CLOSED for requests without a session; today it skips the `session_epoch` comparison when there is no session (only the active/pending check applies).
  3. CI hardening: pin the Postgres and Mailpit service images by digest; consider Playwright `trace: 'on-first-retry'` instead of `retain-on-failure` in the e2e job, because the uploaded artifacts (traces, report) contain demo cookies and passwords of an ephemeral runner.
  4. `AuditLog::$baseTransactionLevel` is a test seam and is always 0 at runtime; keep it out of production code paths (a structure test may assert nothing but tests assign it).
- Phase 8 deployment and ops notes from the Phase 6 build: (a) sessions created before this release carry no `session_epoch` and die once at deploy (everyone signs in again; announce it); (b) run `EXPLAIN` on the dashboard queries and the company count subqueries with 5,000 or more rows and add an index only if a sequential scan shows up; (c) the race tests (last-admin, single-use tokens) need the real Postgres test database; (d) production overrides checklist: `config('app.web_url')` (env `APP_URL_WEB`) defaults to `http://localhost:5173` and MUST be set to the real SPA origin (invitation and reset links are built from it); `.env.example` has `SESSION_SECURE_COOKIE=false` (must be `true` behind HTTPS) and `SESSION_DRIVER=file` (decide per deployment, the epoch check works with any driver); `imports:prune` runs hourly from the Laravel scheduler, so the scheduler (cron `schedule:run` every minute) MUST be running in production or expired previews and their files are never cleaned up.
- Phase 8: audit retention and a privileged purge job or a documented "never purge"; backups of `audit_log` with the database; the app DB role must not own or have TRIGGER/TRUNCATE on `audit_log`, `invoices*` (same item as Phase 5); `imports:prune` in the scheduler cron; `INVITATION_EXPIRE_MINUTES`, `ADMIN_IMPORT_*` in the deploy checklist; real mail provider for invitations (SPF/DKIM); first admin user creation procedure in production (no demo seeder: `php artisan tinker` or a one-off command using `User::provision`), documented in `docs/DEPLOY.md`.
