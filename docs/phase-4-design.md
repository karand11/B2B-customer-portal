# Phase 4 design - Quote requests (RFQ)

HTTP contract: `docs/api-contract.md` (Phase 4 section). This file holds decisions, schema, state machine, conversion, attachments, emails, seeders, frontend design, test plan and open questions. Conventions come from Phases 1 to 3 (Sanctum cookie auth, role middleware before limiters and lookups, `denyAsNotFound()` policies, exact 404 body `{"message":"Not found."}`, random public numbers, integer cents with the 10^9 price cap, `TrimStrings` behaviour, status-machine service with a history table, database queue, Mailpit, Postgres 16 `portal_test`, Linux CI).

Everything here follows the Order pattern on purpose (`Order` -> `QuoteRequest`, `OrderPolicy` -> `QuotePolicy`, `OrderStatusChanger` -> `QuoteWorkflow`, `order_status_history` -> `quote_status_history`), so the backend agent copies a proven shape.

## 1. Decisions

| # | Topic | Decision | Why |
| --- | --- | --- | --- |
| 1 | Isolation | Same as orders: customers see their company's quotes only (`QuoteRequest::visibleTo($user)` scope + `QuotePolicy` with `denyAsNotFound()`); staff/admin see all; foreign = exact 404; role middleware (403) before any lookup. Public id `QUO-XXXXX-XXXXX` (same random alphabet as orders, `random_int`, UNIQUE + savepoint retry); numeric ids never in URLs or bodies. | Proven in Phase 3; no new concept |
| 2 | Staff UI | A MINIMAL staff UI ships in Phase 4, inside the same `/quotes` pages customers use (role-aware controls, driven by `allowed_actions`): list with Company column, detail with a pricing form and the action buttons. No separate `/staff/quotes` tree. | The gate needs a manual Mailpit round trip (staff prices and sends). A curl recipe with CSRF cookie jar is fragile and cannot be shown to a non-developer. One shared page set is the smallest UI. Phase 6 replaces it with `/admin/quotes` (company filter UI, queue counts on the dashboard, audit log, bulk views); the API does not change. A curl recipe is still documented in 12.3 as a QA fallback. |
| 3 | Lines | Two kinds: catalog (`product_id`, snapshot of part number, name, brand at request time) and custom/free text (`description` up to 255 chars, no product). Quantity 1 to 9999 (reuse `config('orders.max_quantity')`), 1 to 50 lines (`config('quotes.max_lines')`). Duplicate `product_id` in one request is 422. NO quantity unit column (customers write "100 m" in the description). Price model: staff set `unit_price_cents` per line (0 to 1,000,000,000); `line_total = unit * quantity`; `subtotal = sum`; `tax = 0`, `shipping = 0`, `total = subtotal` (stored, same CHECK shape as orders); one customer-visible `staff_note` (terms, lead time) set when sending; no discounts or fees (lower the unit price instead). Currency copied from `config('catalog.currency')` at creation. | Smallest model that prices a bearings RFQ; every number is an integer and computed on the server |
| 3a | Catalog lines for restricted products | A catalog line may reference a `price_on_request` or `out_of_stock` product (that is the whole point of a quote). Inactive or unknown product is 422 (one message, same as the cart). | Phase 3 sent price-on-request items here |
| 3b | Validity | `valid_until` is a DATE meaning "last day the quote can be accepted, inclusive, UTC". Expiry instant = `valid_until + 1 day`, 00:00:00 UTC. Sending requires `valid_until` strictly after today (UTC) and at most `config('quotes.max_validity_days')` (365) ahead. | A date is what a customer reads on the quote; a single UTC rule has no per-user timezone ambiguity (`config/app.php` timezone is `UTC`) |
| 3c | Expiry mechanism | ONE definition of "expired", used everywhere: `QuoteStatus::effective(status, valid_until, now)` returns `expired` when status is `quoted` and today(UTC) > `valid_until`. The API always returns the EFFECTIVE status; list filters use the same rule in SQL (see 5.3); accept/decline/reopen check it inside the row lock, so ACCEPT TIME IS AUTHORITATIVE. A scheduled command `quotes:expire` (every 5 minutes) only MATERIALISES the stored status (`quoted` to `expired`, history row with a NULL actor = system). If the scheduler never runs, nothing is wrong except history/housekeeping. | No write on GET, no race between a cron and a customer click, no stale badge |
| 3d | Frozen clock | Application code reads time only through `now()` / `Carbon` (never `NOW()`, `CURRENT_DATE` or `CURRENT_TIMESTAMP` in SQL, never `date()`), the "today" used in SQL is a bound parameter `now()->utc()->toDateString()`. Tests use `$this->travelTo(Carbon::parse('2026-12-31 23:59:59', 'UTC'))` and `travelBack()`. | Makes the boundary test deterministic on Linux CI |
| 4 | State machine | See section 5 (full matrix). `declined` is reused for customer withdraw, customer decline and staff reject; the history row records who and why. No 7th status (the plan lists six). | Fewer states; the actor and note carry the difference. Open question 1 |
| 5 | Accept -> order | In one transaction under a row lock on the quote: re-check state, create the order from the QUOTE's snapshot and agreed prices (not live catalog prices), link `orders.quote_request_id` (UNIQUE), set `accepted`, write both history rows. Second accept: 422 on `status` ("already accepted"). Needs Phase 3 schema amendments (section 3.6). | CLAUDE.md convention: wrong state is 422; exactly-once by lock plus unique constraint |
| 5a | Stale-price guard | `POST /accept` requires `revision` (integer equal to the quote's current revision). Staff reopen + re-send bumps `revision`, so a customer looking at an old page cannot accept new prices silently. Mismatch is 422 `revision`. | The Phase 3 review found "silent price change at checkout" and deferred it; for quotes it is cheap to prevent now |
| 5b | PO reference | `po_reference` is accepted at creation (optional, same rules as orders) AND optionally at accept (a PO is often issued after the quote is agreed). At accept: body value if given, else the quote's value, else NULL. The rules live in ONE shared validator used by `PlaceOrderRequest`, `CreateQuoteRequest`, `AcceptQuoteRequest` (refactor, no behaviour change). | |
| 6 | Attachments | See section 7. Private disk `quotes` (`storage/app/private/quotes`), random stored names, content-sniffed allowlist (pdf, png, jpg, txt, csv), 10 MiB per file, 10 files and 25 MiB per quote, authorized download only. Upload is a separate multipart endpoint, one file per request, AFTER the JSON create. | JSON create stays simple and testable; per-file progress and retry |
| 7 | Emails | Two queued Mailables (`QuoteSubmittedMail`, `QuoteSentMail`), dispatched after the transaction commits. Staff recipients: `config('quotes.notify_address')` if set, else every user with role `staff` or `admin`. Customer recipient: the user who created the quote. See section 8. | Matches the scope; a shared mailbox is how sales teams really work, the fallback makes the demo work with no config |
| 8 | Endpoints | 14 endpoints (contract). Attachment "list" is embedded in the quote detail (no separate list endpoint). No customer edit of lines after submit (withdraw and submit a new quote). Staff may `reopen` a quoted quote (back to `in_review`) to re-price it before the customer answers. | Smallest set that covers the gate and the real workflow |
| 9 | Schema | Four new tables and two amendments to Phase 3 tables (section 3). | |
| 10 | Seeders | `QuoteDemoSeeder`, local/testing only, 8 quotes, no attachments (section 10). | |

## 2. Dependencies, config, CI, PHP limits

- Dependencies: NONE new. Laravel's Mailable, queue, Storage, `finfo` (`ext-fileinfo`, already required by Laravel) and Eloquent cover everything. Rejected: a MIME/antivirus package, a zip library (no Office formats in v1, see 7.2), a media library.
- New `api/config/quotes.php`: `max_lines` 50, `max_validity_days` 365, `notify_address` (env `QUOTES_NOTIFY_ADDRESS`, default null), `max_note_length` 2000, `attachments` => [`disk` 'quotes', `directory` 'quote-attachments', `max_bytes` 10485760, `max_files` 10, `max_total_bytes` 26214400, `max_company_bytes` (env `QUOTES_COMPANY_MAX_BYTES`, default 524288000 = 500 MiB), `types` map extension => canonical MIME, see 7.2], plus (added after the security review) `max_open_per_company` (env `QUOTES_MAX_OPEN_PER_COMPANY`, default 50), `uploads_per_hour` (env `QUOTES_UPLOADS_PER_HOUR`, default 120) and `max_notify_recipients` (50, config only, no env). Quantity bound reuses `config('orders.max_quantity')`. Currency reuses `config('catalog.currency')`.
- New disk in `config/filesystems.php`: `'quotes' => ['driver' => 'local', 'root' => storage_path('app/private/quotes'), 'serve' => false, 'throw' => true, 'visibility' => 'private']`. Never under `public/`, never linked by `storage:link`, no `url`. The framework's default `local` disk also has `'serve' => false` (`config/filesystems.php`, with a comment), so Laravel registers no `/storage/{path}` route at all: an unsigned `GET /storage/...` is a plain 404 (before this change the `local` disk's signed-URL route answered 403 for an unsigned request). The security test asserts 404, not 403.
- `.env.example`: add `QUOTES_NOTIFY_ADDRESS=` (empty = fall back to staff users), `QUOTES_COMPANY_MAX_BYTES=524288000`, `QUOTES_MAX_OPEN_PER_COMPANY=50`, `QUOTES_UPLOADS_PER_HOUR=120`. No other env var. `phpunit.xml` unchanged (`MAIL_MAILER=array`, `QUEUE_CONNECTION=sync` already set; tests use `Mail::fake()` and `Storage::fake('quotes')`).
- Scheduler: `routes/console.php` gets `Schedule::command('quotes:expire')->everyFiveMinutes()->withoutOverlapping()`. Local: `php artisan schedule:work` (README line; optional because expiry is effective at read time). Production cron is Phase 8.
- Queue worker: already needed since Phase 1 (`composer dev` runs `queue:listen`). Mailables use `public int $tries = 3` and `public array $backoff = [60, 300]`; permanent failures land in `failed_jobs` (table exists from the base `jobs` migration).
- Upload limits (the main trap): `php artisan serve` uses the HOST `php.ini`. PHP defaults (`upload_max_filesize=2M`, `post_max_size=8M`) would break a legal 5 MB file with an unhelpful error. README must say: set `upload_max_filesize = 11M`, `post_max_size = 12M` (a little above the 10 MiB app limit so the app, not PHP, produces the clear 422 for a file between 10 MiB and 11 MiB), `file_uploads = On`, and QA must check `php -r "echo ini_get('upload_max_filesize'),' ',ini_get('post_max_size');"`. Behaviour by size: up to 10 MiB accepted; 10 MiB + 1 byte up to about 11 MB: app 422 `file` ("must not be greater than 10 MB"); above `upload_max_filesize` but below `post_max_size`: PHP flags `UPLOAD_ERR_INI_SIZE`, Laravel's `uploaded` rule gives 422 `file` ("failed to upload"); above `post_max_size`: Laravel's `ValidatePostSize` throws `PostTooLargeException`, rendered by the api exception handler as 413 `{"message":"The upload is too large."}` (new render rule in `bootstrap/app.php`, `api/*` only; other paths keep the framework default).
- Vite proxy: no change (node http-proxy does not limit body size and does not buffer it). Phase 8 Nginx needs `client_max_body_size 12m` on `/api` (add to the Phase 8 checklist).
- CI/docker: no change (Postgres service already there; `fileinfo` is compiled into the `setup-php` default build; the tests never touch real PHP upload limits).

