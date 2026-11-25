# Phase 2 design - Product catalog and search

HTTP contract: `docs/api-contract.md` (Phase 2 section). This file holds schema, indexes, search design, seeders, CSV import, frontend design, dependencies and the test plan. Backend, frontend and QA agents build from both files. Conventions come from `docs/phase-1-design.md` (Sanctum cookie auth, `auth:sanctum`, Postgres 16 on 127.0.0.1:5433, test DB `portal_test`, `api` limiter 60/min per user).

## 1. Decisions (settled by the user or by this design)

| Topic | Decision |
| --- | --- |
| Product data | Seeded demo products (no real list yet). 200+ active demo products, deterministic. Real catalog arrives via `products:import` (Phase 8 or earlier). |
| Currency | One configured code, `config('catalog.currency')` from env `CATALOG_CURRENCY`, default `USD`. Not stored per product. Open question for the user. |
| Auth | Catalog needs login (guests 401). Customers, staff and admin all use the same four endpoints. |
| Company scoping | None. Shared catalog; no `company_id` anywhere in Phase 2 tables or queries. |
| Visibility | Only `active = true` is ever returned, for every role. Staff see inactive products in Phase 6 `/admin`. |
| Type vs category | One concept: the bearing type IS the category (`categories` table, one category per product, flat). Filter param `category` takes slugs. |
| Attributes | Typed columns (not JSON) for everything searchable or filterable. Reason below. |
| Search | Normalised part number, `pg_trgm` GIN index, substring match. |
| Product currency/price source | Prices only come from seeders and the CSV import, never from an HTTP request. |
| Import failure policy | Valid rows are imported, bad rows are skipped and reported, exit code 1. `--dry-run` writes nothing. |

### Typed columns vs JSON attributes

Typed columns. The filters are range queries on numbers (`BETWEEN` on diameters) and equality on a few fixed fields; Postgres can index, constrain (CHECK, FK, enum values) and plan these well, and Eloquent and the API Resource stay trivial. A JSONB `attributes` column would need expression or GIN indexes, no type safety and per-key validation in PHP. The cost of typed columns is a migration when a new attribute type (e.g. "cage material") is added; acceptable because the bearing attribute set is small and stable. If non-bearing product families arrive later, add a nullable `attributes jsonb` column for the non-searchable extras then; not now.

## 2. Dependencies

- Backend: none. No Scout, no query-builder package, no `league/csv` (the import uses `SplFileObject` / `fgetcsv`). The `pg_trgm` extension ships with the `postgres:16` image (contrib is included).
- Frontend: none. Uses installed `react-router-dom` (`useSearchParams`), `@tanstack/react-query` v5 (`placeholderData: keepPreviousData`), `zod` (URL param parsing), `Intl.NumberFormat`. A 10-line `useDebouncedValue` hook is written locally. No UI kit, no MSW.
- Docker / CI: no new service. No change needed to `docker/postgres/init` or the CI postgres service, because the migration runs `CREATE EXTENSION IF NOT EXISTS pg_trgm` itself (pg_trgm is a "trusted" extension since PG 13, so the database owner can create it without superuser; in docker and CI the `portal` user is the superuser anyway) and migrations run in `portal`, `portal_test` and CI. Extensions are per database, which is why the migration is the right place, not the init SQL. `down()` must NOT drop the extension. CI/`.env.example` only gain two env vars (section 9). Phase 8: confirm the production Postgres provider allows `pg_trgm` (every major managed Postgres does).

## 3. Database

New migrations only (never edit applied ones). Order: `create_categories_table`, `create_products_table`.

### 3.1 `categories`

| Column | Type | Constraints |
| --- | --- | --- |
| id | bigint identity | PK |
| slug | varchar(64) | NOT NULL, UNIQUE (`categories_slug_unique`) |
| name | varchar(120) | NOT NULL |
| sort_order | smallint | NOT NULL default 0 |
| created_at, updated_at | timestamp | nullable |

Relation: `Category hasMany Product`. Reference data, seeded by `CategorySeeder` (safe in every environment). Seven rows:

| slug | name |
| --- | --- |
| deep-groove-ball | Deep groove ball bearings |
| angular-contact-ball | Angular contact ball bearings |
| tapered-roller | Tapered roller bearings |
| cylindrical-roller | Cylindrical roller bearings |
| thrust-ball | Thrust ball bearings |
| spherical-roller | Spherical roller bearings |
| needle-roller | Needle roller bearings |

No `is_active` on categories (YAGNI). No soft delete.

### 3.2 `products`

| Column | Type | Constraints / notes |
| --- | --- | --- |
| id | bigint identity | PK |
| category_id | bigint | NOT NULL, FK `categories.id` ON DELETE RESTRICT |
| part_number | varchar(64) | NOT NULL, stored trimmed and UPPERCASE (display value, e.g. `6205-2RS/C3`) |
| part_number_search | varchar(64) | GENERATED ALWAYS AS (`lower(regexp_replace(part_number, '[^A-Za-z0-9]', '', 'g'))`) STORED, UNIQUE. Never written by the app. |
| name | varchar(255) | NOT NULL (Phase 3 snapshots part number, name, price) |
| description | text | NULL (detail endpoint only; never selected in list queries) |
| brand | varchar(64) | NOT NULL |
| inner_diameter_mm | numeric(7,2) | NOT NULL, CHECK > 0 |
| outer_diameter_mm | numeric(7,2) | NOT NULL, CHECK > inner_diameter_mm |
| width_mm | numeric(7,2) | NOT NULL, CHECK > 0 |
| material | varchar(64) | NOT NULL |
| seal_type | varchar(8) | NOT NULL, CHECK IN ('open','2rs','zz'). Non-sealable families (cylindrical, thrust, needle...) use `open`. |
| price_cents | bigint | NULL, CHECK >= 0, CHECK `products_price_max`: `price_cents IS NULL OR price_cents <= 1000000000` (added by the later migration `2026_11_27_100004`; same constant as `Product::MAX_PRICE_CENTS`) |
| price_on_request | boolean | NOT NULL default false |
| stock_status | varchar(16) | NOT NULL default 'on_order', CHECK IN ('in_stock','low_stock','out_of_stock','on_order') |
| active | boolean | NOT NULL default true |
| created_at, updated_at | timestamp | nullable |

