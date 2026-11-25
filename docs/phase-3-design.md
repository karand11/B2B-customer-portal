# Phase 3 design - Cart and orders with history

HTTP contract: `docs/api-contract.md` (Phase 3 section). This file holds decisions, schema, Policy, state machine, order numbers, seeders, frontend design, test plan and open questions. Conventions come from `docs/phase-1-design.md` (Sanctum cookie auth, role middleware, error shapes, test DB `portal_test`, `RefreshDatabase`) and `docs/phase-2-design.md` (products table, `config('catalog.currency')`, exact 404 body, query-count tests, `preventLazyLoading`).

## 1. Decisions

| Topic | Decision | Why |
| --- | --- | --- |
| Cart storage | Server-side, table `cart_items`, one cart per USER (not per company) | See 1.1 |
| Cart roles | `customer` only. Staff/admin get 403 on cart, `POST /orders` and reorder | They have `company_id = null`; an order needs a company. Staff-created orders are not in the plan. |
| Order creation input | From the server cart only. `POST /orders` takes no items and no prices | One source of truth, nothing to tamper with, no client price or total ever exists |
| Initial status | `pending`, staff confirm (approved by the user, question 1) | |
| Tax and shipping | `tax_cents = 0`, `shipping_cents = 0` stored on the order; `total_cents = subtotal + tax + shipping` enforced by a CHECK (approved by the user, question 2) | Phase 5 invoices add VAT without a schema change |
| Currency | `orders.currency` char(3) copied from `config('catalog.currency')` at creation; cart and order bodies carry it | A later config change must not re-label old orders |
| price_on_request | Cannot be added to the cart (422 `product_id`); lines that become price-on-request stay in the cart as unavailable and block checkout | Phase 4 (RFQ) is the path for these; no quote hook is built now |
| out_of_stock | Cannot be added; existing lines become unavailable and block checkout. `low_stock` and `on_order` are orderable (on_order = backorder). Open question 3 | No stock quantities exist, so "out" is the only reliable "cannot sell" signal |
| Quantity | integer 1 to 9999 per line, at most 100 lines per cart | Guards overflow and abuse; `config/orders.php` |
| Order identifier | Random `number` (`ORD-XXXXX-XXXXX`) is the only public id; numeric id never leaves the API | Sequential ids or numbers leak order volume across companies. See section 4 |
| Foreign order | 404 everywhere (detail, cancel, reorder), identical body to "does not exist" | CLAUDE.md "404/403"; 404 prevents probing |
| Wrong role | 403 from role middleware before any lookup | Reveals nothing about ids |
| Double submit | Per-user row lock + empty-cart 422; no Idempotency-Key header | See 7.2 |
| Cancel rules | customer: `pending` only; staff/admin: `pending` and `confirmed`; wrong state is 422 not 403 | The user may see the order, so it is a state conflict, not a permission error |
| Staff UI | Deferred to Phase 6. Staff get read-only `/orders` and `/orders/:number`; the status endpoint is tested by Pest and QA curl | PLAN: staff order management is Phase 6 |
| History visibility | Customers see staff actions as "staff" without a name; staff see names | Do not expose employee names to customers |
| PO reference | Optional `po_reference` on `POST /orders`, stored in `orders.po_reference` varchar(64) NULL, immutable, plain text, never used for any decision | Approved by the user (open question 5). Rules and tests in 3.5 |
| Notes, addresses | Not in Phase 3 (the PO reference IS added, see the row above) | Delivery address and notes stay deferred to Phase 5 (open question 5, only this part is still deferred) |

### 1.1 Cart: server-side per user (recommended and chosen)

Server-side `cart_items` beats a localStorage cart for this portal:
- Every product rule (active, price on request, out of stock, current price) lives on the server anyway and must run at checkout. A server cart runs the same code on every read, so the cart page always shows live prices and flags dead lines, instead of showing stale prices from last week's browser state.
- It follows the user across devices and browsers (a buyer starts at the desk, finishes on the shop floor), and survives clearing site data. B2B buyers build carts over days.
- `POST /orders` needs no client-supplied items, so the whole "tampered items/prices" attack surface is gone. With localStorage the checkout request would carry an item list (product ids, quantities) that must be re-validated anyway.
- Reorder naturally writes into the same cart.
- Cost: one table and 4 small endpoints, and every cart click is a request (60/min shared limiter; see open question 6).
- The plan's Vitest gate "persists on reload" is satisfied as: the cart is the TanStack Query `['cart']` loaded from `GET /cart`; the test mounts, adds, unmounts, remounts with a fresh QueryClient and asserts the line is rendered again from the (mocked) server. No localStorage anywhere.

Per user, not per company: a shared company cart would let two colleagues overwrite each other's quantities and let one person check out another's half-built cart. Orders, not carts, are the company-shared object (all users of a company see all its orders).

## 2. Dependencies

- Backend: none. Spatie permission, Sanctum and Laravel's Policy/Gate cover everything. Order numbers use `random_int` (no ULID/hashids package).
- Frontend: none (React Router, TanStack Query, Zod, RHF already installed). No MSW, no toast library (use an `aria-live` region).
- Docker/CI: no change. No new service, no new env var, `phpunit.xml` unchanged. Business limits are constants in `api/config/orders.php` (`max_quantity` 9999, `max_cart_lines` 100).

## 3. Database

New migrations only, in this order: `create_orders_table`, `create_order_items_table`, `create_order_status_history_table`, `create_cart_items_table`. CHECK constraints and any index Laravel's builder cannot express are `DB::statement()` in the migration. Every FK is `ON DELETE RESTRICT` (nothing in these tables is ever deleted by the app except `cart_items`, which the app deletes explicitly). Money columns are `bigint` (integer cents). Timestamps are `timestamp` (UTC, Laravel default).

### 3.1 `orders`

| Column | Type | Constraints |
| --- | --- | --- |
| id | bigint identity | PK (internal only, never exposed) |
| order_number | varchar(16) | NOT NULL, UNIQUE (`orders_order_number_unique`), CHECK `order_number ~ '^ORD-[A-HJKMNP-TV-Z2-9]{5}-[A-HJKMNP-TV-Z2-9]{5}$'` |
| company_id | bigint | NOT NULL, FK `companies.id` RESTRICT |
| created_by_user_id | bigint | NOT NULL, FK `users.id` RESTRICT |
| status | varchar(16) | NOT NULL default `'pending'`, CHECK IN (`pending`,`confirmed`,`shipped`,`delivered`,`cancelled`) |
| currency | char(3) | NOT NULL, CHECK `currency ~ '^[A-Z]{3}$'` |
| subtotal_cents | bigint | NOT NULL, CHECK >= 0 |
| tax_cents | bigint | NOT NULL default 0, CHECK >= 0 |
| shipping_cents | bigint | NOT NULL default 0, CHECK >= 0 |
| total_cents | bigint | NOT NULL, CHECK >= 0 |
| po_reference | varchar(64) | NULL, CHECK `orders_po_reference_length`: `po_reference IS NULL OR char_length(po_reference) <= 64` |
| created_at, updated_at | timestamp | NOT NULL set by app |

Table CHECK `orders_total_consistent`: `total_cents = subtotal_cents + tax_cents + shipping_cents`.
`po_reference` is part of the `create_orders_table` migration (Phase 3 has no migration yet). The character rules are enforced in the Form Request only, not in the DB (the DB CHECK is the length backstop). No index on `po_reference` (see below).
Note the regex class `A-HJKMNP-TV-Z` is the alphabet `ABCDEFGH JK MN PQRST VWXYZ` (30 chars with `2-9`; no `I L O U 0 1`).

Indexes:

| Index | Definition | Serves |
| --- | --- | --- |
| `orders_pkey` | PK (id) | joins |
| `orders_order_number_unique` | UNIQUE (order_number) | lookup by number, uniqueness |
| `orders_company_id_created_at_index` | btree (company_id, created_at) | customer history, default sort and date range (planner scans backwards for DESC) |
| `orders_company_id_status_index` | btree (company_id, status) | customer status filter and counts |
| `orders_status_created_at_index` | btree (status, created_at) | staff list across companies filtered by status |
| `orders_created_at_index` | btree (created_at) | staff list without filters, date range |