## 3. Database

New migrations, in this order (dates continue the Phase 3 series): `2026_11_28_100000_create_quote_requests_table`, `_100001_create_quote_items_table`, `_100002_create_quote_status_history_table`, `_100003_create_quote_attachments_table`, `_100004_link_orders_to_quotes` (Phase 3 amendments), `_100005_widen_quote_revision` (added after the security review: `quote_requests.revision` smallint to integer, see 3.1). CHECKs and partial indexes are `DB::statement()`. Every FK is `ON DELETE RESTRICT`. Money is `bigint` cents. Timestamps are `timestamp` (UTC). All models have `$fillable = []` and are written with `forceFill` and server values only.

### 3.1 `quote_requests`

| Column | Type | Constraints |
| --- | --- | --- |
| id | bigint identity | PK, internal only |
| quote_number | char(15) | NOT NULL, UNIQUE `quote_requests_quote_number_unique`, CHECK `quote_requests_quote_number_format`: `~ '^QUO-[A-HJKMNP-TV-Z2-9]{5}-[A-HJKMNP-TV-Z2-9]{5}$'` |
| company_id | bigint | NOT NULL, FK companies RESTRICT |
| created_by_user_id | bigint | NOT NULL, FK users RESTRICT |
| status | varchar(16) | NOT NULL default `'submitted'`, CHECK IN (`submitted`,`in_review`,`quoted`,`accepted`,`declined`,`expired`) |
| currency | char(3) | NOT NULL, CHECK `~ '^[A-Z]{3}$'` |
| po_reference | varchar(64) | NULL, CHECK `char_length(po_reference) <= 64` |
| customer_note | text | NULL, CHECK `char_length(customer_note) <= 2000` |
| staff_note | text | NULL, CHECK length <= 2000 (visible to the customer only when `sent_at` is not null) |
| internal_note | text | NULL, CHECK length <= 2000 (staff only, never in a customer response) |
| valid_until | date | NULL |
| sent_at | timestamp | NULL (set on send, cleared on reopen; gates what the customer may see) |
| revision | integer | NOT NULL default 0, CHECK `revision >= 0` (incremented on every send). Widened from smallint by `2026_11_28_100005_widen_quote_revision`, so a re-send can never overflow 32767; the accept request accepts 1..2147483647 |
| subtotal_cents | bigint | NULL (null until every line is priced), CHECK `>= 0` |
| tax_cents | bigint | NOT NULL default 0, CHECK `>= 0` |
| shipping_cents | bigint | NOT NULL default 0, CHECK `>= 0` |
| total_cents | bigint | NULL, CHECK `>= 0` |
| created_at, updated_at | timestamp | NOT NULL, set by app |

Table CHECKs: `quote_requests_totals_consistent` (as implemented in the migration): `(subtotal_cents IS NULL AND total_cents IS NULL) OR (subtotal_cents IS NOT NULL AND total_cents IS NOT NULL AND total_cents = subtotal_cents + tax_cents + shipping_cents)`. The first draft of this design omitted `AND total_cents IS NOT NULL`: with `subtotal_cents` set and `total_cents` NULL the comparison evaluates to NULL, and a CHECK accepts NULL, so a half-priced row slipped through. The explicit `IS NOT NULL` closes that loophole. `quote_requests_sent_requires_price`: `status NOT IN ('quoted','accepted') OR (total_cents IS NOT NULL AND valid_until IS NOT NULL AND sent_at IS NOT NULL AND revision >= 1)` (the database itself refuses an unpriced quoted or accepted quote).

Indexes: `quote_requests_company_id_created_at_index` (company_id, created_at) customer list and default sort; `quote_requests_company_id_status_index` (company_id, status); `quote_requests_status_created_at_index` (status, created_at) staff queue; `quote_requests_created_at_index`; partial `quote_requests_quoted_valid_until_index` ON (valid_until) WHERE status = 'quoted' (serves `quotes:expire`). No index on `po_reference` (same reasoning as Phase 3). `quote_requests` has no `order_id`: the link is `orders.quote_request_id` (3.6), one direction only, so there is a single exactly-once constraint and no circular FK.

Relations: `belongsTo Company`, `belongsTo User (createdBy)`, `hasMany QuoteItem` (ordered by position), `hasMany QuoteStatusHistory` (ordered by id), `hasMany QuoteAttachment` (ordered by id), `hasOne Order` (via `orders.quote_request_id`). `status` cast to the `QuoteStatus` enum.

### 3.2 `quote_items`