Table CHECK `products_price_consistent`: `(price_on_request AND price_cents IS NULL) OR (NOT price_on_request AND price_cents IS NOT NULL)`.

Price cap (`products_price_max`): `price_cents` is at most 1,000,000,000 cents ($10M per unit), enforced in three places that must agree: the DB CHECK (migration `2026_11_27_100004`), the importer (bad row, see section 6) and `Product::MAX_PRICE_CENTS`. Why: Phase 3 allows 100 cart lines x 9999 units, so the largest total is 100 x 9999 x 10^9 = 9.999e14. That fits Postgres `bigint` and a PHP int and stays below JavaScript `Number.MAX_SAFE_INTEGER` (9.007e15), so cart and order totals are exact integers in the browser (no BigInt, no rounding).

Why a generated column: one source of truth in the database. `Product::upsert()` (used by the import) bypasses Eloquent events, so an app-side mutator would let `part_number_search` drift; a generated column cannot. The PHP normaliser used on `q` (`strtolower` then `preg_replace('/[^a-z0-9]/', '', $q)`, ASCII only, no `/u` flag) must stay byte-identical in behaviour with the SQL expression; one Pest test asserts equality over a list of tricky inputs (spaces, `-`, `/`, `.`, lowercase, non-ASCII, empty).

Why `part_number_search` is UNIQUE (and `part_number` has no separate unique index): `6205-2RS` and `6205 2rs` are the same part; the normalised value is the real identity and is the import's upsert key. A second unique index on `part_number` would be redundant. Different brands therefore need different designations in the data (real catalogs do this too: `-2RS1` SKF, `-2RSR` FAG, `-DDU` NSK, `-LLU` NTN).

Mass assignment: `Product::$fillable = []` (nothing is written from requests in Phase 2); seeders, factory and import use explicit attributes. `company_id` does not exist on these tables.

Model: `Product belongsTo Category`; global scope NOT used; a local scope `Product::active()` used by every controller query so "active only" is explicit and greppable. Recommend `Model::preventLazyLoading(! app()->isProduction())` in `AppServiceProvider` so any N+1 throws in tests and dev (backend agent: check it does not break Phase 1 code).

### 3.3 Indexes (PostgreSQL, exact)

All catalog queries have `active = true`, and ~95% of rows are active, so `active` is useless as a leading key. Instead the filter indexes are PARTIAL (`WHERE active`): smaller, and they match every query the API runs. Laravel's schema builder cannot do partial or GIN-opclass indexes, so these are `DB::statement()` in the migration (and dropped with the table).

| Index | Definition | Serves |
| --- | --- | --- |
| `products_pkey` | PK (id) | detail by id |
| `products_part_number_search_unique` | UNIQUE (part_number_search) | import upsert key, exact lookup, default sort `part_number` (index order scan + LIMIT), integrity |
| `products_part_number_search_trgm` | `CREATE INDEX ... ON products USING gin (part_number_search gin_trgm_ops) WHERE active` | `q` substring search (`LIKE '%62052%'`) |
| `products_category_size_idx` | btree (category_id, outer_diameter_mm, inner_diameter_mm) WHERE active | type + diameter range (the main combo), category alone, FK lookups |
| `products_brand_category_idx` | btree (brand, category_id) WHERE active | brand alone, brand + type |
| `products_size_idx` | btree (inner_diameter_mm, outer_diameter_mm, width_mm) WHERE active | size-only lookup d x D x B (the classic bearing search) |
| `products_material_idx` | btree (material) WHERE active | material filter |
| `products_seal_type_idx` | btree (seal_type) WHERE active | seal filter |
| `products_stock_status_idx` | btree (stock_status) WHERE active | stock filter |
| `products_price_idx` | btree (price_cents) WHERE active | `sort=price` |
| `categories_slug_unique` | UNIQUE (slug) | category filter lookup |

Notes:
- The three low-cardinality indexes (material, seal, stock) will rarely win alone, but Postgres combines them with BitmapAnd, and CLAUDE.md requires indexes on filtered columns. Phase 7's `EXPLAIN` review may drop any that stay unused.
- The `category_id` FK has no standalone index; `products_category_size_idx` leads with it.
- No dedicated indexes for `sort=brand|width|inner_diameter|outer_diameter`: at 5,000 rows the sort of the filtered set is a few ms in memory; revisit above ~50,000 rows.
- Query planner at 5,000 rows will often choose a sequential scan whatever the indexes (the whole table is ~1 MB). That is correct and fast. The indexes are for growth and for the Phase 7 load check; do not "fix" a seq scan on a 5k table.

### 3.4 Search: pg_trgm GIN vs btree prefix (choice and justification)

Chosen: `pg_trgm` GIN on `part_number_search`, query `part_number_search LIKE '%' || :q || '%'` (bound parameter).