No standalone index on `created_by_user_id` (never filtered; users are not deleted). Search `q` is `(replace(order_number, '-', '') LIKE '%N%' OR po_reference ILIKE '%P%' ESCAPE '\')` on the already company-narrowed (customer) or full (staff) set, where `N` is the normalised order-number fragment (branch omitted when it normalises to empty), `P` is the trimmed `q` with `\`, `%`, `_` escaped with a backslash, both bound as parameters (never concatenated into SQL). The company scope is applied by `visibleTo` and the OR group MUST be wrapped in one `where(function ...)` closure so it cannot escape the scope (a bare `orWhere` would leak other companies' orders; a test covers this). The PO branch is an unindexed `ILIKE` (a sequential scan over the scoped set); at the expected row counts this is fine, Phase 7 may add a generated column plus trigram indexes (not now). `q` is limited to 32 characters while `po_reference` may be 64, so only the first 32 characters of a long PO are searchable (accepted limit). A user with no role at all gets `visibleTo` = false (no rows).

Relations: `Order belongsTo Company`, `belongsTo User (createdBy)`, `hasMany OrderItem`, `hasMany OrderStatusHistory`. `Company hasMany Order`. Model: `$fillable = []` (nothing mass-assigned; the placing service uses `forceFill` with explicit server values), `status` cast to the `OrderStatus` enum.

### 3.2 `order_items` (the snapshot)

| Column | Type | Constraints |
| --- | --- | --- |
| id | bigint identity | PK |
| order_id | bigint | NOT NULL, FK `orders.id` RESTRICT |
| product_id | bigint | NOT NULL, FK `products.id` RESTRICT (products are never hard-deleted; informational link only) |
| part_number | varchar(64) | NOT NULL (snapshot) |
| name | varchar(255) | NOT NULL (snapshot) |
| brand | varchar(64) | NOT NULL (snapshot) |
| unit_price_cents | bigint | NOT NULL, CHECK >= 0 (snapshot) |
| quantity | integer | NOT NULL, CHECK `quantity > 0 AND quantity <= 9999` |
| line_total_cents | bigint | NOT NULL, CHECK >= 0, CHECK `line_total_cents = unit_price_cents * quantity` |
| created_at, updated_at | timestamp | |

Indexes: PK; `order_items_order_id_product_id_unique` UNIQUE (order_id, product_id) (one line per product; also serves the `order_id` lookup). No index on `product_id` (not queried in Phase 3).
Immutability: no endpoint, service or model method updates or deletes `order_items`; the model has no `updated` flows. A Pest test asserts that changing a product (price, name, brand, `active`) leaves existing `order_items` and `GET /orders/{number}` unchanged. A DB trigger forbidding UPDATE/DELETE is a possible Phase 7 hardening, not built now.

### 3.3 `order_status_history`

| Column | Type | Constraints |
| --- | --- | --- |
| id | bigint identity | PK |
| order_id | bigint | NOT NULL, FK `orders.id` RESTRICT |
| from_status | varchar(16) | NULL (null only for the creation row), CHECK IN the five statuses |
| to_status | varchar(16) | NOT NULL, CHECK IN the five statuses |
| actor_user_id | bigint | NOT NULL, FK `users.id` RESTRICT |
| created_at | timestamp | NOT NULL (no `updated_at`: rows are append-only) |

Table CHECK: `from_status IS NULL OR from_status <> to_status`.
Index: `order_status_history_order_id_id_index` btree (order_id, id) (timeline read in order). Append-only: written only by `OrderStatusChanger` and `PlaceOrder`. Every status change writes exactly one row in the same transaction as the update of `orders.status`.

### 3.4 `cart_items`

| Column | Type | Constraints |
| --- | --- | --- |
| id | bigint identity | PK |
| user_id | bigint | NOT NULL, FK `users.id` RESTRICT |
| product_id | bigint | NOT NULL, FK `products.id` RESTRICT |
| quantity | integer | NOT NULL, CHECK `quantity > 0 AND quantity <= 9999` |
| created_at, updated_at | timestamp | |

Indexes: PK; UNIQUE (user_id, product_id) (one line per product per user, also the `user_id` lookup). No `company_id` column: the cart is a per-user scratch area; the company is taken from the user at checkout. Model `CartItem`: `$fillable = []`; `belongsTo Product`; `User hasMany CartItem`.
Product prices are never stored in the cart.

### 3.5 PO reference (`orders.po_reference`)

HTTP rules are in the contract (POST /orders). Implementation notes:
- Validation lives in `PlaceOrderRequest` (new Form Request for `POST /orders`; `authorize()` true, role middleware already ran). Steps in order: nullable; must be a string (`is_string`, else 422); raw value `mb_check_encoding($v, 'UTF-8')` and no `\0` (checked BEFORE trimming, because `trim()` strips NUL); `trim`; empty after trim becomes `null` (`prepareForValidation` or `validated()` post-processing, so Laravel's `TrimStrings`/`ConvertEmptyStringsToNull` middleware behaviour is not relied on); `max:64` by `mb_strlen`; `regex:/^[A-Za-z0-9][A-Za-z0-9 ._\/#-]*$/D`. One message per failure under the key `po_reference`.
- `PlaceOrder` receives the already-validated value and writes it with the other server values (`forceFill`); `$fillable` stays empty.
- It is never used in any authorization, scope, pricing or status decision, and never interpolated into SQL (search binds it as a LIKE parameter with `\`, `%`, `_` escaped).
- Output: `OrderResource` and `OrderDetailResource` add `'po_reference' => $this->po_reference` (string or null, always present). JSON encoding escapes everything; the SPA renders it as a text node. It is stored unescaped (no HTML-encoding at write time).
- Formula-injection safety for future CSV/XLSX export (Phase 5/6 exports): the allowed-first-character rule means no stored value begins with `=`, `+`, `-`, `@`, tab or CR. An exporter must still not rely on that alone (defence in depth: prefix a `'` when a cell starts with one of those characters).
- Immutable in Phase 3: no update endpoint, `OrderStatusChanger` never touches it. A later "edit PO while pending" feature would need its own contract.
- Shown to staff too (staff see every company's PO, same as the rest of the order). A customer can only ever find or see POs of their own company (scope applies to detail, list and search).
- Known limit: ASCII letters and digits only (a PO like `Ä-12` is rejected). Widen the character class later if a customer needs it; the leading-character rule must stay.

## 4. Order numbers

Format: `ORD-` + 5 + `-` + 5 characters from the 30-character alphabet `ABCDEFGHJKMNPQRSTVWXYZ23456789` (no `I L O U 0 1`: unambiguous when read over the phone or from a printout). Example `ORD-K7Q2M-X9PA3`. Keyspace 30^10, about 5.9e14.

Why random and not `PO-2026-000123` from a Postgres sequence: any counter in an identifier lets a customer read order volume off their own numbers (and the numeric database id has the same leak), and the plan's Phase 5 invoice numbers are the place for gap-free counters. So:
- No sequence. The number is generated server-side with `random_int` per character, by an `OrderNumber` generator resolved from the container (so tests can fake it to force collisions). Uniqueness is guaranteed by the UNIQUE index: `PlaceOrder` inserts inside a savepoint (nested transaction) so a unique violation does not abort the outer Postgres transaction, regenerates on conflict, at most 5 attempts, then throws (500 is acceptable at probability about zero).
- The number is the ONLY public identifier. Routes bind `{number}`. The internal bigint `id` is never serialised and never in a URL, otherwise it would reintroduce the count leak. This is the deviation from a literal `/orders/{id}`; the frontend uses `number` everywhere.
- Guessing a valid number is impractical (5.9e14), and even a valid number of another company is 404 because of the company scope. Order number lookups are also under the 60/min limiter.
- The number is generated once at creation and never changes. Phase 5 invoices get their own gap-free numbers and may reference the order number.
- Lookup normalisation: path segment uppercased before the query. The `q` list search normalises as in the contract.

## 5. Authorization (the first Policy)

### 5.1 Layers (all must hold; a failure of any layer is 404 for a foreign order)

1. Role middleware per route group (`role:customer`, `role:staff|admin`): wrong role is 403, before any lookup.
2. Query scope `Order::visibleTo(User $user)`: customer `where company_id = $user->company_id` (and `whereRaw('false')` if the user's `company_id` is null); staff/admin no constraint. Used by EVERY order query in controllers (list and by-number lookups). Raw `Order::where('order_number', ...)` without the scope is forbidden in controllers and a Pest "arch" test greps/asserts controllers do not call `Order::query()`/`Order::where` without `visibleTo` (e.g. Pest `arch()` or a simple source-text assertion).
3. `OrderPolicy` re-checks the loaded model (defence in depth if someone later forgets the scope).

### 5.2 `App\Policies\OrderPolicy` (auto-discovered for `App\Models\Order`)

| Method | Rule | On failure |
| --- | --- | --- |
| `viewAny(User)` | staff/admin, or customer with non-null `company_id` | 403 (cannot normally happen after role middleware) |
| `view(User, Order)` | staff/admin: true. Customer: `user.company_id !== null && user.company_id === order.company_id`. Anyone else: false | `Response::denyAsNotFound()` so `authorize()` produces the 404 `{"message":"Not found."}` body |
| `create(User)` | customer with non-null `company_id` | 403 |
| `cancel(User, Order)` | `view` AND (customer or staff or admin). This answers WHO. It does NOT check status | foreign: not found (via `view`) |
| `reorder(User, Order)` | customer AND `view` | foreign: not found |
| `transition(User, Order)` | staff/admin only | 403 |

State rules ("can it be cancelled now?") live in the state machine (section 6), not in the Policy, so a state conflict is 422 and a missing right is 403/404. Do not register `Gate::before` or give admin a blanket allow; admin is covered explicitly in the rules above.

Role checks in the Policy must not trigger a lazy-load violation (Phase 2 enabled `preventLazyLoading`): implemented as `User::isCustomer()/isStaff()`, which use `hasRole` after `loadMissing('roles')`, so there are no lazy-load violations. A user with no role at all is neither, so the Policy denies and `visibleTo` matches nothing.

### 5.3 Controller usage (thin controllers)

```
// OrderController::show(Request $request, string $number)
$order = Order::visibleTo($request->user())
    ->where('order_number', strtoupper($number))
    ->with([...])            // eager loads, see section 8
    ->firstOrFail();         // missing OR foreign -> ModelNotFoundException -> 404 JSON {"message":"Not found."}
$this->authorize('view', $order);   // second layer; denyAsNotFound -> 404, never 403
return new OrderDetailResource($order);
```

`cancel`, `reorder`, `status` do the same lookup then `authorize('cancel'|'reorder'|'transition', $order)`. `store` does `$this->authorize('create', Order::class)`. The `api/*` 404 renderer in `bootstrap/app.php` was widened (see section 12) so that the plain `HttpException` 404 raised by `authorize()` with `denyAsNotFound()` renders exactly `{"message":"Not found."}` with `APP_DEBUG` true and false. `Controller` uses `AuthorizesRequests` for `$this->authorize()`.

Cart controllers need no Policy: every query is `CartItem::where('user_id', $request->user()->id)`, a cart row is never addressed by its own id, and the route has no object another user could reference. A test proves it anyway.

## 6. Status state machine

`App\Enums\OrderStatus` (string-backed: `pending`, `confirmed`, `shipped`, `delivered`, `cancelled`). One matrix, one code path.

```
pending   -> confirmed, cancelled
confirmed -> shipped, cancelled
shipped   -> delivered
delivered -> (none, terminal)
cancelled -> (none, terminal)
```

Actor rules on top of the matrix:
- `staff`, `admin`: any edge of the matrix.
- `customer` (own company only): only `pending -> cancelled`.

`App\Services\OrderStatusChanger::change(Order $order, OrderStatus $to, User $actor)` is the only place that changes `orders.status`. `OrderController::cancel` calls it with `cancelled`; `OrderController::status` (the status action lives in `OrderController`, there is no `OrderStatusController`) calls it with the request value. Validation (`UpdateOrderStatusRequest`) runs before the order lookup, so an invalid status to an unknown number is 422, not 404. Steps inside `DB::transaction`: `lockForUpdate()` re-read of the order row; check `$from->canMoveTo($to)` and `$actor` rule; if not allowed throw `ValidationException::withMessages(['status' => [...]])` (422); update `status` (and `updated_at`); insert one `order_status_history` row (`from`, `to`, `actor`, now); return the fresh order. `allowed_transitions` for the response = the matrix row filtered by the same actor rule (`OrderStatus::allowedFor(User)`), so UI and server cannot disagree.

`PlaceOrder` (creation) writes the first history row (`null -> pending`) itself.

Form Request `UpdateOrderStatusRequest` validates `status` is in `confirmed,shipped,delivered,cancelled` (so `pending` and unknown values are 422 at validation); the matrix check happens in the service.

## 7. Cart and checkout algorithms

### 7.1 Cart writes
All cart writes, reorder and checkout start `DB::transaction` with `User::whereKey($user->id)->lockForUpdate()->first()` (the user's row; locks even when the cart is empty and no cart rows exist). This serialises one user's concurrent cart actions, so the "existing + quantity <= 9999" and "<= 100 lines" checks are race-free and CHECK violations (500) cannot happen.

Add: validate `product_id` and `quantity` (Form Request `AddCartItemRequest`), load the product with `Product::active()` (inactive and unknown give the same 422 message), reject price on request and `out_of_stock`, then insert or increase. Update/remove: look up `CartItem::where('user_id', me)->where('product_id', X)->firstOrFail()`. Every write returns the freshly built `CartResource` (one query for cart rows joined with products, no N+1).

### 7.2 Checkout (`PlaceOrder` action) and concurrency
1. Authorize `create`. Open transaction, lock the user row.
2. Read cart rows with products in one query (`CartItem::where('user_id', ...)->with('product')->orderBy('id')`), products read fresh inside the transaction.
3. Empty cart or any line unavailable (inactive, price on request, out of stock): throw 422 `cart` with one message per problem; rollback; the cart is unchanged.
4. Per line: `unit_price_cents = product.price_cents`, `line_total = unit * quantity`; `subtotal = sum`; `tax = 0`; `shipping = 0`; `total = subtotal`. All integer arithmetic on bigints (max line 25,000,000 cents x 9999 fits; use PHP int, 64-bit).
5. Insert order (`company_id` and `created_by_user_id` from the session user, `status` pending, `currency` from config, generated number), bulk insert items with the snapshot, insert the first history row, delete this user's cart rows. Commit.
6. Return 201 with `OrderDetailResource`.

Duplicate protection: because step 1 locks the user row until commit, two parallel `POST /orders` run one after the other; the second sees an empty cart (step 3, 422 `cart: ["Your cart is empty."]`). This needs no client cooperation and also covers double click, a retry after a timeout, and two tabs. Rejected alternative: an `Idempotency-Key` header with a unique `(user_id, key)` column would also replay the original 201 for a lost response, at the cost of a column, an expiry story and client work; the user can see their order in the history instead. Revisit if open question 7 matters to the user. The SPA disables the Place-order button while pending and, on a 422 empty cart right after a submit, sends the user to `/orders`.

Parallelism cannot be unit-tested in a single-process Pest run; Pest covers the sequential double submit (one order only), QA runs two simultaneous `curl` requests (7.3 manual step).

### 7.3 Reorder
Transaction + user lock. Load the order's items (scoped order first, 404 if foreign). For each line (in item order): check the product with the same availability rule as add; new-line cap and quantity cap checks; else upsert into the cart. Build `added` and `skipped` while iterating. Response includes the final cart.

## 8. Query-count guarantee (no N+1)

`preventLazyLoading` is on outside production (Phase 2), so a missed eager load throws in tests.
- `GET /orders`: `Order::visibleTo($u)->filters(...)->with(['company:id,name', 'createdBy:id,name'])->withCount('items as line_count')->orderBy(...)->paginate()`. Queries: session user + roles (middleware), count, orders (with `line_count` subselect), companies, users. Asserted upper bound 8, identical for 3 rows and 30 rows and for `per_page` 5 vs 100.
- `GET /orders/{number}`: order (scoped), `items`, `history.actor` (history with `actor:id,name` and role), `company`, `createdBy`. Upper bound 9, constant in the number of items and history rows. The actor `role` for history entries comes from one extra query that loads roles for the distinct actor ids (or `with('actor.roles')`); not per-row.
- `GET /cart`: upper bound 5 (user + roles, cart rows with `product`).
- `POST /orders`: bounded by lines only through bulk inserts (one insert for items). Test asserts the number of INSERT statements is the same for 1 and 20 lines.

## 9. Seeders and factories

### Factories (tests use these, not the seeder)
`OrderFactory` (valid by default: company from a `CompanyFactory`, a customer of that company as creator, 1 to 3 consistent `OrderItem`s, matching subtotal/total, creation history row; states `pending()`, `confirmed()`, `shipped()`, `delivered()`, `cancelled()` that also write a coherent history chain; `forCompany($c)`, `createdAt($date)`), `OrderItemFactory`, `CartItemFactory`.

### `OrderDemoSeeder` (local and testing only, aborts otherwise, same allowlist as `DemoSeeder`/`ProductSeeder`)
- Requires `DemoSeeder` users (calls it if missing) and products (calls `ProductSeeder` if no products). Idempotent: skips an order whose `order_number` exists. Deterministic: fixed numbers, fixed lines (chosen by `products` ordered by `id`, active, priced, `in_stock`; the n-th order uses products at offsets n, n+1, n+2), fixed quantities, fixed UTC timestamps (no `now()`, no random).
- Created through direct inserts (not `PlaceOrder`, so statuses and dates can be fixed) with consistent snapshots, totals and history rows (staff actor `staff@portal.test`, customer actor as creator).

| Number | Company | Creator | Status | Created (UTC) |
| --- | --- | --- | --- | --- |
| `ORD-NWAAA-AAAA2` | Northwind | alice | pending | 2026-11-20 09:00 |
| `ORD-NWAAA-AAAA3` | Northwind | bob | confirmed | 2026-11-12 10:30 |
| `ORD-NWAAA-AAAA4` | Northwind | alice | shipped | 2026-10-28 14:00 |
| `ORD-NWAAA-AAAA5` | Northwind | bob | delivered | 2026-09-15 08:45 |
| `ORD-NWAAA-AAAA6` | Northwind | alice | cancelled | 2026-09-02 16:20 |
| `ORD-GXAAA-AAAA2` | Globex | carol | pending | 2026-11-21 11:00 |
| `ORD-GXAAA-AAAA3` | Globex | carol | delivered | 2026-10-05 13:10 |
| `ORD-GXAAA-AAAA4` | Globex | carol | cancelled | 2026-09-20 09:30 |

PO references in the seed (all other orders NULL): `ORD-NWAAA-AAAA2` = `NW-PO-1001`, `ORD-NWAAA-AAAA3` = `NW-PO-1002`, `ORD-GXAAA-AAAA2` = `GX-PO-2001`. So Alice searching `GX-PO` finds nothing, Carol searching `NW-PO` finds nothing, staff searching `PO-` finds all three.

In total the seeder creates 8 orders, 24 order items and 19 history rows (8 creation rows plus the later status steps).

So Alice and Bob both see all 5 Northwind orders and none of Globex's 3; Carol sees only her 3; staff see 8. `DatabaseSeeder` calls `OrderDemoSeeder` only when `APP_ENV` is `local` or `testing`. A seeded cart is not created. Tests assert the isolation numbers above using this seeder in one test and factories everywhere else.

## 10. Frontend design (`web/src/features/cart/`, `web/src/features/orders/`)

Suggested layout: `cart/api.ts`, `cart/useCart.ts`, `cart/CartPage.tsx`, `cart/CheckoutPage.tsx`, `cart/AddToCart.tsx`, `cart/CartBadge.tsx`; `orders/api.ts`, `orders/urlState.ts`, `orders/OrdersPage.tsx`, `orders/OrderDetailPage.tsx`, `orders/StatusBadge.tsx`, `orders/StatusTimeline.tsx`, `orders/format.ts`. Zod schemas for responses. Money helper takes `currency` from the body.

### 10.1 Cart state
- Single query `['cart']` (`GET /cart`, enabled only for the customer role), mutations for add, update, remove, clear that on success call `queryClient.setQueryData(['cart'], response.data)` (the API returns the whole cart every time). No localStorage, no optimistic updates (the server may clamp or reject). Reload gives the same cart because it is server state.
- Errors: 422 `quantity`/`product_id` messages shown inline next to the control (`role="alert"`); 429 "try again in N seconds"; 401 handled globally.

### 10.2 Add to cart (catalog list and detail)
Customers only (hidden for staff/admin; not relied on for security). Each catalog row and the detail page get a labelled quantity input (`aria-label="Quantity for 6205-2RS"`, default 1, integer 1 to 9999, validated client-side with Zod, invalid blocks the request) and an "Add to cart" button. Disabled with visible text instead of the button when the product is `price_on_request` ("Price on request") or `stock_status = out_of_stock` ("Out of stock"). On success a polite live region says "Added 2 x 6205-2RS to your cart" and the header badge updates; the button shows a pending state. The catalog table gets one extra column "Order" (below `md` it is part of the stacked block). The Phase 2 test "no cart or quote buttons exist" changes: customers get the cart controls, staff/admin still none, quote buttons still none.

### 10.3 Header badge
`<a href="/cart" aria-label="Cart, 3 items">Cart <span>3</span></a>` shows `line_count`, hidden when 0 or while loading, customers only, added to the nav config for role `customer`. "Orders" nav item for all roles.

### 10.4 `/cart`
Table (caption, `<th scope>`): Part number (link to `/catalog/:id`), Name, Brand, Unit price, Quantity (number input plus "Update" on Enter or blur if changed, validated 1 to 9999), Line total, Remove button (`aria-label="Remove 6205-2RS from cart"`). Unavailable lines: row marked with text ("No longer available", "Price on request", "Out of stock") not colour only, excluded from the subtotal, only Remove works, and "Proceed to checkout" is disabled (`aria-describedby` explains) when `can_checkout` is false. Footer: subtotal and note "Taxes and shipping are not included." "Clear cart" button with an inline confirm. States: loading skeleton (`aria-busy`), empty ("Your cart is empty." with link to the catalog), error with "Try again" (429 shows seconds). Live region announces updates ("Quantity updated", "Item removed").

### 10.5 `/checkout`
Review page: read-only lines, subtotal, total (tax and shipping rows shown only when > 0), note "Your order will be reviewed and confirmed by our staff." Above the button, an optional text input with a visible `<label>` "PO reference (optional)", `maxLength={64}`, `autocomplete="off"`, hint text linked by `aria-describedby` ("Letters, digits, spaces and . - _ / #. Up to 64 characters."). Client validation with Zod mirrors the server (trim; empty means omit the key; max 64; `^[A-Za-z0-9][A-Za-z0-9 ._/#-]*$`); on an invalid value the field gets `aria-invalid="true"`, an inline error message (`role="alert"`, linked via `aria-describedby`) and no request is sent. A server 422 `errors.po_reference` shows in the same place. The value is kept in component state when the cart refetches after a 422 `cart`. Body of `POST /orders` is `{}` or `{ "po_reference": "<trimmed>" }`, nothing else. Button "Place order" (disabled while pending, double click safe). On 201: set `['cart']` to empty, invalidate `['orders']`, navigate to `/orders/{number}` with router state `{ placed: true }`, which shows the confirmation banner "Thank you. Order ORD-... was received and is pending confirmation." (focus moved to the banner; lost on reload by design, the order page itself is the permanent confirmation). On 422 `errors.cart`: show the messages as a list in an alert, refetch the cart, remain here. Empty cart on entry redirects to `/cart`. Non-customers see the 403 page.

### 10.6 `/orders` (history)
- URL is the source of truth (same approach as the catalog, Phase 2 7.1): `status` (CSV checkboxes with `<fieldset>`), `from`, `to` (`<input type="date">`, labelled "From date", "To date"), `q` (search labelled "Order number or PO reference", 300 ms debounce, replace history, max 32 characters), `sort` (select: Newest, Oldest, Highest total, Lowest total), `page`. Defaults omitted; invalid values (unknown status, malformed date, `from` after `to`, bad sort, page below 1) dropped before the request; `from` after `to` shows an inline alert and sends nothing. Changing a filter removes `page`. Discrete changes push, text/date typing replaces.
- Table: Order number (link), PO reference (plain text, em dash when null; long values wrap, `overflow-wrap: anywhere`), Date (local date of `created_at`), Status (`StatusBadge`: text label plus colour), Items (`line_count`), Total. Staff and admin also get a Company column. No company filter UI in Phase 3 (the API parameter exists; Phase 6).
- States: skeleton, previous rows kept while paging, empty with filters ("No orders match your filters." + Clear filters), empty without filters (customer: "You have no orders yet." + link to catalog; staff: "No orders yet."), error alert + retry, 429 seconds. Pagination as in the catalog. Result count in an `aria-live` region.
- Customers see the whole company's orders; the page heading says "Orders for {company name}".

### 10.7 `/orders/:number`
- Heading with the number and status badge, placed-by name and date, "PO reference" (plain text node in a labelled `<dt>/<dd>`; "None" when null), company (staff view), item table showing the SNAPSHOT values with the caption "Prices as ordered", totals block (subtotal, tax and shipping when > 0, total), status timeline `<ol>` ("Order placed" -> "Confirmed" -> ..., date/time, actor name or "Portal staff", text first, no colour-only meaning).
- Customer buttons: "Cancel order" visible only if `allowed_transitions` includes `cancelled`; clicking opens an inline confirm ("Cancel this order? This cannot be undone." with "Yes, cancel order" and "Keep order"); on success the detail query is replaced with the response; on 422 shows the message and refetches. "Re-order" (customers, any status): `POST .../reorder`, then a result panel: "N items added to your cart" with link to `/cart`, and a list of skipped items with reason labels ("No longer available", "Price on request", "Out of stock", "Cart is full", "Quantity limit reached"), also `aria-live`.
- Staff/admin: read-only, no cancel or re-order buttons (status-change UI is Phase 6; the Phase 3 endpoint is exercised by tests and QA curl).
- 404 (unknown or foreign) and a malformed `:number` (no request sent) show the same "Order not found" page with a link to `/orders`.
- Catalog links from item rows go to `/catalog/:product_id`; a 404 there already shows "Product not found".

### 10.8 Route guards and nav
`/cart`, `/checkout`: customer only via `RoleRoute` (others see the Phase 1 403 page). `/orders`, `/orders/:number`: any logged-in role. Nav config: Orders (all), Cart with badge (customer). Logout clears the query cache (Phase 1), so one user's cart never shows to the next user in the same browser.

## 11. Test plan

Gate items from `docs/PLAN.md` Phase 3 come first, in PLAN order, then extras. Backend: Pest Feature tests on `portal_test`, `RefreshDatabase`, `RolesSeeder`, Phase 1 helpers (`makeUser`, `loginAs`, Origin and Accept headers), factories. Two companies (A = Northwind with users a1 and a2, B = Globex with b1) plus one staff and one admin are the standard fixture.

### 11.1 Backend (Pest) - `backend-engineer`

Gate items:
1. Customer A cannot read, list or cancel customer B's order. With B's order O: a1 `GET /orders/O` -> 404 exact `{"message":"Not found."}` (same body as for a random unknown number, with `APP_DEBUG` true and false); a1 `GET /orders` -> 200 and `data` contains none of B's numbers while B has orders (and `meta.total` counts only A's); a1 `POST /orders/O/cancel` -> 404, O's status is still `pending` in the DB and no history row was added. Mirror: b1 cannot see A's orders. Same-company colleague a2 CAN read, list and (pending) cancel a1's order (shared company orders).
2. Later price changes do not alter old orders: a1 places an order at 1250; change the product to 9999, rename it, change brand, deactivate it; `GET /orders/{number}` returns the original `unit_price_cents`, `name`, `part_number`, `brand`, `line_total_cents`, `total_cents`; `order_items` rows unchanged in the DB; the order list total unchanged. Reorder afterwards skips the inactive product and, for an active changed product, adds it to the cart at the NEW price (cart shows 9999, not 1250).
3. Totals are computed server-side; a tampered client total is ignored: cart with 2 x 1250 and 1 x 300, POST /orders with a body of `total_cents: 1`, `subtotal_cents: 1`, `tax_cents: 99999`, `shipping_cents: 5`, `items: [{product_id, quantity: 999, unit_price_cents: 1}]`, `currency: "EUR"` -> 201, `subtotal_cents = 2800`, `tax_cents = 0`, `shipping_cents = 0`, `total_cents = 2800`, lines match the CART not the body, currency = config. Totals consistency over several carts: `total = sum(line_total)` and `line_total = unit * quantity`.
4. Invalid quantity or unknown product returns 422: `POST /cart/items` and `PATCH /cart/items/{id}` with quantity `0`, `-1`, `10000`, `1.5`, `"abc"`, `"2"` (string), `null`, missing, `[]` -> 422 with `errors.quantity`; unknown product id, `0`, `-3`, `"x"`, missing -> 422 `errors.product_id`; inactive product -> the same 422 message as unknown. `POST /orders` with an empty cart -> 422 `errors.cart`. Nothing is written on any 422.
5. Only allowed status transitions work: data-provider over all 25 (from, to) pairs with staff: exactly pending->confirmed, pending->cancelled, confirmed->shipped, confirmed->cancelled, shipped->delivered return 200 (status updated, one new history row with from/to/actor/time); the other 20 return 422 `errors.status` with the status and history unchanged. `to = pending`, unknown value, missing, array -> 422. Same-status 422. Admin behaves like staff. Customer on PATCH status -> 403 (own and foreign order and unknown number alike). Customer cancel: pending 200; confirmed, shipped, delivered, cancelled -> 422; staff cancel of confirmed 200, of shipped 422.
6. History filters work (API side of the Vitest gate): `status` single and CSV, `from`/`to` inclusive at day edges (order at 23:59:59 on `to` included, 00:00:00 next day excluded), `from` only, `to` only, combined with status, `q` by full number, partial, lowercase, with dashes and spaces, `q=%` -> empty (no order number or PO can contain `%`; `q=_` is covered by test 23), sort all six values with id tie-break and stable pages, `per_page` 1..100 and 101 -> 422, meta keys exactly six, page beyond last -> 200 empty. 422 cases: bad status, `status[]=x`, `from=2026-02-30`, `from=abc`, `from` later than `to` (`errors.from`), bad sort, `page=0`, `q` 33 chars, NUL byte, invalid UTF-8.

Isolation and IDOR (every endpoint):
7. Per-endpoint cross-company matrix with a1 against B's order O: `GET /orders/O` 404, `POST /orders/O/cancel` 404, `POST /orders/O/reorder` 404 AND a1's cart is unchanged, `PATCH /orders/O/status` 403 (role) . Items of B's order never appear: `GET /orders` for a1 with `q` equal to O's number or a fragment returns empty; B's part numbers/names/prices appear nowhere in a1's list, detail, reorder or cart responses (assert on the raw JSON text); `company_id=<B>` on a1's `GET /orders` is ignored (still only A's orders, 200). Guessing: a1 using a known-valid B number gets 404 even right after B created it.
8. Staff and admin: `GET /orders` returns both companies' orders; `company_id=A` filters to A; `company_id=9999999` empty 200; `company_id=abc` 422; detail of any company 200; each order carries its company name. Staff cannot use cart endpoints, `POST /orders`, reorder (403), and the 403 does not depend on the order existing.
9. Guest 401 on every Phase 3 endpoint (also without `Accept: application/json`).
10. Cart isolation: a1 and a2 (same company) have separate carts (a2 never sees a1's lines); a1's `PATCH`/`DELETE /cart/items/{product}` for a product only in a2's cart -> 404 and a2's cart unchanged; checkout by a1 never consumes a2's lines; `user_id`/`company_id` in any cart body or query ignored.
11. Mass assignment (`po_reference` is the one accepted order field on `POST /orders`; on cancel, status and reorder it is ignored and the stored value is unchanged): `POST /orders` and cancel/status/reorder bodies with `company_id`, `created_by_user_id`, `status: "delivered"`, `number`, `total_cents`, `unit_price_cents`, `created_at` are ignored (order is pending, owned by the session company, server number/time). Model assertions: `Order`, `OrderItem`, `OrderStatusHistory`, `CartItem` `$fillable` empty (`fill()` sets nothing). Cart add with a `price` or `unit_price_cents` field ignored.
12. Cart behaviour: add new, add again increases, sum above 9999 -> 422 `quantity`, 101st distinct line -> 422 `product_id`; PATCH sets absolute quantity; DELETE line and 404 on second delete; clear; `CartResource` exact keys and money as integers; unavailable reasons `inactive`, `price_on_request`, `out_of_stock` in that precedence; subtotal counts available lines only; `can_checkout` false for empty or any unavailable line; adding `price_on_request`, `out_of_stock`, inactive -> 422 `product_id` with the right message; `low_stock` and `on_order` are accepted; cart values are live (change the price, GET /cart shows it).
13. Checkout: success creates order + items + exactly one history row (`null -> pending`, actor = user) and empties only that user's cart; 201 `OrderDetailResource` exact keys; `company_id` = user's company, `created_by` = user; number matches the format regex and is unique across 200 generated orders; order stays pending (no auto-confirm); cart with an unavailable line -> 422 `errors.cart`, no order, cart unchanged; line made price-on-request or deactivated between add and checkout -> 422; customer with null company -> 403; failure midway (simulate an exception on the history insert) rolls back everything (no order, cart intact).
14. Double submit: sequential `POST /orders` twice with the same cart -> first 201, second 422 `cart` ("empty"), exactly 1 order, 1 history row and 0 cart rows; a second `POST /orders` after re-adding items creates a second order (not blocked forever). (Parallel run is a QA manual check.)
15. Cancel and transition edge cases: a cancelled order cannot be re-cancelled (422, no extra history row); delivered cannot be cancelled; cancel writes history with the right actor; a staff confirm then a customer cancel -> 422 and the order stays confirmed; history ordering oldest first; `allowed_transitions` for customer pending `["cancelled"]`, customer confirmed `[]`, staff pending `["confirmed","cancelled"]`, staff confirmed `["shipped","cancelled"]`, staff shipped `["delivered"]`, terminal `[]`; history `actor` for a customer viewer shows staff as `{name: null, role: "staff"}` (admin also "staff") while a staff viewer sees real name and `admin` role.
16. Reorder: deactivated product skipped with `inactive`; `price_on_request` and `out_of_stock` products skipped with their reasons; all skipped -> 200 with empty `added`; quantities add to an existing cart line; `quantity_limit` at the 9999 cap; `cart_full` at 100 lines; `added` plus `skipped` covers every order line once; works for a cancelled order; never creates an order; uses current prices; staff 403.
17. Rate limits: the 11th `POST /orders` within 60 s from one user -> 429 with `Retry-After` > 0 and the standard body (use carts refilled between calls or 422 empty-cart attempts, both count); another user unaffected; works again after `travel(61)->seconds`; the 61st request to any Phase 3 endpoint in a minute -> 429 (shared `api` bucket).
18. Query counts (section 8): list (3 vs 30 orders, `per_page` 5 vs 100) at most 8 and equal; detail (1 vs 20 items) at most 9 and equal; cart at most 5; no `LazyLoadingViolationException`.
19. Database (Phase 2 style): `order_number` UNIQUE and format CHECK; bad status rejected on `orders` and both history columns; `quantity = 0` and `10000` rejected on `order_items` and `cart_items`; negative money rejected; `total_cents <> subtotal + tax + shipping` rejected; `line_total <> unit * quantity` rejected; `from_status = to_status` rejected; FK RESTRICT: deleting a company with orders, a user with orders/history/cart rows, or a product with order items/cart rows all fail; indexes `orders_company_id_created_at_index`, `orders_company_id_status_index`, `orders_status_created_at_index`, `orders_created_at_index`, `order_status_history_order_id_id_index` and the unique ones exist in `pg_indexes`.
20. Seeders: `OrderDemoSeeder` twice -> 8 orders, same numbers; Alice and Bob see 5, Carol 3, staff 8; every seeded order satisfies the total/line/history invariants; aborts in `production`/`staging`; `DatabaseSeeder` seeds it in `testing`.
21. Policy unit tests (`OrderPolicy`): view/cancel/reorder/create/transition for customer same company, customer other company, customer null company, staff, admin, matrix of expected allow/deny/not-found.
22. Structure test: no controller calls `Order::query()`/`Order::where`/`Order::find` without `visibleTo` (source-text or arch assertion); `pint --test` clean; no 500 for any listed bad input.
23. PO reference (`tests/Feature/Orders/PoReferenceTest`; each 422 case asserts `errors` has only `po_reference`, no order, no history row, cart intact):
    - Stored and returned: `po_reference: "PO-4711/A"` -> 201, `data.po_reference` is `"PO-4711/A"`, DB column equal; the same value in `GET /orders/{number}` and in the `GET /orders` item (key always present). Allowed set accepted: `A`, `7`, `a b`, `PO.1-2_3/4#5`, a 64-character value.
    - Trimmed: `"  PO 1  "` and `"\tPO 1\n"` stored as `PO 1`. Case and inner spaces preserved.
    - Empty -> NULL: missing, `null`, `""`, `"   "` all give 201 with `po_reference: null` and a NULL column (not `''`).
    - Rejected 422: 65 characters (64 accepted, boundary); 65 characters that are only 64 after trim is accepted; leading `-`, `=`, `+`, `@`, `#`, `.`, `_`, `/` (for example `=SUM(A1)`, `-1`, `@x`); disallowed characters `%`, `'`, `"`, `<script>`, `&`, `;`, `,`, inner tab, inner newline, `Ä`, emoji; non-string (array `["x"]`, object, number `123`, boolean); NUL byte (`"AB\u0000C"` and trailing `"AB\u0000"`); invalid UTF-8 via a form-encoded body (`po_reference=%FF`).
    - Validation precedes cart handling: bad `po_reference` with an empty cart -> 422 with only `po_reference`; valid `po_reference` with an empty cart -> 422 `cart` and nothing stored. Staff -> 403 even with a bad `po_reference`.
    - Immutable: `po_reference` in the body of cancel, status and reorder is ignored; the stored value is unchanged after cancel and status changes.
    - Search: `q` finds an order by full and partial `po_reference`, case-insensitively (`po-47` finds `PO-4711/A`), and by order number still; one order matched by number and another by PO both appear (OR); `q=%` returns empty even when POs exist; `q=_` returns only orders whose PO contains a literal underscore (not `A1B` for `A_B`), and `q=A_B` does not match `AxB`; `q=\` returns empty without a 500; whitespace-only `q` follows the existing rule; orders with NULL `po_reference` never match the PO branch (q `null` or `none` returns nothing for them).
    - No leak: B's order has PO `SECRET-77`; a1 `GET /orders?q=SECRET-77`, `q=SECRET`, `q=77` return no B order and `meta.total` excludes it; a1's response bodies never contain `SECRET-77` (raw text assertion on list, detail 404, cancel 404, reorder 404); a staff search for `SECRET` finds B's order; the same PO value used by both companies appears only in each own company's results (A searching it sees only A's orders); the OR group does not escape the company scope even combined with `status`/`from`/`to` filters.
    - Schema: `char_length(po_reference) > 64` rejected by the DB CHECK (raw insert); NULL accepted.
    - Resource key lists in the existing exact-keys tests include `po_reference`.

24. Implementation facts (routing, middleware, rendering): foreign order body is exactly `{"message":"Not found."}` with `APP_DEBUG` true and false; staff/admin `POST /orders` is 403 even after 10 prior attempts (never 429); customer `PATCH /orders/{well-formed unknown}/status` 403, malformed number 404, staff with a well-formed unknown number and valid status 404, with an invalid status 422; a user with no role sees no orders and gets 403 on role-guarded routes; `OrderNumber` faked to collide once, `PlaceOrder` still succeeds (savepoint retry); seeder counts 8 orders, 24 items, 19 history rows; `q` of 33 characters is 422 (a 64-character PO is findable by its first 32 characters only).

### 11.2 Frontend (Vitest + RTL) - `frontend-engineer`
Mock `fetch` as in Phases 1 and 2; `QueryClientProvider` (retry off) and `createMemoryRouter`; fake timers for debounce.

Gate items:
1. Cart add: clicking "Add to cart" on a catalog row with quantity 3 sends `POST /cart/items` `{product_id, quantity: 3}` (assert body has exactly those keys), the badge shows the new `line_count`, the live region announces it, and the cart page lists the line.
2. Cart update and remove: changing quantity to 5 sends `PATCH /cart/items/17` `{quantity: 5}` and the line total and subtotal re-render from the response; invalid quantity (0, 10000, empty, 1.5) shows an inline error and sends nothing; Remove sends `DELETE` and the line disappears; removing the last line shows the empty state.
3. Cart persists on reload: render the app, add an item (mock server holds state), unmount, remount with a new `QueryClient` (simulated reload): `GET /cart` is called and the same lines and badge render; no `localStorage`/`sessionStorage` writes (spy).
4. History filters work: ticking status "Pending" writes `?status=pending` and requests it; `from`/`to` write their params; `q` is debounced (one request after 300 ms, URL replace); any filter change removes `page`; restoring from `/orders?status=pending,shipped&from=2026-10-01&to=2026-10-31&sort=-total&page=2` ticks, fills and sorts correctly and the first request carries exactly those params; back/forward restore state; invalid URL values (`status=evil`, `from=2026-13-01`, `sort=x`, `page=-1`) are dropped and not sent; `from` after `to` shows an inline error and sends nothing; "Clear filters" empties the query string.

Extras:
5. Add-to-cart variants: `price_on_request` product shows "Price on request" and no enabled button; `out_of_stock` shows "Out of stock" and no button; 422 `product_id` message shown inline; 429 shows seconds from `Retry-After`; the control is labelled (`getByLabelText('Quantity for 6205-2RS')`); staff and admin see no cart controls, no Cart nav item and `/cart` shows the 403 page; detail page has the same controls.
6. Cart page: unavailable lines show their reason text, are excluded from the subtotal (server value is displayed as is) and "Proceed to checkout" is disabled when `can_checkout` is false; clear cart needs the confirm; loading, empty (with catalog link), error + retry states; money formatted from the body `currency` (USD and EUR cases).
7a. PO reference (checkout and display): the input has an accessible label ("PO reference"); empty or whitespace-only sends `POST /orders` with body `{}` (no `po_reference` key); `"  PO-1/A  "` sends `{ po_reference: "PO-1/A" }` (trimmed); invalid values (65 characters, leading `-` or `=`, `%`, `<`, non-ASCII) show the inline error, set `aria-invalid`, link the error by `aria-describedby` and send nothing; typing then fixing clears the error; a mocked 422 `errors.po_reference` shows the server message on the field and keeps the typed value and the page; the value survives a 422 `cart` refetch. Display: the history table shows the PO in its column and an em dash for null; the detail page shows it, and "None" for null; a PO such as `A&B <b>x</b>` returned by a mocked API renders as literal text (no `<b>` element in the DOM, `getByText` finds the raw string); the `/orders` search field is labelled "Order number or PO reference" and `q=PO-47` is sent as typed (debounced, URL param `q`).
7. Checkout: "Place order" sends `POST /orders` with no items and no totals in the body, is disabled while pending (double click sends once), on 201 empties the cart badge, navigates to `/orders/ORD-...` and shows the confirmation banner; 422 `errors.cart` shows the messages and stays on the page and refetches the cart; empty cart redirects to `/cart`; 429 message.
8. Orders list: loading skeleton, empty with and without filters (customer vs staff text), error + retry, 429; Company column only for staff/admin; status shown as text; pagination bounds and page beyond last redirects; accessible names for all filter controls.
9. Order detail: renders snapshot lines ("Prices as ordered"), totals, timeline `<ol>` in order with actor names or "Portal staff"; Cancel visible only when `allowed_transitions` contains `cancelled` and role is customer; confirm step required, success updates the status and timeline from the response; 422 shows the message; Re-order shows added count, link to cart and the skipped list with reason labels; staff see neither button; 404 and malformed number show "Order not found" (the latter sends no request); a foreign order (404) looks identical to an unknown one.
10. Routing and nav: guest redirected to login with `next`; customer sees Orders and Cart (badge); staff/admin see Orders only; logout clears the cart cache (the next user does not see the previous cart); Phase 2 catalog tests updated for the new customer controls.
11. `npm run lint`, `tsc -b`, `npm test`, `npm run build` pass.

### 11.3 QA (`qa-engineer`)
Run: `cd api && ./vendor/bin/pest && ./vendor/bin/pint --test`; `cd web && npm run lint && npm test && npm run build`; confirm tests ran on `portal_test` and CI is green with no service change. Manual (after `php artisan migrate:fresh --seed` in `local`, so `OrderDemoSeeder` runs; `php artisan serve`; `npm run dev`):
1. A-vs-B in two browsers: Alice (Northwind) sees exactly the 5 Northwind seeded orders, Bob (same company) the same 5, Carol (Globex) only her 3; staff sees 8. Alice opens Carol's order URL (copy `ORD-GXAAA-AAAA2`) -> "Order not found"; `curl` with Alice's cookie: detail, cancel and reorder of `ORD-GXAAA-AAAA2` all return 404 with the exact body, and Carol's order is unchanged; `PATCH .../status` as Alice returns 403.
2. Full buy flow as Alice: add from the list and detail with quantities, badge updates, reload `/cart` keeps lines, edit quantity, remove, place order, banner shown, order appears in history as pending, cart empty. Bob (same company) sees and can cancel that pending order. Cart contents of Alice and Bob stay separate.
3. Status flow with `curl` as staff (cookie jar + XSRF header): confirm, ship, deliver; the timeline in Alice's browser (refresh) shows each step with "Portal staff" and times; customer cancel button disappears after confirm; staff cancel of a confirmed order works; illegal jumps return 422.
4. Price change: place an order, change that product's price/active flag in tinker, reload: the old order is unchanged; reorder skips the deactivated product with a visible reason and uses the new price for others.
5. Tampering with devtools or curl: POST /orders with a fake total and items; POST cart with price fields; quantity 0 / 10000 / 1.5; confirm none are accepted.
6. Double submit: two simultaneous `curl -X POST /api/v1/orders` (background `&`) with one filled cart: one 201 and one 422; exactly one new order in the list. Also double click "Place order" in the UI: one order.
7. Rate limit: 11 rapid `POST /orders` -> 429 with `Retry-After`; UI shows seconds.
8. History filters in the UI: status checkboxes, date range, order-number search, sort; URL copy/paste restores; back/forward; invalid hand-edited URL recovers.
9. Keyboard-only run through cart, checkout, order detail (cancel confirm, re-order panel); labels, live regions, focus; axe scan no serious violations; 375 px layout.
10. Query count spot check with `DB::listen` or Telescope-less log on the list page: constant with 8 vs many orders (seed 200 orders for one company via tinker/factory and compare).
11. PO reference: place an order with `PO-4711/A` and one without; both show correctly in history and detail (em dash / "None"); search `4711`, `po-4711` finds it; `curl` `POST /orders` with `=cmd|' /C calc'!A0`, `<script>alert(1)</script>`, 65 characters, `%`, a NUL (`\u0000`) and an array: all 422 `po_reference`, no order created; Alice searching `GX-PO` finds nothing (Carol's seeded PO), staff finds it; no script runs and no HTML is interpreted anywhere the PO is shown.
12. Report only; do not fix feature code.

### 11.4 security-reviewer focus (after QA)
Company scoping on every order query (scope + policy), 404 not 403 for foreign orders with identical body and no timing-free oracle beyond what is accepted; role middleware before lookup; no numeric id leakage (`id`, `order_id`, `user_id`, `company_id`, `created_by` absent from bodies); mass assignment (`$fillable`, `forceFill` only with server values); price and total never from the client; snapshot immutability; order number randomness (`random_int`, not `rand`/`mt_rand`) and unique handling; race conditions (user-row lock, order-row lock, cart caps under concurrency); history actor name exposure; transition endpoint role check; rate limits including `orders-create`; SQL injection through `q`, `sort`, `status`, `company_id` (bindings, whitelist sort, LIKE wildcards escaped or removed); `po_reference`: strict allow-list validation, NUL and encoding checks before trim, stored XSS (rendered as text only), formula-injection leading-character rule, never used in an authorization/scope decision, the search OR group cannot escape the company scope (cross-company PO never found); CSRF header required on all writes; no stack traces or model names in 404/422 bodies.

## 12. Config, env, CI and docker

- New `api/config/orders.php`: `max_quantity` (9999), `max_cart_lines` (100). Currency from the existing `config('catalog.currency')`. No new env var, `.env.example` unchanged.
- `AppServiceProvider`: register limiter `orders-create` = `Limit::perMinute(10)->by('orders-create:'.$request->user()->id)` (user id key; it sits behind `auth:sanctum` so the user always exists; if reused before auth, fall back to the Phase 1 IP key). Route group: `auth:sanctum` for everything; `role:customer` for cart, `POST /orders`, reorder; `role:staff|admin` for the status endpoint; `throttle:orders-create` on `POST /orders` only. Route constraints: `{number}` is a global route pattern `[A-Za-z0-9-]{1,32}` and `{product_id}` `[0-9]{1,18}`. A non-matching value is a plain 404 decided by routing before any middleware (so even a customer calling the staff status endpoint gets 404, not 403). A well-formed unknown number reaches the role middleware: 403 for a customer on the staff status endpoint, 404 for a staff user. Register `/orders/{number}/...` routes after `GET /orders`.
- Middleware priority (`appendToPriorityList` in `bootstrap/app.php`): the role middleware runs after authentication and BEFORE any rate limiter and before any lookup. Consequences: staff/admin on `POST /orders` get 403 (never 429, `orders-create` is not reached); a customer on the staff status endpoint gets 403 before any lookup.
- Exception rendering (`bootstrap/app.php`, changed in Phase 3): the `api/*` 404 renderer now matches any `HttpExceptionInterface` with status 404 (not only `NotFoundHttpException`/`ModelNotFoundException`), because Policy `Response::denyAsNotFound()` produces a plain `HttpException`. A foreign order returns exactly `{"message":"Not found."}` with `APP_DEBUG` true or false.
- Routes live in the existing `v1` group in `routes/api.php`.
- CI: no change (Postgres service already there; no extension needed). Docker: none. README: add the demo order numbers and the `OrderDemoSeeder` note. `docs/PLAN.md` ticks: main session after QA and review.

## 13. Phase 3 file plan (for plan-mode approval)

- api: `config/orders.php`; migrations (orders, order_items, order_status_history, cart_items); `app/Enums/OrderStatus.php`; `app/Models/{Order,OrderItem,OrderStatusHistory,CartItem}.php` (+ `User`/`Company` relations); `app/Policies/OrderPolicy.php`; `app/Services/{PlaceOrder,OrderStatusChanger,CartService}.php` (or Actions); `app/Http/Controllers/Api/V1/{CartController,OrderController}.php` (the status action is `OrderController::status`; `Controller` uses `AuthorizesRequests`); `User::isCustomer()/isStaff()` (hasRole with `loadMissing('roles')`); `OrderNumber` generator resolved from the container (fakeable in tests; `PlaceOrder` retries number collisions inside a savepoint); `bootstrap/app.php` (404 renderer for any `HttpExceptionInterface` 404, role middleware priority); Form Requests (`AddCartItemRequest`, `UpdateCartItemRequest`, `ListOrdersRequest`, `PlaceOrderRequest`, `UpdateOrderStatusRequest`); Resources (`CartResource`, `CartItemResource`, `OrderResource`, `OrderDetailResource`, `OrderItemResource`, `OrderHistoryResource`); factories (`Order`, `OrderItem`, `CartItem`); `database/seeders/OrderDemoSeeder.php` + `DatabaseSeeder`; `routes/api.php`; `AppServiceProvider` limiter; tests under `tests/Feature/Cart/*`, `tests/Feature/Orders/*`, `tests/Unit/OrderPolicyTest`, `tests/Feature/Database/OrdersSchemaTest`.
- web: `src/features/cart/*`, `src/features/orders/*`, catalog list/detail gain the add-to-cart control, nav config (Orders, Cart badge), routes and guards, tests beside the features, update of the Phase 2 "no cart buttons" test.
- outside agent scope (main session or user): README, `docs/PLAN.md` ticks. No `.github`, docker or `phpunit.xml` change.

## 14. Open questions for the user

Answered by the user (resolved):
- Q1 RESOLVED, approved: new orders start `pending` and staff confirm (manual confirm only; auto-confirm is not built).
- Q2 RESOLVED, approved: `tax_cents` and `shipping_cents` are 0 in Phase 3; columns exist so Phase 5 can add VAT. (VAT per line or on total, and whether shipping is ever charged, is a Phase 5 question, not a Phase 3 blocker.)
- Q3 RESOLVED, block (as designed): `out_of_stock` items cannot be added to the cart and block checkout; only `on_order` is orderable as backorder.
- Q5 RESOLVED in part: add an optional `po_reference` now (this file 3.5, contract POST /orders). Delivery address, notes and contact are NOT added in Phase 3 (deferred, ask again at Phase 5).
- Q12 RESOLVED, approved: the staff status-change UI stays in Phase 6; until then staff use the API (Pest and QA curl).

Still open:

1. (resolved, see above)
2. (resolved, see above)
3. (resolved, see above)
4. Price on request: blocked in the cart with no quote button until Phase 4. Is that acceptable, or should the catalog show a disabled "Request quote" hint now?
5. (resolved, see above: PO reference added; delivery address and notes deferred)
6. Rate limit: every cart click counts against the shared 60/min `api` limiter along with catalog browsing. Raise the shared limit (for example 120/min) or give the cart its own bucket?
7. Double submit: protection is a server lock plus "cart empty" 422 (second click shows an error, order exists once). Do you want the stricter `Idempotency-Key` replay (returns the original 201 on retry) instead?
8. Cancelling: customer may cancel only while `pending`; once staff confirm, only staff cancel. Is that the intended customer experience (some businesses allow customer cancel until shipped)?
9. Public order identifier: `ORD-XXXXX-XXXXX` random code instead of a numeric id or `PO-2026-000123` counter (to avoid leaking volume). Confirm, or does the business require a human-sequential number printed on documents (then add it as a separate display field in Phase 5 with the invoice number)?
10. History visibility: customers see staff steps as "Portal staff" without names. OK?
11. Company-wide visibility: any user of a company can cancel and reorder any of its orders (Phase 1: equal permissions). Confirm, or should only the creator cancel?
12. (resolved, see above: staff status UI stays in Phase 6)
13. New (PO reference): ASCII-only characters are allowed (letters, digits, space, `. - _ / #`). Do any customers use POs with other characters (for example `&`, `(`, `)`, non-ASCII letters)? Widening is a one-line change; the leading-character rule would stay.