| Column | Type | Constraints |
| --- | --- | --- |
| id | bigint identity | PK, internal only |
| quote_request_id | bigint | NOT NULL, FK RESTRICT |
| position | smallint | NOT NULL, CHECK `position BETWEEN 1 AND 50` (stable 1-based line address used by the pricing endpoint) |
| product_id | bigint | NULL, FK products RESTRICT (NULL = custom line) |
| part_number | varchar(64) | NULL (snapshot; NULL for custom lines) |
| name | varchar(255) | NOT NULL (catalog: snapshot of the product name; custom: the customer's description) |
| brand | varchar(64) | NULL (snapshot; NULL for custom lines) |
| quantity | integer | NOT NULL, CHECK `quantity > 0 AND quantity <= 9999` |
| unit_price_cents | bigint | NULL (set by staff), CHECK `unit_price_cents BETWEEN 0 AND 1000000000` |
| line_total_cents | bigint | NULL |
| created_at, updated_at | timestamp | |

CHECKs: `quote_items_line_kind`: `(product_id IS NOT NULL AND part_number IS NOT NULL AND brand IS NOT NULL) OR (product_id IS NULL AND part_number IS NULL AND brand IS NULL)`. `quote_items_price_consistent` (as implemented): `(unit_price_cents IS NULL AND line_total_cents IS NULL) OR (unit_price_cents IS NOT NULL AND line_total_cents IS NOT NULL AND line_total_cents = unit_price_cents * quantity)`. Same NULL loophole as the totals CHECK in the first draft (price set, `line_total_cents` NULL evaluated to NULL and passed); fixed with `AND line_total_cents IS NOT NULL`. The schema test (12.1 item 16) must also insert a row with only one of the two columns NULL and expect rejection, for both tables.
Indexes: UNIQUE (quote_request_id, position); UNIQUE (quote_request_id, product_id) (Postgres treats NULLs as distinct, so any number of custom lines; one line per catalog product, which also keeps the later `order_items` unique key safe).
Immutability: after creation only `unit_price_cents` and `line_total_cents` ever change (staff pricing). No endpoint edits name, quantity, product or position.

### 3.3 `quote_status_history`

| Column | Type | Constraints |
| --- | --- | --- |
| id | bigint identity | PK |
| quote_request_id | bigint | NOT NULL, FK RESTRICT |
| from_status | varchar(16) | NULL (only the creation row), CHECK IN the six statuses |
| to_status | varchar(16) | NOT NULL, CHECK IN the six statuses |
| actor_user_id | bigint | NULL, FK users RESTRICT (NULL = system, only allowed for `to_status = 'expired'`) |
| note | varchar(500) | NULL (decline or reject reason) |
| created_at | timestamp | NOT NULL, no `updated_at` (append-only) |

CHECKs: `from_status IS NULL OR from_status <> to_status`; `actor_user_id IS NOT NULL OR to_status = 'expired'`. Index `quote_status_history_quote_request_id_id_index` (quote_request_id, id). Written ONLY by `QuoteWorkflow` (and the create service for the first row). Pricing saves and note edits are not status changes and write no history row (the Phase 6 audit log covers them).

### 3.4 `quote_attachments`

| Column | Type | Constraints |
| --- | --- | --- |
| id | bigint identity | PK, internal only |
| public_id | uuid | NOT NULL, UNIQUE `quote_attachments_public_id_unique` (random v4 via `Str::uuid()`, the only id in URLs and bodies) |
| quote_request_id | bigint | NOT NULL, FK RESTRICT |
| uploaded_by_user_id | bigint | NOT NULL, FK users RESTRICT |
| uploaded_by_role | varchar(8) | NOT NULL, CHECK IN (`customer`,`staff`) (admin is stored as `staff`) |
| original_name | varchar(120) | NOT NULL (sanitised, display only, never used as a path) |
| storage_path | varchar(64) | NOT NULL, UNIQUE, CHECK `~ '^quote-attachments/[a-f0-9]{32}$'` |
| mime | varchar(100) | NOT NULL, CHECK IN (`application/pdf`,`image/png`,`image/jpeg`,`text/plain`,`text/csv`) (canonical value from the allowlist, not the client's) |
| size_bytes | bigint | NOT NULL, CHECK `size_bytes > 0 AND size_bytes <= 10485760` |
| sha256 | char(64) | NOT NULL, CHECK `~ '^[a-f0-9]{64}$'` |
| created_at | timestamp | NOT NULL (rows are immutable; delete = hard delete) |

Index: (quote_request_id, id). The storage path contains no user-controlled characters at all (16 random bytes as hex), so path traversal and name collisions are impossible by construction.

### 3.5 Relations on existing models

`Company hasMany QuoteRequest`; `User hasMany QuoteRequest` (as creator). `Order belongsTo QuoteRequest` (nullable) and `Order::quote()`.

### 3.6 Phase 3 amendments (`2026_11_28_100004_link_orders_to_quotes`)

Verified in `api/database/migrations`: `order_items.product_id`, `part_number` and `brand` are NOT NULL, and `orders` has no quote link, so a free-text quote line cannot become an order line today. The migration:

1. `orders.quote_request_id` bigint NULL, FK `quote_requests.id` RESTRICT, UNIQUE `orders_quote_request_id_unique` (NULLs distinct, so cart orders are unaffected). This unique index is the database-level exactly-once guarantee.
2. `order_items`: `ALTER COLUMN product_id DROP NOT NULL`, same for `part_number` and `brand`; `ADD CONSTRAINT order_items_line_kind CHECK ((product_id IS NOT NULL AND part_number IS NOT NULL AND brand IS NOT NULL) OR (product_id IS NULL AND part_number IS NULL AND brand IS NULL))`. All existing rows satisfy the first branch. The FK to products stays (a NULL FK is allowed). The existing `UNIQUE (order_id, product_id)` already allows many NULL product ids. The other CHECKs are untouched.
3. `down()` reverses (re-add NOT NULL only if no NULL rows exist; a rollback test is not required).

Code and contract consequences (Phase 3 files that must change, tests updated in the same commit): `OrderItemResource`/`OrderDetailResource` emit `product_id`, `part_number`, `brand` as `null` for custom lines; `OrderResource` and `OrderDetailResource` gain `quote_number` (string or null, always present, eager loaded with `quote:id,quote_number`); `CartService::reorder` must skip a line whose `product_id` is null with the new reason `not_in_catalog` (contract below) instead of dereferencing a missing product; the Phase 3 exact-key tests and the constant-query-count bounds change from 8 to 9 (list) and 9 to 10 (detail). `PlaceOrder::insertOrder` (number retry in a savepoint) is extracted into `App\Services\OrderInserter` and used by both `PlaceOrder` and `AcceptQuote`; Phase 3 behaviour and tests stay green.

## 4. Authorization

Same three layers as orders (5.1 of the Phase 3 design): role middleware per route group (403 before any lookup), `QuoteRequest::visibleTo(User)` on every controller query (customer: `quote_requests.company_id = user.company_id`, nothing if the company is null; staff/admin: unscoped; any other user: `whereRaw('false')`), and `QuotePolicy` on the loaded model. A Pest structure test asserts no controller calls `QuoteRequest::query()/where/find` without `visibleTo` (same technique as `OrderPolicy`).

### 4.1 `App\Policies\QuotePolicy`

| Method | Rule | On failure |
| --- | --- | --- |
| `view(User, Quote)` | staff/admin true; customer: `company_id` non-null and equal | `denyAsNotFound()` (404) |
| `create(User)` | customer with non-null company | 403 |
| `accept`, `decline` | `view` AND customer AND NOT (staff or admin): a user holding the customer role together with a staff/admin role is refused | foreign: 404; dual-role: 403 |
| `review`, `price`, `send`, `reject`, `reopen`, `note` | staff or admin | 403 |
| `attach(User, Quote)` | `view` AND (customer or staff or admin) | foreign: 404 |
| `viewAttachment(User, Quote, Attachment)` | `view`; for a customer additionally `quote.sent_at IS NOT NULL` when the attachment's `uploaded_by_role = 'staff'` | 404 |
| `deleteAttachment(User, Quote, Attachment)` | staff/admin: any attachment; customer: `viewAttachment` AND `uploaded_by_role = 'customer'` | 404 (a customer can never see a staff file in an open state, so no 403 case exists) |

State rules (is the status right?) live in `QuoteWorkflow`, so a state conflict is 422 and a missing right is 403/404. Staff cannot accept or decline on a customer's behalf (open question 5). No `Gate::before`.

### 4.2 Visibility of unsent drafts (one rule: `sent_at IS NOT NULL`)

For a CUSTOMER viewer, while `sent_at IS NULL` (submitted, in_review, reopened, or withdrawn before sending): unit prices, line totals, subtotal, total, `valid_until`, `staff_note`, `revision` and staff-uploaded attachments are hidden (`null` or absent from the list). `internal_note` is never in a customer response (the key does not exist, it is not `null`). Staff always see everything. This protects half-typed prices and means a reopened quote does not show the customer a moving target. List sorting by price is not offered, so the order of rows cannot leak drafts. The same applies to timestamps: for a customer viewer `updated_at` is `max(latest status-history row created_at, sent_at, created_at)`, so staff drafting prices or editing the internal note (which bump the real `updated_at`) is not observable by customers; staff and admin see the real `updated_at`.

## 5. State machine

`App\Enums\QuoteStatus` (string-backed). One matrix, one service, `App\Services\QuoteWorkflow::transition(QuoteRequest, QuoteStatus $to, User|null $actor, ?string $note)`: the only code that changes `quote_requests.status`. It runs `DB::transaction`, re-reads the row with `lockForUpdate()`, computes the EFFECTIVE status (3c), checks the matrix and the actor rule, applies the side effects of the action, writes exactly one history row, returns the fresh model; a refused transition throws `ValidationException::withMessages(['status' => ...])` (422).

### 5.1 Transition matrix

| From (effective) | To | Who | Endpoint | Side effects and preconditions |
| --- | --- | --- | --- | --- |
| (none) | submitted | customer | `POST /quotes` | history `null -> submitted`; staff email |
| submitted | in_review | staff, admin | `POST /review` | |
| submitted | declined | customer | `POST /decline` (withdraw) | optional reason in `note` |
| submitted | declined | staff, admin | `POST /reject` | reason required, shown to the customer |
| in_review | quoted | staff, admin | `POST /send` | every line priced, `valid_until` valid (tomorrow to +365 days), sets `sent_at = now`, `revision + 1`, stores optional `staff_note`; customer email |
| in_review | declined | customer | `POST /decline` (withdraw) | |
| in_review | declined | staff, admin | `POST /reject` | reason required |
| quoted | accepted | customer | `POST /accept` | not expired, `revision` matches, converts to order (section 6.3) |
| quoted | declined | customer | `POST /decline` | optional reason |
| quoted | in_review | staff, admin | `POST /reopen` | clears `sent_at` (hides prices again), keeps prices and `valid_until` as a draft; no email |
| quoted | expired | system | `quotes:expire` or effective at read | actor NULL |
| accepted, declined, expired | anything | nobody | | terminal (a new quote is needed) |

Everything else is 422 `status`, including same-status requests and any action on an effective-expired quote ("This quote has expired."). `allowed_actions` in the response is computed from this same matrix for the viewer (single source for the UI): customer `submitted|in_review` -> `["decline","attach"]`, `quoted` (unexpired) -> `["accept","decline"]`; staff `submitted` -> `["review","reject","attach","note"]`, `in_review` -> `["price","send","reject","attach","note"]`, `quoted` (unexpired) -> `["reopen","note"]`, terminal -> `["note"]`; customers get `[]` on terminal states.

### 5.2 Concurrency

Every action locks the quote row (`lockForUpdate`) and re-reads inside the transaction: staff `send` racing with customer `decline`, staff `reopen` racing with customer `accept`, two parallel `accept`s, and an upload racing with a state change all serialise; the loser gets 422 `status`.

### 5.3 Effective status in SQL (list filters)

`status` filter values map to: `submitted|in_review|accepted|declined` -> `status = x`; `quoted` -> `status = 'quoted' AND valid_until >= :today`; `expired` -> `status = 'expired' OR (status = 'quoted' AND valid_until < :today)`, with `:today = now()->utc()->toDateString()` bound as a parameter. The whole OR group is wrapped in one closure inside the company scope (same leak risk as the Phase 3 `q` OR group; test 14 covers it).

### 5.4 `quotes:expire`

`php artisan quotes:expire`: selects `status = 'quoted' AND valid_until < :today` (partial index), and for each id runs `QuoteWorkflow` in system mode (actor NULL, no matrix check beyond "still quoted and past due" after the lock). Idempotent, prints the count, exit 0. No email on expiry (open question 6).

## 6. Algorithms

### 6.1 Create (`CreateQuote`)

Authorize `create`. Validate in `CreateQuoteRequest` (BEFORE any write; rules in the contract). In one transaction: lock the company row, count the company's open quotes by EFFECTIVE status (`submitted`, `in_review`, `quoted` with `valid_until >= today`) and refuse with 422 `quote` at `quotes.max_open_per_company` (50) or more; then load every catalog product referenced (one query, `whereIn`, active only; a missing id or an inactive product is 422 `lines.N.product_id`), insert the quote (`company_id` and `created_by_user_id` from the session user, `status = submitted`, currency from config, number via the generator with the savepoint retry), bulk insert items with snapshots and `position` 1..n, insert the first history row. After the transaction returns: queue `QuoteSubmittedMail`. A failed mail dispatch is logged and does not fail the request (the quote exists and appears in the staff queue).

### 6.2 Pricing and send

`price` (`PUT /pricing`): quote must be effective `in_review` (422 `status`). Inside the lock: for each `{position, unit_price_cents|null}` update the matching item (unknown position is 422 `lines.N.position`, duplicate position in the request is 422); set `valid_until` if the key is present; recompute `line_total_cents`, then `subtotal_cents = sum` and `total_cents = subtotal` when EVERY line is priced, else both NULL. `send`: re-read items inside the lock, require all priced (else 422 `lines`), `valid_until` set and `today < valid_until <= today + 365 days` (else 422 `valid_until`), then transition. Prices are never accepted from customers, never in any customer request.

### 6.3 Accept (`AcceptQuote`) and exactly-once

1. Role middleware (customer), body validation (`revision` integer 1..2147483647, optional `po_reference`; a non-empty body that is not a JSON object or array is 422 `body`), then lookup with `visibleTo` (404), `authorize('accept')`.
2. `DB::transaction`: lock the quote row. Effective status: `accepted` -> 422 `status` "This quote has already been accepted."; `expired` -> 422 `status` "This quote has expired."; anything not `quoted` -> 422 `status`. `revision` differs -> 422 `revision`. 
3. Build the order from the quote's items (their SNAPSHOT part number, name, brand, quantity and the staff's `unit_price_cents`, `line_total_cents`; NOT the live catalog, so a later price change, deactivation or price-on-request flag does not break an accepted deal), `subtotal = quote.subtotal_cents`, `tax = 0`, `shipping = 0`, `total = subtotal`, currency from the quote, `company_id` from the quote (equals the user's), `created_by_user_id` = accepting user, `status = pending`, `po_reference` = body or the quote's, `quote_request_id` = the quote. Insert through `OrderInserter` (number retry), items bulk insert (`product_id` NULL for custom lines), first order history row `null -> pending` (actor = accepting user), then `QuoteWorkflow` `quoted -> accepted` (history row, actor = accepting user, same transaction).
4. Response 200: the quote detail (status `accepted`, `order.number` set). The SPA navigates to `/orders/{order.number}`.
5. Exactly-once layers: (a) the row lock serialises parallel accepts, the second sees `accepted` and gets 422; (b) UNIQUE `orders_quote_request_id_unique` would reject a second order even if code were wrong (surfaces as 500 only in that impossible case; a test inserts a second order for the same quote directly and expects the DB error); (c) the whole step is one transaction (no order without `accepted`, no `accepted` without an order; a test injects a failure on the history insert and asserts nothing changed).
6. No catalog availability check at accept (the staff agreed the price), no cart involvement, no stock logic.
7. The order carries its origin: `quote_number` in order resources, shown on the order page as "From quote QUO-...".

## 7. Attachments

### 7.1 Who, when, how many

- Upload: customers (own company's quote) and staff/admin, only while the effective status is `submitted` or `in_review` (422 `status` otherwise: files are frozen once the quote is sent, so the customer's accepted version cannot change). Customer files are visible to the company and to staff. Staff files (for example the formal quotation PDF) are hidden from customers until `sent_at` is set (4.2).
- Caps per quote (checked under the quote row lock, so parallel uploads cannot exceed them): 10 files, 25 MiB total, 10 MiB (10,485,760 bytes) per file, no empty file. Over a cap: 422 `file`.
- Delete: while `submitted`/`in_review`; customer: only customer-uploaded files of the visible quote; staff: any. Hard delete: DB row in the transaction, file removed after commit; a failed file removal is logged (`Log::error`, no rethrow) and leaves an orphan on disk but never a dangling row. No orphan sweeper in Phase 4 (note for Phase 7).
- Company cap (added after the security review): `quotes.attachments.max_company_bytes` (500 MiB default) over ALL attachments of ALL the company's quotes, closed quotes included; staff uploads count toward the quote's company. Enforced inside the quote-lock transaction: lock the quote row, then lock the company row `FOR UPDATE`, then sum and compare (lock order quote then company everywhere). Over the cap: 422 `file`.
- Rate limits: named limiters `quote-uploads` (20 per 60 s per user id) AND `quote-uploads-hourly` (`quotes.uploads_per_hour`, 120 per 3600 s per user id), both counting every attempt (including 413/422), after the role middleware (limiters registered in `AppServiceProvider`; `quotes-create` is 10 per 60 s per user id). A wrong role gets 403 before any 429.
- Malware scanning is OUT OF SCOPE. Mitigations in place: allowlist by content, forced `attachment` disposition, `nosniff`, `Content-Security-Policy: default-src 'none'; sandbox`, private storage, no execution path. Add ClamAV (or a provider scan) in Phase 7/8 if the business needs it.

### 7.2 Allowlist and content sniffing

| Extension (from the SANITISED name, lowercased) | Canonical MIME stored and served | `finfo` MIME accepted for it | Extra check |
| --- | --- | --- | --- |
| `pdf` | `application/pdf` | `application/pdf` | |
| `png` | `image/png` | `image/png` | |
| `jpg`, `jpeg` | `image/jpeg` | `image/jpeg` | |
| `txt` | `text/plain` | `text/plain` | valid UTF-8, no NUL byte |
| `csv` | `text/csv` | `text/csv`, `text/plain`, `application/csv` (libmagic versions differ) | valid UTF-8, no NUL byte |

Rules: the file is sniffed with `new finfo(FILEINFO_MIME_TYPE)->file($tmpPath)` (content, not the client-sent `Content-Type`, not the extension). It is accepted only if the extension is in the table AND the sniffed type is in that row. So an `.exe`, `.html`, `.svg`, `.zip`, `.php`, `.js` or `.docx` is rejected by extension, and a renamed one (`evil.exe` -> `evil.pdf`, `page.html` -> `page.pdf`, `logo.svg` -> `logo.png`) is rejected because the sniffed type does not match the row. The stored `mime` is the canonical value from the table (never the client's string). Rejected on purpose: SVG and HTML (script carriers), all archives, executables, Office formats.

Why no docx/xlsx: libmagic reports them inconsistently as `application/zip`, `application/octet-stream` or the OOXML type depending on the libmagic version, which differs between Windows PHP builds and the Linux CI image, and a reliable check needs `ext-zip` structure inspection. Customers who have Excel lists can export CSV or PDF. Open question 2 (if wanted: add `ZipArchive` check of `[Content_Types].xml` plus `xl/` or `word/`, require `ext-zip`, and extend the CHECK list).

Where it runs (as implemented; there is NO `UploadAttachmentRequest` FormRequest): the controller does the route lookup, `visibleTo` scoping, policy and the open-state check first; `App\Services\QuoteAttachments` then takes the quote row lock and calls `App\Support\AttachmentInspector` (the `UPLOAD_ERR_*` check, size, extension allowlist, content sniffing, UTF-8/NUL check, per-quote count and total caps) INSIDE the locked transaction. So a foreign quote is 404 and a closed quote is 422 `status` even when the file is bad, and the caps cannot be raced. Every failure is a 422 on key `file`. Size is checked on bytes (`$file->getSize() <= max_bytes`, not the float KB rule), not by a validation rule.

### 7.3 Filename handling (display only)

`App\Support\AttachmentName::clean(string $clientName): string`: scrub invalid UTF-8 (`mb_scrub`), keep only the last path segment after splitting on both `/` and `\` (so `..\..\x.pdf` and `a/b/c.pdf` become `x.pdf` and `c.pdf`), strip all `\p{Cc}` and `\p{Cf}` characters (NUL, CR, LF, bidi overrides U+202E), collapse whitespace runs to one space, trim spaces and dots from both ends, cut to 100 characters while keeping the extension, fall back to `file.<ext>` when nothing remains. The extension check in 7.2 uses this cleaned name. The cleaned name is stored in `original_name` and is never used to build a path. `Content-Disposition` is produced by Laravel/Symfony (`attachment; filename="..."; filename*=UTF-8''...`) from the cleaned name, which can contain no CR/LF, so header injection is impossible; quotes and semicolons are encoded by the framework. The SPA shows it as a React text node.

### 7.4 Storage and download

- Write: `Storage::disk('quotes')->putFileAs('quote-attachments', $file, bin2hex(random_bytes(16)))` inside the locked transaction; on any exception after the write, delete the file and rethrow.
- Download `GET /quotes/{number}/attachments/{attachment}`: lookup order = role middleware, route patterns (`{number}`, `{attachment}` uuid, malformed = 404), quote via `visibleTo` (404), attachment via the quote's own relation by `public_id` (an attachment of a different quote or company is 404), `viewAttachment` (404), then stream `Storage::disk('quotes')->download($path, $cleanName, $headers)` with headers `Content-Type: <canonical mime>`, `X-Content-Type-Options: nosniff`, `Content-Security-Policy: default-src 'none'; sandbox`, `Cache-Control: private, no-store`, `Content-Disposition: attachment` (forced, never `inline`). A missing file on disk with an existing row logs an error and returns 404 (never 500, never a path in the body). Any logged-in company user may download; downloads count only against the shared 60/min `api` bucket.
- No public URL, no `storage:link`, no signed URL. The SPA uses a plain `<a href="/api/v1/quotes/{number}/attachments/{id}" download>`; the session cookie authorises the GET (no CSRF needed for GET).

### 7.5 Text echoed elsewhere

Attachment names and note text are never put into emails or subjects. CSV/XLSX exports (Phase 5/6) must escape any cell starting with `= + - @ tab CR` (line `description`, notes and PO are customer text; only PO has a leading-character rule).

## 8. Emails

Two Mailables in `app/Mail`, both `implements ShouldQueue` (database queue), `tries = 3`, `backoff = [60, 300]`, Markdown templates under `resources/views/mail/quotes/`, built with Blade `{{ }}` escaping only (no `{!! !!}`).

| Event | Mailable | To | Subject | Body content | Link |
| --- | --- | --- | --- | --- | --- |
| Customer creates a quote | `QuoteSubmittedMail` | staff recipients (below) | `New quote request QUO-XXXXX-XXXXX` | quote number, company name, requester name, number of lines, PO reference (restricted charset) or "none" | `{web_url}/quotes/{number}` |
| Staff sends a quote | `QuoteSentMail` | the user who created the quote (`created_by`) | `Your quote QUO-XXXXX-XXXXX is ready` | quote number, total formatted with the quote's currency, valid-until date ("valid through 2026-12-31 UTC"), line count | `{web_url}/quotes/{number}` |

Rules: one message per event (a re-send after reopen is a new event and sends again); sent AFTER the transaction commits and outside it (so a rollback never emails and a queue outage never fails the request; the failure is logged). Links use `config('app.web_url')` only, never the request Host. No customer note, line text, staff note, attachment names, internal note, tokens or session data in any email (untrusted text and prices stay behind login; this also removes header-injection and formula concerns, and the subject has no user text). Customer name and company name are staff-visible data and Blade-escaped.

Recipient logic lives in `App\Services\QuoteNotifier`. Staff recipients: `config('quotes.notify_address')` when set (one shared mailbox); otherwise users with role `staff` or `admin` (ordered by id). The list is capped at `quotes.max_notify_recipients` (50); a truncation is logged with `Log::warning`. One queued `QuoteSubmittedMail` PER recipient (never several addresses in `To`, so no recipient sees another's address and one failing address, with its own 3 tries and backoff, does not stop the others; a failure while queueing one is logged and the loop continues). If the list is empty: log a warning and continue. With the demo seed the fallback is `staff@portal.test` and `admin@portal.test`, so the Mailpit round trip works with zero config.

Failure behaviour: a failure while queueing the mail (queue connection down, exception in the notifier) is caught and logged by the caller and never fails the HTTP request. A mail job runs at most 3 tries (`$tries = 3`) with `$backoff = [60, 300]` seconds, so the worker retries after 60 s, then 300 s, then the job lands in `failed_jobs` (`php artisan queue:failed`); the quote is unaffected. A real provider, SPF/DKIM and bounce handling are Phase 8 (note in the Phase 8 checklist: set `QUOTES_NOTIFY_ADDRESS` to a monitored mailbox).

## 9. Query-count guarantee

`preventLazyLoading` is on outside production. Upper bounds (constant in rows, items, history, attachments), with the counts actually measured by the implementation: `GET /quotes` measures 9 queries, test bound at most 10 (user + roles, count, rows with `company:id,name` and `createdBy:id,name`, `withCount items as line_count`; the earlier design bound of 8 was too tight); `GET /quotes/{number}` measures 10, test bound at most 11 (quote scoped, items, history with actors and roles, attachments with uploaders and roles, company, createdBy, order); `POST /quotes`: INSERT statement count equal for 1 and 20 lines (bulk item insert); orders list and detail grow by one query each for `quote` (bounds 9 and 10, already raised in the Phase 3 tests). Rule: a bound is the measured count plus at most one query of headroom, and the test also asserts equality between small and large data sets, so a regression to N+1 still fails.

## 10. Seeders and factories

Factories: `QuoteRequestFactory` (valid default; states `submitted()`, `inReview()`, `quoted()` (priced, `sent_at`, `valid_until` in the future, `revision 1`), `accepted()` (also creates the order and history), `declined()`, `expired()` (materialised), `forCompany($c)`), `QuoteItemFactory` (`catalog()`, `custom()`, `priced()`), `QuoteAttachmentFactory` (creates a tiny file on `Storage::fake('quotes')`). Tests build their own data with factories; none use the seeder except the seeder test.

`QuoteDemoSeeder` (local and testing only: aborts otherwise like `OrderDemoSeeder`; called from `DatabaseSeeder` in those environments; requires `DemoSeeder` users and products, calling those seeders if missing; idempotent by `quote_number`; direct inserts so statuses and dates can be fixed; NO attachment rows and NO files, because seeding tiny fixture files would leave rows that fail if `storage/` is wiped, and QA uploads real files by hand):

| Number | Company | Creator | Status | Notes |
| --- | --- | --- | --- | --- |
| `QUO-NWAAA-AAAA2` | Northwind | alice | submitted | 2 lines: 1 catalog (the first active `price_on_request` product if any, else a priced one), 1 custom |
| `QUO-NWAAA-AAAA3` | Northwind | bob | in_review | 2 lines, first line draft-priced (hidden from customers), `internal_note` "Check lead time with supplier" |
| `QUO-NWAAA-AAAA4` | Northwind | alice | quoted | fully priced, `valid_until` = seeding day (UTC) + 30 days, `staff_note`, `po_reference` `NW-PO-3001`, `revision 1` |
| `QUO-NWAAA-AAAA5` | Northwind | bob | accepted | lines copied from the seeded order `ORD-NWAAA-AAAA3` (same snapshot and prices), and that order gets `quote_request_id` set to this quote; NO new order is created, so the Phase 3 seeder test (8 orders) stays valid |
| `QUO-NWAAA-AAAA6` | Northwind | alice | declined | declined by the customer from `quoted`, reason "Price too high" |
| `QUO-NWAAA-AAAA7` | Northwind | alice | expired | quoted, `valid_until` 2026-10-31, materialised with a system history row |
| `QUO-GXAAA-AAAA2` | Globex | carol | submitted | 1 custom line |
| `QUO-GXAAA-AAAA3` | Globex | carol | quoted | priced, `valid_until` = seeding day + 14, `po_reference` `GX-PO-4001` |

Visibility: Alice and Bob see the same 6 Northwind quotes, Carol sees her 2, staff sees 8. Staff queue (`status=submitted,in_review`): 3 rows (`QUO-NWAAA-AAAA2`, `QUO-NWAAA-AAAA3`, `QUO-GXAAA-AAAA2`), not 4 as an earlier draft said. The relative `valid_until` values mean the quoted demo rows expire in real time; the seeder test asserts counts, statuses and the invariants, never those dates. History rows are coherent (creation row, then each step with actor and fixed UTC timestamps, system actor for the expired one).

## 11. Frontend design (`web/src/features/quotes/`)

Suggested layout: `api.ts`, `schemas.ts` (Zod for responses and the form), `urlState.ts`, `QuotesPage.tsx`, `QuoteFormPage.tsx`, `QuoteDetailPage.tsx`, `StatusBadge.tsx`, `StatusTimeline.tsx`, `ProductPicker.tsx`, `AttachmentsPanel.tsx`, `PricingForm.tsx`, `ActionDialogs.tsx`, `money.ts` (parse "12.50" to cents, reuse the orders formatter).

Routes (inside `ProtectedRoute`): `/quotes` and `/quotes/:number` any logged-in role; `/quotes/new` customer only (`RoleRoute`, others get the 403 page). Nav: "Quotes" for all roles. `:number` not matching `[A-Za-z0-9-]{1,32}` or a 404 shows one "Quote not found" page (unknown and foreign look identical, no request for the malformed case).

### 11.1 Form (`/quotes/new`)

React Hook Form + Zod (`useFieldArray`). Fields: PO reference (same Zod rules and `String.prototype.trim()` as checkout), note (`textarea`, max 2000, counter), lines (at least 1, at most 50). Each line is a discriminated union: catalog (`product_id`, display-only `part_number`/`name`, `quantity`) or custom (`description` 1..255 after trim, no control/format characters except none allowed in a single-line field, `quantity`). Quantity: integer 1..9999. Buttons: "Add catalog item" (opens `ProductPicker`), "Add custom item", "Remove line" (labelled per line, focus moves to the next line or the add button). Errors per field with `aria-invalid` + `aria-describedby`; server `errors['lines.N.field']` mapped to the right line; an error summary at the top takes focus on submit failure.
- Catalog picker: a labelled search input (debounced 300 ms) calling the existing `GET /products?q=...&per_page=10` (any role endpoint, nothing new); results list with an "Add" button each; part number and name shown; already-added products disabled ("Already in this quote"). Prices are not shown and never sent.
- Attachments: file input (`multiple`), client-side pre-check for extension (pdf, png, jpg, jpeg, txt, csv), 10 MiB each, 10 files; invalid files listed with the reason and not queued (UX only; the server is the authority). On submit: `POST /quotes`; then upload files ONE AT A TIME (`FormData`, field `file`, no manual `Content-Type`, `X-XSRF-TOKEN` header), per-file status in an `aria-live` region; navigate to `/quotes/{number}` when done. If a file fails (422/413/429/network) the quote still exists: go to the detail page with a notice listing the failed files, where the attachments panel lets the user retry (status is still `submitted`). Quote creation is never retried automatically (no double quote); the submit button is disabled while pending.
- Prefill from the catalog (price-on-request flow): on the catalog list rows and the product detail page, for CUSTOMERS, a product that is `price_on_request` or `out_of_stock` shows a "Request quote" link (replacing the Phase 3 text-only "Price on request"/"Out of stock" cell; the text stays visible as well) to `/quotes/new?product=<id>` (repeatable `product` param, at most 20). The form calls `GET /products/{id}` for each id, creates catalog lines with quantity 1, and silently drops ids that 404 or are not integers (shows a notice "N items could not be added"). Only ids travel in the URL; no price, name or quantity is read from it. Priced in-stock rows keep "Add to cart" only (adding a "Request quote" link for them too is a one-line change, open question 7). Staff and admin see no "Request quote" link and `/quotes/new` shows the 403 page.

### 11.2 List (`/quotes`)

URL is the source of truth (as `/orders`): `status` (CSV checkboxes in a `<fieldset>`), `from`, `to`, `q` ("Quote number or PO reference", 300 ms debounce, max 32), `sort` (Newest, Oldest, Number), `page`. `per_page` and `company_id` not in the URL. Defaults omitted, invalid values dropped, `from` after `to` shows an inline alert and sends nothing. Table: Number (link), PO reference (plain text, em dash for null), Date, Status (`StatusBadge`), Lines, Total (em dash or "Awaiting pricing" when null), Valid until (shows "Expired" text for expired). Staff also see Company. Staff get a "Needs attention" shortcut that sets `status=submitted,in_review`. States: skeleton, empty with and without filters (customer: "You have no quote requests yet." + "Request a quote" button; staff: "No quotes."), error + retry, 429 seconds, result count in an `aria-live` region.

### 11.3 Detail (`/quotes/:number`)

Heading with number and `StatusBadge` (text label plus colour, six statuses), requester and date, PO reference (text node, "None" when null), customer note (text node, `white-space: pre-wrap`), staff note (when present), lines table (caption "Requested items"; custom lines labelled "Custom item"; price columns show "Awaiting pricing" while hidden or null), totals, "Valid until 2026-12-31 (end of day, UTC)", status timeline `<ol>` from `history` (actor name or "Portal staff", reason notes shown), attachments panel (name, size, uploader, download link, delete button with confirm when `attach` is allowed, upload control when `attach` is allowed). Order link "Order ORD-..." when `order` is present.
- Customer actions from `allowed_actions`: Accept opens a dialog showing lines, total, valid-until, an optional PO field, "Accept quote and create order" (sends `revision` from the loaded detail; on 422 `revision` shows "This quote was updated. Please review the new prices." and refetches); Decline/Withdraw opens a dialog with an optional reason. Expired (effective): banner "This quote expired on {date}. Request a new quote." with a link to `/quotes/new`; no accept/decline buttons. After accept: navigate to `/orders/{number}` with a router-state banner "Quote accepted. Order ORD-... was created and is pending confirmation."
- Staff actions: "Take into review" (submitted); in `in_review` a pricing form (unit price input per line, validated with a strict regex `^\d{1,7}(\.\d{1,2})?$` and at most 10,000,000.00, converted to integer cents; client-side line totals are a convenience preview, the server totals are displayed after save), a `valid_until` date input (min tomorrow, max +365 days), "Save prices" (`PUT /pricing`), "Send quote" (dialog with optional staff note; shows the server's 422 for unpriced lines or a bad date), "Reject" (dialog, reason required); `quoted`: "Reopen for editing"; internal note textarea with its own Save (label "Internal note (never shown to the customer)"). All buttons come from `allowed_actions`; hiding is a convenience, the server decides.
- Dialogs: native `<dialog>` or `role="dialog" aria-modal="true"` with focus trap, Esc to close, focus returned to the trigger, buttons disabled while pending (double click sends once).
- Errors: 404 "Quote not found" page; 422 on actions shows the `status` message and refetches; 413 "The file is too large for the server."; 422 `file` message under the upload control; 429 "try again in N seconds".

### 11.4 Orders pages (Phase 3 amendments)

Order detail shows "From quote QUO-..." (link) when `quote_number` is not null; custom lines render the description with a "Custom item" label, no part-number link, no catalog link; the reorder result panel labels the new skipped reason `not_in_catalog` as "Custom item, not in the catalog". Order list may show a small "Quote" text marker (optional, no new column required).

## 12. Test plan

Gate items from `docs/PLAN.md` Phase 4 come first and in the PLAN order, then extras. Backend: Pest Feature tests on `portal_test`, `RefreshDatabase`, `RolesSeeder`, Phase 1 helpers (`makeUser`, `loginAs`, Origin and Accept headers), factories. Standard fixture: company A (users a1, a2), company B (b1), one staff, one admin, a few products (priced, price-on-request, out-of-stock, inactive).

### 12.1 Backend (Pest) - `backend-engineer`

Gate items:
1. Customer creates a quote with catalog and free-text lines; other companies cannot see it. a1 `POST /quotes` with one catalog line (snapshot of part_number, name, brand stored; a later product price/name/active change does not alter the quote lines) and one custom line, plus `po_reference` and `customer_note`: 201 exact `QuoteDetailResource` keys, `status = submitted`, `company_id` = a1's, history row `null -> submitted` by a1, no prices exposed. a2 (same company) lists and reads it. b1: `GET /quotes` (A's number absent, `meta.total` excludes it), `GET /quotes/{number}` 404 exact body (identical to a random unknown number, with `APP_DEBUG` true and false), `POST /accept|decline` 404, attachment upload/download/delete 404; staff and admin see it. A's PO and customer note appear nowhere in b1's responses (raw text assertion).
2. Staff prices and sends; customer gets a queued email. Staff `review`, `PUT pricing`, `send` (`Mail::fake()`): `Mail::assertQueued(QuoteSentMail::class)` exactly once, `hasTo(creator email)`, rendered body contains `config('app.web_url').'/quotes/'.$number`, the total and valid-until, does NOT contain the staff note, customer note, line text or an attachment name; the mail class implements `ShouldQueue`; `Mail::assertQueued(QuoteSubmittedMail)` on create with staff recipients (fallback to staff/admin users) or exactly `quotes.notify_address` when set. Send with an unpriced line 422 `lines`; `valid_until` missing, today, past, more than 365 days ahead: 422 `valid_until`; totals server-side (tampered `total_cents`, `unit_price_cents` from the customer ignored).
3. Accepting creates exactly one order; a second accept is rejected. a1 accept (valid `revision`) -> 200, quote `accepted`, exactly one order with `quote_request_id`, status `pending`, one `null -> pending` order history row (actor a1), items equal the quote lines (custom lines have NULL product_id/part_number/brand), `subtotal/total` equal the quote total, tax/shipping 0, currency of the quote, `po_reference` from the quote (or the body value), later catalog price change or product deactivation irrelevant; quote history `quoted -> accepted` actor a1. Second accept (sequential) 422 `status`, still 1 order, no extra history. a2 or staff also cannot accept again; staff accept is 403; accept by b1 404. Order of checks: invalid body 422 before lookup, then 404, then state, then `revision`.
4. Expired quote cannot be accepted: with `travelTo` at `valid_until 23:59:59 UTC` accept succeeds; at `valid_until + 1 day 00:00:00 UTC` accept is 422 `status` and no order exists, decline and reopen are 422 too; before `quotes:expire` has run the API already returns `status: "expired"`, `allowed_actions` has no accept; after `quotes:expire` the stored status is `expired`, one history row with NULL actor, and re-running adds nothing. Timezone: a user-agent-irrelevant UTC rule (assert with the app timezone untouched and with a `Carbon::setTestNow` in a non-UTC zone object).
5. Upload rejects wrong MIME and over 10 MB; files only downloadable by authorized users. Wrong content: `.pdf` containing an EXE header (`MZ`), HTML, SVG, a ZIP; `.exe`, `.html`, `.svg`, `.php`, `.docx`, `.zip`, `.js` names; a `.png` that is really a PDF; extensionless; double extension `a.php.pdf` with real PDF content accepted and stored under a random path; client-supplied `Content-Type: application/pdf` on an EXE is ignored. Size boundary: exactly 10,485,760 bytes accepted (201), 10,485,761 rejected 422 `file`, 0 bytes 422. Valid pdf/png/jpg/txt/csv accepted (fixtures generated in code). Download: owner company 200 with `Content-Type` = canonical MIME, `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`, `Cache-Control` private, body identical to the upload; guest 401; company B user 404; same user but an attachment id belonging to another quote 404; staff 200; staff attachment before `sent_at` is 404 for the customer and 200 after send; stored file lives under `quote-attachments/<32 hex>` on the `quotes` disk and not under `public/` or the `public` disk; an unsigned `GET` of a guessed `/storage/...` path is a plain 404 (the `local` disk has `serve => false`, so no storage route exists; it used to be a signed-route 403). For a customer viewer, attachments[].uploaded_by is `{ "name": null, "role": "staff" }` for a staff or admin uploader (same masking as history); a staff viewer sees the real name and `admin` shown as `staff`.

Isolation, IDOR and security extras:
6. IDOR matrix: every endpoint x {guest, a1, b1, staff, admin} x {own, foreign, unknown, malformed number} with the exact status and body from the contract (404 body byte-exact; role 403 before lookup even for nonexistent numbers; malformed number is 404 for everyone).
7. Staff-only fields: customer responses never contain `internal_note` (key absent, also in list and in error bodies), draft prices, draft `valid_until`, `staff_note` or `revision` before `sent_at`, staff attachments before `sent_at`; after `reopen` they disappear again; customers sending `internal_note`, `staff_note`, `unit_price_cents`, `valid_until`, `status`, `company_id`, `created_by`, `quote_number`, `total_cents`, `revision`, `sent_at` in `POST /quotes` or any other body: ignored (201, values server-set). Models: `$fillable` empty for the four quote models.
8. Transition matrix: data provider over all 6 statuses x all actions x {customer, staff, admin} with the expected allow/deny (section 5.1); every denied transition 422 `status` (or 403 for a wrong role), state and history unchanged; `allowed_actions` equals the matrix row for the viewer; reject needs a reason (422 `reason`), reopen clears `sent_at`, resend bumps `revision` and a stale `revision` in accept is 422 `revision` with no order.
9. Concurrency (sequential simulation in Pest, real parallel in QA): accept twice, accept after decline, send after withdraw, reopen then accept with the old revision; a failure injected on the history insert or the order insert rolls back everything (no order, status unchanged, no history); a second order for the same quote inserted directly fails on `orders_quote_request_id_unique`.
10. Validation of create: `lines` missing, empty, not an array, 51 lines, a line that is not an object, both `product_id` and `description`, neither, `product_id` unknown / inactive / string / 0 / negative / duplicate, price-on-request and out-of-stock products ACCEPTED, `description` empty / whitespace-only / 256 chars (255 accepted) / with control or format characters (NUL, ESC, U+200B, newline) / interior NUL / invalid UTF-8, `quantity` 0, -1, 10000, 1.5, "2", null, missing; `po_reference` cases reuse the Phase 3 data set through the shared validator (trim set, NUL on the raw value, `Cc`/`Cf`, 64/65, leading characters, charset); `customer_note` 2000 vs 2001 characters, interior NUL, ESC, newline allowed, trailing NUL stripped by `TrimStrings`; every 422 case writes nothing (no quote, items, history, mail). 422 `errors` keys are `lines`, `lines.N.field`, `po_reference`, `customer_note`; exact placement as implemented: a `description` that is blank after trimming reports on `lines.N.description`; a line with neither `product_id` nor `description` reports on `lines.N.product_id`; a line that is not a JSON object reports on `lines` (not `lines.N`). Line `description` containing `<script>`, `=SUM(A1)`, quotes is stored and returned verbatim as JSON text.
11. Attachments extras: path traversal and injection names (`../../x.pdf`, `..\..\x.pdf`, `a/b.pdf`, `x.pdf\0.exe`, `NUL`/`CON.pdf`, `"; filename=evil.exe`, CRLF in the name, bidi override `\u202E`, 300-character name, emoji, name that is only dots) are sanitised; the response `name` and the download `Content-Disposition` contain no path separators or CR/LF; stored path is always `quote-attachments/<32 hex>`; caps (11th file 422, total above 25 MiB 422, parallel via sequential boundary); closed states (`quoted`, `accepted`, `declined`, `expired`) upload and delete 422 `status`; customer deletes own file 204, cannot delete staff's (404) or another company's (404); staff deletes any; delete removes the row and the file (`Storage::fake('quotes')` assertions), a failing file removal is logged and still 204; sha256 and size stored correctly; missing file on disk with a row: download 404, no 500; `PostTooLargeException` renders 413 `{"message":"The upload is too large."}` on `api/*` only (unit-test the renderer); a malformed `{attachment}` id is 404 by route pattern for every role, before any middleware; upload limiter: the 21st upload within 60 s is 429 with `Retry-After`, other user unaffected, `travel(61)->seconds` resets.
12. Rate limits: the 11th `POST /quotes` within 60 s from a user is 429 (including 422 attempts), staff/admin get 403 (never 429, role middleware first), another user unaffected; the shared `api` 60/min applies to the rest.
13. Query counts: list at most 10 (measured 9) and equal for 3 vs 30 quotes and `per_page` 5 vs 100; detail at most 11 (measured 10) and equal for 1 vs 20 items/history/attachments; `POST /quotes` INSERT count equal for 1 vs 20 lines; orders list/detail at most 9/10 with the `quote` relation; no `LazyLoadingViolationException`.
14. List filters: `status` single, CSV (including the effective `expired` and `quoted` rules with `travelTo` across the boundary, and an expired-but-unmaterialised row), `from`/`to` day edges UTC inclusive, `q` by number (normalised, partial, lowercase, spaces) and PO (case-insensitive, `%`/`_`/`\` literal, `q=%` empty), the OR group never escapes the company scope even combined with filters, `company_id` honoured for staff and ignored for customers (`company_id=abc` 422 for staff, ignored for a customer), `sort` four values with id tie-break, `per_page` 1..100 and 101 is 422, `meta` exactly six keys, page beyond last 200 empty, 422 cases (bad status, `status[]=x`, bad dates, `from` after `to`, bad sort, `page=0`, 33-character `q`, interior NUL, invalid UTF-8), `q=%00`/`q=abc%00` trimmed and 200.
15. Emails extras: the link ignores the request `Host` header (send a request with `Host: evil.test`, link still `config('app.web_url')`); no mail when validation fails or the transaction rolls back; staff recipients: notify address wins over users, fallback is staff plus admin users only (never customers), empty recipient list logs a warning and the request still succeeds; a mail dispatch exception does not turn a 201 into a 500; `QuoteSentMail` goes to the creator, not to every company user; a re-send after reopen sends one more.
16. Database: number format CHECK and UNIQUE; bad status in the three tables; `quote_requests_sent_requires_price` rejects a `quoted` row with NULL total, `valid_until`, `sent_at` or `revision` 0; totals CHECK; `quote_items` kind CHECK (product without part number, custom with brand), price CHECK (line total <> unit x quantity), unit price 1,000,000,001 rejected and 1,000,000,000 accepted, quantity 0 and 10000 rejected, unique position, unique catalog product, many custom lines allowed; history CHECKs (`from = to`, NULL actor only for `expired`); attachments CHECKs (mime outside the allowlist, size 0 and 10,485,761, bad `storage_path`, bad sha256); RESTRICT FKs (delete company/user/product/quote with dependants fails); `orders.quote_request_id` UNIQUE, `order_items` accepts the custom-line shape and rejects a half-null line; existing order CHECKs still hold; the new indexes exist in `pg_indexes` (incl. the partial one).
17. Phase 3 amendments: order resources contain `quote_number` (null for cart orders, set for converted) at the position right after `po_reference` in both `OrderResource` and `OrderDetailResource` (exact-key tests assert the key set; do not assert order positions beyond this); order items of a converted custom line serialise `product_id`/`part_number`/`brand` as null; reorder of such an order skips custom lines with reason `not_in_catalog` and `added` plus `skipped` still cover every line once; all Phase 3 tests updated and green; `PlaceOrder` number-collision retry still works through `OrderInserter`.
18. `quotes:expire`: expires only `quoted` rows past `valid_until`, leaves the boundary day alone, is idempotent, writes system history, scheduled every five minutes (assert the `Schedule` entry), no mail sent.
19. Seeder: `QuoteDemoSeeder` twice gives 8 quotes with the documented statuses and visibility (Alice = Bob = 6, Carol 2, staff 8, queue 3), every quote satisfies the item/total/history invariants, `ORD-NWAAA-AAAA3` links to `QUO-NWAAA-AAAA5` and `Order::count()` stays 8, aborts in `production`/`staging`, `DatabaseSeeder` runs it in `testing`, no attachment rows.
20. Policy unit tests for every `QuotePolicy` method (customer same company, other company, null company, staff, admin, no role), the structure test (no unscoped quote query in controllers), `pint --test` clean, no 500 for any bad input listed above.

Post-review hardening tests (added with the final doc sync; each uses `config([...])` for the limits, never `putenv`):
21. Company storage limit: with `quotes.attachments.max_company_bytes` set to a small value, an upload that would exceed it is 422 `file` and stores nothing (no row, no file); a file that lands exactly on the limit is accepted; attachments on CLOSED quotes of the same company still count; a staff upload counts toward the quote's company, and another company's usage is not counted; the quote row and then the company row are locked (assert the order with a query log: quote `FOR UPDATE` before company `FOR UPDATE`); the sequential boundary is tested (parallel overshoot is a QA check).
22. Open-quote cap: with `quotes.max_open_per_company` = 2, the third `POST /quotes` is 422 `quote` and creates nothing (no quote, items, history, mail); `submitted`, `in_review` and unexpired `quoted` count; `accepted`, `declined` and an expired-but-unmaterialised `quoted` quote (use `travelTo` across `valid_until`) do not count, so accepting or declining one frees a slot; another company is unaffected; default config value is 50.
23. Hourly upload limiter: the 121st upload within an hour is 429 with `Retry-After` even when the per-minute limiter is clear (use `travel` to step past the 20/min window), 422/413 attempts count, another user is unaffected, `travel(3601)->seconds` resets; the 21st upload in a minute is still 429 (both limiters present); staff/admin on `POST /quotes` and customers on staff routes get 403 before any 429.
24. Per-recipient mails: with three staff/admin users `Mail::assertQueued(QuoteSubmittedMail::class, 3)`, each message `hasTo` exactly one address and no message has more than one recipient in `To`, `cc` or `bcc`; with `quotes.notify_address` set exactly one message to it; with 51 staff/admin users only 50 are queued (lowest ids) and `Log::warning` is written once; a recipient whose queueing throws is logged and the other recipients still get theirs and the request is 201; the job class has `tries = 3` and `backoff = [60, 300]`.
25. Customer `updated_at`: after staff `PUT /pricing` and `PUT /internal-note` (real `updated_at` moves) the customer's `updated_at` of the quote is unchanged in list and detail, while staff see the new real value; after `send` it equals the `sent_at`/history time; after `reopen` it equals the reopen history time; a quote with only the creation row equals `created_at`; the value never goes backwards.
26. Download security headers: the response has `Content-Security-Policy: default-src 'none'; sandbox` together with `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff` and `Cache-Control` containing `private` and `no-store`; asserted for all five file types and for staff and customer downloads.
27. Revision bounds: accept with `revision` 0, -1, 2147483648, `"1"`, 1.5, true, null, missing and `[]` is 422 `revision` with no order; 2147483647 passes validation (then 422 `revision` as a mismatch, since the stored one differs, or 200 when a factory row with `revision = 2147483647` is used, proving the column is wide); a schema test asserts `quote_requests.revision` is `integer` (not smallint) and that 40000 can be stored.
28. Dual-role policy: a user holding `customer` and `staff` (and one with `customer` and `admin`) in the quote's own company gets 403 on `accept` and `decline` (state and history unchanged, no order); the pure customer still succeeds; policy unit tests cover `accept` and `decline` for the dual-role user.
29. Malformed JSON: for each of `POST /quotes`, `accept`, `decline`, `reject`, `PUT /pricing`, `send`, `PUT /internal-note`, bodies `{`, `abc`, `"text"`, `123`, `null` (sent as raw content with `Content-Type: application/json`) give 422 with key `body` and write nothing; an empty body, `{}` and `[]` are accepted by the endpoints where all fields are optional (`decline`, `send`, `PUT /pricing`) and give the normal field 422 elsewhere (never `body`); a guest still gets 401, a wrong role 403, a foreign or unknown number 404 (these three take precedence over the `body` 422); never a 500.

### 12.2 Frontend (Vitest + RTL) - `frontend-engineer`

Mock `fetch` as before; `QueryClientProvider` (retry off), `createMemoryRouter`, fake timers for debounce.

Gate items:
1. Dynamic quote form validates: starts with no lines and blocks submit ("Add at least one item"); add catalog line from the picker (search debounced, `GET /products?q=` called once after 300 ms, already-added product disabled), add custom line, remove line (focus moves), quantity 0, 10000, 1.5, empty rejected inline with `aria-invalid` and `aria-describedby` and no request; custom description empty, 256 chars, control character rejected; 51st line blocked; PO reference rules identical to checkout (trim, `{}`/omit when empty, `-`/`=`/`%`/65 chars/zero-width space rejected); note over 2000 rejected; the `POST /quotes` body has exactly `po_reference?`, `customer_note?`, `lines` and each line has only `product_id|description` plus `quantity` (no prices, no names); a server 422 `errors['lines.1.description']` shows on line 2; submit disabled while pending (double click sends once).
2. Status badges render: all six statuses show the right text label (`Submitted`, `In review`, `Quoted`, `Accepted`, `Declined`, `Expired`) and a distinguishing class; unknown status falls back safely; an effective-expired quote shows "Expired" not "Quoted".

Extras:
3. Attachments in the form: wrong extension and 10 MiB + 1 byte and the 11th file are rejected client-side with a message and not uploaded; valid files are uploaded sequentially after the create, `FormData` field `file`, no `Content-Type` header set manually, `X-XSRF-TOKEN` present; one failing upload (mock 422/413/429) leaves the user on the detail page with a notice and a retry; the quote is not created twice.
4. Prefill: `/quotes/new?product=17&product=21&product=abc` fetches `GET /products/17` and `/21`, creates two lines with quantity 1, drops `abc` and a 404 id with a notice; no price from the URL is used; the catalog list and detail show "Request quote" for customers on price-on-request and out-of-stock products only, not for staff/admin, and the link carries the product id.
5. List: URL sync like orders (`status` CSV, `from`, `to`, `q` debounced, `sort`, `page`; restore from URL; invalid values dropped; `from` after `to` blocks; Clear filters), loading skeleton, empty with and without filters (customer vs staff text), error + retry, 429 seconds, Company column only for staff/admin, "Needs attention" shortcut for staff, "Awaiting pricing" when total is null.
6. Detail (customer): shows lines, hidden prices as "Awaiting pricing", timeline `<ol>`, attachments with download links (`href` is the API path, `download` attribute), Accept dialog sends `{ revision, po_reference? }` only, success navigates to the order with the banner, 422 `revision` shows the stale message and refetches, 422 `status` message shown; Decline/Withdraw dialog with optional reason; expired banner and no accept button; buttons shown only for `allowed_actions`; 404 and malformed number show the same "Quote not found" page (the latter sends no request); notes and PO rendered as text (`<b>` in a note stays literal).
7. Detail (staff): pricing form parses `12.5` to 1250 and `12.50` to 1250, rejects `-1`, `1e3`, `10000000.01`, `abc`, empty means unpriced; `PUT /pricing` body is `{ lines: [{position, unit_price_cents}], valid_until? }`; Send dialog shows server 422 messages; Reject needs a reason; Reopen; internal note saved and labelled; a customer-role render never shows the internal note field.
8. Routing and nav: guest redirected to login with `next`; customers see Quotes and can open `/quotes/new`; staff/admin see Quotes and get the 403 page on `/quotes/new`; logout clears the quote cache; orders pages show "From quote" link and the custom-line rendering; reorder panel label for `not_in_catalog`; Phase 3 catalog tests updated for the new link.
9. `npm run lint`, `tsc -b`, `npm test`, `npm run build` pass.
10. Hardening messages: a 422 `quote` on create shows a form-level error and keeps the form values; a 422 `file` for the company storage limit shows under the upload control like any other file error; a 429 from either upload limiter shows the "try again in N seconds" message; a customer's `updated_at` is displayed as returned (no client-side recomputation); the Accept request `revision` is sent as the integer from the detail (values above 32767 are not truncated or rejected client-side).

### 12.3 QA (`qa-engineer`)

Run `cd api && ./vendor/bin/pest && ./vendor/bin/pint --test`; `cd web && npm run lint && npm test && npm run build`; confirm CI is green with no workflow change. Before manual steps: PHP limits (`php -r "echo ini_get('upload_max_filesize'),' ',ini_get('post_max_size');"` must print `11M 12M` or larger), `docker compose up -d`, `php artisan migrate:fresh --seed` (local, so `QuoteDemoSeeder` runs), `php artisan serve`, `composer dev` queue listener (or `php artisan queue:listen`), `npm run dev`, Mailpit at `http://localhost:8025`. Manual:
1. Full round trip with Mailpit (the gate): Alice logs in, requests a quote (one catalog line from "Request quote" on a price-on-request product, one custom line, a PDF attachment); Mailpit shows ONE "New quote request" mail to the staff address(es) with a working link and no customer text. Staff opens the link, takes it into review, prices both lines, sets a validity date, adds an internal note, sends; Mailpit shows ONE "Your quote ... is ready" mail to Alice with total, date and link. Alice (and Bob, same company) see prices only now; Alice accepts with a PO; the order appears as pending with "From quote ..."; the quote shows `accepted` and its order; staff confirms the order. Carol (Globex) never sees any of it.
2. Isolation: Alice opens a Globex quote URL (`QUO-GXAAA-AAAA2`) -> "Quote not found"; `curl` with Alice's cookie: detail, accept, decline, attachments (upload, download, delete) of Carol's quote all return 404 with the exact body; staff-only endpoints as Alice return 403; staff sees all 8 seeded quotes.
3. Hidden drafts: as staff open `QUO-NWAAA-AAAA3` (draft price visible, internal note visible); as Bob the same quote shows "Awaiting pricing" and no internal note (also check the raw `curl` JSON has no `internal_note` key and no `unit_price_cents` values).
4. Exactly once: with `QUO-NWAAA-AAAA4` (quoted) and Alice's cookie jar + XSRF header, fire two `POST /accept` in parallel (`&`): one 200 and one 422; `select count(*) from orders where quote_request_id = ...` returns 1; the UI double click on "Accept" also creates one order. `artisan serve` runs with `PHP_CLI_SERVER_WORKERS=4` so the requests really overlap.
5. Expiry: set a quote's `valid_until` to yesterday in tinker (`quoted` row): the UI shows "Expired" immediately, accept returns 422; run `php artisan quotes:expire` and check the stored status and the system history row. Then `valid_until` = today: still acceptable.
6. Uploads with real multipart `curl` (`--form-string` for text fields, `-F file=@path` for files; cookie jar and `X-XSRF-TOKEN`): valid PDF/PNG/JPG/TXT/CSV 201; a renamed `.exe` as `.pdf`, an HTML as `.pdf`, an SVG as `.png` 422; 10,485,760 bytes ok; 10,485,761 bytes 422; about 11.5 MB 422; about 13 MB 413 body `{"message":"The upload is too large."}`; 11th file 422; download as Alice works and the response headers show `attachment`, `nosniff`; as Carol 404; as a guest 401; `find api/storage -name '*.pdf'` shows only random names with no extension under `storage/app/private/quotes/quote-attachments/` and nothing under `api/public`; try `http://localhost:8000/storage/...` and a direct file path in the browser: 404. Open a `.csv` attachment starting with `=cmd` in the UI: it is a download, not rendered.
7. Staff pricing form: invalid price strings, past/too-far validity date, send with an unpriced line (server messages shown), reject without a reason, reopen then re-send (second email, `revision` 2) and a stale Accept page returns the "was updated" message.
8. Rate limits: 11 rapid `POST /quotes` -> 429 with `Retry-After`; 21 rapid uploads -> 429; UI shows seconds. Hardening spot checks: set `QUOTES_MAX_OPEN_PER_COMPANY=2` and `QUOTES_COMPANY_MAX_BYTES` to a few MB locally and confirm the 422 `quote` and 422 `file` messages; fire two large uploads in parallel against a nearly full company limit and confirm the locks keep the total under the cap (the only real parallel check of the lock order, since the Pest tests are sequential); with 2 staff users and no `QUOTES_NOTIFY_ADDRESS`, Mailpit shows two separate messages each with a single recipient; a download shows the CSP, `nosniff` and `attachment` headers (`curl -i`).
9. Emails: kill the queue worker, create a quote (UI works, no mail), start the worker, the mail arrives; break the SMTP port, watch the job retry and land in `failed_jobs` after 3 tries, the quote is intact. Spoof `Host: evil.test` on the create request: the Mailpit link still points to `http://localhost:5173`.
10. Keyboard-only run through the form (add/remove line, picker, file input, submit error summary), detail dialogs (focus trap, Esc), axe scan with no serious violations, 375 px layout, screen-reader names for all inputs and buttons.
11. Query count spot check on `/quotes` with 8 vs 200 quotes for one company.
12. Report only; do not fix feature code.

### 12.4 security-reviewer focus

Company scoping on every quote and attachment query (scope + policy + relation-scoped attachment lookup), exact 404 for foreign, role middleware before lookup, no numeric ids in bodies or URLs, mass assignment (`$fillable`, `forceFill`), prices and totals never from customers and drafts hidden until `sent_at`, `internal_note` never serialised for customers (also in 422 and history), row locks and exactly-once accept (unique index), stale `revision`, expiry decided by `now()` in the locked transaction, content sniffing and allowlist (no client MIME trust, no SVG/HTML/archive), size and count caps under concurrency, random storage names and no path from user input, filename sanitisation and `Content-Disposition`/`nosniff`/`no-store` headers, private disk and no public URL, orphan file handling, upload limiter, email content (no untrusted text, link from `web_url` only), SQL safety of `q`/`status`/`sort`, XSS (all customer text rendered as text), CSRF on multipart writes, PHP upload limits documented.

### 12.5 Linux CI hygiene

- No test depends on `putenv`, `$_ENV` mutation or the host `php.ini` upload limits; config is changed with `config([...])` (for example `quotes.notify_address`, `app.web_url`).
- Time: only `travelTo`/`Carbon::setTestNow` with explicit UTC instants; no assertion on "now"; reset in `afterEach` (`travelBack`).
- Files: every test that stores a file uses `Storage::fake('quotes')` (temp dir managed by the framework) and `UploadedFile::fake()->createWithContent(...)` with content generated in code (PDF header `%PDF-1.4`, PNG 8-byte signature plus IHDR, JPEG `FF D8 FF E0` ... `FF D9`, text); no binary fixtures committed; no hard-coded `C:\` or `\` separators; never write under `storage/app` directly; large files (10 MiB) built with `str_repeat` and unlinked by the fake. Assertions on sniffed types use the same `finfo` that production uses, with fixtures whose magic bytes are unambiguous on every libmagic version (CSV accepts `text/plain` and `text/csv`).
- No random-data-dependent assertions: faker values are never asserted; numbers come from the generator only for format/uniqueness checks; the order-collision test fakes the generators.
- `Mail::fake()` and `Queue` are the only mail checks; no test talks to Mailpit or SMTP.
- Tests do not rely on array ordering of unsorted queries, on JSON key order, or on PHP's default timezone (assert ISO strings with `Z`).
- Case-sensitive file systems: nothing depends on file name case.

## 13. File plan (for plan-mode approval)

- api: `config/quotes.php`; `config/filesystems.php` (disk `quotes`); migrations (5); `app/Enums/QuoteStatus.php`; `app/Models/{QuoteRequest,QuoteItem,QuoteStatusHistory,QuoteAttachment}.php` (+ `Order::quote`, `Company`/`User` relations); `app/Policies/QuotePolicy.php`; `app/Services/{CreateQuote,QuoteWorkflow,QuotePricing,AcceptQuote,OrderInserter,QuoteAttachments,QuoteNotifier}.php` (`PlaceOrder` refactored to use `OrderInserter`; `OrderInserter` retries ONLY on an `orders_order_number_unique` collision, any other unique or DB error is rethrown, and is shared by `PlaceOrder` and `AcceptQuote`); `app/Support/{QuoteNumber,AttachmentName,AttachmentInspector,PoReference}.php` (`PoReference` holds the PO rules extracted from `PlaceOrderRequest`; the trait `app/Http/Requests/Concerns/ValidatesPoReference.php` wires it into FormRequests and is shared by checkout (`PlaceOrderRequest`), `CreateQuoteRequest` and `AcceptQuoteRequest`, so there is one PO validator); `app/Mail/{QuoteSubmittedMail,QuoteSentMail}.php` + `resources/views/mail/quotes/*`; `app/Console/Commands/ExpireQuotes.php` + schedule in `routes/console.php`; `app/Http/Controllers/Api/V1/{QuoteController,QuoteAttachmentController}.php`; Form Requests (`ListQuotesRequest` (extends `ListOrdersRequest`, reusing its date, `q`, `page`, `per_page` and `company_id` rules), `CreateQuoteRequest`, `AcceptQuoteRequest`, `DeclineQuoteRequest`, `RejectQuoteRequest`, `PricingRequest`, `SendQuoteRequest`, `InternalNoteRequest`; there is NO `UploadAttachmentRequest`, upload validation is `AttachmentInspector` called from `QuoteAttachments` under the row lock, see 7.2); Resources (`QuoteResource`, `QuoteDetailResource`, `QuoteItemResource`, `QuoteHistoryResource`, `QuoteAttachmentResource`) and the Phase 3 resource changes; `AppServiceProvider` limiters `quotes-create` (10/min/user), `quote-uploads` (20/min/user) and `quote-uploads-hourly` (120/hour/user); migration `_100005_widen_quote_revision`; malformed-JSON check (422 `body`) shared by the body-taking quote requests; `routes/api.php` (new `{attachment}` uuid pattern; role groups as in the contract); `bootstrap/app.php` (413 render for `PostTooLargeException` on `api/*`); `config/filesystems.php` (`serve => false` on `local` and `quotes`); `OrderController`, `ListOrdersRequest`, `PlaceOrderRequest`, `PlaceOrder`, `OrderResource`/`OrderDetailResource` (Phase 3 amendments: `quote_number`, shared PO validator, `OrderInserter`); updated Phase 3 tests (exact-key tests for `quote_number`, query-count bounds 9 and 10); factories; `database/seeders/QuoteDemoSeeder.php` + `DatabaseSeeder`; `.env.example`; tests under `tests/Feature/Quotes/*`, `tests/Unit/QuotePolicyTest`, `tests/Feature/Database/QuotesSchemaTest`, updated Phase 3 tests.
- web: `src/features/quotes/*`, catalog list/detail "Request quote" link, orders pages (quote link, custom lines, reorder label), nav config, routes and guards, tests beside the features.
- outside agent scope (main session or user): README (PHP upload limits, `schedule:work`, demo quote numbers, `QUOTES_NOTIFY_ADDRESS`), `docs/PLAN.md` ticks, `php.ini` on the dev machine. No `.github`, docker or `phpunit.xml` change.

## 14. Open questions for the user

Defaults below are what the design assumes; each is cheap to change before implementation.

1. Status `declined` is reused for customer withdraw, customer decline and staff reject (the history row says who and why). OK, or add a separate seventh status `withdrawn`/`rejected`? (Default: reuse, six statuses as in the plan.)
2. Attachment types: PDF, PNG, JPEG, TXT, CSV only. Do customers need Excel (xlsx) or Word (docx) or CAD (dxf/dwg)? Office files need `ext-zip` plus a structure check (a small, approved dependency-free addition if the extension is present); CAD formats have no reliable content signature and would be extension-only (not recommended). (Default: not allowed in v1.)
3. Staff uploads (for example the formal quotation PDF) are allowed and hidden from the customer until the quote is sent. Keep, or customers' files only in Phase 4? (Default: keep; it is one rule, `sent_at`.)
4. Customers cannot edit a submitted quote (they withdraw and submit a new one). Is editing lines or adding a note while `submitted` needed? (Default: no.)
5. Staff cannot accept or decline on a customer's behalf (for example a phone approval). Add a staff "accept on behalf" later? (Default: no, Phase 6 if the business needs it.)
6. Extra emails: staff when a quote is accepted or declined, customer when staff rejects or when a quote expires. The scope names only two emails. (Default: only the two; a pending order from an accepted quote is visible in the order list; revisit with the Phase 6 dashboard.)
7. Show "Request quote" only for price-on-request and out-of-stock products (default), or for every product?
8. Staff recipients: `QUOTES_NOTIFY_ADDRESS` mailbox if set, else all staff and admin users. Which shared mailbox should be configured for production?
9. Validity: staff must pick a date between tomorrow and 365 days ahead, "valid through the end of that day, UTC". Is a default validity (for example 30 days pre-filled in the form) wanted, and is UTC end-of-day acceptable for the business time zone?
10. Currency and tax: same single catalog currency, tax and shipping fixed at 0 on quotes and orders (VAT is a Phase 5 question). Confirm.
11. Quote numbers: random `QUO-XXXXX-XXXXX`, no human-sequential number (same decision as orders).
12. Per-quote limits: 50 lines, 10 files, 25 MiB total, 10 MiB per file, 2000-character notes. Adjust?
13. Malware scanning of uploads is not in Phase 4. Is that acceptable until Phase 7/8, or must scanning be in the first release?
14. Phase 8 reminder: see "Deployment notes (Phase 8)" below (all items are decided, nothing to answer).

## 15. Deployment notes (Phase 8)

Carry these into the Phase 8 checklist; nothing here is needed for local development beyond the README PHP limits.

- PHP: `upload_max_filesize = 11M` and `post_max_size = 12M` (PHP-FPM and CLI), `file_uploads = On`.
- Nginx: `client_max_body_size 12m` on the `/api` location (above 12m Nginx itself answers 413 with an HTML body, not the JSON one).
- Mail: set `QUOTES_NOTIFY_ADDRESS` to a monitored shared mailbox (empty falls back to up to 50 staff/admin users, one message each). Real SMTP provider, SPF/DKIM and bounce handling.
- Scheduler: a cron entry running `php artisan schedule:run` every minute, so `quotes:expire` runs (every five minutes). Expiry is effective at read time, so a missed run is not a correctness problem.
- Backups: include the private quotes storage (`storage/app/private/quotes`) together with the database, taken at the same point in time; a database restore without the files leaves attachment rows whose download is 404, and files without rows are orphans.
- Queue worker: a supervised `php artisan queue:work` (database driver) must run for `QuoteSubmittedMail` and `QuoteSentMail`; monitor `failed_jobs` (mail jobs fail permanently after 3 tries).
- Env: review `QUOTES_COMPANY_MAX_BYTES` (500 MiB), `QUOTES_MAX_OPEN_PER_COMPANY` (50) and `QUOTES_UPLOADS_PER_HOUR` (120) against real disk size and customer behaviour; set `APP_URL` and `app.web_url` correctly (see the email header limitation in section 16).

## 16. Post-review hardening, known limitations, deferred work

Added after the security review (the backend implemented these; this section and the contract are the record).

Hardening decisions:

| # | Topic | Decision |
| --- | --- | --- |
| H1 | Company storage cap | `quotes.attachments.max_company_bytes` (`QUOTES_COMPANY_MAX_BYTES`, 500 MiB) over all attachments of all the company's quotes, closed ones included; staff uploads count toward the quote's company. Enforced in the quote-lock transaction after locking the company row `FOR UPDATE`; lock order quote then company. 422 `file` |
| H2 | Open-quote cap | `quotes.max_open_per_company` (`QUOTES_MAX_OPEN_PER_COMPANY`, 50); open = `submitted`, `in_review`, `quoted` by EFFECTIVE status (an expired `quoted` quote does not count). 422 `quote` on `POST /quotes` |
| H3 | Upload limiters | `quote-uploads` 20/min plus `quote-uploads-hourly` 120/hour (`QUOTES_UPLOADS_PER_HOUR`), per user, every attempt counts, role 403 before 429 |
| H4 | Notification mail | One queued message per staff recipient, recipients = `quotes.notify_address` else staff/admin by id, max `quotes.max_notify_recipients` (50, config only) with `Log::warning` on truncation; failures isolated per address; 3 tries, backoff `[60, 300]` |
| H5 | Customer `updated_at` | `max(latest status-history row, sent_at, created_at)` for customers, real value for staff |
| H6 | Download headers | Added `Content-Security-Policy: default-src 'none'; sandbox` next to `attachment`, `nosniff`, `private, no-store` |
| H7 | Revision width | `quote_requests.revision` is `integer` (migration `2026_11_28_100005_widen_quote_revision`); accept takes 1..2147483647 (was 1..32767) |
| H8 | Dual-role users | `accept` and `decline` policies are customer-only; a user who also holds staff/admin is 403 |
| H9 | Malformed JSON | Any quote endpoint with a body answers 422 `body` when the body is non-empty and does not decode to a JSON object or array; empty body, `{}` and `[]` are accepted; 401/403/404 come first |

Known limitations (accepted for Phase 4):
- libmagic can refuse 1-byte `.txt` or `.csv` files ("content does not match its file type").
- No malware scanning (Phase 7/8).
- The mail layout header link still shows the API origin (`APP_URL`) while action links use `app.web_url` (Phase 8 email branding).
- Parallel upload overshoot is guarded by the quote and company locks but only tested sequentially (QA does the real parallel check, 12.3 item 8).

Deferred follow-ups (Phase 7):
- Staff purge of files on closed quotes.
- Warn staff about CSV attachments in the UI.