- The requirement is partial match for part numbers; buyers search by suffix and middle too (`2rs`, `205`, `/c3`). A `text_pattern_ops` btree only serves prefix (`LIKE 'q%'`); the "rest" would be an unindexed `ILIKE '%q%'` seq scan, i.e. two code paths and no index for the case that matters.
- Performance gate (5,000 products, under 300 ms): both approaches pass at 5,000 rows (a seq scan of 5k short strings is about 1-2 ms). The GIN index is chosen for the Phase 7 gate (p95 under 500 ms at 50 concurrent users) and for catalog growth to tens of thousands of rows, at the price of one migration statement and one extension. Insert cost on GIN is higher, irrelevant for a read-mostly catalog with bulk imports.
- Trigram limits to document in code comments: the index helps only when the normalised query has 3 or more characters; for 1-2 characters Postgres scans sequentially (fine at this size).
- Normalised input has only `a-z0-9`, so LIKE wildcards cannot appear. The code still escapes `\`, `%`, `_` with `addcslashes($q, '\\%_')` (defence in depth), uses a bound parameter (never string concatenation), and treats an empty normalised query as "no match".
- Relevance order when `q` is set and no `sort` is given:
  `ORDER BY CASE WHEN part_number_search = ? THEN 0 WHEN part_number_search LIKE ? THEN 1 ELSE 2 END, part_number_search, id` (second binding is `q || '%'`).

### 3.5 Query construction rules (backend)

- One Form Request `ListProductsRequest` validates and normalises all parameters (`prepareForValidation` splits CSV into arrays; validation errors are mapped back to the parameter name, never `brand.0`; `brand`/`material` use a `preg_match` with the `/u` flag so a NUL byte or invalid UTF-8 fails validation, because Postgres rejects such bytes with a 500). Laravel's `TrimStrings` runs first and strips leading and trailing NUL/control characters from every query parameter (`q` included), so only an INTERIOR NUL or invalid UTF-8 reaches validation as an error; `q=%00` and `q=abc%00` are empty and `abc` (200). The same `/u` check must therefore be applied to `q` as well (interior NUL, invalid UTF-8: 422).
- Query: `Product::active()->select([list columns, no description])->with('category:id,slug,name')`. Category filter is a subquery on `categories.slug` (no join in the main query, so the eager load stays 1 extra query). Ranges use `>=` and `<=` (inclusive). Multi-value filters use `whereIn`.
- Sorting: map the whitelist to column + direction in code (`['part_number' => 'part_number_search', 'brand' => 'brand', 'inner_diameter' => 'inner_diameter_mm', 'outer_diameter' => 'outer_diameter_mm', 'width' => 'width_mm', 'price' => 'price_cents']`); never pass the request value to `orderBy`. `price` adds `NULLS LAST` in both directions. Always append `id ASC`.
- Pagination: `paginate($perPage)` (needs the `count(*)`), response built by a Resource that emits exactly `data` and `meta` (six keys), no `links`.
- `ProductResource` casts `numeric` strings to floats (`(float)`; `25.00` serialises as `25`, `16.25` as `16.25`) and `price_cents` to int.
- `/products/filters` and `/categories`: `Cache::remember(key, 600, ...)`; filters run at most 5 aggregate queries when cold (distinct brand, distinct material, distinct seal, distinct stock, one `min/max` query for the three ranges). `currency` is added after the cache read. `products:import` and the seeders call `Cache::forget` for both keys. Phase 6 CRUD must do the same (note for that phase).
- Constant query count (list): auth user lookup (Sanctum/session) + `count` + products + eager-loaded categories, plus 1 for the category-slug validation or subquery when `category` is used. Hard upper bound asserted in tests: 6. The count must be identical for `per_page=5` and `per_page=100`.

## 4. Performance approach (5,000 products, under 300 ms)

- Select only list columns (no `description`), eager load category with `id,slug,name`, no accessors that query.
- Partial indexes (3.3), `count(*)` over the filtered set (cheap at 5k rows), per_page capped at 100, default 24.
- Filters and categories are cached, so the page's two side requests cost no catalog SQL when warm.
- Frontend: TanStack Query caches (list staleTime 30 s, filters and categories staleTime 10 min), `keepPreviousData` so paging never blanks the table, 300 ms debounce so typing issues one request.
- Seeder option for 5,000: `CATALOG_SEED_COUNT=5000 php artisan db:seed --class=ProductSeeder` (count = number of ACTIVE products; see 5). Measure per section 8.3.

## 5. Seeders and factories

### Env and config
`config/catalog.php`: `currency` (env `CATALOG_CURRENCY`, default `USD`, must match `^[A-Z]{3}$`), `seed_count` (env `CATALOG_SEED_COUNT`, default `250`, integer 1 to 20000).

### `CategorySeeder`
Idempotent `updateOrCreate` by `slug` with the table in 3.1 and `sort_order` 10, 20, ... Runs in every environment (production needs the taxonomy). Flushes catalog caches.

### `ProductSeeder` (local and testing only, aborts otherwise, same rule as `DemoSeeder`)
- Requires categories; calls `CategorySeeder` if empty.
- Creates `seed_count` ACTIVE products plus `floor(seed_count / 20)` INACTIVE products (so default 250 gives 250 active + 12 inactive; the "200+ products" requirement is on active ones, and inactive ones exist to exercise hiding).
- Deterministic: no `rand()`, `Faker` unseeded, `now()` in data, or `Str::random`. Everything is derived from the loop index (fixed arrays and modulo arithmetic). Running it twice must give the same final table: it upserts by `part_number_search` (idempotent), never duplicates.
- Realistic data: a hand-written table of base designations with real ISO dimensions (d x D x B in mm) as anchors, then variants:

| Designation | Category | d | D | B |
| --- | --- | --- | --- | --- |
| 6000 | deep groove | 10 | 26 | 8 |
| 6200 | deep groove | 10 | 30 | 9 |
| 6201 | deep groove | 12 | 32 | 10 |
| 6202 | deep groove | 15 | 35 | 11 |
| 6203 | deep groove | 17 | 40 | 12 |
| 6204 | deep groove | 20 | 47 | 14 |
| 6205 | deep groove | 25 | 52 | 15 |
| 6005 | deep groove | 25 | 47 | 12 |
| 6305 | deep groove | 25 | 62 | 17 |
| 6206 | deep groove | 30 | 62 | 16 |
| 7205B | angular contact | 25 | 52 | 15 |
| 7206B | angular contact | 30 | 62 | 16 |
| 30205 | tapered roller | 25 | 52 | 16.25 |
| NU206 | cylindrical roller | 30 | 62 | 16 |
| 51105 | thrust ball | 25 | 42 | 11 |
| 51106 | thrust ball | 30 | 47 | 11 |
| 51205 | thrust ball | 25 | 47 | 15 |
| 22205 | spherical roller | 25 | 52 | 18 |
| HK2512 | needle roller | 25 | 32 | 12 |

  Further series and bore sizes are generated from per-family patterns (bore code x series -> d, D, B approximations that always satisfy `D > d`, `B > 0`) so 5,000 rows stay plausible. Exact ISO fidelity beyond the anchor table is not required.
- Variants: seal (`open`, `-2RS`, `-2Z` mapped to `2rs`/`zz`; non-sealable families only `open`), clearance suffix (none, `/C3`), brand rotating over SKF, FAG, NSK, NTN, Timken with brand-specific suffix so `part_number` is unique (fictional demo data; real trademarks are only brand labels in demo data). Part numbers uppercase, e.g. `6205-2RS`, `6205-2Z/C3`, `NU206`.
- Distribution (by index modulo, deterministic): material Chrome steel 80%, Stainless steel 12%, Ceramic hybrid 8%; stock in_stock 60%, low_stock 15%, on_order 15%, out_of_stock 10%; `price_on_request` about 5% (price null); other prices 150 to 25,000 cents scaled by size; every 20th extra product inactive.
- Writes with chunked `upsert` (500 rows) inside one transaction; flushes catalog caches at the end. 5,000 rows must seed in well under a minute.
- `DatabaseSeeder`: `RolesSeeder` and `CategorySeeder` always; `DemoSeeder` and `ProductSeeder` only when `APP_ENV` is `local` or `testing` (same allowlist as Phase 1).

### `ProductFactory` (tests)
Valid by default and satisfies every CHECK (`outer > inner`, price consistency). States: `inactive()`, `priceOnRequest()`, `inStock()` and friends, and explicit attribute overrides. Tests use the factory with explicit values, not the seeder, for assertions about specific part numbers. `CategoryFactory` with unique slugs.

## 6. CSV import: `php artisan products:import {file} {--dry-run}`

Purpose: load a product list from an Excel/ERP export (open item in PLAN.md) and keep it in sync. CLI only (no HTTP upload in Phase 2, so no upload/MIME surface; the operator is trusted but every row is validated). Prices come from the file's `price_cents` column, which is operator data; no request input ever sets a price.

### File format
UTF-8 (a BOM is stripped), comma delimiter, RFC 4180 quoting, CRLF or LF, first line is the header. Header names are case-insensitive and may be in any order; unknown columns are ignored with a warning; a missing required column is a fatal error. Max 100,000 data rows. Max record length 1,000,000 bytes: a data record longer than that is a bad row (`row: line longer than 1000000 bytes`; the import continues and exits 1); an over-long header is fatal (exit 2).

| Column | Required | Rule |
| --- | --- | --- |
| `part_number` | yes | trimmed, uppercased on import; 1-64 chars; pattern `^[A-Za-z0-9][A-Za-z0-9 ._/-]*$`; the normalised form must be non-empty. Upsert key is its normalised form. |
| `name` | yes | 1-255 chars; must not start with `=`, `+`, `-`, `@`, tab or CR (leading spaces are ignored for this check), else bad row `name: must not start with = + - @ or a tab/carriage return (spreadsheet formula)` |
| `brand` | yes | 1-64 chars, pattern as the API `brand` filter (no commas), and must start with a letter or digit |
| `category` | yes | an existing category slug (exact). Unknown slug is a bad row (categories are not auto-created). |
| `inner_diameter_mm` | yes | decimal, `.` separator, max 2 decimals, 0 < value <= 5000 |
| `outer_diameter_mm` | yes | same, and strictly greater than `inner_diameter_mm` |
| `width_mm` | yes | same |
| `material` | yes | 1-64 chars, pattern as `brand`, and must start with a letter or digit |
| `seal_type` | yes | `open`, `2rs` or `zz` (case-insensitive) |
| `price_cents` | one of the two | digits only (no `12.50`, no `$`, no sign, no thousands separators; format check first), then an integer from 0 to 1000000000 (`Product::MAX_PRICE_CENTS`, $10M per unit). Longer or larger values are bad rows, never a DB error. Empty when price on request. |
| `price_on_request` | no (default false) | `true/false/1/0/yes/no` (case-insensitive) or empty |
| `stock_status` | yes | `in_stock`, `low_stock`, `out_of_stock` or `on_order` |
| `active` | no (default true) | same boolean forms as `price_on_request` |
| `description` | no | up to 5,000 chars; same formula-prefix rule as `name` (message `description: must not start with = + - @ or a tab/carriage return (spreadsheet formula)`) |

Price rule: exactly one of `price_cents` (non-empty) or `price_on_request = true`. Both set, or neither, is a bad row.

### Behaviour
1. Read the file once, row by row (streaming, no full load). Validate each row. Rows are keyed by normalised part number; a second row with the same key in the same file is a bad row ("duplicate of line N"; the first occurrence wins).
2. Not `--dry-run`: valid rows are written in chunks of 500 with `upsert(..., uniqueBy: ['part_number_search'])` inside ONE transaction, updating every listed column except the generated one. Re-running the same file changes nothing observable (same row count, same values; `updated_at` is not part of the idempotency check). Products absent from the file are NEVER deleted or deactivated by the import. Setting `active = false` in the file deactivates.
3. `--dry-run`: runs all validation and the create/update classification (a `SELECT` of existing keys), writes nothing, does not flush caches.
4. After a real run (even with bad rows) flush `catalog:filters` and `catalog:categories`. The flush happens after the transaction commits, never inside it, and not on a fatal error or with `--dry-run`.
5. Output (stdout): a line per bad row, then a summary.
   ```
   line 14: outer_diameter_mm: must be greater than inner_diameter_mm
   line 22: category: unknown category "tapered-rolller"
   line 31: price_cents: must be a non-negative integer; got "12.50"
   line 35: price_cents: must be at most 1000000000 (cents); got "2000000000"
   line 40: part_number: duplicate of line 9 (6205-2RS)
   Rows read: 200, created: 150, updated: 47, bad: 3
   ```
   Reasons are `column: message` pairs; several problems in one row are joined with `; ` on one line. Line number is the 1-based physical line of the start of the record, header = line 1. Bad rows are listed in file order; if more than 100 bad rows, the first 100 are listed and the summary states the total. In `--dry-run` the summary line starts with `DRY RUN:` and says "would create / would update". Every existing row in the file counts as updated, including rows whose values are unchanged (there is no "unchanged" counter).
6. Exit codes: `0` all rows valid; `1` one or more bad rows (valid rows were still imported unless `--dry-run`); `2` fatal, nothing imported (file missing or unreadable, empty, missing required header column, not valid UTF-8 header, header longer than 1,000,000 bytes, more than 100,000 rows).
7. Security: the path is an operator-supplied CLI argument, only read, never executed or echoed unescaped; no network; values reach the database only through bindings; the command is not registered as a scheduled task or HTTP route.

### Example (use in tests, written to a temp file, never real data)
```
part_number,name,brand,category,inner_diameter_mm,outer_diameter_mm,width_mm,material,seal_type,price_cents,price_on_request,stock_status,active
6205-2RS,Deep groove ball bearing 6205-2RS,SKF,deep-groove-ball,25,52,15,Chrome steel,2rs,1250,,in_stock,true
NU206,Cylindrical roller bearing NU206,FAG,cylindrical-roller,30,62,16,Chrome steel,open,,true,on_order,true
```

## 7. Frontend design (web/)

Suggested layout under `web/src/features/catalog/`: `api.ts` (typed fetch via the existing wrapper + Zod response types), `urlState.ts` (parse and serialise), `CatalogPage.tsx`, `FilterSidebar.tsx`, `ProductTable.tsx`, `Pagination.tsx`, `ProductDetailPage.tsx`, `format.ts` (price, stock, seal labels), `useDebouncedValue.ts`. Routes `/catalog` and `/catalog/:id` inside `ProtectedRoute` (any role). Nav config gains "Catalog" for customer, staff and admin; Home links to it. No role-specific UI (no edit buttons until Phase 6; no cart or quote buttons until Phases 3 and 4).

### 7.1 URL is the source of truth
- Read state with `useSearchParams`; parse with a Zod schema (`urlState.ts`) into a typed `CatalogQuery`. Anything invalid (unknown `sort`, non-numeric range, min > max, page < 1, unknown stock value) is dropped, not sent to the API and not an error. Unknown params are ignored and dropped from the URL on the next change.
- Keys equal the API parameter names. CSV for multi-select (`brand=SKF,NSK`). Defaults omitted (no `page=1`, no default `sort`). Serialise in a fixed key order so equal states give equal URLs and equal TanStack query keys.
- Discrete changes (checkbox, sort, page, clear, chip removal) use `navigate` push so back/forward steps through filter states. Typing in text and range inputs uses `replace` after the debounce (300 ms search, 400 ms ranges) so the history is not flooded. Any filter or search change deletes `page`.
- The search box keeps local text state for responsiveness; when the URL `q` changes from outside (back/forward, clear all) the box is re-synced to it. Restoring from URL on first load, reload and a pasted link must give the same view (checkboxes ticked, ranges filled, sort selected, input filled).
- Query keys: `['catalog','products', canonicalQuery]`, `['catalog','filters']`, `['catalog','categories']`, `['catalog','product', id]`. List uses `placeholderData: keepPreviousData`.

### 7.2 Page structure and states
- Layout: filter sidebar (`<aside aria-label="Filters">`) left on `lg+`; below `lg` it collapses behind a "Filters" button (`aria-expanded`, `aria-controls`). Results region on the right with a heading, the result count, sort select and the table.
- Sidebar controls: search input (`type="search"`, visible label "Part number", hint "e.g. 6205 or 6205-2RS"); Category, Brand, Material, Seal type, Stock status as `<fieldset>` + `<legend>` with checkboxes (each has a real `<label>`); three range pairs with labels "Inner diameter min (mm)" / "max (mm)", etc., `inputMode="decimal"`, placeholders from `ranges` min/max; invalid range (min > max or bad number) shows an inline `role="alert"` message tied by `aria-describedby` and is not sent. "Clear all filters" button (only when something is active). Active filters shown as removable chips (`aria-label="Remove filter Brand: SKF"`).
- Results: a real `<table>` with `<caption class="sr-only">`, columns Part number (link to detail), Brand, Type, Size d x D x B (mm), Seal, Stock, Price. Below `md` rows collapse to stacked labelled blocks. Stock status is text (not colour only).
- A polite live region announces "N products found" after each result change. After a page change, focus moves to the results heading.
- Loading: first load shows skeleton rows with `aria-busy="true"`; subsequent loads keep the previous rows visible (dimmed, `aria-busy`) so the layout does not jump.
- Empty: with active filters or `q`: "No products match your search." plus a "Clear all filters" button. With no filters: "The catalog is empty." (no button).
- Error: `role="alert"` message plus "Try again" button (refetch). 429 shows "Too many requests, try again in N seconds" from `Retry-After`. 401 is handled globally (Phase 1 redirect). A 422 should not happen after URL sanitising; if it does, show the generic error with "Clear all filters".
- The page renders the table only when both the list and `/products/filters` (for the currency) are successful; a filters failure shows the same error state with retry. Categories failure only disables the Category facet with a small inline error.
- Pagination: `<nav aria-label="Pagination">` with Previous, page "X of Y", Next; disabled (not hidden) at the bounds; 24 per page fixed. A `page` beyond `last_page` in the URL (empty `data`, `total > 0`) redirects (replace) to the last page.

### 7.3 Price and stock display
- `price_on_request = true`: text "Price on request" (never "0" or "$0.00").
- Otherwise `new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(price_cents / 100)` with `currency` from `/products/filters`. Fixed `en-US` locale until the user decides the market. No tax label ("excl./incl. VAT") until the VAT open question is settled.
- Stock labels: `in_stock` "In stock", `low_stock` "Low stock", `out_of_stock` "Out of stock", `on_order` "On order". No quantities (no live stock in v1).
- Seal labels: `open` "Open", `2rs` "2RS (rubber sealed)", `zz` "ZZ (metal shielded)". Dimensions rendered as `25 x 52 x 15 mm`.

### 7.4 Detail page `/catalog/:id`
- `:id` not matching `^\d{1,18}$` shows the "Product not found" page without calling the API. 404 from the API shows the same page (heading, short text, link to `/catalog`), not an error banner. Loading skeleton; other errors show alert and "Try again".
- Content: heading with part number, name, brand, category; a `<dl>` specification list (inner and outer diameter, width, material, seal, stock, price, description); `document.title` set to the part number.
- "Back to results" link uses the catalog `location.search` passed in router state from the list (falls back to `/catalog`), so filters are kept. Direct deep links work.

## 8. Test plan

Gate items from `docs/PLAN.md` Phase 2 come first in each list, in PLAN order, then extras. Backend tests are Pest Feature tests on `portal_test` with `RefreshDatabase`, `RolesSeeder`, the Phase 1 helpers (Origin and Accept headers, `loginAs`) and `ProductFactory` fixtures.

### 8.1 Backend (Pest) - `backend-engineer`

Gate items:
1. Search by exact and partial part number. Fixtures: `6205`, `6205-2RS`, `6205-2RS/C3`, `16205`, `6305`, `7205B`. `q=6205` returns the first four, `6305` and `7205B` excluded; order is `6205`, then `6205-2RS`, `6205-2RS/C3` (prefix, by part number), then `16205` (relevance). Exact full part number returns it first. Partial `205` and suffix `2rs` match. Case-insensitive: `q=6205-2rs`, `q=6205-2RS`. Normalised: `6205 2rs`, `6205-2rs`, `62052RS`, `  6205-2RS  ` all return the same set. No match returns 200 with empty `data` and `total: 0`. Explicit `sort` overrides relevance.
2. Each filter alone: `category`, `brand`, `inner_diameter_min`, `inner_diameter_max`, `outer_diameter_min/max`, `width_min/max`, `material`, `seal_type`, `stock_status`, each with a fixture set that includes non-matching rows. Combined: type + outer diameter range + brand returns only the intersection; ranges are inclusive at both bounds; min-only and max-only work; a CSV value list is OR within a filter and AND across filters; `q` combined with filters.
3. Pagination: default `per_page` 24 and `meta` keys exactly (`current_page,last_page,per_page,total,from,to`), no `links`; with 30 fixtures page 1 and 2 have 24 and 6 rows, `from/to/last_page/total` correct; walking all pages returns every id exactly once; page beyond last returns 200 empty with `from: null`. Invalid sort and filter return 422 with the standard shape and the `errors` key equal to the parameter name: `sort=foo`, `sort=password`, `sort=id;drop`, `sort[]=part_number`, `seal_type=rubber`, `stock_status=gone`, `category=nope`, `outer_diameter_min=abc`, `outer_diameter_min=-5`, `outer_diameter_min=1e3`, `outer_diameter_min=60&outer_diameter_max=50` (key `outer_diameter_min`), `per_page=0`, `per_page=101`, `per_page=abc`, `page=0`, `page=-1`, `brand[]=SKF`, `q` of 65 characters.
4. Constant query count: with 5 products and with 100 products, list requests (no filter, with `q`, with `category` + `brand` + range, `per_page=5` vs `100`) run the same number of queries, at most 6 (`DB::enableQueryLog`); `/products/{id}` at most 4; Eloquent lazy loading prevention is on and no `LazyLoadingViolationException` is thrown. Filters and categories when warm run no query against `products` or `categories`.
5. CSV import idempotent and reports bad rows: valid file creates N rows; second run leaves identical rows (count and column values equal, exit 0). A file with bad rows exits 1, prints `line N:` with the column and reason for each (wrong dimension order, unknown category, bad price, price conflict, bad enum, missing required field, duplicate part number in file), imports the valid rows anyway, and counts in the summary are right. Update path: changed price/stock in a second file updates the same row (same id).

Authorization and visibility:
6. For the four endpoints: guest 401; customer, staff and admin 200. Non-GET methods return 405 for customer and staff (and no product row is created or changed by any of them).
7. Company scoping (shared catalog): customer of company A and customer of company B get identical bodies for the same request; `?company_id=<other>` changes nothing and does not 422; response JSON contains no `company`, `company_id`, `active`, `description` (list), or timestamps keys.
8. Inactive products: absent from `/products` (also when `q` is their exact part number), `/products/{id}` is 404 for customer, staff and admin, absent from `brands`, `materials` and `ranges` (e.g. an inactive product with the only `Timken` brand or the max diameter does not appear in `/filters`), excluded from `product_count`. Unknown id, inactive id and `/products/abc`, `/products/-1`, `/products/99999999999999999999999` are 404 (not 500), and so is an unknown `api/*` route; every one asserts `assertExactJson(['message' => 'Not found.'])` (no model name), with `APP_DEBUG` both true and false. `/products/filters` is not captured by the `{id}` route.
9. `ProductResource` exact keys and types (integers, floats, `price_cents` null for price on request, `seal_type`/`stock_status` strings); detail adds only `description`.

Search safety:
10. `q` values `' OR 1=1 --`, `6205'; DROP TABLE products;--`, `"`, `\`, `%`, `_`, `%%%`, `6_05`, `62%5`, `\%`, an emoji, `%00` alone (trimmed to empty: 200, whole catalog as with no `q`), `abc%00` (trimmed: 200, same as `abc`), an interior NUL (`a%00b`: 422), and invalid UTF-8 (`%FF`: 422) return 200 or 422 as stated, never 500, and `%`, `_`, `6_05`, `62%5` return an EMPTY result (wildcards are literal/removed, never "match everything"). The `products` table still exists with the same row count afterwards. `brand=S%00KF` (embedded NUL) and `brand=%FF` return 422. A lone leading or trailing NUL (`brand=%00`, `brand=SKF%00`) is trimmed by `TrimStrings`: 200, same result as the parameter absent (or as `brand=SKF`). A brand containing `%` or `_` in the filter is 422 (pattern) so it cannot act as a wildcard.
11. Normaliser parity: PHP normaliser output equals Postgres `part_number_search` for a list of inputs (spaces, `-`, `/`, `.`, mixed case, non-ASCII, leading zeros).

Sorting:
12. Each of the 12 sort values orders correctly; `price` and `-price` put price-on-request last; ties broken by `id` ascending in both directions: with 30 products sharing one `outer_diameter_mm`, paging through `sort=outer_diameter` and `sort=-outer_diameter` yields each id exactly once and the order within ties is id ascending. Default order without `q` is `part_number`.
13. `per_page` cap: 100 allowed, 101 is 422, response never has more than 100 rows.

Rate limit:
14. 60 `GET /products` requests per minute by one user succeed; the 61st returns 429 with `Retry-After` > 0 and the standard 429 body; the bucket is shared across the four endpoints; another user is not affected; works again after `travel(61)->seconds`.

Database and seeders:
15. Migration: `pg_trgm` extension exists; indexes from 3.3 exist in `pg_indexes` (the trigram index definition contains `gin_trgm_ops` and `WHERE active`); the generated column yields `62052rs` for `6205-2RS` and `62052rs` for `6205 2rs`; inserting a second row with a part number that normalises equal violates UNIQUE; CHECK violations are rejected (outer <= inner, price with price_on_request true, null price with price_on_request false, bad seal or stock value, negative price); deleting a category with products fails (RESTRICT); `part_number_search` cannot be written.
16. `CategorySeeder` creates 7 categories and is idempotent. `ProductSeeder` with the default count creates at least 200 active products plus inactive ones, all part numbers unique, every CHECK satisfied, a second run changes nothing (deterministic: same part numbers and prices), `CATALOG_SEED_COUNT` is honoured, and it aborts in `production`, `staging` and other non-allowlisted environments. `DatabaseSeeder` in `testing` env seeds products.
17. Import extras: `--dry-run` writes nothing (row count unchanged) but reports the same bad rows and says `DRY RUN`; missing file, empty file, missing header column exit 2 with nothing imported; BOM and CRLF files work; quoted field with a comma works; `price_cents` `12.50`, `-1`, `abc` are bad rows; `1000000000` is accepted and `1000000001` and `99999999999999999999` are bad rows with `price_cents: must be at most 1000000000 (cents); got "..."` (nothing reaches the DB, no 500); the DB CHECK `products_price_max` rejects a raw insert of 1000000001 and accepts 1000000000; `name` or `description` starting with `=`, `+`, `-`, `@`, tab, CR (also after leading spaces) and `brand`/`material` not starting with a letter or digit are bad rows with the messages above; a data record over 1,000,000 bytes is a bad row (`row: line longer than 1000000 bytes`), the rest imports, exit 1, while an over-long header exits 2; the cache is flushed after commit only (not on exit 2, not with `--dry-run`); an update of an existing row is counted as updated and the summary has no unchanged counter; price and price-on-request both set or both empty are bad rows; `6205-2rs` after `6205-2RS` in the same file is a duplicate bad row; import never deletes products missing from the file; `active=false` deactivates and a later file with `active=true` reactivates; import flushes `catalog:filters` (a filters call before and after shows the new brand).
18. `config('catalog.currency')` is returned in `/products/filters` and changes when the config is overridden (not served from the cache).
19. `pint --test` clean.

### 8.2 Frontend (Vitest + RTL) - `frontend-engineer`

Mock `fetch` as in Phase 1; wrap in `QueryClientProvider` (retry off) and a memory router (use `createMemoryRouter` so back/forward can be driven with `router.navigate(-1)`). Use fake timers for debounce.

Gate items:
1. Filters update the URL: ticking brand SKF sets `?brand=SKF`; ticking a second brand gives `brand=SKF,NSK`; choosing a category, seal type, stock status and sort writes their params; typing a range value writes `outer_diameter_min` after the debounce; any change removes `page`; "Clear all filters" empties the query string.
2. Filters restore from the URL: rendering at `/catalog?q=6205&category=deep-groove-ball&brand=SKF,NSK&outer_diameter_min=40&sort=-price&page=2` ticks the right checkboxes, fills the inputs, selects the sort, and the first list request carries exactly those params (assert the request URL). Back and forward (`navigate(-1)` and `navigate(1)`) restore the previous filter state in the UI and refetch.
3. Empty state: list returns `data: []` with filters active shows "No products match your search." and a working "Clear all filters"; with no filters shows "The catalog is empty.".
4. Error state: list 500 shows an alert and "Try again" which refetches and recovers; 429 shows the seconds from `Retry-After`; filters request failing also shows the alert.

Extras:
5. Search debounce: typing "6205" quickly sends one request after 300 ms with `q=6205`, URL uses replace (history length does not grow per keystroke); URL `q` changed by back/forward updates the input.
6. Invalid URL values are dropped and not sent: `?sort=evil&page=-3&outer_diameter_min=abc&seal_type=rubber`; min greater than max shows the inline range error and no request carries it.
7. Loading: skeleton with `aria-busy` on first load; previous rows stay visible while the next page loads.
8. Pagination: Previous disabled on page 1, Next disabled on last, clicking Next sets `page=2` and requests it; page beyond `last_page` is replaced by the last page; focus moves to the results heading.
9. Price display: price on request shows "Price on request"; `price_cents: 125050` with currency `USD` shows `$1,250.50`; with currency `EUR` from the filters mock shows the euro format; no "0" or "$0.00" for null prices. Stock and seal labels as in 7.3.
10. Accessibility: every checkbox and input has an accessible name (`getByRole('checkbox', { name: 'SKF' })`, `getByLabelText('Part number')`); each facet is a `group` with a name; the results count is in a live region; chips have remove labels; table has a caption; mobile "Filters" button toggles `aria-expanded`.
11. Detail page: renders spec list and description; loading skeleton; 404 shows "Product not found" with a link to `/catalog` (no error banner); non-numeric id shows not found and sends no request; 500 shows alert and retry; "Back to results" keeps the previous filters.
12. Roles and routing: customer, staff and admin each see the "Catalog" nav item and can open `/catalog`; guest is redirected to `/login?next=/catalog?...` with the query string preserved in `next`; no staff-only items appear for customers (Phase 1 regression); no cart or quote buttons exist.
13. A 401 on a catalog call follows the Phase 1 global behaviour (clear auth, redirect to login).
14. `npm run lint`, `tsc -b`, `npm test`, `npm run build` pass.

### 8.3 QA (`qa-engineer`)

Run: `cd api && ./vendor/bin/pest && ./vendor/bin/pint --test`; `cd web && npm run lint && npm test && npm run build`; confirm tests ran on `portal_test` and CI is green without any service change.

Manual gate (5,000 products under 300 ms):
1. `php artisan migrate:fresh --seed`, then `CATALOG_SEED_COUNT=5000 php artisan db:seed --class=ProductSeeder` (check it finishes and `select count(*) from products where active` is 5000).
2. Log in as `alice@northwind.test` (curl with a cookie jar, or the browser network tab "Waiting for server response"). Warm up with 3 requests, then 20 requests each of: `/products` (page 1), `q=6205`, `q=205` (substring), `q=2rs`, category + outer diameter range + brand, `sort=-price`, last page, `/products/{id}`, `/products/filters` (cold once, warm after), `/categories`. Report median and max in ms. Pass: list requests max under 300 ms (use `php artisan serve` with `APP_DEBUG=false`, `APP_ENV=local`; note the machine). Also run `EXPLAIN (ANALYZE, BUFFERS)` on the `q` query and the combined query and attach the output; at this table size a seq scan is acceptable if the time is low.
3. Compare to 250 products (default seed): response size and time sane.

Manual UI checks (customer and staff accounts):
4. Catalog loads; type `6205`, `6205 2rs`, `6205-2RS` give the same results; URL updates; copy the URL into a new tab and the same view appears; reload keeps filters; browser back and forward step through filter changes.
5. Each filter works alone and combined; "Clear all" and chips work; invalid hand-edited URL (`?sort=evil&page=999`) recovers without an error screen.
6. Price on request, low stock and out-of-stock rows display correctly; currency symbol matches `CATALOG_CURRENCY` (change it to `EUR`, clear cache, reload).
7. Keyboard only: tab through search, every facet, range inputs, table links, pagination; focus visible; screen reader or browser accessibility tree shows labels, live result count; an axe scan has no serious violations; layout at 375 px width (Filters button toggles).
8. Guest opening `/catalog` is sent to login and returns to `/catalog` after login; staff and customer both see the same catalog and nav item; an inactive product's URL (deactivate one in tinker) shows "Product not found".
9. Import: run the example CSV from section 6 with and without `--dry-run`, and a CSV with deliberate bad rows; check the printed line numbers match the file, exit code (`$LASTEXITCODE` or `echo $?`) is 1, and a second run changes nothing.
10. Report only; do not fix feature code.

### 8.4 security-reviewer focus (after QA)
No company data in catalog responses and no `company_id` input honoured; inactive products unreachable by id or search for every role; SQL injection through `q`, `sort`, CSV filters (bindings only, whitelist sort); LIKE wildcard handling; NUL and invalid UTF-8 inputs return 4xx not 500; 405 on write methods; unauthenticated access to all four routes; rate limit present; import command path handling and no price ingestion from HTTP; response bodies free of internal columns.

## 9. Config, env, CI and docker changes

- `api/.env.example`: add `CATALOG_CURRENCY=USD` and `CATALOG_SEED_COUNT=250`. New file `api/config/catalog.php` (section 5).
- `bootstrap/app.php` or `routes/api.php`: the four routes inside the existing `v1` group under `auth:sanctum`; `/products/filters` declared before `/products/{id}` and `{id}` constrained with `->where('id', '[0-9]{1,18}')`. No new throttle (existing `api` limiter applies).
- CI (`.github/workflows/ci.yml`): no change. The existing postgres:16 service is enough because the migration creates `pg_trgm`; `phpunit.xml` needs no change (array cache store is the Laravel test default; confirm `CACHE_STORE=array` there, otherwise add it so the filters cache does not leak between tests, and clear cache in `beforeEach` of the catalog tests).
- Docker: no change. README: add the 5,000-product seeding command and the `products:import` usage and exit codes.
- `docs/PLAN.md`: tick Phase 2 boxes after QA and review (main session, not this agent).

## 10. Phase 2 file plan (for plan-mode approval)

- api: `config/catalog.php`, migrations (categories, products + raw indexes and extension), `app/Models/{Category,Product}.php`, `app/Http/Controllers/Api/V1/{ProductController,CategoryController}.php`, `app/Http/Requests/ListProductsRequest.php`, `app/Http/Resources/{ProductResource,ProductDetailResource,CategoryResource}.php`, `app/Console/Commands/ImportProducts.php`, a small `App\Support\PartNumber::normalise()` used by `ListProductsRequest`/tests, factories (`Category`, `Product`), seeders (`CategorySeeder`, `ProductSeeder`, `DatabaseSeeder` update), routes, `AppServiceProvider` (lazy loading prevention), `.env.example`, tests under `tests/Feature/Catalog/*`, `tests/Feature/Console/ImportProductsTest`, `tests/Feature/Database/CatalogSchemaTest`.
- web: `src/features/catalog/*`, routes, nav config, tests under `src/features/catalog/__tests__`.
- outside agent scope (main session or user): README, `docs/PLAN.md` ticks, `.github/workflows/ci.yml` (no change expected).

## 11. Open questions for the user

1. Currency: which currency (and VAT shown net or gross)? Built as one configured code defaulting to `USD` with no tax label; changing it is an env change.
2. Locale for number formatting: fixed `en-US` for now. Which market and language?
3. Search scope: part number only today. Should the search box also match product name or brand (e.g. typing "SKF")? Brand is a filter.
4. Customer-specific prices or hidden products per company: not in v1 (shared catalog and one price). Confirm that is right for the first customers.
5. Import policy: bad rows are skipped and good rows imported (exit 1). Prefer all-or-nothing instead?
6. Rate limit: the catalog uses the existing 60 requests per minute per user. A fast user paging and filtering could approach it; raise before Phase 7 if the UAT shows it.
7. Real product list (PLAN open question): when available, its columns decide whether the CSV spec (section 6) needs a mapping.

### Phase 2 review follow-ups (open items, all deferred to Phase 7)

- Lower the maximum `page` (now 1,000,000) and/or cap deep `OFFSET` so a deep page cannot force a slow scan.
- Optional dedicated limiter for `GET /products` search (separate from the shared 60/min `api` bucket).
- Stampede protection (lock or stale-while-revalidate) for the `catalog:filters` cache when it expires or is flushed.
- Any future CSV/XLSX export must escape cells starting with `=`, `+`, `-` or `@` (formula injection).
