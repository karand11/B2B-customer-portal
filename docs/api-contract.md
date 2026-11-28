# API contract

Base path: `/api/v1`. JSON only. Source of truth for backend and frontend agents.

## Phase 0

### GET /api/v1/health
- Auth: none (public)
- Scope: none (no company data)
- Request: no body, no query parameters
- Response 200:
  ```json
  { "status": "ok" }
  ```
- Errors: none expected. Any unhandled failure returns 500 with no stack trace (APP_DEBUG=false in production). A failed database query always returns `{"message":"Server Error"}` (see the Phase 1 conventions, 500).

---

## Phase 1 - Authentication and roles

Schema, config, seeders and test plan: `docs/phase-1-design.md`. This section is the HTTP contract.

### Conventions (apply to every endpoint from Phase 1 on)

- Auth is Sanctum SPA cookie auth (session cookie `portal_session` + `XSRF-TOKEN` cookie). No bearer tokens, no `personal_access_tokens` table.
- The browser talks only to the Vite origin `http://localhost:5173`. Vite proxies `/api` and `/sanctum` to `http://localhost:8000`, so the SPA sees one origin.
- Every request must send `Accept: application/json`. The API forces JSON error rendering for `api/*` and `sanctum/*` anyway: guests get 401 JSON `{"message":"Unauthenticated."}` on `api/*` and `sanctum/*` even when no `Accept: application/json` header is sent (never a 302 or 500).
- Every state-changing request (POST/PUT/PATCH/DELETE) must send header `X-XSRF-TOKEN` = URL-decoded value of the `XSRF-TOKEN` cookie, plus `Content-Type: application/json`. Without it: `419 {"message":"CSRF token mismatch."}` (SPA reacts by calling `GET /sanctum/csrf-cookie` again and retrying once).
- Stateful requests are recognised by `Origin` or `Referer` host being in `SANCTUM_STATEFUL_DOMAINS` (`localhost:5173`). Tests must send `Origin: http://localhost:5173`.
- Success bodies for resources are wrapped: `{ "data": ... }`. Messages-only responses are `{ "message": "..." }`. 204 responses have no body.
- Standard error shapes (the `message` text is informational, only the status code and the `errors` keys are contractual):

  401 (not logged in):
  ```json
  { "message": "Unauthenticated." }
  ```
  403 (logged in, wrong role): the message text is non-contractual (it comes from spatie: "User does not have the right roles."); only the status code is contractual.
  ```json
  { "message": "User does not have the right roles." }
  ```
  404 (every `api/*` 404: unknown route, unknown or inactive resource id, malformed id): the body is contractual and is exactly the JSON below, with debug on or off. Internal model names (for example `No query results for model [App\Models\Product]`) must never appear.
  ```json
  { "message": "Not found." }
  ```
  419 (missing or bad CSRF token):
  ```json
  { "message": "CSRF token mismatch." }
  ```
  422 (validation, Laravel standard shape; `message` is the first error, plus "(and N more errors)" when there are several):
  ```json
  {
    "message": "The email field is required. (and 1 more error)",
    "errors": {
      "email": ["The email field is required."],
      "password": ["The password field is required."]
    }
  }
  ```
  429 (rate limited; always sends header `Retry-After: <seconds>`):
  ```json
  { "message": "Too many attempts. Please try again in 42 seconds." }
  ```
  500 (database failure, global rule for EVERY `api/*` endpoint, added in Phase 5 after the security review): any `Illuminate\Database\QueryException` is answered with exactly the JSON below, also with `APP_DEBUG=true`. The body never contains SQL, bound values, a driver message, a table or constraint name or a stack trace.
  ```json
  { "message": "Server Error" }
  ```
  Logging of the same failure: one log record with the fixed message `Database query failed.` and ONLY the context keys `sqlstate`, `sql` (the PLACEHOLDER SQL with `?` markers, never with bound values), `connection` and `code`. Bound values and the driver's error message (which can quote the offending row, for example in a unique-violation detail) are NEVER logged, by the handler or anywhere else. Covered by `tests/Feature/ErrorLeakTest.php` (design doc 13.5).
- Role names are exactly `customer`, `staff`, `admin`. A user has exactly one role.
- Company scoping rule (Phase 1 and later): `customer` users are bound to one `company_id` taken from the server-side session user, never from request input. `staff` and `admin` have `company_id = null` and may read across companies in later phases. No Phase 1 endpoint accepts `company_id` or `role` from the client; if sent, they are ignored.
- Email is case-insensitive: the API lowercases and trims it on input.

### UserResource (shape used by login and /me)

```json
{
  "data": {
    "id": 3,
    "name": "Alice Customer",
    "email": "alice@northwind.test",
    "role": "customer",
    "company": { "id": 1, "name": "Northwind Machinery Ltd" }
  }
}
```

Staff and admin:

```json
{
  "data": {
    "id": 2,
    "name": "Sam Staff",
    "email": "staff@portal.test",
    "role": "staff",
    "company": null
  }
}
```

Rules: exactly these keys, no others (never `password`, `remember_token`, `company_id`, timestamps). `id` and `company.id` are integers. `role` is one of `customer|staff|admin`. `company` is `null` for staff and admin.

### GET /sanctum/csrf-cookie
- Auth: none (public). Not under `/api/v1`.
- Scope: none.
- Request: no body.
- Response 204, no body. Sets cookies `XSRF-TOKEN` (readable by JS) and `portal_session` (HttpOnly).
- Errors: 429 if the global limiter trips (60/min per IP). No 401/403/404/422.
- SPA calls this before login, after logout (the token rotates), and after any 419.

### POST /api/v1/auth/login
- Auth: none (guests). A logged-in user posting again is allowed and simply re-authenticates (session is regenerated).
- Scope: public endpoint; the response contains only the authenticated user's own record and own company.
- Request body (JSON):
  ```json
  { "email": "alice@northwind.test", "password": "Demo-Passw0rd!" }
  ```
  Rules: `email` required, string, valid email, max 255. `password` required, string, max 255. Any other field (`role`, `company_id`, `remember`, ...) is ignored.
- Response 200: UserResource (above). Sets a fresh session cookie (session id regenerated on login).
- Errors:
  - 422 validation (missing or malformed fields): standard shape.
  - 422 wrong credentials, unknown email and wrong password are indistinguishable:
    ```json
    {
      "message": "These credentials do not match our records.",
      "errors": { "email": ["These credentials do not match our records."] }
    }
    ```
  - 419 missing CSRF token.
  - 419 `{"message":"CSRF token mismatch."}` also for a request that is not stateful (no `Origin`/`Referer`, or one outside `SANCTUM_STATEFUL_DOMAINS`). Same body as a CSRF mismatch. Checked before rate limiting and authentication, so nobody is logged in.
  - 429 rate limited (see Rate limits). Applies even if the password in that request is correct.
  - 401, 403, 404: not applicable.

### POST /api/v1/auth/logout
- Auth: `auth:sanctum` (any role).
- Scope: acts only on the caller's own session.
- Request: no body.
- Response 204, no body. Server invalidates the session, regenerates the CSRF token, logs the web guard out. The old session cookie is useless afterwards: `GET /api/v1/me` with it returns 401.
- Errors: 401 guest (including a second logout with the old cookie), 419 missing CSRF token. 403, 404, 422: not applicable.

### GET /api/v1/me
- Auth: `auth:sanctum` (any role).
- Scope: returns the authenticated user only. There is no id in the path or query, so a customer cannot address another user. The company is resolved from `users.company_id` of the session user.
- Request: no body, no query parameters.
- Response 200: UserResource (above).
- Errors: 401 guest. 403, 404, 422: not applicable.
- SPA calls this on app start; 401 means "guest", not an error to display.

### POST /api/v1/auth/forgot-password
- Auth: none (guests).
- Scope: public. The response is identical whether or not the email exists, so company or user existence is never revealed.
- Request body: `{ "email": "alice@northwind.test" }`. Rules: `email` required, string, valid email format, max 255.
- Response 200 (always, for any well-formed email, known or unknown, throttled by the broker or not):
  ```json
  { "message": "If an account exists for that email, a reset link has been sent." }
  ```
- Side effect, only when the user exists (and broker per-user throttle of 60 s is not active): one email with link
  `{APP_URL_WEB}/reset-password?token=<token>&email=<urlencoded email>` (for local dev `http://localhost:5173/reset-password?token=...&email=alice%40northwind.test`). The link points at the SPA, never at the API. Token is valid 60 minutes.
- Unknown emails must not return noticeably faster than known ones: use Laravel's `Password::sendResetLink` and ignore its status; do not add an existence check with an early return. The reset notification is queued (`ShouldQueue`, database queue) so SMTP time never shows in the response time, and it is `ShouldBeEncrypted` (like the Phase 6 invitation mail): the `jobs` payload contains no token (Phase 6 change). Local dev needs `php artisan queue:listen` running (part of `composer dev`); tests use the sync queue.
- Errors: 422 only for missing/malformed email (standard shape). 419 missing CSRF. 429 rate limited (see Rate limits). 401, 403, 404: not applicable.

### POST /api/v1/auth/reset-password
- Auth: none (guests).
- Scope: public; operates on the user matching `email` and a valid token for that user only.
- Request body:
  ```json
  {
    "token": "<token from link>",
    "email": "alice@northwind.test",
    "password": "New-Passw0rd-123",
    "password_confirmation": "New-Passw0rd-123"
  }
  ```
  Rules: `token` required string; `email` required valid email; `password` required, string, min 10, max 255, must equal `password_confirmation`.
- Response 200: `{ "message": "Your password has been reset." }`. The user is NOT logged in by this call; SPA sends them to /login. The token is deleted (single use). Other tokens for that email are gone too. Single use holds UNDER CONCURRENCY (Phase 6 change): the token check, the delete and the password change run in one transaction under a row lock on the user (`App\Services\SingleUseToken::consume()` inside the broker callback), so parallel requests with the same token give exactly one 200 and the identical generic 422 below for all the others. Every session of that user is invalidated: the reset bumps `users.session_epoch` in the same save as the password, so an old session cookie from before the reset gets 401 on its next request (including `GET /api/v1/me`); the user can log in again with the new password. Phase 6 replaced the Phase 1 mechanism (Sanctum `AuthenticateSession`) with the epoch.
- Errors:
  - 422 validation: standard shape (e.g. `errors.password`: "The password field must be at least 10 characters.", "The password field confirmation does not match.").
  - 422 invalid, expired (older than 60 minutes), already used, or unknown email, all identical:
    ```json
    {
      "message": "This password reset link is invalid or has expired.",
      "errors": { "email": ["This password reset link is invalid or has expired."] }
    }
    ```
  - 419 missing CSRF. 429 rate limited. 401, 403, 404: not applicable.

### GET /api/v1/staff/ping
- Auth: `auth:sanctum` + role `staff` or `admin`.
- Scope: no company data. Staff and admin are internal and not tied to a company.
- Request: none.
- Response 200: `{ "status": "ok" }`
- Errors: 401 guest. 403 customer. 404, 422: not applicable.

### GET /api/v1/admin/ping
- Auth: `auth:sanctum` + role `admin` only.
- Scope: no company data.
- Request: none.
- Response 200: `{ "status": "ok" }`
- Errors: 401 guest. 403 customer and staff. 404, 422: not applicable.

The two ping endpoints exist to prove the role guards and are the template for Phase 6 `/admin` routes. They may stay in the codebase.

### Not in Phase 1 (must return 404, covered by a test)
- `POST /api/v1/auth/register` and any other self-registration route do not exist. Users are created by seeders now and by admin invite in Phase 6.
- No email verification, no token (bearer) login, no `PUT /me` or password change while logged in.

### Rate limits (exact numbers)

| Endpoint | Limit | Key | On exceed |
| --- | --- | --- | --- |
| POST /api/v1/auth/login, failed attempts | 5 failed attempts per 60 s | lowercase(email) + "\|" + IP key (see below) | 6th request in the window returns 429, even with a correct password. `Retry-After` = seconds left. Successful login clears the counter. |
| POST /api/v1/auth/login, failed attempts per email | 20 failed attempts per 900 s (15 min) | lowercase(trimmed email), any IP | 429 with `Retry-After` and the standard JSON 429 body, even with the correct password. A successful login does NOT clear it (so an attacker with one working IP cannot reset it). Unknown emails count like known ones. |
| POST /api/v1/auth/login, any outcome | 20 requests per 60 s | IP key (see below) | 429 |
| POST /api/v1/auth/forgot-password | 5 requests per 60 s | IP key | 429 |
| POST /api/v1/auth/reset-password | 5 requests per 60 s | IP key | 429 |
| GET /sanctum/csrf-cookie and everything else | 60 requests per 60 s | user id or IP key (Laravel `api` default) | 429 |

IP key: the client IP, except that an IPv6 address is reduced to its /64 prefix (IPv4 unchanged; an IPv4-mapped IPv6 address such as `::ffff:1.2.3.4` is treated as the IPv4 address). Every per-IP limit (api 60/min, login-ip 20/min, password-reset 5/min, and the IP part of the login email+IP key) uses this same IP key.

Unknown emails count toward the per-email keys exactly like known ones (no enumeration through lockout behaviour). A different email from the same IP is not locked by the email+IP key, nor by the email-only key.

Login check order: 419 check, validation, email+IP limiter, email-only limiter, credentials.

Trade-off (accepted by the user): 20 failures from anywhere can lock a victim's email out for up to 15 minutes, in exchange for stopping distributed guessing.

### Endpoint summary

| Method | Path | Guest | Customer | Staff | Admin |
| --- | --- | --- | --- | --- | --- |
| GET | /sanctum/csrf-cookie | 204 | 204 | 204 | 204 |
| POST | /api/v1/auth/login | 200/422/429 | 200 | 200 | 200 |
| POST | /api/v1/auth/logout | 401 | 204 | 204 | 204 |
| GET | /api/v1/me | 401 | 200 | 200 | 200 |
| POST | /api/v1/auth/forgot-password | 200 | 200 | 200 | 200 |
| POST | /api/v1/auth/reset-password | 200/422 | 200/422 | 200/422 | 200/422 |
| GET | /api/v1/staff/ping | 401 | 403 | 200 | 200 |
| GET | /api/v1/admin/ping | 401 | 403 | 403 | 200 |
| POST | /api/v1/auth/register | 404 | 404 | 404 | 404 |

### Frontend behaviour bound to this contract
- Bootstrap: `GET /api/v1/me`. 200 -> authenticated. 401 -> guest. Anything else -> error screen with retry.
- Login: `GET /sanctum/csrf-cookie`, then `POST /api/v1/auth/login`, store returned `data` as the `me` query. Show `errors.email[0]` on 422, a "try again in N seconds" message from `Retry-After` on 429.
- Logout: `POST /api/v1/auth/logout`, clear all query cache, call `GET /sanctum/csrf-cookie`, go to `/login`.
- Any 401 on a non-bootstrap call: clear auth state, go to `/login?next=<current path>`.
- `next` is honoured only if it starts with a single `/` (not `//`, not a scheme).
- Routes: `/login`, `/forgot-password`, `/reset-password` (reads `token`, `email` from the query string) are guest-only (logged-in users go to `/`). `/` requires login. `/staff` requires staff or admin. `/admin` requires admin. Wrong role -> a 403 "Not allowed" page (not a redirect loop).
- Navigation (single config, filtered by role): Home (all), Staff area `/staff` (staff, admin), Admin area `/admin` (admin). Header shows user name, role, and company name when present.

---

## Phase 2 - Product catalog and search

Schema, indexes, seeders, CSV import, frontend design and test plan: `docs/phase-2-design.md`. This section is the HTTP contract. All Phase 1 conventions (cookie auth, `Accept: application/json`, error shapes, `{ "data": ... }` wrapping, 429 body and `Retry-After`) apply unchanged.

### Phase 2 rules (apply to all four endpoints)

- Auth: `auth:sanctum`, any role (`customer`, `staff`, `admin`). Guests get 401. Decision: the catalog is for logged-in users only; there is no public catalog.
- Company scoping: NONE, on purpose. The catalog is one shared list for every company (no per-company prices or visibility in v1), so no query filters by `company_id` and no endpoint reads or accepts `company_id`. A `company_id` query parameter is ignored like any other unknown parameter. Response bodies contain no company, user or cost data, so there is nothing company-specific to leak. A customer of company A and a customer of company B get byte-identical responses for the same request.
- Visibility: every endpoint returns only products with `active = true`, for ALL roles including staff and admin. An inactive product is indistinguishable from an unknown one (list omits it, detail returns 404). Staff see inactive products only through the Phase 6 `/admin` endpoints.
- Read-only: only `GET` routes exist. `POST/PUT/PATCH/DELETE` on these paths return 405 (JSON). No endpoint accepts a price, stock or `active` value from the client. Product data enters only via seeders and the `products:import` command.
- Unknown query parameters are ignored (not 422).
- Rate limit: the existing Laravel `api` limiter, 60 requests per 60 s per user id (see Phase 1 Rate limits table); one shared bucket across all four endpoints. 429 uses the standard body and `Retry-After`. No new limiter in Phase 2.
- Numbers: `*_mm` are JSON numbers (max 2 decimals), `price_cents` is an integer or `null`, never a string, and never above 1,000,000,000 cents (10,000,000.00 per unit; see "Price cap" under ProductResource). Money is integer minor units; the currency is not on the product (see `/products/filters`).

### ProductResource (list items)

```json
{
  "id": 17,
  "part_number": "6205-2RS",
  "name": "Deep groove ball bearing 6205-2RS 25x52x15",
  "brand": "SKF",
  "category": { "id": 1, "slug": "deep-groove-ball", "name": "Deep groove ball bearings" },
  "inner_diameter_mm": 25,
  "outer_diameter_mm": 52,
  "width_mm": 15,
  "material": "Chrome steel",
  "seal_type": "2rs",
  "price_cents": 1250,
  "price_on_request": false,
  "stock_status": "in_stock"
}
```

Rules: exactly these keys (no `active`, `description`, `part_number_search`, `category_id`, timestamps). `price_on_request = true` implies `price_cents = null`; `price_on_request = false` implies `price_cents` is an integer from 0 to 1,000,000,000 inclusive. `seal_type` is one of `open|2rs|zz`. `stock_status` is one of `in_stock|low_stock|out_of_stock|on_order`. `ProductDetailResource` (detail endpoint) is the same plus `"description": string|null`.

Price cap: `products.price_cents` is at most 1,000,000,000 cents ($10M per unit), enforced by the DB CHECK `products_price_max` (migration `2026_11_27_100004`), by the CSV importer (bad row message `price_cents: must be at most 1000000000 (cents); got "..."`) and by the constant `Product::MAX_PRICE_CENTS`. Rationale: the largest possible cart total is 100 lines x 9999 units x 10^9 = 9.999e14, which fits Postgres `bigint` and a PHP int and stays below JavaScript `Number.MAX_SAFE_INTEGER` (9.007e15), so every cart and order total is an exact integer in the browser.

### GET /api/v1/products
- Auth: `auth:sanctum`, any role. Scope: none (shared catalog, see Phase 2 rules).
- Request: query string only.

| Param | Type and rule | Meaning |
| --- | --- | --- |
| `q` | string, trimmed (Laravel `TrimStrings` also strips leading and trailing NUL/control characters, so `q=%00` and `q=abc%00` behave as empty and as `abc`), max 64. Empty or absent means no search. An interior NUL (`q=a%00b`) or invalid UTF-8 is 422. | Part-number search, see below |
| `category` | CSV of slugs, max 10, each `^[a-z0-9-]{1,64}$`, each must exist in `categories` (unknown slug is 422) | Bearing type; OR within the list |
| `brand` | CSV, max 20 values, each 1-64 chars matching `^[\p{L}\p{N} .&/+-]+$`, exact case-sensitive match against stored value (unknown brand is NOT 422, it just matches nothing) | OR within the list |
| `material` | same rules as `brand` | OR within the list |
| `seal_type` | CSV, each in `open,2rs,zz` | OR within the list |
| `stock_status` | CSV, each in `in_stock,low_stock,out_of_stock,on_order` | OR within the list |
| `inner_diameter_min`, `inner_diameter_max` | decimal matching `^\d{1,4}(\.\d{1,2})?$`, value 0.01 to 5000, mm | Inclusive range on `inner_diameter_mm`; either bound may be sent alone |
| `outer_diameter_min`, `outer_diameter_max` | same | Inclusive range on `outer_diameter_mm` |
| `width_min`, `width_max` | same | Inclusive range on `width_mm` |
| `sort` | one of the whitelist below, default described below | Ordering |
| `page` | integer 1 to 1000000, default 1 | |
| `per_page` | integer 1 to 100, default 24 | Values above 100 are 422, not clamped |

- CSV params: comma separated, no spaces around commas needed (surrounding spaces are trimmed), empty items are 422. Different params combine with AND; values inside one CSV param combine with OR. Array syntax (`brand[]=x`, `q[]=x`) is 422 for every param above.
- Min greater than max for a range is 422 on the `*_min` key.
- Search `q`: the server normalises both sides by lowercasing and removing every character that is not `a-z` or `0-9`. `6205-2RS`, `6205 2rs`, `6205-2rs` and `62052RS` are the same query and match the stored `6205-2RS`. Match is a substring match on the normalised part number (so `6205` matches `6205`, `6205-2RS`, `6205-2RS/C3` and `16205`; `2rs` matches suffixes). Wildcards have no meaning: `%`, `_`, `\`, quotes and any other symbol are removed by normalisation, they never act as LIKE wildcards. If `q` is not empty but normalises to the empty string (for example `%` or `---`), the result is an empty list (200, `total: 0`), not the whole catalog. Only the part number is searched (not name, brand or description).
- Sort whitelist (`-` prefix means descending): `part_number`, `brand`, `inner_diameter`, `outer_diameter`, `width`, `price`, and the `-` variants of each (12 values). `price` sorts products with `price_on_request = true` last in both directions. Every ordering ends with `id` ascending as tie-break, so pages are stable and never overlap. `part_number` orders by the normalised part number.
- Default order: without `q`, `part_number`. With `q` and no `sort`: relevance (exact normalised match first, then prefix matches, then other substring matches, then `part_number`, then `id`). An explicit `sort` always wins over relevance.
- Response 200:
  ```json
  {
    "data": [ { "...ProductResource": "..." } ],
    "meta": { "current_page": 1, "last_page": 9, "per_page": 24, "total": 212, "from": 1, "to": 24 }
  }
  ```
  `meta` has exactly these six keys; there is no `links` key. A page beyond `last_page` returns 200 with `data: []`, `from: null`, `to: null`. No matches: `data: []`, `total: 0`, `last_page: 1`, `current_page` as requested.
- Errors:
  - 401 guest.
  - 422 standard shape; the `errors` key is always the query parameter name (for example `sort`, `brand`, `outer_diameter_min`, `per_page`), never `brand.0`. Triggers: unknown `sort`, enum value not allowed, unknown category slug, malformed or out-of-range number, min greater than max, `per_page` outside 1..100, `page` below 1, `q` longer than 64, array syntax, a value with an INTERIOR NUL byte (for example `brand=S%00KF`, `q=a%00b`) or invalid UTF-8 (`%FF`). NUL is not rejected at the ends of a value: Laravel's `TrimStrings` strips leading and trailing NUL/control characters from every query parameter (this applies to `q`, `brand`, `material` and all others), so `brand=%00`, `q=%00` and `q=abc%00` are treated as empty or as the trimmed value (200, same as the parameter absent or as `abc`), never 422 and never 500.
  - 429 rate limited.
  - 403: not applicable (any role is allowed). 404: not applicable (unknown filter values are 422 or empty, never 404).
- Query count: constant, independent of `per_page` and of the number of rows returned (no N+1). Category is eager loaded.

### GET /api/v1/products/{id}
- Auth: `auth:sanctum`, any role. Scope: none (shared catalog).
- Path: `id` must match `[0-9]{1,18}` (route constraint); anything else (including `filters` handled by its own route, `abc`, `-1`, a 25-digit number) is a 404, never a 500. Body is exactly `{"message":"Not found."}`.
- Response 200: `{ "data": <ProductDetailResource> }`.
- Errors: 401 guest. 404 unknown id OR `active = false`, for every role, body exactly `{"message":"Not found."}` (same as any `api/*` 404, no model name). 429. 403 and 422: not applicable.

### GET /api/v1/products/filters
- Auth: `auth:sanctum`, any role. Scope: none. Registered before `/products/{id}`.
- Request: no parameters (unknown ones ignored).
- Response 200:
  ```json
  {
    "data": {
      "currency": "USD",
      "brands": ["FAG", "NSK", "NTN", "SKF", "Timken"],
      "materials": ["Ceramic", "Chrome steel", "Stainless steel"],
      "seal_types": ["open", "2rs", "zz"],
      "stock_statuses": ["in_stock", "low_stock", "out_of_stock", "on_order"],
      "ranges": {
        "inner_diameter_mm": { "min": 3, "max": 120 },
        "outer_diameter_mm": { "min": 10, "max": 215 },
        "width_mm": { "min": 3, "max": 60 }
      }
    }
  }
  ```
  - `currency`: the single configured ISO 4217 code (`config('catalog.currency')`, env `CATALOG_CURRENCY`, default `USD`). It applies to every `price_cents` in the catalog. It is read at request time and is not part of the cached payload. This is the only place the SPA gets the currency; there is no `/config` endpoint in Phase 2.
  - `brands`, `materials`: distinct values among active products, ascending. `seal_types`, `stock_statuses`: distinct values present among active products, in the canonical order shown. `ranges`: min and max among active products; when there are no active products every `min`/`max` is `null` and the lists are empty.
  - Options are global, not narrowed by other selected filters (no faceted counts).
  - Everything except `currency` is cached (Laravel cache, key `catalog:filters`, TTL 600 s) and flushed by `products:import` and by the seeders. The payload is identical for all users.
- Errors: 401 guest. 429. 403, 404, 422: not applicable.

### GET /api/v1/categories
- Auth: `auth:sanctum`, any role. Scope: none.
- Request: no parameters.
- Response 200:
  ```json
  {
    "data": [
      { "id": 1, "slug": "deep-groove-ball", "name": "Deep groove ball bearings", "product_count": 120 }
    ]
  }
  ```
  Ordered by `sort_order`, then `name`. `product_count` counts active products only; categories with 0 active products are still listed (count 0). Not paginated (a handful of rows). Cached like filters (key `catalog:categories`, TTL 600 s). The category `slug` is the value used by the `category` filter.
- Errors: 401 guest. 429. 403, 404, 422: not applicable.

### Phase 2 endpoint summary

| Method | Path | Guest | Customer | Staff | Admin |
| --- | --- | --- | --- | --- | --- |
| GET | /api/v1/products | 401 | 200/422 | 200/422 | 200/422 |
| GET | /api/v1/products/{id} | 401 | 200/404 | 200/404 | 200/404 |
| GET | /api/v1/products/filters | 401 | 200 | 200 | 200 |
| GET | /api/v1/categories | 401 | 200 | 200 | 200 |
| POST/PUT/PATCH/DELETE | /api/v1/products, /api/v1/products/{id} | 401 or 405 | 405 | 405 | 405 |

(All also return 429 when the user exceeds 60 requests per minute. For a guest on a non-GET method, 401 vs 405 is not contractual.)

### CSV import (Artisan, not HTTP)

`php artisan products:import {file} {--dry-run}`. Column spec, validation, exit codes and output format are in `docs/phase-2-design.md` section 6. There is no upload endpoint in Phase 2.

### Frontend behaviour bound to this contract
- Routes: `/catalog` and `/catalog/:id`, any logged-in role (no role branching; staff and customers see the same catalog). Nav item "Catalog" for all roles.
- URL query string uses exactly the API parameter names above (`q`, `category`, `brand`, `material`, `seal_type`, `stock_status`, `inner_diameter_min`, ..., `sort`, `page`). `per_page` is not in the URL (fixed 24). Defaults are omitted from the URL. Invalid URL values are dropped client-side before calling the API.
- Money: `price_cents / 100` formatted with `Intl.NumberFormat('en-US', { style: 'currency', currency })`, where `currency` comes from `/products/filters`. `price_on_request = true` shows the text "Price on request".
- 404 on detail shows a "Product not found" page, not an error banner. 429 shows the Phase 1 style "try again in N seconds" message from `Retry-After`.

---

## Phase 3 - Cart and orders with history

Schema, Policy, state machine, order numbers, seeders, frontend design and test plan: `docs/phase-3-design.md`. This section is the HTTP contract. All Phase 1 conventions (cookie auth, CSRF header on writes, `Accept: application/json`, error shapes, `{ "data": ... }` wrapping, 429 body and `Retry-After`) and the Phase 2 conventions (exact 404 body, CSV params, `meta`) apply unchanged.

### Phase 3 rules (apply to every endpoint below)

- Auth: `auth:sanctum`. Guests get 401 on every endpoint.
- Roles (role middleware runs BEFORE any order lookup, so a wrong role gets 403 for every id, existing or not, and learns nothing):

  | Group | Endpoints | Allowed roles |
  | --- | --- | --- |
  | Cart and checkout | all `/cart...`, `POST /orders`, `POST /orders/{number}/reorder` | `customer` only (staff and admin have no company and no cart: 403) |
  | Order reads | `GET /orders`, `GET /orders/{number}` | `customer`, `staff`, `admin` |
  | Cancel | `POST /orders/{number}/cancel` | `customer`, `staff`, `admin` (state rules below) |
  | Status change | `PATCH /orders/{number}/status` | `staff`, `admin` only (customer: 403) |

- Company scoping (the core rule of this phase):
  - The company is ALWAYS `users.company_id` of the session user, never request input. Customers: every order query includes `orders.company_id = <session user's company_id>` (model scope `Order::visibleTo($user)`), and `OrderPolicy` re-checks company equality on every loaded order (two independent layers). All customer users of one company see all of that company's orders (Phase 1 decision: equal permissions); a customer never sees another company's order.
  - Staff and admin (`company_id = null`) are not scoped and may read and change every company's orders.
  - A customer whose `company_id` is null (data error) sees nothing and cannot create orders (list empty, detail 404, create 403).
  - A user with no role at all sees no orders (`visibleTo` matches nothing) and gets 403 on every role-guarded route.
- Middleware order (implemented): the role middleware runs right after authentication and BEFORE any rate limiter and before any order lookup (it is prepended in the middleware priority list). So staff/admin on `POST /orders` get 403, never 429 (the `orders-create` limiter is not reached), and a customer on the staff status endpoint gets 403 before any lookup.
- Exception rendering: every `api/*` response with HTTP status 404 (`ModelNotFoundException`, route not found, and the plain `HttpException` produced by Policy `Response::denyAsNotFound()`) is rendered as exactly `{"message":"Not found."}`, with `APP_DEBUG` true or false. A foreign order therefore has the identical body as an unknown one.
  - The cart is scoped narrower than the company: it belongs to one user (`cart_items.user_id` = session user id). Two users of one company never share or see each other's cart.
- Foreign orders are 404, never 403: an order that belongs to another company returns exactly the same status and body as an order that does not exist: `404 {"message":"Not found."}`. This holds for detail, cancel and reorder, and a foreign order never appears in a list. The only 403s in this phase are role errors (table above).
- Public identifier: orders are addressed by `number` (for example `ORD-K7Q2M-X9PA3`), a random code (see design doc section 4). The numeric database id is never in a URL or a JSON body, so order ids cannot be enumerated and the order volume is not leaked. Path segment `{number}` is matched case-insensitively and uppercased server-side; anything not matching `[A-Za-z0-9-]{1,32}` or not found is 404. The pattern is a global route pattern for `{number}`, enforced by routing BEFORE any middleware: a malformed number is a plain 404 for every role and every endpoint, including a customer calling `PATCH /orders/{number}/status` (404, not 403). A well-formed but unknown number goes through the role middleware first: 403 for a customer on the staff status endpoint, 404 for a staff user.
- Mass assignment: the only order field a client may supply is the optional `po_reference` on `POST /orders` (see that endpoint); it is customer-supplied text, never trusted for any decision (no authorization, pricing, status or scoping logic reads it). No endpoint accepts `company_id`, `user_id`, `status` (except the target of the status endpoint), `number`, prices, line totals, subtotal, tax, shipping, total, currency or `created_at` from the client. If sent they are ignored (not 422). Prices always come from `products.price_cents` read inside the server transaction; totals are computed on the server from integer cents.
- Money: integer cents (JSON numbers), never strings or floats. Every cart and order body carries `currency` (3-letter code). Orders store the currency in force at creation (`config('catalog.currency')`).
- Dates: ISO 8601 UTC with `Z`, for example `"2026-11-25T10:15:00Z"`. Date filters are `YYYY-MM-DD` (UTC).
- Limits: quantity per line 1 to 9999 (`config('orders.max_quantity')`); at most 100 distinct lines per cart (`config('orders.max_cart_lines')`). With the Phase 2 price cap (`price_cents` at most 1,000,000,000) every line total is at most 9.999e12 and every cart or order total at most 9.999e14 cents, an exact integer in PHP, Postgres `bigint` and JavaScript (`Number.MAX_SAFE_INTEGER` is 9.007e15).
- Input trimming and NUL: Laravel's `TrimStrings` middleware is active for every request field and query parameter EXCEPT `po_reference` (`trimStrings(except: ['po_reference'])`). For all other fields (`q`, `status`, `from`, `to`, `sort`, `company_id`, `page`, `per_page`, `status` in the body, ...) leading and trailing NUL/control characters are stripped before validation, so `q=%00` and `q=abc%00` are an empty and a trimmed `q` (200, no extra filter), not errors. Only an INTERIOR NUL (`q=a%00b`) or invalid UTF-8 is 422 on that parameter. `po_reference` has its own stricter rules (see POST /orders).
- Rate limits: the Laravel `api` limiter (60/min per user id, shared bucket) applies to every endpoint. Additionally `POST /orders` has the named limiter `orders-create`: 10 requests per 60 s per user id (every attempt counts, including 422). 429 uses the standard body and `Retry-After`. Cart, cancel, reorder and status writes have no extra limiter.
- 422 `errors` keys are the field name for body or query fields (including `po_reference`), and `cart` for checkout problems (see POST /orders).

### CartResource

```json
{
  "data": {
    "currency": "USD",
    "items": [
      {
        "product_id": 17,
        "part_number": "6205-2RS",
        "name": "Deep groove ball bearing 6205-2RS 25x52x15",
        "brand": "SKF",
        "stock_status": "in_stock",
        "unit_price_cents": 1250,
        "quantity": 2,
        "line_total_cents": 2500,
        "available": true,
        "unavailable_reason": null
      }
    ],
    "line_count": 1,
    "unit_count": 2,
    "subtotal_cents": 2500,
    "can_checkout": true
  }
}
```

Rules:
- Exactly these keys. Items are ordered by the time the line was first added (then `product_id`). Values are LIVE catalog values (the cart stores only `product_id` and `quantity`); they are snapshotted into the order only at checkout.
- `available = false` when the product is now inactive (`unavailable_reason: "inactive"`), price on request (`"price_on_request"`) or out of stock (`"out_of_stock"`); otherwise `unavailable_reason` is `null`. Check order for the reason: `inactive`, then `price_on_request`, then `out_of_stock`. Unavailable lines stay in the cart so the user can remove them.
- `unit_price_cents` is the product's current price or `null` (price on request); `line_total_cents = unit_price_cents * quantity` or `null` when the unit price is null.
- `subtotal_cents` sums `line_total_cents` of `available` lines only. `line_count` = number of lines (available or not). `unit_count` = sum of quantities of all lines.
- `can_checkout = true` only if the cart has at least one line and every line is available.
- Empty cart: `items: []`, `line_count: 0`, `unit_count: 0`, `subtotal_cents: 0`, `can_checkout: false`.

### GET /api/v1/cart
- Auth: `auth:sanctum` + role `customer`. Scope: rows where `user_id` = session user.
- Request: none (any query parameter, including `user_id`, is ignored).
- Response 200: CartResource.
- Errors: 401 guest. 403 staff, admin. 429. 404, 422: not applicable.

### POST /api/v1/cart/items
- Auth: `auth:sanctum` + role `customer`. Scope: writes only to the session user's cart.
- Request body: `{ "product_id": 17, "quantity": 2 }`. `product_id`: required, integer, 1 to 9223372036854775807. `quantity`: required, integer (JSON number, not a numeric string, not a float), 1 to 9999.
- Behaviour: if the product is already in the cart the quantities are added (`existing + quantity`, must stay at most 9999), otherwise a new line is created. Idempotency is not provided: sending twice adds twice.
- Response 200: CartResource (the whole updated cart; the same shape for add, update, remove, clear).
- Errors:
  - 401 guest. 403 staff, admin. 429.
  - 422 `quantity`: missing, not an integer, below 1, above 9999, or the sum with the existing line would exceed 9999.
  - 422 `product_id`: missing or not an integer; unknown product or `active = false` (one identical message, "The selected product is not available.", so inactive and unknown are indistinguishable); price on request ("This product is price on request and cannot be added to the cart."); `stock_status = out_of_stock` ("This product is out of stock."); the cart already has 100 lines and this would be a new line ("Your cart is full.").
  - 404: not applicable (an unknown product is a validation error, not a missing URL resource).

### PATCH /api/v1/cart/items/{product_id}
- Auth: `auth:sanctum` + role `customer`. Scope: the line must be in the session user's cart; the path uses the product id so no cart row ids exist.
- Path: `product_id` matches `[0-9]{1,18}`, else 404.
- Request body: `{ "quantity": 5 }`. Sets the quantity (absolute, not a delta). Same `quantity` rules as POST (1 to 9999).
- Response 200: CartResource.
- Errors: 401 guest. 403 staff, admin. 404 `{"message":"Not found."}` if this user's cart has no line for that product (including a product that exists only in another user's cart). 422 `quantity` (missing, not an integer, out of range). 429. Availability is NOT re-checked here (it is checked at checkout), so an unavailable line can still be edited.

### DELETE /api/v1/cart/items/{product_id}
- Auth: `auth:sanctum` + role `customer`. Scope as above. Path as above.
- Request: no body.
- Response 200: CartResource (without that line).
- Errors: 401 guest. 403 staff, admin. 404 if the line is not in this user's cart (a second delete of the same line is 404). 429. 422: not applicable.

### DELETE /api/v1/cart
- Auth: `auth:sanctum` + role `customer`. Scope: session user's cart.
- Request: no body. Response 200: empty CartResource (idempotent: an already empty cart also returns 200).
- Errors: 401 guest. 403 staff, admin. 429. 404, 422: not applicable.

### OrderResource (list items)

```json
{
  "number": "ORD-K7Q2M-X9PA3",
  "status": "pending",
  "currency": "USD",
  "subtotal_cents": 2500,
  "tax_cents": 0,
  "shipping_cents": 0,
  "total_cents": 2500,
  "line_count": 1,
  "po_reference": "PO-4711/A",
  "quote_number": null,
  "company": { "id": 1, "name": "Northwind Machinery Ltd" },
  "placed_by": { "name": "Alice Customer" },
  "created_at": "2026-11-25T10:15:00Z",
  "updated_at": "2026-11-25T10:15:00Z"
}
```

`quote_number` (added in Phase 4, positioned right after `po_reference`): the `QUO-...` number of the quote this order was created from, or `null` for orders created from the cart; always present.

`po_reference` is the customer's optional purchase-order reference: a string (1 to 64 characters, rules under POST /orders) or `null` when none was given. It is always present as a key. It is plain text: clients must render it as text, never as HTML.

`status` is one of `pending|confirmed|shipped|delivered|cancelled`. In Phase 3 `tax_cents` and `shipping_cents` are always 0 and `total_cents = subtotal_cents + tax_cents + shipping_cents` always holds. No numeric id, no `company_id`, no user id, no email in any order body.

### OrderDetailResource (detail, create, cancel, status change)

OrderResource plus `items`, `history` and `allowed_transitions`:

```json
{
  "data": {
    "number": "ORD-K7Q2M-X9PA3",
    "status": "pending",
    "currency": "USD",
    "subtotal_cents": 2500,
    "tax_cents": 0,
    "shipping_cents": 0,
    "total_cents": 2500,
    "line_count": 1,
    "po_reference": "PO-4711/A",
    "quote_number": null,
    "company": { "id": 1, "name": "Northwind Machinery Ltd" },
    "placed_by": { "name": "Alice Customer" },
    "created_at": "2026-11-25T10:15:00Z",
    "updated_at": "2026-11-25T10:15:00Z",
    "items": [
      {
        "product_id": 17,
        "part_number": "6205-2RS",
        "name": "Deep groove ball bearing 6205-2RS 25x52x15",
        "brand": "SKF",
        "unit_price_cents": 1250,
        "quantity": 2,
        "line_total_cents": 2500
      }
    ],
    "history": [
      {
        "from_status": null,
        "to_status": "pending",
        "actor": { "name": "Alice Customer", "role": "customer" },
        "created_at": "2026-11-25T10:15:00Z"
      }
    ],
    "allowed_transitions": ["cancelled"]
  }
}
```

Rules:
- `items` are the order's SNAPSHOT (`part_number`, `name`, `brand`, `unit_price_cents`, `quantity`, `line_total_cents` copied at creation). They never change when the product later changes price, name or is deactivated. Ordered by line insertion order (the order of the cart at checkout: first added first), then `product_id`. `product_id` is informational (link target); the product may be inactive later, so the SPA must tolerate a 404 on the catalog link.
- `history` is the status timeline, oldest first. The first entry always has `from_status: null`, `to_status: "pending"`. Every status change adds exactly one entry.
- `actor.role` is `customer` or `staff`. For a customer viewer, entries made by staff or admin are returned as `{ "name": null, "role": "staff" }` (internal employee names are not shown to customers); a staff or admin viewer sees the real name and the real role (`staff` or `admin`). Customers' names are shown to their own company's users and to staff.
- `allowed_transitions` is computed by the server for the VIEWER and the current state (the single source for the UI): for a customer it is `["cancelled"]` only while the order is `pending`, else `[]`; for staff and admin it is the matrix row of the current status (see PATCH status), `[]` for terminal states.

### GET /api/v1/orders
- Auth: `auth:sanctum`, roles `customer`, `staff`, `admin`.
- Scope: customer: `company_id` = session user's company (query-level). Staff/admin: all companies, narrowed by the optional `company_id` filter.
- Query parameters:

| Param | Type and rule | Meaning |
| --- | --- | --- |
| `status` | CSV, each in `pending,confirmed,shipped,delivered,cancelled`, empty items are 422 | OR within the list |
| `from` | date `YYYY-MM-DD`, a real calendar date | orders created on or after 00:00:00 UTC of that day |
| `to` | date `YYYY-MM-DD` | orders created up to and including 23:59:59 UTC of that day. `from` later than `to` is 422 on `from`. |
| `q` | string, trimmed (including leading/trailing NUL/control characters by `TrimStrings`: `q=%00` and `q=abc%00` are 200, empty and `abc`), max 32 (so only the first 32 characters of a 64-character PO reference can be searched for) | Matches the order number OR the `po_reference` (an order is returned if either matches). (1) Order number: uppercased, every character outside `A-Z0-9` removed, substring match against the order number without dashes (`k7q2`, `ORD-K7Q2`, `ord k7q2m` all match `ORD-K7Q2M-X9PA3`); if `q` normalises to empty (for example `%` or `--`) this branch matches nothing. (2) PO reference: the trimmed `q` as typed (not normalised), case-insensitive substring match against `po_reference`; `%`, `_` and `\` in `q` are matched literally (escaped, never wildcards); orders with a null `po_reference` never match this branch. A `q` that matches neither gives an empty result, never everything. Search always runs inside the viewer's scope (customers: own company only), so another company's PO reference can never be found or inferred. |
| `company_id` | integer >= 1. STAFF/ADMIN ONLY. Customers: ignored (their own company is always used, no 422). | Filter by company; unknown company gives an empty list, not 404/422 |
| `sort` | one of `created_at`, `-created_at`, `total`, `-total`, `number`, `-number`; default `-created_at` | Every ordering ends with the internal id ascending as tie-break (stable pages) |
| `page` | integer 1 to 10000, default 1 | |
| `per_page` | integer 1 to 100, default 20 | 101 is 422, not clamped |

- Array syntax (`status[]=x`), an INTERIOR NUL (`q=a%00b`) and invalid UTF-8 are 422 on that parameter. Leading or trailing NUL/control characters are stripped by `TrimStrings` first, so `q=%00` and `q=abc%00` are treated as empty or trimmed (200, no filter for `q`), and the same trimming applies to `status`, `from`, `to` and the other parameters before their own rules run; it is never an error by itself. Unknown parameters are ignored.
- Response 200:
  ```json
  {
    "data": [ { "...OrderResource": "..." } ],
    "meta": { "current_page": 1, "last_page": 3, "per_page": 20, "total": 47, "from": 1, "to": 20 }
  }
  ```
  `meta` has exactly these six keys, no `links`. A page beyond `last_page` is 200 with `data: []`, `from: null`, `to: null`. No matches: `data: []`, `total: 0`, `last_page: 1`.
- Errors: 401 guest. 422 standard shape, `errors` key = parameter name (`status`, `from`, `to`, `q`, `company_id`, `sort`, `page`, `per_page`, never `status.0`). 429. 403, 404: not applicable (customers never get 403/404 here; they just get their own rows).
- Query count: constant, independent of the number of rows (no N+1; company and placed_by are eager loaded, `line_count` is a `withCount`; `po_reference` is a plain column). No items or history are loaded in the list.
- Performance note: the PO branch of `q` is an unindexed `ILIKE '%...%'`, a sequential scan over the already scoped set (a customer's own company, or all orders for staff). Acceptable at the expected row counts; Phase 7 may add a trigram index.

### GET /api/v1/orders/{number}
- Auth: `auth:sanctum`, roles `customer`, `staff`, `admin`.
- Scope: customer: the order must have `company_id` = session user's company, else 404. Staff/admin: any order.
- Response 200: `{ "data": <OrderDetailResource> }`.
- Errors: 401 guest. 404 `{"message":"Not found."}` for an unknown number, a malformed number, and an order of another company (identical). 429. 403, 422: not applicable.
- Query count: constant (order, items, history, history actors, company, placed_by eager loaded).

### POST /api/v1/orders
- Purpose: create an order from the session user's CART. The request carries no items and no prices; the server is the only source of lines, prices and totals.
- Auth: `auth:sanctum` + role `customer`. Limiters: `api` and `orders-create` (10/min per user).
- Scope: reads the session user's cart; the order gets `company_id` = session user's company and `created_by` = session user, both server-side.
- Request body: optional `{ "po_reference": "PO-4711/A" }`; `{}` or no body is valid. Any other field (`items`, `total_cents`, `subtotal_cents`, `unit_price_cents`, `status`, `company_id`, `number`, ...) is ignored.
- `po_reference` (optional, customer purchase-order reference). Rules, applied in this order:
  1. Missing or JSON `null` is the same as empty: stored as `NULL`.
  2. Must be a string. An array, object, number or boolean is 422.
  3. The RAW value must be valid UTF-8 and contain no NUL byte (`\0`) anywhere; otherwise 422. This check runs on the raw value BEFORE trimming, so a NUL-only value, a NUL plus whitespace (`"\u0000 "`) and a trailing NUL (`"AB\u0000"`) are all 422, never `NULL` and never silently trimmed. `po_reference` is the only field excluded from Laravel's `TrimStrings` (`trimStrings(except: ['po_reference'])`), which is what lets the raw value reach this check.
  4. Trimmed of leading and trailing whitespace exactly as JavaScript `String.prototype.trim()` strips it: space, tab, LF, VT, FF, CR, NBSP (U+00A0) and the other Unicode space separators (U+1680, U+2000 to U+200A, U+202F, U+205F, U+3000), U+2028, U+2029 and U+FEFF. Nothing else is trimmed. The browser's `trim()` and the server therefore agree.
  5. After trimming, any control character (Unicode `Cc`: NUL, U+0001 to U+001F, ESC, DEL U+007F, U+0080 to U+009F including U+0085) or format character (Unicode `Cf`, for example U+200B zero-width space, U+200E, U+00AD) ANYWHERE in the value, including at its first or last position, is 422 (it is not trimmed because it is not whitespace in the sense of step 4). Steps 3 to 7 run in an `after()` validation hook so they also run for values that trim to empty. A result of `""` after steps 4 and 5 (empty or whitespace-only, for example `"   "` or `" "`) is stored as `NULL`, not as an empty string.
  6. At most 64 characters after trimming (65 is 422).
  7. Must match `^[A-Za-z0-9][A-Za-z0-9 ._/#-]*$` (anchored with `\z`/`D`, so a trailing newline cannot slip through): it starts with an ASCII letter or digit and contains only ASCII letters, digits, space (U+0020) and the five characters `.` `-` `_` `/` `#`. Anything else is 422: a leading `-`, `=`, `+`, `@`, `#`, `.`, `_` or `/`; and any of `%`, quotes, `<`, `>`, `&`, `;`, `,`, inner tabs, inner newlines, non-ASCII letters, emoji, control and format characters anywhere (steps 3 and 5). The leading-character rule also keeps future CSV/XLSX exports safe from formula injection (`=`, `+`, `-`, `@`, tab, CR cannot start the value).
  - Stored value is the trimmed string, case preserved, internal spacing unchanged.
  - Validated before the cart is read: a bad `po_reference` is 422 `po_reference` even when the cart is also empty or invalid (then `errors` contains only `po_reference`), and nothing is written. Role middleware (403) still runs first. The attempt counts toward `orders-create`.
  - Immutable: no endpoint changes `po_reference` after creation in Phase 3 (cancel, status, reorder ignore it if sent). Reorder does not copy it into the cart.
  - It is customer-supplied data and is never used for any decision (authorization, scoping, pricing, status). It is returned as plain text and must be rendered as text, never HTML.
- Behaviour (one DB transaction): lock the user's row (serialises this user's cart and checkout actions), read the cart with the products, validate every line, snapshot each line from the current product row, compute `line_total = unit_price * quantity`, `subtotal = sum`, `tax = 0`, `shipping = 0`, `total = subtotal`, insert order (`status = pending`), items and the first history entry (`null -> pending`, actor = user), delete the user's cart lines. All or nothing.
- Double submit and concurrency: two simultaneous requests are serialised by the lock; the first creates the order and empties the cart, the second finds an empty cart and returns 422 `cart`. At most one order is ever created per cart content. No `Idempotency-Key` header in Phase 3.
- Response 201: `{ "data": <OrderDetailResource> }` (status `pending`, `history` has one entry, `po_reference` is the stored value or `null`). The cart is empty afterwards.
- Errors:
  - 401 guest. 403 staff, admin, or a customer with no company. 429 (`api` or `orders-create`).
  - 422 `po_reference` (array with one message): not a string, NUL byte anywhere in the raw value (including NUL-only or NUL plus whitespace) or invalid UTF-8, a control or format character after trimming, longer than 64 characters after trimming, bad leading character, or a disallowed character (see rules above). Nothing is created and the cart is NOT changed. Invalid UTF-8 can only reach validation in a form-encoded body (`po_reference=%FF`); a JSON body that is not valid JSON is not decoded by the framework and is treated as having no fields, which is not a contract case.
  - 422 `cart` (array of messages, one per problem; nothing is created and the cart is NOT changed): the cart is empty ("Your cart is empty."), or one or more lines are unavailable, one message per line, for example `"6205-2RS is no longer available."`, `"6305 is price on request."`, `"7205B is out of stock."`. The SPA reloads the cart (GET /cart shows `unavailable_reason` per line) so the user can remove them.
  - 404: not applicable.

### POST /api/v1/orders/{number}/cancel
- Auth: `auth:sanctum`, roles `customer`, `staff`, `admin`.
- Scope: customer: order must be of the session user's company, else 404 (nothing changes, no history row). Staff/admin: any order.
- Request: no body (any field ignored).
- Rules (same state machine as PATCH status, target `cancelled`):
  - Customer: only while `pending`.
  - Staff/admin: while `pending` or `confirmed`.
  - `shipped`, `delivered`, `cancelled` can never be cancelled by anyone.
- Response 200: `{ "data": <OrderDetailResource> }` with `status: "cancelled"` and a new history entry (`from_status` = previous status, actor = the user).
- Errors: 401 guest. 404 unknown, malformed or foreign order. 422 `status` "This order can no longer be cancelled." when the state or role rule above forbids it (customer on `confirmed` or later, anyone on `shipped`, `delivered`, or an already `cancelled` order). 429. 403: not applicable (every logged-in role may call it; foreign is 404, wrong state is 422).
- Concurrency: the order row is locked and re-read inside the transaction, so a staff confirm and a customer cancel racing each other cannot both win; the loser gets 422.

### POST /api/v1/orders/{number}/reorder
- Purpose: copy the lines of an old order into the session user's cart at CURRENT catalog prices. It never creates an order and never uses the old prices.
- Auth: `auth:sanctum` + role `customer`.
- Scope: the order must be of the session user's company (404 otherwise, the cart is untouched); the lines go into the session user's own cart.
- Request: no body.
- Behaviour: for each order line, in order: skip when the product is inactive (`inactive`), price on request (`price_on_request`), out of stock (`out_of_stock`), when adding it as a new line would exceed 100 cart lines (`cart_full`), or when `existing cart quantity + line quantity` would exceed 9999 (`quantity_limit`). Otherwise add the line's quantity to the cart (existing line: quantities add). Runs in one transaction under the same user lock as cart writes. Works for an order in any status (including `cancelled`).
- Response 200 (also when everything is skipped):
  ```json
  {
    "data": {
      "cart": { "...CartResource": "..." },
      "added": [ { "product_id": 17, "part_number": "6205-2RS", "quantity": 2 } ],
      "skipped": [ { "product_id": 21, "part_number": "6305", "name": "Deep groove ball bearing 6305", "reason": "inactive" } ]
    }
  }
  ```
  `reason` is one of `inactive|price_on_request|out_of_stock|cart_full|quantity_limit`. `added` and `skipped` together cover every order line exactly once.
- Errors: 401 guest. 403 staff, admin. 404 unknown, malformed or foreign order. 429. 422: not applicable.

### PATCH /api/v1/orders/{number}/status
- Auth: `auth:sanctum` + role `staff` or `admin`. Customers get 403 (role middleware, before any lookup, even for a well-formed number that does not exist). A malformed number is a plain 404 for everyone (route pattern, see Public identifier).
- Order of checks: route pattern (404) then authentication (401) then role (403) then body validation (422) then order lookup (404) then state machine (422). Validation runs BEFORE the lookup, so a staff user sending an invalid `status` to an unknown (well-formed) number gets 422, not 404; a valid `status` to an unknown number gets 404.
- Scope: staff/admin are cross-company; any order. (`OrderPolicy::transition` is staff/admin only; there is no company check for them.)
- Request body: `{ "status": "confirmed" }`. `status` required, string, one of `confirmed|shipped|delivered|cancelled` (`pending` is not a valid target; sending it or an unknown value is 422).
- Transition matrix (everything else is 422; terminal states have no exits):

  | From \ To | confirmed | shipped | delivered | cancelled |
  | --- | --- | --- | --- | --- |
  | pending | yes | no | no | yes |
  | confirmed | no | yes | no | yes |
  | shipped | no | no | yes | no |
  | delivered | no | no | no | no |
  | cancelled | no | no | no | no |

  Same-status requests (for example `confirmed` on a confirmed order) are 422. Staff and admin have identical rights here. Who may do what: pending to confirmed, confirmed to shipped, shipped to delivered, confirmed to cancelled: staff, admin only. pending to cancelled: customer of the company (via the cancel endpoint), staff, admin.
- Response 200: `{ "data": <OrderDetailResource> }` with the new status and one new history entry (actor = the staff or admin user, timestamp = now).
- Errors: 401 guest. 403 customer. 404 unknown or malformed number. 422 `status`: missing, not a string, not one of the four values, or the transition is not allowed from the current status ("Cannot change status from shipped to pending." style, text not contractual). 429. The order row is locked inside the transaction, so two staff members acting at once cannot both apply a transition from the same state.

### Phase 3 endpoint summary

| Method | Path | Guest | Customer | Staff | Admin |
| --- | --- | --- | --- | --- | --- |
| GET | /api/v1/cart | 401 | 200 | 403 | 403 |
| POST | /api/v1/cart/items | 401 | 200/422 | 403 | 403 |
| PATCH | /api/v1/cart/items/{product_id} | 401 | 200/404/422 | 403 | 403 |
| DELETE | /api/v1/cart/items/{product_id} | 401 | 200/404 | 403 | 403 |
| DELETE | /api/v1/cart | 401 | 200 | 403 | 403 |
| GET | /api/v1/orders | 401 | 200 (own company) | 200 (all) | 200 (all) |
| GET | /api/v1/orders/{number} | 401 | 200 / 404 foreign | 200/404 | 200/404 |
| POST | /api/v1/orders (optional `po_reference`) | 401 | 201/422/429 | 403 | 403 |
| POST | /api/v1/orders/{number}/cancel | 401 | 200 / 404 foreign / 422 | 200/404/422 | 200/404/422 |
| POST | /api/v1/orders/{number}/reorder | 401 | 200 / 404 foreign | 403 | 403 |
| PATCH | /api/v1/orders/{number}/status | 401 | 403 (404 if `{number}` is malformed) | 200/404/422 | 200/404/422 |

(All also return 429 over the rate limits above. `PUT`/`DELETE` on `/api/v1/orders...` and `POST` on `/api/v1/cart` are not routes: 405.)

### Frontend behaviour bound to this contract
- Routes (inside `ProtectedRoute`): `/cart` and `/checkout` customer only (other roles get the 403 "Not allowed" page); `/orders` and `/orders/:number` any logged-in role. Nav: "Orders" for all roles; "Cart" with a badge (`line_count`) for customers only.
- `/orders` URL parameters use exactly the API names: `status` (CSV), `from`, `to`, `q`, `sort`, `page`. `per_page` and `company_id` are not in the URL. Defaults omitted. Invalid URL values are dropped client-side.
- `/orders/:number`: a `:number` that does not match `[A-Za-z0-9-]{1,32}`, or a 404 from the API (unknown OR foreign), shows the same "Order not found" page. The Cancel button is shown only when `allowed_transitions` contains `cancelled` and the viewer is a customer; the Re-order button for customers only. Staff see these pages read-only (the status-change UI is Phase 6; the endpoint exists now).
- Money: `cents / 100` through `Intl.NumberFormat('en-US', { style: 'currency', currency })` with `currency` taken from the cart or order body (not from `/products/filters`).
- Checkout has an optional "PO reference" text input (`maxLength` 64, label, hint "Optional. Letters, digits, spaces and . - _ / #"). Client-side Zod validation mirrors the server rules (`String.prototype.trim()`, which is exactly the server's trim set; empty is omitted from the body; control and format characters `/[\p{Cc}\p{Cf}]/u` rejected; max 64; leading letter or digit; allowed characters), invalid blocks the request and shows an inline `role="alert"` error linked with `aria-describedby`/`aria-invalid`; a server 422 `errors.po_reference` is shown on the same field. The body is `{}` when empty, else `{ "po_reference": "<trimmed>" }`. `po_reference` is shown as plain text (a React text node, never `dangerouslySetInnerHTML`) in a "PO reference" column of the history table (an em dash when null) and on the order detail page (row hidden or "None" when null). The `/orders` search input label becomes "Order number or PO reference".
- 422 on `POST /orders` with `errors.cart`: show the messages, refetch the cart, stay on the checkout page. 429: Phase 1 "try again in N seconds" message.

---

## Phase 4 - Quote requests (RFQ)

Schema, state machine, conversion, attachments, emails, seeders, frontend design and test plan: `docs/phase-4-design.md`. This section is the HTTP contract. All Phase 1 conventions (cookie auth, CSRF header on writes, `Accept: application/json`, error shapes, `{ "data": ... }` wrapping, 429 body and `Retry-After`), Phase 2 conventions (exact 404 body, CSV params, `meta`) and Phase 3 conventions (role middleware before limiters and lookups, foreign = 404, global route pattern for `{number}`, `TrimStrings`, money, dates) apply unchanged.

### Phase 4 rules (apply to every endpoint below)

- Auth: `auth:sanctum`. Guests get 401 on every endpoint.
- Roles (role middleware runs BEFORE any rate limiter, body validation and quote lookup, so a wrong role is 403 for every number, existing or not):

  | Group | Endpoints | Allowed roles |
  | --- | --- | --- |
  | Customer actions | `POST /quotes`, `POST /quotes/{number}/accept`, `POST /quotes/{number}/decline` | `customer` only (staff and admin: 403) |
  | Staff actions | `POST .../review`, `PUT .../pricing`, `POST .../send`, `POST .../reject`, `POST .../reopen`, `PUT .../internal-note` | `staff`, `admin` only (customer: 403) |
  | Reads and attachments | `GET /quotes`, `GET /quotes/{number}`, `POST/GET/DELETE .../attachments...` | `customer`, `staff`, `admin` (state and ownership rules below) |

  Dual-role rule: `accept` and `decline` are customer-only in `QuotePolicy` as well as in the role middleware. A user who holds the `customer` role AND a `staff` or `admin` role is refused with 403 on accept and decline (an own-company quote included); the policy never lets a staff role act as a customer.

- Company scoping (same as orders): the company is ALWAYS `users.company_id` of the session user, never request input. Customers: every quote and attachment query includes `quote_requests.company_id = <session user's company_id>` (model scope `QuoteRequest::visibleTo($user)`), attachments are looked up only through the visible quote (never by their own id alone), and `QuotePolicy` re-checks company equality (two independent layers). All customer users of one company see all of that company's quotes and may accept or decline them (Phase 1: equal permissions). Staff and admin (`company_id = null`) are not scoped. A customer whose `company_id` is null sees nothing and cannot create (list empty, detail 404, create 403). A user with no role sees nothing and gets 403 on role-guarded routes.
- Foreign quotes are 404, never 403: a quote or attachment of another company returns exactly the same status and body as one that does not exist: `404 {"message":"Not found."}` (detail, accept, decline, attachments upload/download/delete, and every staff action reached by staff). The only 403s are role errors (table above). A staff-only action called by a customer is 403 before any lookup, even for a foreign or unknown number.
- Public identifiers: quotes by `number` (`QUO-K7Q2M-X9PA3`, same random alphabet as orders), attachments by `id` (random UUID v4, lowercase). No numeric database id, `company_id`, `user_id` or `created_by` in any URL or body. `{number}` uses the existing global pattern `[A-Za-z0-9-]{1,32}` (uppercased server-side); `{attachment}` must match the lowercase UUID pattern, otherwise 404 decided by routing before any middleware (even for a wrong role). Order numbers on quote routes are simply unknown (404).
- Mass assignment: the client may send only the fields documented per endpoint. `company_id`, `created_by`, `status`, `number`, `currency`, `unit_price_cents`, `line_total_cents`, `subtotal_cents`, `tax_cents`, `shipping_cents`, `total_cents`, `valid_until` (customers), `staff_note`/`internal_note` (customers), `revision`, `sent_at`, timestamps and any other field are ignored when sent (not 422). Prices come only from staff via `PUT .../pricing`; all totals are computed on the server from integer cents. Staff-only fields are never accepted from customers (the staff endpoints are 403 for them) and never returned to customers (see Visibility).
- Money: integer cents (JSON numbers), never strings or floats; `currency` (3-letter code) from `config('catalog.currency')` at creation. `tax_cents` and `shipping_cents` are always 0 and `total_cents = subtotal_cents + tax_cents + shipping_cents`. Per-unit price 0 to 1,000,000,000 (Phase 2 cap); max lines 50 and max quantity 9999 keep every total below 5e14, exact in PHP, Postgres and JavaScript.
- Dates: timestamps are ISO 8601 UTC with `Z`; `valid_until` is a plain date `YYYY-MM-DD` meaning "the last day the quote can be accepted, inclusive, in UTC". The quote expires at `valid_until + 1 day`, 00:00:00 UTC (`expires_at`). The server clock is UTC and read only through `now()`.
- Effective status (important): the `status` in every response is the EFFECTIVE status. A stored `quoted` quote whose `valid_until` is before today (UTC) is returned as `expired`, filtered as `expired`, and refused by accept/decline/reopen, even if the `quotes:expire` command has not yet written `expired` to the database. Accept time is authoritative (checked under a row lock).
- Visibility of unsent drafts: for a CUSTOMER, while the quote has not been sent (`sent_at` null: statuses `submitted`, `in_review`, a reopened quote, or a quote withdrawn before sending) these are hidden: `unit_price_cents`, `line_total_cents`, `subtotal_cents`, `total_cents`, `valid_until`, `expires_at`, `staff_note`, `revision` are `null`, and attachments uploaded by staff are omitted (their download is 404). `internal_note` is NEVER sent to customers: the key does not exist in any customer response. Staff and admin always see everything.
- Input rules for text (all quote text fields are customer-supplied text: never trusted for any decision, always rendered as text, never HTML): `TrimStrings` applies to every field and query parameter EXCEPT `po_reference` (raw, see below). After trimming, `lines.*.description` rejects any control or format character (Unicode `Cc`, `Cf`, including NUL, ESC, DEL, U+0085, U+200B, and newlines), an interior NUL or invalid UTF-8 is 422 (a leading/trailing NUL is stripped by `TrimStrings` first). `customer_note`, `staff_note`, `internal_note` and `reason` allow newline (LF, CRLF) and tab but reject any other `Cc`/`Cf` character, NUL and invalid UTF-8. Quantity and ids must be real JSON integers (`"2"`, `1.5`, `true` are 422).
- `po_reference` (create and accept): EXACTLY the Phase 3 rules (optional, raw NUL/UTF-8 check before trimming, JavaScript-equivalent trim, `Cc`/`Cf` rejection, empty becomes null, max 64, `^[A-Za-z0-9][A-Za-z0-9 ._/#-]*$`), implemented by one shared validator used by checkout, quote create and quote accept. Error key `po_reference`.
- Rate limits: the shared `api` limiter (60/min per user id) applies to every endpoint. Additionally `POST /quotes` has the named limiter `quotes-create`: 10 requests per 60 s per user id (every attempt counts, including 422), and `POST .../attachments` has TWO named limiters, both per user id: `quote-uploads` 20 requests per 60 s and `quote-uploads-hourly` 120 requests per 3600 s (`quotes.uploads_per_hour`, env `QUOTES_UPLOADS_PER_HOUR`). Both count every attempt, including 413/422 that reach the app, and either one can produce the 429. 429 uses the standard body and `Retry-After`. Staff/admin on customer-only routes get 403 (the limiters are not reached): the role 403 always comes before the 429.
- Quota configuration (all server-side, none client-controllable):

  | Config key | Env | Default | Meaning |
  | --- | --- | --- | --- |
  | `quotes.attachments.max_company_bytes` | `QUOTES_COMPANY_MAX_BYTES` | 524288000 (500 MiB) | Total attachment bytes of ALL quotes of one company, closed quotes included. Staff uploads count toward the quote's company. Over the limit: 422 `file` on upload |
  | `quotes.max_open_per_company` | `QUOTES_MAX_OPEN_PER_COMPANY` | 50 | Open quotes per company; open = `submitted`, `in_review`, `quoted` by EFFECTIVE status (an expired-but-unmaterialised `quoted` quote does not count). Over the limit: 422 `quote` on `POST /quotes` |
  | `quotes.uploads_per_hour` | `QUOTES_UPLOADS_PER_HOUR` | 120 | `quote-uploads-hourly` limiter, per user |
  | `quotes.max_notify_recipients` | (config only) | 50 | Cap on staff recipients of `QuoteSubmittedMail` |

  Company storage limit enforcement: inside the quote-lock transaction of an upload the server first locks the quote row, then locks the company row `FOR UPDATE`, then sums the company's attachment bytes and adds the new file. Lock order is always quote, then company (no other code path takes them in the opposite order, so no deadlock).
- Email failures never fail a request: the mails are queued after the transaction commits; if queueing throws, the error is logged and the 201/200 is returned unchanged. Mail jobs run with 3 tries and backoff `[60, 300]` seconds (design doc section 8). `QuoteSubmittedMail` is queued as ONE message PER staff recipient (never several addresses in one `To`): the recipient is `quotes.notify_address` when configured, else the staff/admin users ordered by id, at most `quotes.max_notify_recipients` (50; a truncation is logged with `Log::warning`). One failing address does not stop the others (each message is queued and retried on its own).
- Customer-visible `updated_at`: for a CUSTOMER viewer the `updated_at` of a quote is `max(created_at of the latest status-history row, sent_at, created_at)`, not the real column. Staff and admin see the real `updated_at`. So staff saving price drafts or an internal note (which touch the real column but are not customer-visible) are not observable by customers. This applies to `QuoteResource` and `QuoteDetailResource`.
- Malformed JSON: every quote endpoint that takes a body (`POST /quotes`, `accept`, `decline`, `reject`, `PUT .../pricing`, `send`, `PUT .../internal-note`) answers 422 with `errors.body` (string message) when the request body is NON-EMPTY and does not decode to a JSON object or array (for example `{`, `abc`, `"text"`, `123`, `null`). An empty body, `{}` and `[]` are accepted (then the normal field validation applies). 401, 403 and 404 still take precedence over this 422, and a 500 is never returned. (Verify against the implementation for `accept`, `reject`, `pricing` and `internal-note`, whose field validation otherwise runs before the lookup: the `body` check is the one that must not outrank the 404.) `review` and `reopen` ignore any body.
- 422 `errors` keys: the body field name; for lines `lines` (list-level problems) and `lines.N.field` (0-based index, for example `lines.1.description`, `lines.0.quantity`, `lines.0.product_id`). Exact placement for create: a `description` that is blank after trimming is on `lines.N.description`; a line with neither `product_id` nor `description` is on `lines.N.product_id`; a line that is not a JSON object is on `lines` (not `lines.N`). Other keys: `status` for a state conflict; `revision`, `valid_until`, `reason`, `file`, `po_reference`, `customer_note`, `internal_note`, `staff_note` for the matching input; `body` for a malformed JSON body (see the rule above); `file` also for the company storage limit (upload); `quote` for too many open quotes (`POST /quotes`).
- Check order for body endpoints: route pattern (404) then authentication (401) then role (403) then rate limiter (429) then body validation (422, format only) then quote lookup with company scope (404) then policy (404) then state and business rules under the row lock (422). For upload endpoints the lookup and state come BEFORE the file rules (a foreign quote is 404 even with a bad file; a closed quote is 422 `status` even with a bad file). Staff `PUT /pricing` and customer `accept` validate the body before the lookup, as in Phase 3.
- Query count: constant for list, detail and create (see design doc section 9): list measures 9 queries (test bound 10), detail measures 10 (test bound 11), create has the same number of INSERTs for 1 and 20 lines.
- Shared code (no behaviour difference visible to clients): `OrderInserter` (retries only on an `orders_order_number_unique` collision) is used by both `POST /orders` and `POST /quotes/{number}/accept`; the `ValidatesPoReference` trait and `PoReference` class give checkout, quote create and quote accept one `po_reference` validator; `ListQuotesRequest` extends `ListOrdersRequest`.
- Private files: the `local` and `quotes` disks have `serve => false`, so there is no `/storage/...` route; an unsigned `GET /storage/...` is a plain 404 (not 403).

### Statuses and transitions

`status` is one of `submitted|in_review|quoted|accepted|declined|expired`. `accepted`, `declined` and `expired` are terminal. Full matrix with actors (everything not listed is 422 `status`):

| From | To | Who | Endpoint |
| --- | --- | --- | --- |
| (new) | submitted | customer | `POST /quotes` |
| submitted | in_review | staff, admin | `POST /quotes/{number}/review` |
| submitted, in_review | declined | customer (withdraw) | `POST /quotes/{number}/decline` |
| submitted, in_review | declined | staff, admin (reject, reason required) | `POST /quotes/{number}/reject` |
| in_review | quoted | staff, admin | `POST /quotes/{number}/send` |
| quoted (not expired) | accepted | customer | `POST /quotes/{number}/accept` |
| quoted (not expired) | declined | customer | `POST /quotes/{number}/decline` |
| quoted (not expired) | in_review | staff, admin | `POST /quotes/{number}/reopen` |
| quoted | expired | system | `quotes:expire` command or effective at read |

### QuoteResource (list items)

```json
{
  "number": "QUO-K7Q2M-X9PA3",
  "status": "quoted",
  "currency": "USD",
  "line_count": 2,
  "po_reference": "PO-4711/A",
  "subtotal_cents": 28000,
  "tax_cents": 0,
  "shipping_cents": 0,
  "total_cents": 28000,
  "valid_until": "2026-12-31",
  "company": { "id": 1, "name": "Northwind Machinery Ltd" },
  "requested_by": { "name": "Alice Customer" },
  "created_at": "2026-11-25T10:15:00Z",
  "updated_at": "2026-11-25T11:00:00Z"
}
```

Exactly these keys (no numeric quote id, no `company_id`, no `internal_note`, no email). `updated_at` for a customer is the derived value `max(latest status-history row, sent_at, created_at)` (see Phase 4 rules); staff see the real column. `po_reference` string or null (always present). `subtotal_cents`, `total_cents` and `valid_until` are `null` when not yet priced/set, and for customers while hidden (see Visibility). `tax_cents` and `shipping_cents` are 0.

### QuoteDetailResource (detail, create, every action)

QuoteResource plus:

```json
{
  "data": {
    "number": "QUO-K7Q2M-X9PA3",
    "status": "quoted",
    "...": "all QuoteResource keys",
    "customer_note": "Need CE certificates please",
    "staff_note": "Lead time 3 weeks, ex works.",
    "internal_note": "Margin ok, supplier confirmed",
    "revision": 1,
    "expires_at": "2027-01-01T00:00:00Z",
    "items": [
      {
        "position": 1,
        "kind": "catalog",
        "product_id": 17,
        "part_number": "6205-2RS",
        "name": "Deep groove ball bearing 6205-2RS 25x52x15",
        "brand": "SKF",
        "quantity": 10,
        "unit_price_cents": 1250,
        "line_total_cents": 12500
      },
      {
        "position": 2,
        "kind": "custom",
        "product_id": null,
        "part_number": null,
        "name": "Taper roller bearing 32208, SKF or equivalent",
        "brand": null,
        "quantity": 4,
        "unit_price_cents": 3875,
        "line_total_cents": 15500
      }
    ],
    "history": [
      {
        "from_status": null,
        "to_status": "submitted",
        "actor": { "name": "Alice Customer", "role": "customer" },
        "note": null,
        "created_at": "2026-11-25T10:15:00Z"
      }
    ],
    "attachments": [
      {
        "id": "3f2b8c1e-4d5a-4b7e-9c10-2a6f5e8d7b90",
        "name": "drawing-rev2.pdf",
        "mime": "application/pdf",
        "size_bytes": 482113,
        "uploaded_by": { "name": "Alice Customer", "role": "customer" },
        "created_at": "2026-11-25T10:16:00Z"
      }
    ],
    "order": { "number": "ORD-K7Q2M-X9PA3" },
    "allowed_actions": ["accept", "decline"]
  }
}
```

Rules:
- `kind` is `catalog` or `custom`. Catalog items are a SNAPSHOT of `part_number`, `name`, `brand` at request time (never change when the product changes or is deactivated); `product_id` is informational (the SPA must tolerate a 404 on the catalog link). Custom items have `product_id`, `part_number`, `brand` all `null` and `name` = the customer's description. `position` (1-based, stable) is how staff address a line in `PUT /pricing`. Items are ordered by `position`.
- `customer_note`: string or null (visible to the company and staff). `staff_note`: string or null, customers see it only after the quote was sent. `internal_note`: staff/admin responses ONLY (key absent for customers). `revision`: integer (0 for a never-sent quote for staff; null for customers while hidden), incremented on every send; the client echoes it on accept. `expires_at`: `valid_until + 1 day` 00:00:00 UTC or null (hidden like `valid_until`).
- `history`: status timeline, oldest first, first entry `from_status: null, to_status: "submitted"`. One entry per status change. `actor.role` is `customer`, `staff`, `admin` (staff viewer) or `system` (expiry, `name` null). For a customer viewer staff and admin actors are `{ "name": null, "role": "staff" }`. `note` is the decline/reject reason or null (staff reasons are visible to the customer, customer reasons to staff). An effective-expired quote that the command has not yet materialised has no `expired` history entry yet; clients derive the expiry from `status` and `expires_at`.
- `attachments`: oldest first. For a customer, staff-uploaded attachments are omitted until `sent_at` is set. `uploaded_by.role` is `customer` or `staff` (admin shown as `staff`). `uploaded_by.name` follows the same masking as `history[].actor`: for a customer viewer a staff or admin uploader is `{ "name": null, "role": "staff" }` (internal names are never shown to customers; customer uploaders keep their name); a staff or admin viewer sees the real name. `name` (the file name, not the uploader) is the sanitised display name; `mime` is one of `application/pdf|image/png|image/jpeg|text/plain|text/csv`. The download URL is `/api/v1/quotes/{number}/attachments/{id}` (not repeated in the body).
- `order`: `{ "number": "ORD-..." }` once accepted (the order created from this quote), else `null`.
- `allowed_actions` is computed by the server for the VIEWER and the current effective status (single source for the UI): customer `submitted`/`in_review`: `["decline","attach"]`; `quoted` (unexpired): `["accept","decline"]`; terminal or expired: `[]`. Staff/admin `submitted`: `["review","reject","attach","note"]`; `in_review`: `["price","send","reject","attach","note"]`; `quoted` (unexpired): `["reopen","note"]`; terminal or expired: `["note"]`. (`decline` on `submitted`/`in_review` is a withdrawal; the UI labels it "Withdraw".)

### GET /api/v1/quotes
- Auth: `auth:sanctum`, roles `customer`, `staff`, `admin`.
- Scope: customer: own company only (query-level). Staff/admin: all companies, narrowed by the optional `company_id` filter.
- Query parameters:

| Param | Type and rule | Meaning |
| --- | --- | --- |
| `status` | CSV, each in `submitted,in_review,quoted,accepted,declined,expired`, empty items 422 | OR within the list, on the EFFECTIVE status (`quoted` excludes past-validity rows, `expired` includes them) |
| `from`, `to` | `YYYY-MM-DD` real dates, UTC, inclusive; `from` later than `to` is 422 on `from` | on `created_at` (same as `/orders`) |
| `q` | string, trimmed, max 32, same rules as `/orders` `q` | Matches the quote number (normalised, substring, without dashes) OR the `po_reference` (case-insensitive substring, `%`, `_`, `\` literal); a `q` that matches neither is an empty list; always inside the viewer's scope |
| `company_id` | integer >= 1. STAFF/ADMIN ONLY; customers: ignored | Filter by company; unknown company gives an empty list |
| `sort` | `created_at`, `-created_at`, `number`, `-number`; default `-created_at` | id ascending as tie-break. There is deliberately no sort by price or validity (it could leak unsent draft prices) |
| `page` | integer 1 to 10000, default 1 | |
| `per_page` | integer 1 to 100, default 20 | 101 is 422 |

- Array syntax, interior NUL and invalid UTF-8 are 422 on that parameter; leading/trailing NUL/control characters are stripped by `TrimStrings`; unknown parameters are ignored.
- Response 200: `{ "data": [ QuoteResource ], "meta": { six keys exactly as /orders } }`, no `links`. Page beyond last: 200, `data: []`, `from: null`, `to: null`.
- Errors: 401 guest. 422 standard shape, `errors` key = parameter name. 429. 403, 404: not applicable.
- Query count: constant (measures 9, test bound at most 10).

### GET /api/v1/quotes/{number}
- Auth: roles `customer`, `staff`, `admin`. Scope: customer: company must match, else 404; staff/admin: any.
- Response 200: `{ "data": <QuoteDetailResource> }` (customer view hides drafts and `internal_note`, see Visibility).
- Errors: 401 guest. 404 `{"message":"Not found."}` for unknown, malformed or foreign number (identical). 429. 403, 422: not applicable.
- Query count: constant (measures 10, test bound at most 11).

### POST /api/v1/quotes
- Auth: `auth:sanctum` + role `customer`. Limiters: `api` and `quotes-create`.
- Scope: `company_id` = session user's company, `created_by` = session user, both server-side.
- Request body (JSON):
  ```json
  {
    "po_reference": "PO-4711/A",
    "customer_note": "Need CE certificates please",
    "lines": [
      { "product_id": 17, "quantity": 10 },
      { "description": "Taper roller bearing 32208, SKF or equivalent", "quantity": 4 }
    ]
  }
  ```
  - `lines`: required array, 1 to 50 items, each a JSON object with `quantity` (integer 1 to 9999) and EXACTLY ONE of `product_id` (catalog line) or `description` (custom line). Both present, or neither, is 422 on `lines.N.description` / `lines.N.product_id`. `description`: string, 1 to 255 characters after trimming, text rules above. `product_id`: integer >= 1, must exist and be `active` (unknown and inactive give one identical message, "The selected product is not available."), the same product twice in one request is 422 on the second `lines.N.product_id`. Products that are `price_on_request` or `out_of_stock` ARE allowed (that is what quotes are for). Any other key in a line (`unit_price_cents`, `name`, `brand`, ...) is ignored.
  - `po_reference`: optional, Phase 3 rules. `customer_note`: optional string or null, at most 2000 characters, text rules above; empty after trimming is stored as null.
  - Ignored if sent: everything in the mass-assignment rule.
- Behaviour (one DB transaction): insert the quote (`status = submitted`, `currency` from config, random number with collision retry), snapshot catalog lines (`part_number`, `name`, `brand` from the product row inside the transaction), insert items with `position` 1..n and `unit_price_cents = null`, insert the first history row (`null -> submitted`, actor = user). After commit, one queued email `QuoteSubmittedMail` goes to the staff recipients (design doc section 8). A mail failure does not fail the request.
- Response 201: `{ "data": <QuoteDetailResource> }` (`status: "submitted"`, no prices, `allowed_actions: ["decline","attach"]`). The SPA then uploads attachments one at a time (see attachments).
- Open-quote cap: under a lock on the company row the server counts the company's open quotes (`submitted`, `in_review`, `quoted` by EFFECTIVE status, so an expired-but-unmaterialised quote does not count); at `quotes.max_open_per_company` (50) or more the request is 422 `quote` ("Too many open quotes...") and nothing is created. Accepted, declined and expired quotes never count.
- Errors: 401 guest. 403 staff, admin, or a customer with no company. 422 (see keys above, including `body` for malformed JSON and `quote` for the open-quote cap; nothing is created on any 422). 429 (`api` or `quotes-create`). 419 CSRF. 404: not applicable.

### POST /api/v1/quotes/{number}/review
- Auth: `staff`, `admin`. Scope: any quote (staff are cross-company). Request: no body (any field ignored).
- Rule: `submitted` -> `in_review`. Response 200: `{ "data": <QuoteDetailResource> }` with a new history entry (actor = user).
- Errors: 401 guest. 403 customer (even for a foreign/unknown number). 404 unknown or malformed number. 422 `status` when the quote is not `submitted`. 429.

### PUT /api/v1/quotes/{number}/pricing
- Auth: `staff`, `admin`. Saves a DRAFT (nothing is sent to the customer, no email). Idempotent.
- Request body:
  ```json
  {
    "lines": [ { "position": 1, "unit_price_cents": 1250 }, { "position": 2, "unit_price_cents": null } ],
    "valid_until": "2026-12-31"
  }
  ```
  - `lines`: optional array (at most 50); each `position` integer matching an existing line (unknown position is 422 `lines.N.position`, a position twice is 422), `unit_price_cents` integer 0 to 1,000,000,000 or `null` (clears the price). Lines not mentioned are unchanged.
  - `valid_until`: optional; key absent = unchanged; `null` = clear; otherwise a real date `YYYY-MM-DD` with `today (UTC) < date <= today + 365 days` (422 `valid_until` otherwise).
  - Validation (422) runs before the lookup.
- Behaviour: quote must be in `in_review` (422 `status` otherwise). Under the row lock the server recomputes each `line_total_cents = unit_price_cents * quantity`, then `subtotal_cents = sum` and `total_cents = subtotal` ONLY when every line is priced, else both stay `null`. No history row (not a status change).
- Response 200: `{ "data": <QuoteDetailResource> }` (staff view with draft prices).
- Errors: 401. 403 customer. 404. 422 (`lines`, `lines.N.position`, `lines.N.unit_price_cents`, `valid_until`, `status`). 429.

### POST /api/v1/quotes/{number}/send
- Auth: `staff`, `admin`.
- Request body: optional `{ "staff_note": "Lead time 3 weeks" }` (string or null, max 2000, text rules; sets or replaces the customer-visible note; absent keeps the existing one).
- Rule: `in_review` -> `quoted`. Under the row lock the server requires EVERY line priced (422 `lines`: "All lines must be priced."), `valid_until` set and `today (UTC) < valid_until <= today + 365 days` (422 `valid_until`). Then: `status = quoted`, `sent_at = now`, `revision = revision + 1`, history row (actor = user), and after commit one queued `QuoteSentMail` to the user who created the quote.
- Response 200: `{ "data": <QuoteDetailResource> }`.
- Errors: 401. 403 customer. 404. 422 (`status` not `in_review`, `lines`, `valid_until`, `staff_note`). 429.

### POST /api/v1/quotes/{number}/reject
- Auth: `staff`, `admin`. Request: `{ "reason": "We do not stock this series." }` (required, string, 1 to 500 characters, text rules). Validated before the lookup.
- Rule: `submitted` or `in_review` -> `declined`; history `note` = reason (visible to the customer). No email in Phase 4.
- Response 200: `{ "data": <QuoteDetailResource> }`. Errors: 401, 403 customer, 404, 422 (`reason`, `status`), 429.

### POST /api/v1/quotes/{number}/reopen
- Auth: `staff`, `admin`. Request: no body.
- Rule: effective `quoted` (not expired) -> `in_review`. Clears `sent_at` (prices, `valid_until`, `staff_note` and staff attachments are hidden from the customer again; `revision` is kept, the next send makes it +1). No email. A customer accept racing with reopen is serialised by the row lock: the loser gets 422.
- Response 200: `{ "data": <QuoteDetailResource> }`. Errors: 401, 403 customer, 404, 422 `status` (not quoted, or expired), 429.

### PUT /api/v1/quotes/{number}/internal-note
- Auth: `staff`, `admin`. Allowed in any status. Request: `{ "internal_note": "text" }` (string or null to clear, max 2000, text rules; the key is required). Validated before the lookup.
- Response 200: `{ "data": <QuoteDetailResource> }`. Errors: 401, 403 customer, 404, 422 (`internal_note`), 429.

### POST /api/v1/quotes/{number}/accept
- Auth: `customer` (any user of the quote's company). Staff and admin: 403, and so is a user who holds the customer role together with a staff or admin role (policy rule, see the role table).
- Request body: `{ "revision": 1, "po_reference": "PO-4711/A" }`. `revision`: required integer 1 to 2147483647 (the `revision` the client saw in the detail; the column is a 32-bit integer since migration `2026_11_28_100005_widen_quote_revision`, the earlier bound 32767 no longer applies; 0, negatives, 2147483648, strings, floats, booleans and null are 422 `revision`). `po_reference`: optional, Phase 3 rules; when given it becomes the order's PO, else the quote's PO (may be null). Validated before the lookup. Any other field ignored (never a price or total).
- Behaviour (one transaction under the quote row lock): effective status must be `quoted` and not expired, `revision` must equal the stored one; creates exactly one order from the quote's snapshot lines and staff-set unit prices (not the current catalog), `subtotal = total = quote total`, `tax = shipping = 0`, currency of the quote, `company_id` of the quote, `created_by` = the accepting user, `status = pending`, `po_reference` as above, `quote_request_id` set (UNIQUE: a quote can never have two orders), first order history row `null -> pending` (actor = user, as a normal order); sets the quote to `accepted` with a history row (actor = user). All or nothing. Custom lines become order lines with `product_id`, `part_number`, `brand` null. Catalog availability is NOT re-checked (the agreed quote stands).
- Response 200: `{ "data": <QuoteDetailResource> }` with `status: "accepted"` and `order.number`. The SPA navigates to `/orders/{order.number}`.
- Errors: 401 guest. 403 staff/admin. 404 unknown, malformed or foreign quote. 422 `status`: already accepted ("This quote has already been accepted."), declined, expired ("This quote has expired."), still submitted/in_review, i.e. any state other than an unexpired `quoted`; 422 `revision`: missing, not an integer, or not the current revision ("This quote was updated. Please review the new prices."); 422 `po_reference`. Nothing is created on any 422. 429. Parallel accepts: exactly one wins, the other gets 422 `status`.

### POST /api/v1/quotes/{number}/decline
- Auth: `customer` (company users only; a dual-role customer+staff user is 403). Request: optional `{ "reason": "Price too high" }` (string or null, max 500, text rules).
- Rule: `submitted` or `in_review` (withdraw) or an unexpired `quoted` -> `declined`; history `note` = reason (visible to staff).
- Response 200: `{ "data": <QuoteDetailResource> }`. Errors: 401, 403 staff/admin, 404 unknown/foreign, 422 (`status` for terminal or expired, `reason`), 429.

### Attachments

Rules for all three endpoints: allowed roles `customer`, `staff`, `admin`; the quote is first resolved through the company scope (foreign = 404); an attachment is addressed only through its own quote (`/quotes/{number}/attachments/{id}`; an id belonging to another quote is 404); customers get 404 for staff attachments while `sent_at` is null. Files live on a private disk outside the web root with random names; the original name is sanitised and display-only.

Allowed content (by sniffing the file CONTENT, never the client MIME): `.pdf` (`application/pdf`), `.png` (`image/png`), `.jpg`/`.jpeg` (`image/jpeg`), `.txt` (`text/plain`), `.csv` (`text/csv`, `text/plain` or `application/csv` when sniffed). The extension of the sanitised name must be in this list AND the sniffed type must match it; text files must be valid UTF-8 without NUL. Anything else (executables, HTML, SVG, archives, Office files, scripts, a renamed file whose content does not match) is 422. Limits: 10 MiB (10,485,760 bytes) per file, at most 10 files and 25 MiB (26,214,400 bytes) in total per quote, no empty file, and a per-company cap of `quotes.attachments.max_company_bytes` (500 MiB by default) across all the company's quotes, closed ones included (staff uploads count toward the quote's company).

#### POST /api/v1/quotes/{number}/attachments
- Request: `multipart/form-data` with one part `file`. (CSRF header required; do NOT send `Content-Type: application/json`; the browser sets the multipart boundary.) One file per request.
- Allowed while the effective status is `submitted` or `in_review` (customers of the company and staff/admin). There is no FormRequest for the file: the controller resolves the route, quote scope and policy, then `QuoteAttachments` takes the quote row lock and runs `AttachmentInspector` (upload error, empty, size, extension, content sniffing, UTF-8/NUL for text) together with the state, per-quote count and total-size checks; only then is the file stored and one row inserted; the uploader's role is recorded. Every file problem is 422 `file`.
- Response 201: `{ "data": <attachment object as in the detail> }` (`id`, `name`, `mime`, `size_bytes`, `uploaded_by`, `created_at`).
- Errors: 401 guest. 404 unknown, malformed or foreign quote. 413 `{"message":"The upload is too large."}` when the request body exceeds PHP `post_max_size` (12M recommended; Laravel throws `PostTooLargeException` before the app sees the file, and a render rule in `bootstrap/app.php` turns it into this JSON body for `api/*` paths only). A malformed `{attachment}` id (not a lowercase UUID) never reaches this: it is a plain 404 by route pattern. 422 `status` when the quote is not `submitted`/`in_review`; 422 `file`: missing, not a file or upload failed (`UPLOAD_ERR_*`, for example above PHP `upload_max_filesize`), empty, larger than 10,485,760 bytes (10,485,761 is rejected, exactly 10,485,760 is accepted), extension not allowed, content type does not match the extension, text file not valid UTF-8 or containing NUL, 11th file, total above 25 MiB, company total above `quotes.attachments.max_company_bytes` (same key `file`, a different message; checked inside the quote-lock transaction after locking the company row, lock order quote then company). 422 `body` does not apply (multipart). 429 (`api`, `quote-uploads` or `quote-uploads-hourly`). 403: not applicable (all three roles may call; wrong state is 422).

#### GET /api/v1/quotes/{number}/attachments/{attachment}
- Response 200: the raw file with headers `Content-Type: <canonical mime of the row>`, `Content-Disposition: attachment; filename="<sanitised name>"` (never `inline`), `X-Content-Type-Options: nosniff`, `Content-Security-Policy: default-src 'none'; sandbox`, `Cache-Control: private, no-store`, `Content-Length`. Allowed in every status for everyone who may view the quote (and, for a customer, the attachment).
- Errors: 401 guest. 404 unknown/malformed number or attachment id, foreign quote, attachment of another quote, staff attachment not yet visible to the customer, or the file is missing on disk (logged, never a 500). 429 (shared `api` bucket). 403, 422: not applicable.

#### DELETE /api/v1/quotes/{number}/attachments/{attachment}
- Allowed while the effective status is `submitted` or `in_review`. Customers may delete only customer-uploaded attachments they can see; staff/admin any. Hard delete (row and file).
- Response 204, no body.
- Errors: 401 guest. 404 as for download (a customer deleting a staff attachment gets 404, since they cannot see it in an open state). 422 `status` when the quote is no longer open. 419 CSRF. 429. 403: not applicable.

### Expiry command (Artisan, not HTTP)

Deployment notes (Phase 8): PHP `upload_max_filesize=11M` and `post_max_size=12M`; Nginx `client_max_body_size 12m`; a monitored `QUOTES_NOTIFY_ADDRESS` mailbox; a scheduler cron (`schedule:run` every minute) for `quotes:expire`; backups must include the private quotes storage together with the database; a queue worker for mail. Details: `docs/phase-4-design.md` section 15.

`php artisan quotes:expire`: sets every stored `quoted` quote whose `valid_until` is before today (UTC) to `expired` with a system history entry (actor null); idempotent; scheduled every five minutes; sends no email. Effective-status rules above make the API correct even if it has not run.

### Known limitations (Phase 4, accepted)

- libmagic can refuse a 1-byte `.txt` or `.csv` file with 422 `file` ("content does not match its file type"); very small text files may be rejected.
- No malware scanning of uploads (Phase 7/8). Mitigations: content allowlist, forced `attachment` disposition, `nosniff`, `Content-Security-Policy: default-src 'none'; sandbox`, private storage.
- The mail layout header link still shows the API origin (`APP_URL`), while action links use `app.web_url` (fixed with the Phase 8 email branding).
- Parallel upload overshoot of the per-quote and per-company caps is guarded by the quote and company row locks but is only tested sequentially; a real parallel check is a QA/Phase 8 task.

### Deferred follow-ups (Phase 7)

- Staff purge of attachment files on closed quotes (today a closed quote's files stay and count toward the company limit).
- Warn staff in the UI about CSV attachments (formula injection when opened in a spreadsheet).

### Changes to the Phase 3 contract (made by this phase)

- `order_items.product_id`, `part_number`, `brand` may be `null` (order lines created from custom quote lines); in `OrderDetailResource.items` they are `null` for such lines (`name` holds the description). The order item `CHECK` requires all three null or all three set.
- `OrderResource` and `OrderDetailResource` gain the key `quote_number` (string or null, always present; null for orders created from the cart). The key sits right after `po_reference` in both resources (see the Phase 3 examples above). The Phase 3 "exact keys" tests and query-count bounds are updated (orders list at most 9 queries, detail at most 10).
- `POST /orders/{number}/reorder`: a line with `product_id = null` is skipped with the new reason `not_in_catalog` (`skipped[].product_id` and `part_number` are null, `name` is the description). `reason` is now one of `inactive|price_on_request|out_of_stock|cart_full|quantity_limit|not_in_catalog`.
- `po_reference` validation is shared (the `ValidatesPoReference` trait and `PoReference` class; no behaviour change). `PlaceOrder`'s number-collision retry moves to the shared `OrderInserter`, which retries only on an `orders_order_number_unique` collision (no behaviour change).
- Cart behaviour is unchanged: price-on-request products are still rejected by `POST /cart/items`; the catalog now offers "Request quote" for them instead.

### Phase 4 endpoint summary

| Method | Path | Guest | Customer | Staff | Admin |
| --- | --- | --- | --- | --- | --- |
| GET | /api/v1/quotes | 401 | 200 (own company) | 200 (all) | 200 (all) |
| GET | /api/v1/quotes/{number} | 401 | 200 / 404 foreign | 200/404 | 200/404 |
| POST | /api/v1/quotes | 401 | 201/422/429 | 403 | 403 |
| POST | /api/v1/quotes/{number}/review | 401 | 403 | 200/404/422 | 200/404/422 |
| PUT | /api/v1/quotes/{number}/pricing | 401 | 403 | 200/404/422 | 200/404/422 |
| POST | /api/v1/quotes/{number}/send | 401 | 403 | 200/404/422 | 200/404/422 |
| POST | /api/v1/quotes/{number}/reject | 401 | 403 | 200/404/422 | 200/404/422 |
| POST | /api/v1/quotes/{number}/reopen | 401 | 403 | 200/404/422 | 200/404/422 |
| PUT | /api/v1/quotes/{number}/internal-note | 401 | 403 | 200/404/422 | 200/404/422 |
| POST | /api/v1/quotes/{number}/accept | 401 | 200 / 404 foreign / 422 | 403 | 403 |
| POST | /api/v1/quotes/{number}/decline | 401 | 200 / 404 foreign / 422 | 403 | 403 |
| POST | /api/v1/quotes/{number}/attachments | 401 | 201 / 404 / 413 / 422 / 429 | 201/404/413/422/429 | same |
| GET | /api/v1/quotes/{number}/attachments/{attachment} | 401 | 200 / 404 | 200/404 | 200/404 |
| DELETE | /api/v1/quotes/{number}/attachments/{attachment} | 401 | 204 / 404 / 422 | 204/404/422 | 204/404/422 |

(All also return 429 over the rate limits. A customer on a staff action gets 403 before any lookup; a malformed `{number}` or `{attachment}` is a plain 404 for every role. `PUT`/`DELETE` on `/api/v1/quotes` and `POST` on `/api/v1/quotes/{number}` are not routes: 405. There is no endpoint to edit lines, change a customer note or change a PO reference after creation.)

### Frontend behaviour bound to this contract
- Routes (inside `ProtectedRoute`): `/quotes` and `/quotes/:number` any logged-in role; `/quotes/new` customer only (others get the 403 page). Nav: "Quotes" for all roles. `/quotes` URL parameters are exactly the API names: `status` (CSV), `from`, `to`, `q`, `sort`, `page`; `per_page` and `company_id` are not in the URL; defaults omitted; invalid values dropped client-side.
- `/quotes/:number`: a `:number` not matching `[A-Za-z0-9-]{1,32}`, or an API 404 (unknown OR foreign), shows the same "Quote not found" page. Every button comes from `allowed_actions`; hiding is a convenience, not security. The Accept request sends `revision` from the loaded detail and, optionally, `po_reference`; nothing else.
- Quote creation body is exactly `po_reference?`, `customer_note?`, `lines[]` (`product_id` or `description`, plus `quantity`); never a price, name or total. Attachments are uploaded after the create, one request per file, `FormData` field `file`, `X-XSRF-TOKEN` header, no manual `Content-Type`. A failed upload never re-creates the quote.
- Catalog entry point: for customers, price-on-request and out-of-stock products show a "Request quote" link to `/quotes/new?product=<id>` (repeatable, at most 20; the form loads each with `GET /products/{id}` and drops unknown ones). Only ids are taken from the URL.
- Money: `cents / 100` through `Intl.NumberFormat('en-US', { style: 'currency', currency })` with `currency` from the quote body. Staff price inputs accept `^\d{1,7}(\.\d{1,2})?$` up to 10,000,000.00 and are converted to integer cents.
- Dates: show `valid_until` as "valid through YYYY-MM-DD (end of day, UTC)". `po_reference`, notes, descriptions and attachment names are rendered as plain text nodes, never HTML.
- 413 shows "The file is too large for the server."; 422 `file` shows the message under the upload control (this includes the company storage limit); 422 `quote` on create shows the message as a form-level error; 422 `body` is a client bug and shows a generic error; 422 `status` shows the message and refetches; 422 `revision` shows "This quote was updated. Please review the new prices." and refetches; 429 shows the Phase 1 "try again in N seconds" message.

---

## Phase 5 - Invoices and PDF download

Schema, numbering, VAT rounding, PDF design, seeders, frontend design and test plan: `docs/phase-5-design.md`. This section is the HTTP contract. All Phase 1 conventions (cookie auth, CSRF header on writes, `Accept: application/json`, error shapes, `{ "data": ... }` wrapping, 429 body and `Retry-After`), Phase 2 conventions (exact 404 body, CSV params, `meta`), Phase 3 conventions (role middleware before limiters and lookups, foreign = 404, `TrimStrings`, money, dates) and the Phase 4 `body` (malformed JSON) rule apply unchanged. All VAT, seller, legal and retention values are configurable PLACEHOLDERS until the user answers the open questions in the design doc.

### Phase 5 rules (apply to every endpoint below)

- Auth: `auth:sanctum`. Guests get 401 on every endpoint.
- Roles (role middleware runs BEFORE any rate limiter, body validation and lookup, so a wrong role is 403 for every id, existing or not):

  | Group | Endpoints | Allowed roles |
  | --- | --- | --- |
  | Reads | `GET /invoices`, `GET /invoices/{invoice}`, `GET /invoices/{invoice}/pdf` | `customer`, `staff`, `admin` |
  | Staff actions | `POST /orders/{number}/invoice`, `POST /invoices/{invoice}/mark-paid`, `POST /invoices/{invoice}/void` | `staff`, `admin` only (customer: 403) |

  Customers have no write endpoint in this phase. A customer-only user can never create, pay or void.
- Company scoping: customers: every invoice query includes `invoices.company_id = <session user's company_id>` (model scope `Invoice::visibleTo($user)`), and `InvoicePolicy` re-checks company equality on every loaded invoice (two independent layers). All customer users of one company see all of that company's invoices. Staff and admin (`company_id = null`) are not scoped. A customer whose `company_id` is null sees nothing (list empty, detail and PDF 404). A user with no role sees nothing and gets 403 on role-guarded routes. The company is never read from request input; the `company_id` list filter is staff/admin only.
- Foreign invoices are 404, never 403: an invoice of another company (detail AND PDF) returns exactly the same status and body as an unknown one: `404 {"message":"Not found."}`. The PDF is authorized exactly like the JSON detail (same scope, same policy method). The only 403s are role errors. The invoice NUMBER is sequential and therefore guessable, so company scoping is the only real protection; the random `id` in the URL is defence in depth only.
- Public identifiers: invoices are addressed in URLs by `id`, a random lowercase UUID v4 (`3f2b8c1e-4d5a-4b7e-9c10-2a6f5e8d7b90`), global route pattern `{invoice}` = `[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}`, enforced by routing BEFORE any middleware: anything else (including an invoice number such as `INV-2026-000001`, uppercase UUIDs, `abc`) is a plain 404 for every role and endpoint. The user-facing `number` (`INV-2026-000123`) is display and search only. No numeric database id, `company_id`, `user_id` or `created_by` appears in any URL or body. `POST /orders/{number}/invoice` uses the existing order `{number}` pattern.
- Mass assignment: the client may send only the fields documented per endpoint (`due_date`; `paid_on`; `reason`). `company_id`, `order_id`, `number`, `id`, `status`, `issue_date`, `currency`, `po_reference`, `vat_rate_bp`, `subtotal_cents`, `tax_cents`, `total_cents`, `seller`, `buyer`, `items`, `paid_on` (on create), timestamps and any other field are ignored when sent (not 422). Every amount, rate, number, date other than the optional overrides, and every snapshot is computed or copied on the server from the order, the company and `config/invoicing.php`.
- Money: integer cents (JSON numbers), never strings or floats. `currency` (3-letter code) is copied from the order. `vat_rate_bp` is an integer (basis points, `1900` = 19%, 0 to 5000). `tax_cents = floor((subtotal_cents * vat_rate_bp + 5000) / 10000)` (half up, once, on the subtotal; design doc section 5), `total_cents = subtotal_cents + tax_cents`. `subtotal_cents` is at most 999,900,000,000,000 and `total_cents` at most 1,499,850,000,000,000, exact in PHP, Postgres and JavaScript. Order and quote prices are treated as NET: with a rate above 0 the invoice total is higher than the order total.
- Dates: `issue_date`, `due_date` and `paid_on` are plain dates `YYYY-MM-DD` (UTC, no time part, no timezone). `created_at` and history `created_at` are ISO 8601 UTC with `Z`. `issue_date` is today (UTC), set by the server, never client-supplied. The server clock is UTC and read only through `now()`.
- Effective status (important): the `status` in every response is the EFFECTIVE status. A stored `open` invoice whose `due_date` is before today (UTC) is returned as `overdue`, filtered as `overdue`, and has `days_overdue >= 1`, even if `invoices:mark-overdue` has not run. An invoice due TODAY is `open`. `days_overdue` is `today - due_date` in whole days when the effective status is `overdue`, else `0` (always an integer, always present).
- Input rules: `TrimStrings` applies to every field and query parameter (leading/trailing NUL/control characters stripped; an interior NUL or invalid UTF-8 is 422 on that parameter). `reason` follows the Phase 4 text rules (newline and tab allowed, any other `Cc`/`Cf` character, NUL and invalid UTF-8 rejected). Dates must be real calendar dates in `YYYY-MM-DD` form (not datetimes).
- Body only (trait `ReadsBodyOnly`): `due_date`, `paid_on` and `reason` are read from the request BODY only (JSON or form-encoded). A query-string value (`?due_date=...`, `?paid_on=...`, `?reason=...`) is IGNORED, never merged and never validated. Consequences: `POST .../void?reason=x` with no body reason is 422 `reason` (required); `POST /orders/{number}/invoice?due_date=2030-01-01` uses the default due date; `POST .../mark-paid?paid_on=...` uses today.
- Malformed JSON: `POST /orders/{number}/invoice`, `POST .../mark-paid` and `POST .../void` answer 422 with `errors.body` when the request body is NON-EMPTY and does not decode to a JSON object or array (same rule and precedence as Phase 4: 401, 403 and 404 win; never 500). An empty body, `{}` and `[]` are accepted.
- Rate limits: the shared `api` limiter (60/min per user id) applies to every endpoint. Additionally `GET /invoices/{invoice}/pdf` (and `HEAD`, see below) has two named limiters: `invoice-pdf`, 20 requests per 60 s per user id, and `invoice-pdf-ip`, 60 requests per 60 s per client IP (key `ClientIp::key`: IPv4 as is, IPv6 collapsed to its /64), so several accounts behind one address cannot multiply the rendering budget. Every request that reaches them counts, including 404 and HEAD. The three staff write endpoints share `invoices-write`: 30 requests per 60 s per user id (every attempt counts, including 422). 429 uses the standard body and `Retry-After`. The role 403 always comes before the 429.
- Check order for the PDF route: route pattern (404) then authentication (401) then role (403) then limiters `api`, `invoice-pdf`, `invoice-pdf-ip` (429) then, for `HEAD` only, 405 (before any lookup or rendering), then lookup with company scope (404), policy (404), render.
- Check order for body endpoints: route pattern (404) then authentication (401) then role (403) then rate limiter (429) then body validation (422, format only) then lookup with company scope (404) then policy (404/403) then state and business rules under the row lock (422).
- Query count: constant in the number of rows, lines and history entries. Bounds: list 6, detail 8, PDF 6, order detail 11. Measured excluding the session-user query: list 3, detail 6, PDF 3, order detail 9 (design doc section 10).
- Immutability: an issued invoice's number, dates, amounts, rate, items, `seller` and `buyer` snapshots never change (enforced by database triggers). Only `status` and `paid_on` change. There is no endpoint to edit, delete or re-date an invoice, and invoices are never deleted. `paid` and `void` are final in the database too: once the stored status is `paid` or `void`, a trigger refuses any change of `status` or `paid_on` (only `updated_at` may change); the legal transitions (`open` to `paid`, `open` to `overdue`, `overdue` to `paid`, `open` to `void`, `overdue` to `void`) still work.

### InvoiceResource (list items)

```json
{
  "id": "3f2b8c1e-4d5a-4b7e-9c10-2a6f5e8d7b90",
  "number": "INV-2026-000123",
  "status": "overdue",
  "currency": "USD",
  "subtotal_cents": 107079,
  "vat_rate_bp": 1900,
  "tax_cents": 20345,
  "total_cents": 127424,
  "issue_date": "2026-11-26",
  "due_date": "2026-12-26",
  "paid_on": null,
  "days_overdue": 12,
  "order_number": "ORD-K7Q2M-X9PA3",
  "po_reference": "PO-4711/A",
  "line_count": 3,
  "company": { "id": 1, "name": "Northwind Machinery Ltd" },
  "created_at": "2026-11-26T10:15:00Z"
}
```

Exactly these keys (no numeric invoice id, no `company_id`, no user, no email). `status` is one of `open|overdue|paid|void` (effective). `paid_on` is a date for `paid` invoices, else `null`. `po_reference` is the order's PO reference (string or `null`, plain text). `company.name` is the buyer name SNAPSHOTTED on the invoice (it does not change if the company is renamed). `order_number` is the snapshotted order number. All keys are always present.

### InvoiceDetailResource (detail, create, mark-paid, void)

InvoiceResource plus `seller`, `buyer`, `items`, `history`, `allowed_actions`:

```json
{
  "data": {
    "id": "3f2b8c1e-4d5a-4b7e-9c10-2a6f5e8d7b90",
    "number": "INV-2026-000123",
    "status": "open",
    "...": "all InvoiceResource keys",
    "seller": {
      "name": "PLACEHOLDER SELLER NAME",
      "address_lines": ["PLACEHOLDER Street 1", "00000 PLACEHOLDER City", "PLACEHOLDER Country"],
      "tax_id": "PLACEHOLDER-VAT-ID",
      "email": "billing@example.invalid",
      "phone": "+00 000 000000",
      "bank": { "account_holder": "PLACEHOLDER", "bank_name": "PLACEHOLDER BANK", "iban": "XX00 PLACEHOLDER IBAN", "bic": "PLACEHOLDER" },
      "payment_terms_text": "Please pay by bank transfer and quote the invoice number. (PLACEHOLDER TEXT)",
      "footer": "PLACEHOLDER legal footer: registered office, registration number, managing directors.",
      "vat_label": "VAT"
    },
    "buyer": { "name": "Northwind Machinery Ltd", "address_lines": ["12 Example Road", "Sampletown"], "tax_id": "NW-TAX-0001" },
    "items": [
      {
        "position": 1,
        "part_number": "6205-2RS",
        "name": "Deep groove ball bearing 6205-2RS 25x52x15",
        "brand": "SKF",
        "quantity": 3,
        "unit_price_cents": 1250,
        "line_total_cents": 3750
      },
      {
        "position": 2,
        "part_number": null,
        "name": "Taper roller bearing 32208, SKF or equivalent",
        "brand": null,
        "quantity": 10,
        "unit_price_cents": 333,
        "line_total_cents": 3330
      }
    ],
    "history": [
      {
        "from_status": null,
        "to_status": "open",
        "actor": { "name": "Sam Staff", "role": "staff" },
        "note": null,
        "created_at": "2026-11-26T10:15:00Z"
      }
    ],
    "allowed_actions": ["download", "mark_paid", "void"]
  }
}
```

Rules:
- `seller` and `buyer` are the SNAPSHOTS taken at issue time (never the live config or company). Their keys are always emitted in the order shown in the example above (the database stores them as jsonb, which reorders keys; the resource re-emits them in this order). `address_lines` are arrays of plain strings; `tax_id`, `email`, `phone` and bank fields may be `null`. `seller.name` and `buyer.name` are never null.
- `items` are the order's snapshot lines copied at issue time (`part_number` and `brand` are `null` for free-text quote lines; `name` then holds the description), ordered by `position` (1-based, in order-line order). There is no `product_id`. `line_total_cents = unit_price_cents * quantity`; `subtotal_cents` equals the sum of `line_total_cents`.
- `history`: oldest first, first entry `from_status: null, to_status: "open"`. One entry per STORED status change (an effective overdue invoice that the command has not yet materialised has no `overdue` entry yet; clients derive overdue from `status`/`days_overdue`). `from_status`/`to_status` are stored values (`open|overdue|paid|void`). `actor.role` is `staff`, `admin` (staff viewer), `customer` (never in this phase) or `system` (`name` null, for the overdue command). For a CUSTOMER viewer staff and admin actors are `{ "name": null, "role": "staff" }`. `note` is the void reason for staff and admin viewers and ALWAYS `null` for customers (the reason is internal).
- `allowed_actions` is computed by the server for the VIEWER and the effective status (single source for the UI): customer: `["download"]`; staff and admin: `["download","mark_paid","void"]` while the effective status is `open` or `overdue`, else `["download"]` (paid and void are terminal; a void invoice can still be downloaded, with a VOID banner).

### GET /api/v1/invoices
- Auth: `auth:sanctum`, roles `customer`, `staff`, `admin`.
- Scope: customer: own company only (query-level, `visibleTo`). Staff/admin: all companies, narrowed by the optional `company_id` filter.
- Query parameters:

| Param | Type and rule | Meaning |
| --- | --- | --- |
| `status` | CSV, each in `open,overdue,paid,void`, empty items 422 | OR within the list, on the EFFECTIVE status (`open` excludes past-due rows, `overdue` includes them) |
| `from`, `to` | `YYYY-MM-DD` real dates, inclusive; `from` later than `to` is 422 on `from` | on `issue_date` |
| `q` | string, trimmed, max 32 | Matches the invoice number OR the order number OR the PO reference. (1) Invoice number and order number: uppercased, every character outside `A-Z0-9` removed, substring match against the stored number without dashes (`inv-2026-1`, `INV2026000123`, `2026000123`, `k7q2` all work); if `q` normalises to empty the number branches match nothing. (2) PO reference: the trimmed `q` as typed, case-insensitive substring, `%`, `_` and `\` literal, null PO never matches. A `q` that matches nothing gives an empty list, never everything. Always inside the viewer's scope |
| `company_id` | integer >= 1. STAFF/ADMIN ONLY; customers: ignored (no 422) | Filter by company; unknown company gives an empty list |
| `sort` | one of `issue_date`, `-issue_date`, `due_date`, `-due_date`, `number`, `-number`, `total`, `-total`; default `-issue_date` | Every ordering ends with the internal id ascending as tie-break. `number` orders by `(number_year, number_seq)` |
| `page` | integer 1 to 10000, default 1 | |
| `per_page` | integer 1 to 100, default 20 | 101 is 422 |

- `status` with an EMPTY value (`?status=`) is treated as no filter (same as `/orders`); a CSV with an empty item (`open,,paid`, `,open`, `open,`) is 422 `status`.
- Array syntax, interior NUL and invalid UTF-8 are 422 on that parameter; unknown parameters are ignored.
- Response 200: `{ "data": [ InvoiceResource ], "meta": { six keys exactly as /orders } }`, no `links`. A page beyond `last_page` is 200 with `data: []`, `from: null`, `to: null`. No matches: `data: []`, `total: 0`, `last_page: 1`.
- Errors: 401 guest. 422 standard shape, `errors` key = parameter name (never `status.0`). 429. 403, 404: not applicable (customers just get their own rows).

### GET /api/v1/invoices/{invoice}
- Auth: roles `customer`, `staff`, `admin`. Scope: customer: company must match, else 404; staff/admin: any.
- Response 200: `{ "data": <InvoiceDetailResource> }`.
- Errors: 401 guest. 404 `{"message":"Not found."}` for an unknown id, a malformed id (route pattern), and a foreign invoice (all identical). 429. 403, 422: not applicable.

### GET /api/v1/invoices/{invoice}/pdf
- Auth: roles `customer`, `staff`, `admin`. Scope and policy: identical to `GET /invoices/{invoice}` (`Invoice::visibleTo` then `InvoicePolicy::download`).
- Request: no body, no query parameters (any are ignored). Role middleware runs before the limiters (403 before 429).
- Response 200: the PDF, generated on the fly from the immutable snapshot (A4, English, `DejaVu Sans`). Headers exactly:
  ```
  Content-Type: application/pdf
  Content-Disposition: attachment; filename="INV-2026-000123.pdf"
  Content-Length: <bytes>
  X-Content-Type-Options: nosniff
  Cache-Control: no-store, private
  Content-Security-Policy: default-src 'none'; sandbox
  ```
  The filename is `<number>.pdf` built only from the stored invoice number (always `INV-` plus year plus 6 to 9 digits); no user text ever appears in a header. The disposition is always `attachment`. The body starts with `%PDF-`. A void invoice downloads too, with a VOID banner; paid and overdue are not printed. The same invoice downloaded twice is visually identical; byte equality is not guaranteed.
- Errors: 401 guest (JSON). 404 JSON `{"message":"Not found."}` for unknown, malformed or foreign id. 429 JSON (`api`, `invoice-pdf` or `invoice-pdf-ip`, `Retry-After`). 405 for `HEAD` (below). 403, 422: not applicable (403 only for a role error). Error responses are always JSON, never a PDF or HTML.
- `HEAD` is NOT supported: it answers `405` with header `Allow: GET` (JSON error body), decided BEFORE any invoice lookup or PDF rendering, so it can never trigger a render and never reveals whether an id exists. Authentication (401), role (403) and all three limiters still run first, so a guest gets 401, a wrong role 403, and a HEAD request COUNTS against `invoice-pdf` and `invoice-pdf-ip` (a client cannot probe for free). Role 403 always comes before 429.
- Limiters on this route: `api` (60/min per user), `invoice-pdf` (20/min per user id) and `invoice-pdf-ip` (60/min per client IP).

### POST /api/v1/orders/{number}/invoice
- Purpose: create the invoice for an order. The server is the only source of lines, prices, rate, tax, totals, number, issue date, seller and buyer.
- Auth: `auth:sanctum` + role `staff` or `admin`. Limiters: `api` and `invoices-write`. The `{number}` is the order number (global pattern; malformed is a plain 404 for every role).
- Scope: staff are cross-company; the invoice gets `company_id` of the ORDER, server-side.
- Request body: optional `{ "due_date": "2026-12-31" }`; `{}` or no body is valid. `due_date` is read from the BODY only (a query-string `due_date` is ignored, see "Body only"). `due_date`: absent or `null` means the default (`issue_date + 30` days, `config('invoicing.payment_terms_days')`); otherwise a real date `YYYY-MM-DD` with `today (UTC) <= due_date <= today + 120 days` (`config('invoicing.max_due_days')`); anything else (datetime, array, number, an impossible date, out of range) is 422 `due_date`. Validated before the order lookup, and re-checked inside the transaction against the actual issue date. Every other field is ignored (see mass assignment).
- Behaviour (one DB transaction): lock the order row; the order status must be `confirmed`, `shipped` or `delivered` (`config('invoicing.eligible_order_statuses')`); the order must have no non-void invoice; the order must have `tax_cents = shipping_cents = 0` and at least one line; the company must have a billing address; invoicing must be configured (design doc 2.2). Then copy the order lines (and their snapshot values) into `invoice_items`, snapshot seller (config) and buyer (company), allocate the next gap-free number under the counter row lock (as late as possible), set `issue_date` = today (UTC), compute tax and total with the half-up rule, insert the invoice, the items and the first history row (`null -> open`, actor = the user). All or nothing: a failure rolls back and consumes no number. No email.
- Response 201: `{ "data": <InvoiceDetailResource> }` (`status: "open"`, `history` has one entry, `allowed_actions: ["download","mark_paid","void"]`).
- Errors:
  - 401 guest. 403 customer (even for an unknown well-formed number). 419 CSRF. 429 (`api` or `invoices-write`).
  - 404 unknown or malformed order number.
  - 422 `due_date`; 422 `body` (malformed JSON); 422 `order`: order status not eligible ("Only confirmed, shipped or delivered orders can be invoiced."), order already has an invoice ("This order already has an invoice."), order has tax or shipping, order has no lines; 422 `company`: the order's company has no billing address, or its billing address has more than 6 lines; 422 `invoice` ("Invoicing is not configured."): invalid VAT rate, payment terms or seller basics in ANY environment, or the SELLER GUARD (below) failing in any environment except `local` and `testing` (production AND staging and every other name). Nothing is created and no number is consumed on any 422.
  - Seller guard (every environment except `local` and `testing`): issuing is refused when (a) any of seller `name`, each `address_lines` entry, `tax_id`, `email`, `bank.iban`, `bank.bic`, `bank.account_holder`, `bank.bank_name`, `footer`, `payment_terms_text` or `phone` contains `PLACEHOLDER` (case-insensitive, after trimming); (b) `email` ends in `.invalid` (case-insensitive); or (c) a required field is empty after trimming: required are `name`, `tax_id`, `email`, `footer`, `payment_terms_text`, `bank.iban`, `bank.bic`, `bank.account_holder` and at least one non-blank address line (`phone` and `bank.bank_name` may be empty but must not contain the placeholder). A VAT rate of 0 is ALLOWED there (it can be legitimate) but every issue in those environments logs one warning. The basic checks (rate 0 to 5000 integer, terms 0 to 120, name present, address limits and sizes) apply in EVERY environment. The guard runs inside the transaction before the number is allocated, so nothing is written and no number is burned.
- Concurrency: two simultaneous requests for one order are serialised by the order row lock: one 201, the other 422 `order`. Parallel requests for different orders get distinct consecutive numbers.

### POST /api/v1/invoices/{invoice}/mark-paid
- Auth: `staff`, `admin`. Limiters: `api`, `invoices-write`. Scope: any invoice (staff are cross-company).
- Request body: optional `{ "paid_on": "2026-12-20" }`. `paid_on` is read from the BODY only (query string ignored). `paid_on`: absent or `null` = today (UTC); otherwise a real date `YYYY-MM-DD` with `issue_date <= paid_on <= today (UTC)` (422 `paid_on` otherwise; the lower bound is checked under the lock). Records full payment on that date; there are no partial payments and no amount. Other fields ignored.
- Rule: stored or effective `open` or `overdue` -> `paid`. Sets `status = paid`, `paid_on`, writes one history row (`from_status` = the stored status, actor = the user), under the invoice row lock.
- Response 200: `{ "data": <InvoiceDetailResource> }`.
- Errors: 401. 403 customer (before any lookup). 404 unknown or malformed id. 419. 422 `status` (already paid: "This invoice is already paid."; void: "Only open or overdue invoices can be marked as paid."), `paid_on`, `body`. 429. A wrong mark-paid cannot be undone through the API.

### POST /api/v1/invoices/{invoice}/void
- Auth: `staff`, `admin`. Limiters: `api`, `invoices-write`. Scope: any invoice.
- Request body: `{ "reason": "Wrong billing address" }`; `reason` required, string, 1 to 500 characters after trimming, text rules above (422 `reason`). Read from the BODY only: `?reason=x` without a body reason is 422 `reason`. Validated before the lookup.
- Rule: stored or effective `open` or `overdue` -> `void`. History row with `note = reason` (staff and admin viewers only). The number stays consumed and the invoice stays visible (status `void`) to the company and staff; the order can be invoiced again (the new invoice gets the next number). Credit notes do not exist: a PAID invoice cannot be voided.
- Response 200: `{ "data": <InvoiceDetailResource> }`.
- Errors: 401. 403 customer. 404 unknown or malformed id. 419. 422 `reason`, `status` (paid or already void: "Only open or overdue invoices can be voided."), `body`. 429.

### Invoice number allocation (not HTTP)

Format `INV-YYYY-NNNNNN` (`INV-2026-000123`): year of the UTC issue date, per-year counter starting at 1 on 1 January, zero padded to 6 digits (7 or more digits after 999,999). Numbers are allocated from the counter table inside the same transaction that inserts the invoice, so a rolled-back creation never consumes a number; they are sequential in issue order, unique, and never reused (void keeps its number). Invoices are never deleted.

### Overdue command (Artisan, not HTTP)

`php artisan invoices:mark-overdue`: sets every stored `open` invoice whose `due_date` is before today (UTC) to `overdue` with a system history entry (actor null); idempotent; scheduled hourly; sends no email. Never touches `paid` or `void`. The effective-status rules above make the API correct even if it has not run.

### Changes to the Phase 3 and Phase 4 contracts (made by this phase)

- `OrderDetailResource` (detail, create, cancel, status change; NOT the list `OrderResource`) gains two keys, positioned right after `quote_number` (the non-void invoice is eager-loaded in the shared order `detail()` helper, so the create, cancel and status-change responses carry `invoice` and `can_invoice` too, not only `GET /orders/{number}`): `invoice` (`{ "id": "<uuid>", "number": "INV-2026-000123", "status": "open" }`, the order's NON-VOID invoice with its effective status, or `null`) and `can_invoice` (boolean; `true` only for staff and admin when the order status is eligible and the order has no non-void invoice; always `false` for customers). Detail query bound grows by one query (10 to 11).
- `PATCH /orders/{number}/status` and `POST /orders/{number}/cancel` to `cancelled`: refused with 422 `status` ("This order has an invoice. Void the invoice first.") while the order has a non-void invoice. A voided invoice does not block cancellation.
- Database: `companies` gains nullable `billing_address` (text, at most 6 lines, 500 characters) and `tax_id` (varchar 32). Neither is exposed in any resource except through the invoice `buyer` snapshot; no endpoint edits them in Phase 5 (seeders and Phase 6).
- Order and quote prices are NET; invoice tax is added on top, computed from the order's snapshot lines (never copied from the order, whose `tax_cents` stays 0).

### Phase 5 endpoint summary

| Method | Path | Guest | Customer | Staff | Admin |
| --- | --- | --- | --- | --- | --- |
| GET | /api/v1/invoices | 401 | 200 (own company) | 200 (all) | 200 (all) |
| GET | /api/v1/invoices/{invoice} | 401 | 200 / 404 foreign | 200/404 | 200/404 |
| GET | /api/v1/invoices/{invoice}/pdf | 401 | 200 / 404 foreign / 429 | 200/404/429 | 200/404/429 |
| POST | /api/v1/orders/{number}/invoice | 401 | 403 | 201/404/422/429 | 201/404/422/429 |
| POST | /api/v1/invoices/{invoice}/mark-paid | 401 | 403 | 200/404/422/429 | 200/404/422/429 |
| POST | /api/v1/invoices/{invoice}/void | 401 | 403 | 200/404/422/429 | 200/404/422/429 |

(All also return 429 over the `api` limit. A customer on a staff action gets 403 before any lookup; a malformed `{invoice}` or `{number}` is a plain 404 for every role. `HEAD /api/v1/invoices/{invoice}/pdf` is 405 with `Allow: GET` for staff, admin and customer (guest 401). `PUT`/`PATCH`/`DELETE` on `/api/v1/invoices...` and `POST /api/v1/invoices` are not routes: 405. There is no endpoint to edit or delete an invoice.)

### Details decided by the backend (Phase 5)

Small points the contract left open; all are tested and binding:
- Mark-paid on a `void` invoice: 422 `status` "Only open or overdue invoices can be marked as paid." (already paid keeps "This invoice is already paid.").
- A company billing address with more than 6 lines: 422 `company` on create.
- `GET /invoices?status=` (empty value) is no filter; empty CSV items such as `open,,paid` are 422 `status`.
- `HEAD /invoices/{invoice}/pdf` is 405 with `Allow: GET`, checked before any lookup or render; auth, role and both PDF limiters run first and HEAD counts against them (this replaces the earlier "HEAD renders the PDF" decision).
- Order create, cancel and status-change responses carry `invoice` and `can_invoice` (shared `detail()` helper).
- Issuing with placeholder or incomplete seller data: 422 `invoice` in every environment except `local` and `testing` (seller guard, create endpoint above); this replaces the earlier "production only, seller name only" rule.
- `due_date`, `paid_on` and `reason` are read from the body only; query-string values are ignored (`ReadsBodyOnly`).
- Final states are also enforced by a database trigger (`paid` and `void` cannot change `status` or `paid_on`).
- PDF layout rules (binding, design doc 9.4 and 9.5): percentage widths on header cells; the last 4 item rows, the totals block and the payment block form one unsplittable row; footer text limited to 72% of the page width with `Page X of Y` in the right area; PO reference cleaned and wrapped in 20-character runs; bottom page margin 30 mm.
- Invoice numbers are `INV-YYYY-` plus the sequence zero padded to 6 digits; sequences of 1,000,000 and more are printed unpadded (7 to 9 digits).

### Known limitations (Phase 5, accepted)

- Layout trade-off: totals and payment are never orphaned on a trailing page (they stay with the last 4 item rows), but a SMALL invoice with a VOID banner can leave a mostly empty first page (accepted, cosmetic; content is complete).
- No credit notes, partial payments, payment reminders, invoice emails, multiple VAT rates, discounts, shipping lines or multi-currency. A wrong mark-paid is corrected outside the portal.
- The PDF is regenerated on every download from the snapshot (no stored copy); a template change alters how old invoices look.
- Right-to-left scripts are not shaped in the PDF; emoji and control/format characters are replaced or removed in the PDF only (the stored data and the JSON are untouched).
- Seller details, VAT rate and legal wording are placeholders until configured; creation is refused in every environment except `local` and `testing` while any seller value is a placeholder or a required one is empty (seller guard).
- Follow-ups for Phase 7 and 8 (design doc 15 and 16): app DB role without ownership, TRIGGER and TRUNCATE privileges on the invoice tables; optional PDF caching; LGPL notices if the app is distributed; legal review of wording, VAT rate and numbering format.

### Frontend behaviour bound to this contract
- Routes (inside `ProtectedRoute`): `/invoices` and `/invoices/:id` any logged-in role. Nav: "Invoices" for all roles. `/invoices` URL parameters are exactly the API names: `status` (CSV), `from`, `to`, `q`, `sort`, `page`; `per_page` and `company_id` are not in the URL; defaults omitted; invalid values dropped client-side.
- `/invoices/:id`: an `:id` that is not a lowercase UUID, or an API 404 (unknown OR foreign), shows the same "Invoice not found" page (no request for the malformed case). Every staff button comes from `allowed_actions`; hiding is a convenience, not security.
- Overdue highlighting never relies on colour alone: the badge text "Overdue", a warning icon with `aria-hidden`, and visible text "N days overdue" taken from `days_overdue` (the browser clock is not used).
- Money: `cents / 100` through `Intl.NumberFormat('en-US', { style: 'currency', currency })` with `currency` from the invoice body; the VAT rate is shown as `vat_rate_bp / 100` percent. Dates are shown as the `YYYY-MM-DD` strings from the API and never parsed with `new Date(string)` (timezone shift).
- Download: `fetch` with `credentials: 'same-origin'` and `Accept: application/pdf`, then a blob and a temporary `<a download>`; the saved name is the `Content-Disposition` filename if it matches `^INV-\d{4}-\d{6,9}\.pdf$`, else `<number>.pdf`. A non-`application/pdf` response is an error. 401 goes to the login redirect; 404 shows "Invoice not found"; 429 shows the Phase 1 "try again in N seconds" message; the button is disabled while pending.
- Staff dialogs: Create invoice (order detail, shown when `can_invoice`; optional `due_date`), Mark paid (`paid_on`, max today) and Void (required `reason`, warning that the number stays reserved). Requests contain only those fields. 422 `order`, `company`, `invoice`, `status`, `due_date`, `paid_on`, `reason` messages are shown in the dialog; `status` and `order` also refetch. All invoice text (names, addresses, PO reference, reason) is rendered as text nodes, never HTML.

---

## Phase 6 - Staff back office (`/admin`)

Decisions, schema, services, frontend, Playwright design, seeders and test plan: `docs/phase-6-design.md`. This section is the HTTP contract. All Phase 1 to 5 conventions apply unchanged (cookie auth, CSRF header on writes, `Accept: application/json`, exact 404 body, `TrimStrings` and NUL rules, `{ "data": ... }` wrapping, six-key `meta`, 429 body and `Retry-After`, global `QueryException` handling, role middleware before limiters) except where "Changes to earlier contracts" below says otherwise.

### Phase 6 rules (apply to every endpoint below)

- Auth: `auth:sanctum` plus the new `active` middleware (below). Guests get 401 on every endpoint.
- Two role tiers, enforced by role middleware BEFORE any limiter, binding, validation or lookup (a wrong role is 403 for every id, existing or not):

  | Tier | Middleware | Endpoints |
  | --- | --- | --- |
  | S (staff or admin) | `role:staff\|admin` | dashboard, products, imports, companies (read and billing edit), the orders/quotes/invoices endpoints that already exist |
  | A (admin only) | `role:admin` | users, invitations, company create and rename, audit log, `GET /admin/ping` |

  Customers get 403 on every `/admin` endpoint, staff get 403 on every tier-A endpoint.
- Existing staff endpoints are REUSED, not wrapped: order status (`PATCH /orders/{number}/status`), quote actions, invoice create, mark-paid and void keep their Phase 3 to 5 paths and bodies. The back office calls them directly. There is no `/admin/orders`, `/admin/quotes` or `/admin/invoices` API (the SPA has pages at those URLs, see Frontend).
- Company scoping: `/admin` endpoints are cross-company by design and reachable only by `staff` and `admin`, who have `company_id = null` and see every company. There is no customer path to any of them. Where a `company_id` appears (company filters, user assignment) it is an EXPLICIT admin or staff input, validated to exist; it is never taken from the session. Customer-facing endpoints are unchanged and still scope by the session user's `company_id`.
- Identifiers: `/admin` URLs use numeric ids for products, companies and users (route patterns `product`, `company`, `user` = `[0-9]{1,18}`, enforced by routing before any middleware: anything else is a plain 404) and a random lowercase UUID for imports (route pattern `import` = the UUID pattern of `attachment`). This is an accepted exception to the earlier "no numeric ids in URLs" rule: that rule protects customers from enumeration, and no customer can reach these routes. Customer-facing resources never gain a numeric id.
- Check order for `/admin` routes: route pattern (404) then authentication (401) then `active` (401) then role (403) then limiters (429) then path-model lookup (404) then body validation (422) then state and business rules under the row lock (409 or 422). Departure from Phases 3 to 5 (validation before lookup): on `/admin` the lookup comes first because implicit route-model binding is used and there is no foreign-resource oracle for staff and admin. Import tokens are looked up manually and scoped to the uploader (foreign = 404).
- Mass assignment: every endpoint reads only the fields documented for it. `role`, `company_id`, `active`, `session_epoch`, `invited_at`, `password`, `email_verified_at`, `version` (outside the endpoints that document it), prices outside the product endpoints, `status`, `number`, `created_at` and any other key are ignored when sent (not 422), EXCEPT the explicit admin actions that document them (`role` and `company_id` on `PUT /admin/users/{user}/assignment` and on invite, `company_id` on tier-A user and company endpoints). Models are never filled from `request()->all()`; services call `forceFill` with explicit server-built arrays.
- Optimistic concurrency (products and companies): every row has an integer `version` (starts at 1, incremented by a database trigger on EVERY update). `PUT` bodies must echo the `version` the client loaded. A stale version is `409`:
  ```json
  { "message": "This record was changed by someone else. Reload it and try again.", "errors": { "version": ["This record was changed by someone else. Reload it and try again."] } }
  ```
  A missing or non-integer `version` is 422 `version`. The check happens under a row lock after validation; the 409 never overwrites anything.
- Audit: every state change listed in "Audit log" below writes exactly one `audit_log` row in the SAME database transaction as the change, so a rolled-back change leaves no row. Customer-visible history tables are unchanged and remain the customer timeline.
- Input shape: every string field of every admin write endpoint answers an array, object, nested array, number, boolean or `null` (where the field is not nullable) with `422` keyed by that field, never `400` and never `500`. All requests read their input through `bodyInput()`, never through `$this->request->get()` (which throws `BadRequestException` on array values). On the apply endpoint the route binding (`{import}` owned by the caller) answers 404 BEFORE any validation.
- Text rules: product, company and user text fields reject `Cc` and `Cf` control/format characters (NUL, ESC, U+200B, bidi marks), invalid UTF-8 and interior NUL (422). `description` and `billing_address` allow LF (CRLF is normalised to LF) and tab only. The importer's formula-prefix rule (value must not start with `=`, `+`, `-`, `@`, tab or CR) applies to product `name` and `description` on create, update and import alike (one shared rule class).
- Money: integer cents, `0..1,000,000,000` per unit (Phase 2 cap). Dashboard sums can reach 1.5e15, exact as JSON integers below `Number.MAX_SAFE_INTEGER`.
- Dates: timestamps ISO 8601 UTC with `Z`; plain dates `YYYY-MM-DD` (UTC).
- Rate limits (all keyed by user id; the role 403 always comes first):

  | Limiter | Applies to | Limit |
  | --- | --- | --- |
  | `api` (changed) | every endpoint | 60 per 60 s for customers and guests (unchanged), 240 per 60 s for staff and admin (one shared bucket per user). The limiter reads the role stored in the session at login (not the role middleware, which does not run on catalog or `/me` routes); a role change bumps the session epoch, so the stored role cannot go stale |
  | `admin-writes` | every `POST`, `PUT`, `PATCH`, `DELETE` under `/admin` | 60 per 60 s, every attempt counts including 422 |
  | `admin-import` | `POST /admin/products/imports` and `.../apply` | 5 per 60 s and `admin-import-hourly` 20 per 3600 s, every attempt counts |
  | `admin-invites` | `POST /admin/users` and `POST /admin/users/{user}/invitation` | 20 per 3600 s |
  | `password-reset` (existing) | `POST /auth/accept-invitation` | 5 per 60 s per client IP |

- Lists: `page` integer 1 to 10000 (default 1), `per_page` integer 1 to 100 (default 25, 101 is 422), `sort` from a whitelist (default stated per endpoint), every ordering ends with the primary key ascending as tie-break, `q` trimmed max 64 with `%`, `_`, `\` literal. CSV parameters, array syntax, interior NUL and invalid UTF-8 follow Phase 2 and 3 (422 on the parameter name, never `status.0`). Response: `{ "data": [...], "meta": { six keys exactly as /orders } }`, page beyond last is 200 with `data: []`. Unknown parameters are ignored.
- Query counts (excluding the session user query): dashboard 4, product list 3, company list 3, user list 4, audit list 4, company detail at most 8, user detail 3. Constant in the number of rows (no N+1).
- 500 is never returned for bad input. A unique-constraint race on `part_number_search` is mapped to 422 `part_number` by the service.

### The `active` middleware and user states (new, applies to EVERY `auth:sanctum` route)

- `users` gain `active` (boolean), `session_epoch` (integer), `invited_at`, `invitation_accepted_at`, `invited_by_user_id`. States: `pending` (`invited_at` set, `invitation_accepted_at` null, `active` true), `inactive` (`active` false, includes a revoked invitation), `active` (everything else). Users created before Phase 6, by seeders and by factories are `active`.
- On every authenticated request the `active` middleware (after `auth:sanctum`, before role) answers `401 {"message":"Unauthenticated."}` and logs the session out when the user is `inactive` or `pending`, or when the session's stored `session_epoch` differs from the user's current `users.session_epoch` (a session that exists but has no stored value counts as different, so sessions created before this release die once at deploy). A request that has NO session at all skips the epoch comparison and only the active/pending check applies. The epoch is stored in the session at login and bumped by deactivation, reactivation, role or company assignment, accepting an invitation and a completed password reset (self-service `POST /auth/reset-password`, or a reset the admin triggered; the bump is in the same save as the password, so every old session dies on its next request and the user logs in again with the new password). Known limit: the epoch comparison is skipped when the request has no session (see above); if personal access tokens are ever added, the check must fail closed for requests without a session (Phase 7 follow-up). A deactivated user's open sessions therefore die on their next request, with any session driver.
- `POST /auth/logout` is `auth:sanctum` only (NO `active` middleware), so a session that is stale (deactivated user, old epoch) can still log out and gets 204 instead of 401. The route-audit matrix labels it `session`.
- Login: a user who is not `active` (deactivated or pending) gets exactly the wrong-credentials 422, after the same password hash check, and the failure counts toward the login limiters. `POST /auth/forgot-password` sends nothing for them (same 200 body). Pending and deactivated users cannot complete `reset-password` (their tokens are deleted on deactivation and the notification is not sent).

### AdminProductResource

```json
{
  "id": 17,
  "part_number": "6205-2RS",
  "name": "Deep groove ball bearing 6205-2RS 25x52x15",
  "description": null,
  "brand": "SKF",
  "category": { "id": 1, "slug": "deep-groove-ball", "name": "Deep groove ball bearings" },
  "inner_diameter_mm": 25,
  "outer_diameter_mm": 52,
  "width_mm": 15,
  "material": "Chrome steel",
  "seal_type": "2rs",
  "price_cents": 1250,
  "price_on_request": false,
  "stock_status": "in_stock",
  "active": true,
  "version": 3,
  "created_at": "2026-11-26T10:00:00Z",
  "updated_at": "2026-11-27T09:00:00Z"
}
```

Exactly these keys. The list item omits `description`. Number rules as Phase 2 (`*_mm` JSON numbers, `price_cents` integer or null).

### GET /api/v1/admin/products
- Auth: tier S. Scope: all products, active and inactive, for staff and admin.
- Query: `q` (normalised part-number substring, Phase 2 rules, a `q` that normalises to empty is an empty list), `active` (`true` or `false`; absent = both), `category` (CSV of slugs, unknown slug 422), `brand` (CSV, exact), `stock_status` (CSV enum), `sort` in `part_number`, `name`, `brand`, `price`, `updated_at` and their `-` variants (default `part_number`; `price` puts price-on-request last in both directions), `page`, `per_page`.
- Response 200: list of AdminProductResource (no `description`) plus `meta`.
- Errors: 401, 403 customer, 422 (parameter names), 429.

### POST /api/v1/admin/products
- Auth: tier S. Limiters: `api`, `admin-writes`.
- Body: `category_id` (required integer, must exist), `part_number` (required; trimmed, uppercased; 1 to 64 characters matching `^[A-Za-z0-9][A-Za-z0-9 ._/-]*$`; its normalised form must be non-empty and not used by any product, active or inactive), `name` (required, 1 to 255), `description` (optional string or null, max 5000), `brand` and `material` (required, 1 to 64, `^[\p{L}\p{N}][\p{L}\p{N} .&/+-]*$`), `inner_diameter_mm`, `outer_diameter_mm`, `width_mm` (required, JSON number or numeric string, max 2 decimals, above 0 and at most 5000; outer strictly greater than inner), `seal_type` (`open|2rs|zz`), `stock_status` (`in_stock|low_stock|out_of_stock|on_order`), `price_on_request` (required boolean), `price_cents` (integer 0 to 1,000,000,000; REQUIRED when `price_on_request` is false, must be `null` or absent when true), `active` (optional boolean, default true).
- Behaviour (one transaction): insert, audit `product.created`; after commit flush `catalog:filters` and `catalog:categories`.
- Response 201: `{ "data": AdminProductResource }`.
- Errors: 401, 403, 419, 422 (field names; `part_number` also for "This part number is already in use." including the unique-index race), 429.

### GET /api/v1/admin/products/{product}
- Auth: tier S. Response 200 `{ "data": AdminProductResource }` (inactive included). Errors: 401, 403, 404, 429.

### PUT /api/v1/admin/products/{product}
- Auth: tier S. Limiters `api`, `admin-writes`. Full replacement: the same fields and rules as POST (without `active`) plus required `version`. `active` is never accepted here (use activate/deactivate); `price_cents` and `price_on_request` follow the same XOR rule.
- Behaviour (one transaction): lock the row, compare `version` (409 on mismatch), write only if something changed, audit `product.updated` with before/after of the changed allowlisted fields (no row and no version bump when nothing changed, still 200), flush the catalog cache after commit. Snapshots on existing orders, quotes and invoices are never touched. Changing `part_number` is allowed (old documents keep the old number).
- Response 200: `{ "data": AdminProductResource }` with the new `version`.
- Errors: 401, 403, 404, 409, 422 (including `version`), 429.

### POST /api/v1/admin/products/{product}/activate and /deactivate
- Auth: tier S. Limiters `api`, `admin-writes`. No body (ignored).
- Behaviour: sets `active` true or false under the row lock; idempotent (already in that state is 200, no audit row, no version bump); audit `product.activated` or `product.deactivated`; cache flush after commit. A deactivated product disappears from every catalog endpoint at once (list, detail 404, filters, categories counts) and from cart add and quote request creation (422 "The selected product is not available."). Existing cart lines stay and show `unavailable_reason: "inactive"`; checkout refuses them; reorder skips them; old orders, quotes and invoices are unchanged. There is NO delete endpoint for products.
- Response 200: `{ "data": AdminProductResource }`. Errors: 401, 403, 404, 429.

### POST /api/v1/admin/products/imports (CSV preview, step 1)
- Auth: tier S. Limiters `api`, `admin-writes`, `admin-import`, `admin-import-hourly`.
- Request: `multipart/form-data`, one part `file` (CSRF header required, no manual `Content-Type`). Rules: extension `.csv`, at most 5 MiB (`admin.imports.max_bytes`), sniffed type `text/csv`, `text/plain` or `application/csv`, valid UTF-8 (BOM allowed) without NUL, at most 5,000 data rows (`admin.imports.max_rows`; larger files use `php artisan products:import`). Column spec, per-row validation and messages are exactly the Phase 2 importer (`docs/phase-2-design.md` section 6), implemented by one shared `ProductImporter` service used by the command and this endpoint.
- Behaviour: validates and classifies every row with NO product write, stores the file privately (disk `imports`, random name, outside the public root, never served), inserts a `product_imports` row, returns the preview. The preview expires after 1 hour.
- Response 201:
  ```json
  {
    "data": {
      "id": "3f2b8c1e-4d5a-4b7e-9c10-2a6f5e8d7b90",
      "file_name": "catalog.csv",
      "size_bytes": 123456,
      "status": "previewed",
      "expires_at": "2026-11-27T11:15:00Z",
      "rows_read": 200,
      "would_create": 150,
      "would_update": 47,
      "bad_rows": 3,
      "errors": [ { "line": 14, "messages": ["outer_diameter_mm: must be greater than inner_diameter_mm"] } ],
      "errors_truncated": false,
      "warnings": ["unknown column \"foo\" ignored"]
    }
  }
  ```
  `errors` lists at most 100 bad rows in file order (`line` is the 1-based physical line of the record start, header = line 1); `errors_truncated` is true when `bad_rows` is above 100. `file_name` is the sanitised display name (never used as a path).
- Errors: 401, 403, 413 (body above `post_max_size`), 419, 422 `file` (missing, not a file, wrong extension or content, too large, not UTF-8, header problems such as a missing required column, empty, more than the row limit), 429.

### POST /api/v1/admin/products/imports/{import}/apply (CSV apply, step 2)
- Auth: tier S. Same limiters as the preview. Scope: the import must belong to the calling user (another user's token, an unknown one and a malformed one are all 404; the route binding answers 404 BEFORE body validation).
- Body: optional `{ "skip_invalid": true }` (boolean, default false). When the preview had `bad_rows > 0` and `skip_invalid` is not true: 422 `skip_invalid`.
- Behaviour (one transaction, under a row lock on the import): status must be `previewed` and not expired (422 `import` "This import was already applied." / "This preview has expired. Upload the file again."), the stored file must still match its SHA-256 (else 422 `import`), the file is parsed again and the valid rows are upserted by normalised part number (idempotent, same rules as the command: nothing is ever deleted; `active` in the file is honoured ONLY when the CSV header has an `active` column: a file without that column never changes `active` of existing products, and inserts default to `active = true`), the import row becomes `applied`, audit `product.imported` with counts. After commit: flush the catalog cache, delete the stored file. A double click or a replay is therefore applied once: the second request is 422 `import`.
- Response 200: `{ "data": { "id": "...", "status": "applied", "rows_read": 200, "created": 150, "updated": 47, "bad_rows": 3 } }` (actual counts at apply time).
- Errors: 401, 403, 404, 419, 422 (`import`, `skip_invalid`), 429.

### CompanyAdminResource

```json
{
  "id": 1,
  "name": "Northwind Machinery Ltd",
  "tax_id": "NW-TAX-0001",
  "has_billing_address": true,
  "version": 2,
  "users_count": 2,
  "open_orders_count": 3,
  "open_quotes_count": 1,
  "unpaid_invoices_count": 2,
  "created_at": "2026-11-25T10:00:00Z"
}
```
Definitions (counts are live, effective statuses, one correlated subquery each): `open_orders_count` = orders `pending`, `confirmed` or `shipped`; `open_quotes_count` = quotes `submitted`, `in_review`, or `quoted` with `valid_until` today or later (UTC); `unpaid_invoices_count` = invoices that are effectively `open` or `overdue`; `users_count` = all users of the company (any state). The detail resource adds `billing_address` (string with LF line breaks, or null), `users` (at most 100: `id`, `name`, `email`, `role`, `status`) and `recent`: `orders` (5 newest: `number`, `status`, `total_cents`, `currency`, `created_at`), `quotes` (5 newest: `number`, `status` effective, `created_at`), `invoices` (5 newest: `id`, `number`, `status` effective, `total_cents`, `currency`, `due_date`). The staff view of quote status and amounts follows the existing staff visibility (no customer masking applies).

### GET /api/v1/admin/companies
- Auth: tier S. Query: `q` (name substring, case-insensitive), `sort` in `name`, `created_at` and `-` variants (default `name`), `page`, `per_page`. Response: list of CompanyAdminResource without `users` and `recent`, plus `meta`. Errors: 401, 403, 422, 429.

### GET /api/v1/admin/companies/{company}
- Auth: tier S. Response 200 `{ "data": CompanyAdminResource (detail form) }`. Errors: 401, 403, 404, 429.

### POST /api/v1/admin/companies
- Auth: tier A (admin only). Limiters `api`, `admin-writes`. Body: `name` (required, 1 to 255), `billing_address` (optional), `tax_id` (optional), rules below. Audit `company.created`. Response 201 detail form. Errors: 401, 403 (staff too), 419, 422, 429. Names are not unique (two companies may share a name; the id disambiguates).

### PUT /api/v1/admin/companies/{company}
- Auth: tier A. Body: `name`, `billing_address`, `tax_id`, `version` (all keys required, null allowed for the two optional ones). Audit `company.updated` (name and tax_id before/after, billing address as a changed flag). 409 on stale `version`. Response 200 detail form. Errors: 401, 403, 404, 409, 419, 422, 429.

### PUT /api/v1/admin/companies/{company}/billing
- Auth: tier S (staff may fix the data needed to issue an invoice, but cannot rename or create companies). Body: `billing_address` (string or null), `tax_id` (string or null), `version`. Same audit and 409 behaviour. Response 200 detail form. Errors as above.
- Rules: `billing_address`: CRLF becomes LF, each line trimmed, blank lines dropped, at most 6 lines, each at most 80 characters, at most 500 characters in total (documentation only: 6 lines of 80 plus 5 line breaks is at most 485 characters, so this limit is unreachable and has no separate error), no control or format characters; empty becomes `null`. `tax_id`: trimmed, empty becomes `null`, at most 32 characters, `^[A-Za-z0-9][A-Za-z0-9 ./-]*$`. Editing these NEVER changes an issued invoice (invoices hold their own `buyer` snapshot). A company cannot be deleted or deactivated.

### UserAdminResource

```json
{
  "id": 7,
  "name": "Dave Dealer",
  "email": "dave@northwind.test",
  "role": "customer",
  "company": { "id": 1, "name": "Northwind Machinery Ltd" },
  "status": "active",
  "invited_at": null,
  "invitation_accepted_at": null,
  "created_at": "2026-11-25T10:00:00Z",
  "allowed_actions": ["edit", "assign", "deactivate", "reset_password"]
}
```
Exactly these keys (never `password`, `remember_token`, `session_epoch`, tokens). `role` is `customer|staff|admin`; `company` is null for staff and admin; `status` is `pending|active|inactive`. `allowed_actions` is computed for the calling admin and the row: `edit` always; for the admin's own row only `edit`; otherwise `assign` always, `deactivate` and `reset_password` when `active`, `reactivate` when `inactive` and the invitation was accepted (or the user predates invitations), `resend_invitation` when the user was invited and has not accepted (pending or revoked), `revoke_invitation` when `pending`. The list does not pre-check the last-admin rule (it would be a query per row); the server refuses it with 422.

### GET /api/v1/admin/users
- Auth: tier A. Query: `q` (name or email substring, case-insensitive), `role` (CSV of roles), `status` (CSV of `pending,active,inactive`), `company_id` (integer), `sort` in `name`, `email`, `created_at` and `-` variants (default `name`), `page`, `per_page`. Response: list of UserAdminResource plus `meta`. Errors: 401, 403 (customer and staff), 422, 429.

### GET /api/v1/admin/users/{user}
- Auth: tier A. Response 200 `{ "data": UserAdminResource }`. Errors: 401, 403, 404, 429.

### POST /api/v1/admin/users (invite)
- Auth: tier A. Limiters `api`, `admin-writes`, `admin-invites`.
- Body: `name` (required, 1 to 255), `email` (required, valid, max 255, trimmed and lowercased; unique case-insensitively among ALL users), `role` (required, `customer|staff|admin`), `company_id` (integer, REQUIRED and must exist when `role` is `customer`; must be absent or `null` for `staff` and `admin`, otherwise 422 `company_id`).
- Behaviour (one transaction): create the user through `User::provision`-style code (forceFill, never mass assignment) with a random unusable password, `active` true, `invited_at` now, `invited_by_user_id` = the admin; create a single-use invitation token (see below); audit `user.invited`. After commit one queued, encrypted email `UserInvitationMail` (`ShouldBeEncrypted`, supported on queued mailables in the installed Laravel 12.74.0; a test confirms the `jobs` payload contains no `token=`; the mail text does NOT include the invited user's name, to rule out markdown injection) with the link `{APP_URL_WEB}/accept-invitation?token=<token>&email=<urlencoded email>` (never built from the request Host). A mail failure never fails the request.
- Response 201: `{ "data": UserAdminResource, "meta": { "email_queued": true } }` (`false` when queueing threw; the admin then uses resend).
- Errors: 401, 403, 419, 422 (`name`, `email` "This email is already in use.", `role`, `company_id`), 429.

### PATCH /api/v1/admin/users/{user}
- Auth: tier A. Body `{ "name": "..." }` only (1 to 255, text rules). Email is IMMUTABLE after creation (to fix a typo: revoke the invitation and invite the correct address). Audit `user.updated`. Response 200 UserAdminResource. Errors: 401, 403, 404, 419, 422, 429.

### PUT /api/v1/admin/users/{user}/assignment
- Auth: tier A. Body `{ "role": "staff", "company_id": null }` (both keys required; same role/company invariants as invite).
- Behaviour (one transaction; the last-admin lock is described below): refuses the admin's own row (422 `user` "You cannot change your own role or company."), refuses removing the admin role from the LAST active admin (422 `role` "At least one active administrator is required."). Both refusals come from the service (`UserPolicy` does not refuse the admin's own row). The last-admin rule is unreachable over HTTP for a single acting admin (the actor is never the target, so at least one other active admin exists); it is tested at service level, and the race between two admins is tested with real parallel processes.
- Last-admin lock (shared by `assign` and `deactivate`, the ONLY two operations that can reduce the number of active admins; reactivate, invitation accept, invite, revoke, resend, rename, password reset and company changes cannot): inside the transaction, first take ONE transaction-level advisory lock, `pg_advisory_xact_lock(UserAdmin::ADMIN_SET_LOCK)` with the constant `UserAdmin::ADMIN_SET_LOCK = 7206001`, then re-read the admin set in a SEPARATE statement (so the read sees everything committed before the lock was granted), then lock the target user row (`lockForUpdate`). Guarantee: under concurrency a role change or deactivation can never leave zero active admins; of two simultaneous mutual demotions or deactivations (A acts on B while B acts on A) exactly one succeeds and the other gets the 422. Applies role (single role, `syncRoles`) and `company_id`, bumps `session_epoch` (the user must sign in again), deletes the user's cart lines (carts belong to customers), audit `user.assignment_changed` with before/after `role` and `company_id`. A no-op (same role and company) is 200 without audit or epoch bump. Existing orders, quotes and invoices keep their company.
- Response 200 UserAdminResource. Errors: 401, 403, 404, 419, 422 (`role`, `company_id`, `user`), 429.

### POST /api/v1/admin/users/{user}/deactivate and /reactivate
- Auth: tier A. No body. Deactivate: sets `active` false, bumps `session_epoch` (all sessions die on the next request), deletes the user's password-reset and invitation tokens, audit `user.deactivated`. Refused for the admin's own row (422 `user`, from the service, not the policy), for the LAST active admin (422 `user`; same advisory lock and guarantee as in assignment above, so it also holds under concurrency), and for a `pending` user (use revoke). Reactivate: sets `active` true and bumps the epoch, audit `user.reactivated`; refused (422 `user`) for a user who was invited and never accepted (send a new invitation instead). Both are idempotent when already in the target state (200, no audit row).
- Response 200 UserAdminResource. Errors: 401, 403, 404, 419, 422 (`user`), 429.

### POST /api/v1/admin/users/{user}/password-reset
- Auth: tier A. No body. Sends the standard reset email to an `active` user (queued and encrypted, same link as forgot-password) without changing the password. When the user later completes the reset, `session_epoch` is bumped with the password (all old sessions die). Audit `user.password_reset_requested`. 422 `user` when the user is not `active`, or when a reset link was sent less than 60 seconds ago.
- Response 200 `{ "message": "A password reset email has been queued." }`. Errors: 401, 403, 404, 419, 422, 429.

### POST /api/v1/admin/users/{user}/invitation (resend) and DELETE the same path (revoke)
- Auth: tier A. Limiters as invite (resend) and `admin-writes` (revoke). Only for a user who was invited and has not accepted (422 `user` otherwise).
- Resend: sets `active` true (re-invites a revoked one), issues a NEW token (the old one stops working), queues the mail, sets `invited_at` now, audit `user.invitation_resent`; 422 `user` if the previous invitation was created less than 60 seconds ago. Response 200 `{ "data": UserAdminResource, "meta": { "email_queued": true } }`.
- Revoke: sets `active` false, deletes the token, bumps the epoch, audit `user.invitation_revoked`; the row stays (the email stays reserved; invite again with resend). Response 200 UserAdminResource.
- Errors: 401, 403, 404, 419, 422, 429.

### POST /api/v1/auth/accept-invitation (public)
- Auth: none (guests). Limiter: `password-reset` (5 per 60 s per IP).
- Body: `token`, `email`, `password` (min 10, max 255), `password_confirmation`. Behaviour: the invitation token is single use (also under concurrency: `App\Services\SingleUseToken::consume()` runs inside the broker callback in ONE transaction: lock the user row, re-check the token under the lock, delete it, then apply the change; parallel requests with the same token give exactly one 200 and the identical generic 422 for every other one), expires after `INVITATION_EXPIRE_MINUTES` (default 10080 = 7 days) and is stored hashed in `user_invitation_tokens`. Accept is serialised with revoke and deactivate: `pending` is re-checked under `lockForUpdate`, so a revoked or deactivated user can never complete an accept that raced the revoke. Expected failures are signalled by the dedicated exception `App\Exceptions\InvitationNotPending` and become the generic 422; any other failure (database error, audit insert failure) propagates as the generic 500 and rolls the whole transaction back (it is NOT swallowed into the generic 422). On success: sets the password, `invitation_accepted_at` and `email_verified_at` to now, bumps `session_epoch`, deletes the token, audit `user.invitation_accepted` (actor = that user). The user is NOT logged in; the SPA sends them to `/login`.
- Response 200: `{ "message": "Your account is ready. You can sign in now." }`.
- Errors: 422 validation (standard shape); 422 invalid, expired, used, revoked, wrong email, unknown email, deactivated user and non-pending user are ALL identical: `{ "message": "This invitation link is invalid or has expired.", "errors": { "email": ["This invitation link is invalid or has expired."] } }`; 419, 429. 401, 403, 404: not applicable.

### GET /api/v1/admin/dashboard
- Auth: tier S. No parameters. Scope: all companies. Not cached (live values; four aggregate queries).
- Response 200:
  ```json
  {
    "data": {
      "generated_at": "2026-11-27T10:15:00Z",
      "week_start": "2026-11-23",
      "currency": "USD",
      "quotes": { "open": 4, "awaiting_customer": 2, "expiring_soon": 1 },
      "orders": { "this_week": 12, "pending": 3 },
      "invoices": { "overdue_count": 2, "overdue_total_cents": 254848, "due_soon_count": 3, "due_soon_total_cents": 120000 },
      "products": { "inactive": 3 }
    }
  }
  ```
  Definitions (all UTC, `today` = `now()` date): `week_start` is the Monday of the current ISO week; `quotes.open` = quotes in `submitted` or `in_review`; `quotes.awaiting_customer` = `quoted` with `valid_until >= today`; `quotes.expiring_soon` = the subset of those with `valid_until <= today + 7 days`; `orders.this_week` = orders of ANY status created at or after `week_start 00:00:00Z`; `orders.pending` = status `pending`; `invoices.overdue_*` = EFFECTIVE `overdue` invoices (stored `overdue`, or stored `open` with `due_date < today`), count and sum of `total_cents`; `invoices.due_soon_*` = effective `open` with `today <= due_date <= today + 7 days`; `products.inactive` = `active = false`. `currency` is `config('catalog.currency')` (all invoices share it in v1).
- Errors: 401, 403 customer, 429.

### GET /api/v1/admin/audit-log
- Auth: tier A (staff get 403: the log also records staff behaviour and user management). Scope: everything.
- Query: `actor_id` (integer), `action` (CSV of vocabulary values, unknown value 422), `subject_type` (one of `order|quote|invoice|product|company|user|import`), `subject_id` (string 1 to 64, exact), `company_id` (integer), `from`, `to` (`YYYY-MM-DD` UTC, inclusive, `from` after `to` is 422 on `from`), `sort` in `occurred_at`, `-occurred_at` (default `-occurred_at`), `page`, `per_page` (default 25).
- Response 200: `{ "data": [AuditEntry], "meta": { six keys } }`.
  ```json
  {
    "id": 981,
    "occurred_at": "2026-11-27T10:15:00Z",
    "action": "order.status_changed",
    "actor": { "id": 2, "name": "Sam Staff", "role": "staff" },
    "subject": { "type": "order", "id": "ORD-K7Q2M-X9PA3", "label": "ORD-K7Q2M-X9PA3" },
    "company": { "id": 1, "name": "Northwind Machinery Ltd" },
    "changes": { "before": { "status": "pending" }, "after": { "status": "confirmed" } },
    "metadata": {}
  }
  ```
  `actor` is null for system rows; `actor.role` is the role at the time of the action (snapshot); `company` is null when not applicable; `changes` and `metadata` contain ONLY allowlisted keys (below), filtered again when read. No IP address or user agent is stored or returned. Rows cannot be edited or deleted through any endpoint.
- Errors: 401, 403, 422 (parameter names), 429.

### Audit log (what is written, by whom)

One row per change, written by `App\Services\AuditLog::record()` (model `App\Models\AuditLogEntry`) inside the transaction of the service that makes the change (not by model observers: observers miss `upsert`, double-fire on `touch`, and know no actor). `audit_log` is append-only (database trigger refuses UPDATE and DELETE). Actions (stable strings), where they are written and what `changes` may contain (allowlist; everything else is dropped before insert and again before output, values are scalars or null, strings cut at 200 characters):

| Action | Subject (`subject_type`, `subject_id`) | Written by | `changes` keys (before and after) | `metadata` keys |
| --- | --- | --- | --- | --- |
| `order.status_changed` | order, order number | `OrderStatusChanger::change` (status endpoint AND cancel endpoint, any actor) | `status` | none |
| `quote.reviewed`, `quote.sent`, `quote.reopened`, `quote.rejected`, `quote.accepted`, `quote.declined` | quote, quote number | `QuoteWorkflow::run` for every action that changes status (any actor) | `status`; `quote.sent` also `valid_until`, `total_cents`, `revision` | `order_number` (accepted) |
| `quote.priced` | quote, quote number | `QuotePricing` (draft price save) | `subtotal_cents`, `total_cents`, `valid_until` | `lines_changed` (integer) |
| `invoice.created` | invoice, invoice `id` (UUID) | `IssueInvoice` | after only: `status`, `total_cents`, `due_date` | `invoice_number`, `order_number` |
| `invoice.paid`, `invoice.voided` | invoice, invoice `id` | `InvoiceWorkflow::markPaid`, `void` | `status`, `paid_on` | `invoice_number` (the void reason is NEVER stored here) |
| `product.created`, `product.updated`, `product.activated`, `product.deactivated` | product, product id | `ProductAdmin` | `part_number`, `name`, `brand`, `category_id`, `inner_diameter_mm`, `outer_diameter_mm`, `width_mm`, `material`, `seal_type`, `price_cents`, `price_on_request`, `stock_status`, `active` | `description_changed` (boolean) |
| `product.imported` | import, import id | `ProductImportService::apply` | none | `rows_read`, `created`, `updated`, `bad_rows`, `file_name` (sanitised display name), `sha256`, `applied_rows`, `skipped_rows` |
| `company.created`, `company.updated` | company, company id | `CompanyAdmin` | `name`, `tax_id` | `billing_address_changed` (boolean) |
| `user.invited`, `user.invitation_resent`, `user.invitation_revoked`, `user.invitation_accepted`, `user.updated`, `user.assignment_changed`, `user.deactivated`, `user.reactivated`, `user.password_reset_requested` | user, user id | `UserAdmin`, `UserInvitations` | `name`, `role`, `company_id`, `active` | none |

Diff and no-change rules: for updates, keys whose value did not change are dropped from BOTH `before` and `after`. Example: `quote.sent` has `before` = `{status, revision}` only (`valid_until` and `total_cents` were null or unchanged, so they appear only in `after`). A call with no changes and no metadata writes no row, except events that carry no changes by nature, which are recorded with the `always` flag: `user.password_reset_requested`, `user.invitation_resent`, `user.invitation_accepted`.

Known gap (Phase 7 follow-up): a CSV import writes ONE `product.imported` row; it does NOT write per-product audit rows for the products it created or changed (that would be unbounded, up to `max_rows` rows per request). Individual price changes made by an import are therefore visible only in aggregate (counts, file name, SHA-256).

Never stored anywhere in the log: passwords, hashes, tokens, `remember_token`, `session_epoch`, email bodies, internal notes, staff notes, void reasons, customer notes, product descriptions, billing addresses, file CONTENTS, IP addresses. (The only file-related values are the sanitised display `file_name` and the `sha256` of `product.imported`.) `subject.label` is a display snapshot (order, quote or invoice number, part number, company name, user email). Not audited in v1: system jobs (`quotes:expire`, `invoices:mark-overdue`, `imports:prune`), seeders, customer order and quote creation, carts, attachments, quote notes, logins and logouts.

### Changes to earlier contracts (made by this phase)

- Phase 1: `/admin/ping` stays admin-only; `/staff/ping` stays staff or admin. Login for a deactivated or pending user is the wrong-credentials 422 (above). `GET /me` and every other authenticated endpoint answer 401 for a user who has just been deactivated or whose session epoch changed. Frontend: `/admin` now requires staff or admin (it was admin only); `/admin/users` and `/admin/audit-log` require admin; `/staff` redirects to `/admin`.
- Phase 1 limiter table: the shared `api` limiter allows 240 per minute (not 60) for staff and admin users; customers and guests keep 60.
- Phase 2: unchanged paths. `POST/PUT/PATCH/DELETE /api/v1/products...` stay 405 (writes live under `/admin/products`). Deactivation is the only way to retire a product. The importer is now a shared service; the `products:import` output and exit codes are unchanged, with one bug fix: an import whose CSV header has no `active` column never changes `active` on existing products (inserts default to true).
- Phase 3 to 5: no path, body or response change. Two ADDITIVE list filters, open to every role within its own scope: `GET /quotes?expiring_within_days=N` (integer 1 to 30; quotes whose stored status is `quoted` with `today <= valid_until <= today + N`) and `GET /invoices?due_within_days=N` (integer 0 to 30; effectively `open` invoices with `today <= due_date <= today + N`); invalid values are 422 on the parameter name. Status changes, quote actions and invoice actions now also write audit rows (table above); a failure to write the audit row fails and rolls back the change.
- Phase 3 amendment: staff and admin now have a status-change UI on the shared order detail page (the endpoint is unchanged).

### Phase 6 endpoint summary

| Method | Path | Guest | Customer | Staff | Admin |
| --- | --- | --- | --- | --- | --- |
| GET | /api/v1/admin/dashboard | 401 | 403 | 200 | 200 |
| GET, POST | /api/v1/admin/products | 401 | 403 | 200 / 201, 422 | same |
| GET, PUT | /api/v1/admin/products/{product} | 401 | 403 | 200 / 200, 404, 409, 422 | same |
| POST | /api/v1/admin/products/{product}/activate, /deactivate | 401 | 403 | 200, 404 | same |
| POST | /api/v1/admin/products/imports | 401 | 403 | 201, 413, 422, 429 | same |
| POST | /api/v1/admin/products/imports/{import}/apply | 401 | 403 | 200, 404, 422, 429 | same |
| GET | /api/v1/admin/companies, /admin/companies/{company} | 401 | 403 | 200, 404 | same |
| POST | /api/v1/admin/companies | 401 | 403 | 403 | 201, 422 |
| PUT | /api/v1/admin/companies/{company} | 401 | 403 | 403 | 200, 404, 409, 422 |
| PUT | /api/v1/admin/companies/{company}/billing | 401 | 403 | 200, 404, 409, 422 | same |
| GET, POST | /api/v1/admin/users | 401 | 403 | 403 | 200 / 201, 422 |
| GET, PATCH | /api/v1/admin/users/{user} | 401 | 403 | 403 | 200, 404, 422 |
| PUT | /api/v1/admin/users/{user}/assignment | 401 | 403 | 403 | 200, 404, 422 |
| POST | /api/v1/admin/users/{user}/deactivate, /reactivate, /password-reset | 401 | 403 | 403 | 200, 404, 422 |
| POST, DELETE | /api/v1/admin/users/{user}/invitation | 401 | 403 | 403 | 200, 404, 422 |
| GET | /api/v1/admin/audit-log | 401 | 403 | 403 | 200, 422 |
| GET | /api/v1/admin/ping | 401 | 403 | 403 | 200 |
| POST | /api/v1/auth/accept-invitation | 200, 422, 429 | same | same | same |

(All also return 429 over the limits above and 419 without the CSRF header on writes. A customer or staff user on a route above their tier gets 403 before any lookup, for existing and unknown ids alike; a malformed `{product}`, `{company}`, `{user}` or `{import}` is a plain 404 for every role. There is no delete endpoint for products, companies, users or audit rows.)

### Frontend behaviour bound to this contract

- Routes (inside `ProtectedRoute`): `/admin` (dashboard), `/admin/orders`, `/admin/orders/:number`, `/admin/quotes`, `/admin/quotes/:number`, `/admin/invoices`, `/admin/invoices/:id`, `/admin/products`, `/admin/products/new`, `/admin/products/import`, `/admin/products/:id`, `/admin/companies`, `/admin/companies/:id` require staff or admin; `/admin/users` (and `/admin/users/:id`) and `/admin/audit-log` require admin. Wrong role shows the 403 "Not allowed" page. Guest-only route `/accept-invitation` (reads `token` and `email` from the query string). `/staff` redirects to `/admin`.
- Token hygiene (accept-invitation AND the Phase 1 reset-password page): on load the page copies `token` and `email` into component state and immediately replaces the URL (`history.replaceState`/`navigate(..., { replace: true })` to the path without a query string), so neither value stays in the address bar or in the history entry. `web/index.html` carries `<meta name="referrer" content="origin">`. Phase 7/8: send `Referrer-Policy: origin` as a real response header in production (the meta tag is only a fallback). The policy must be `origin` (Referer carries the site address but never the path or query), NOT `no-referrer`: Sanctum recognises a browser request as a stateful session request from the `Origin` or `Referer` header, and a same-site GET sends only the Referer, so `no-referrer` makes every page load and reload look like a guest request (found by the Playwright suite).
- Back-office layout with its own navigation (label "Back office"): Dashboard, Orders, Quotes, Invoices, Products, Companies, then Users and Audit log for admin only. Hiding is UX only. The admin Orders, Quotes and Invoices pages are the existing list and detail components mounted under `/admin/...` with links pointing back into `/admin/...`; they use the existing endpoints and URL parameters. Staff and admin top navigation shows Catalog and Back office (the customer-facing Orders, Quotes and Invoices links are customer-only items from Phase 6; the old URLs keep working).
- Admin list URLs use exactly the API parameter names (`q`, filters, `sort`, `page`); `per_page` is not in the URL; defaults omitted; invalid values dropped client-side. Tables: `<caption>`, sortable headers are buttons with `aria-sort`, pagination is a labelled `nav`, result count in an `aria-live="polite"` region, loading skeleton, empty and error (with retry) states, 429 "try again in N seconds".
- Forms (React Hook Form + Zod) mirror the server rules and map 422 `errors` keys to fields; 409 shows a conflict banner with "Reload latest" (refetch and reset the form); destructive actions (deactivate, revoke, demote, apply import) use a confirm dialog; buttons are disabled while pending.
- Money: `cents / 100` through `Intl.NumberFormat('en-US', { style: 'currency', currency })`, currency from the body (dashboard `currency`). Price inputs accept `^\d{1,7}(\.\d{1,2})?$` up to 10,000,000.00 and are converted to integer cents. All user text (names, addresses, file names, error messages from CSV) is rendered as text nodes, never HTML.
- Dashboard cards link to the filtered lists: open quotes `/admin/quotes?status=submitted,in_review`, awaiting customer `/admin/quotes?status=quoted`, expiring soon `/admin/quotes?status=quoted&expiring_within_days=7`, pending orders `/admin/orders?status=pending`, orders this week `/admin/orders?from=<week_start>`, overdue invoices `/admin/invoices?status=overdue`, due soon `/admin/invoices?status=open&due_within_days=7`, inactive products `/admin/products?active=false`.
- E2E hooks (accessible names the Playwright tests rely on, so they are part of the contract): the order detail page has a region named "Order summary" containing the status text, an ordered list named "Status history" (one `listitem` per history entry, text includes the target status label), and, for staff and admin, one button per `allowed_transitions` entry named "Mark as confirmed", "Mark as shipped", "Mark as delivered" or "Cancel order", which opens a dialog whose confirm button is named "Confirm". The back-office navigation is a `nav` named "Back office". The audit page is a table with a caption "Audit log".

---

## Phase 7 - Security, performance and quality pass

Decisions, work packages and test plan: `docs/phase-7-design.md`. This section lists ONLY what changes API behaviour. All Phase 1 to 6 rules apply unchanged unless a bullet below says otherwise. Company scoping is unchanged: no Phase 7 change adds, widens or removes a company boundary; the one new public route returns no company data.

### Response headers (every response of the Laravel app)

Applies to every status code and path handled by Laravel (`/api/*`, `/sanctum/csrf-cookie`, `/up`), error responses and CORS preflights included. Set by a global middleware that wraps all others, so a 401, 404, 405, 413, 419, 422, 429 or 500 carries them too.

| Header | Value | Condition |
| --- | --- | --- |
| `X-Content-Type-Options` | `nosniff` | always |
| `Referrer-Policy` | `origin` | always (never `no-referrer`: see the Phase 6 token-hygiene note) |
| `X-Frame-Options` | `DENY` | always |
| `Content-Security-Policy` | `default-src 'none'; frame-ancestors 'none'` | only if the response has none (the attachment download and the invoice PDF download keep their own `default-src 'none'; sandbox`) |
| `Permissions-Policy` | `accelerometer=(), camera=(), geolocation=(), gyroscope=(), microphone=(), payment=(), usb=()` | always |
| `Cross-Origin-Resource-Policy` | `same-site` | always |
| `Cache-Control` | `no-store` | `api/*` and `sanctum/*` unless the response already contains `no-store` (attachments and PDF send `private, no-store`) |
| `Strict-Transport-Security` | `max-age=31536000` | only when the request is secure (after trusted-proxy resolution) and the production rules apply (every environment except `local`, `testing` and `loadtest`; `APP_ENV` is trimmed and compared case-insensitive, so `prod`, `live` and `Production` get it); no `includeSubDomains`, no `preload`; `PORTAL_HSTS_MAX_AGE=0` disables it |
| `X-Request-Id` | request id | always (below) |
| `X-Powered-By` | absent | always |

`X-XSS-Protection` is deliberately not sent.

### Request id

- The server accepts an inbound `X-Request-Id` only if it matches `^[A-Za-z0-9._-]{8,64}$`; otherwise it generates a UUIDv4. The id is returned in the `X-Request-Id` response header (CORS `Access-Control-Expose-Headers` includes it), is attached to every log record of the request and of jobs it queues, and is shown by the SPA on GENERIC error states only (500 and unknown failures, never on 401, 403, 404, 409 or 422) as ` Reference: <id>` appended to the message.
- Log records contain user id, never email or name (design doc 4.1). Exception messages are logged with email addresses masked as `[email]`; stack traces are logged as frames only (file:line and function, no arguments) with a capped depth; a database error logs only the SQLSTATE and the SQL with placeholders, never bound values.

### Hosts, proxies, CORS

- `TRUSTED_HOSTS` (comma list of host names ONLY: no scheme, port, path, wildcard, IPv6 literal or trailing dot; entries are lower-cased, empty tokens ignored). A request whose `Host` is not in the list is answered `400 {"message":"Bad Request."}` (the host value is never echoed). Required (non-empty) whenever the production rules apply. An invalid list throws `InvalidConfiguration` at boot in every environment (the message names the variable, never the value), except that `php artisan portal:check-config` still boots and reports `TRUSTED_HOSTS` (known small hack: it is detected by `portal:check-config` appearing in `$_SERVER['argv']`).
- `TRUSTED_PROXIES` (comma list of IPs or CIDRs, empty = trust nobody). `*` and any prefix shorter than /8 (IPv4) or /16 (IPv6) are refused at boot like any unparsable entry (`InvalidConfiguration`, message without the value). Only `X-Forwarded-For`, `X-Forwarded-Proto` and `X-Forwarded-Port` are honoured, and only from a listed peer. Every per-IP limiter and the IPv6 /64 key use the forwarded client address only in that case. Whenever the production rules apply an EMPTY list is UNSAFE (see "Production configuration") unless `TRUSTED_PROXIES_NONE=true` ("clients reach the app directly, no proxy"; honoured only as a real boolean true). Known limit: `TRUSTED_PROXIES_NONE=true` together with a non-empty `TRUSTED_PROXIES` is not flagged; the list wins.
- A boot-time `InvalidConfiguration` is written with `error_log()` (the logger does not exist yet), so the operator sees the real message.
- CORS is unchanged in shape: exact allowed origins from `CORS_ALLOWED_ORIGINS`, credentials allowed, methods and headers as today, plus `X-Request-Id` exposed. A foreign or `null` origin never receives `Access-Control-Allow-Origin`. Whenever the production rules apply (every environment except local, testing and loadtest) every allowed origin must be `https` and the SPA origin must be in the list (checked by `php artisan portal:check-config`).
- CSRF: unchanged (419 `{"message":"CSRF token mismatch."}` for a stateful browser without a valid `X-XSRF-TOKEN` on every non-GET route). A request from a non-stateful origin is a guest request.
- `GET /` (the framework welcome page on the API host) no longer exists: 404.

### Pagination window (every list endpoint)

Applies to `GET /products`, `GET /orders`, `GET /quotes`, `GET /invoices`, `GET /admin/products`, `GET /admin/companies`, `GET /admin/users`, `GET /admin/audit-log`.

- Rule: `page * per_page` must not exceed `10000` (config `PORTAL_MAX_WINDOW`). Otherwise `422` with the key `page`:
  ```json
  { "message": "Only the first 10,000 results can be paged through. Narrow the search with filters.", "errors": { "page": ["Only the first 10,000 results can be paged through. Narrow the search with filters."] } }
  ```
- The per-parameter caps stay (`page` at most 1,000,000 on products and 10,000 elsewhere, `per_page` 1 to 100). Inside the window, a page beyond `last_page` is still 200 with `data: []`. `meta.total` and `last_page` still report the real totals; the SPA disables "Next" at the window and shows the hint.
- Example: `per_page=24` allows pages 1 to 416; `per_page=100` allows pages 1 to 100.

### New and changed rate limits (keyed by user id except `health-ready`; role 403 before 429 unchanged; standard 429 body and `Retry-After`)

| Limiter | Applies to | Limit |
| --- | --- | --- |
| `catalog-search` (new) | `GET /products` | 40 per 60 s per user (`PORTAL_CATALOG_SEARCH_PER_MINUTE`), on top of `api` |
| `order-search` (new) | `GET /orders`, `GET /quotes` and `GET /invoices`, only when `q` is non-empty | 30 per 60 s for customers, 60 per 60 s for staff and admin, ONE shared bucket per user across the three lists (30 searches per minute IN TOTAL, not per list); requests with an empty or absent `q` are not limited and not counted (the limiter returns `Limit::none()`) |
| `orders-reorder` (new) | `POST /orders/{number}/reorder` | 10 per 60 s, every attempt counts |
| `quote-attachment-delete` (new) | `DELETE /quotes/{number}/attachments/{attachment}` | 30 per 60 s per user (customer deletes and staff purges share it) |
| `health-ready` (new) | `GET /health/ready` | 120 per 60 s per client IP, its own bucket; the route does NOT use the guest `api` throttle. Keyed by IP, not user (public route) |
| `orders-create` (extended) | also `POST /quotes/{number}/accept` | the existing 10 per 60 s, one shared bucket with `POST /orders`, every attempt counts |

`GET /products/filters`, `GET /products/{id}` and `GET /categories` are not covered by `catalog-search` (only `api`). Every other limiter is unchanged.

### POST /api/v1/orders: optional `expected_subtotal_cents`

- New optional body field `expected_subtotal_cents`: integer, 0 to 99,999,999,999,999. Absent or JSON `null` means no check (behaviour exactly as in Phase 3). The SPA always sends the subtotal of the cart it is displaying. A non-integer or negative value is `422 expected_subtotal_cents` (validated with `po_reference`, before the cart is read).
- Inside the existing transaction under the user lock, after the cart checks: if the value is present and differs from the freshly computed cart subtotal, nothing is created and the response is `409`:
  ```json
  {
    "message": "The prices in your cart changed. Review the cart and place the order again.",
    "errors": { "expected_subtotal_cents": ["The prices in your cart changed. Review the cart and place the order again."] },
    "cart": { "...CartResource, exactly the `data` object of GET /cart": "..." }
  }
  ```
- Order of outcomes: 401, 403, 429, 422 `po_reference`/`expected_subtotal_cents`, 422 `cart` (empty or unavailable lines, unchanged), 409, 201. The attempt counts toward `orders-create`. The cart is not changed by a 409. Limitation: two offsetting price changes that leave the subtotal equal are not detected; the order detail always shows the snapshot taken at placement.
- Frontend: always send the field; on 409 replace the cart data with `cart`, show the new (and old) subtotal in an alert, keep the PO reference the user typed, and require a new click (no automatic resubmit).

### Attachments: staff purge on any quote

- `DELETE /quotes/{number}/attachments/{attachment}`: customers keep the Phase 4 rule (only while `submitted` or `in_review`, only customer-uploaded files they can see, otherwise 422 `status` or 404; customers still get 422 on a quote that is not open). Staff and admin may now delete any attachment of a quote in ANY status (hard delete: row in the transaction, file after commit; a failed file removal is logged and counted by `quotes:storage-check`). 204, 404, 429 and 419 as before; no new 403.
- EVERY staff or admin purge writes one audit row in the same transaction, on an open quote and on a closed one (`accepted`, `declined`, effective `expired`, or any status outside `submitted`/`in_review`): action `quote.attachment_purged`, subject = the quote, `company_id` = the quote's company, `changes` empty, `metadata` = `{ "file_name": <sanitised display name>, "size_bytes": <int>, "sha256": <hex> }` (no content). A customer deleting their own file is NOT audited, as before. The audit action list and the audit viewer's `action` filter accept the new value.
- The staff UI shows a warning next to every `.csv` attachment ("CSV files can contain spreadsheet formulas. Open it in a text editor, or disable formulas and links in your spreadsheet."). Customers do not see it.

### Audit log additions

- `product.import_updated` (new action): one row per PRODUCT UPDATED by an applied CSV import whose diff of AUDITED fields is non-empty (written by `AuditLog::recordMany` in chunks of 500 inside the apply transaction). Unchanged rows, rows that change only non-audited fields (for example a description-only change) and CREATED products write no per-product row; created products appear only in the aggregate `product.imported` row, which stays. Subject = the product (`label` = part number), `company_id` null, `changes` = `before`/`after` of the changed fields only (same rules as `product.updated`, including price), `metadata` = `{ "import_id": <uuid>, "file_name": <sanitised> }`. This closes the Phase 6 "known gap" for HTTP imports (at most `ADMIN_IMPORT_MAX_ROWS` = 5,000 rows per import, so the write is bounded). The CLI `products:import` stays unaudited.
- `quote.attachment_purged` (above).
- Unchanged: never stored are passwords, tokens, IP addresses, file contents.

### Passwords

- `POST /auth/reset-password` and `POST /auth/accept-invitation`: the new password must also not appear in a known data breach (Have I Been Pwned range API, only the first five characters of the SHA-1 leave the server; the check is skipped when `AUTH_PASSWORD_BREACH_CHECK` is false, which is the default for `local` and `testing`). A breached password is `422` with the key `password` (message not contractual). If the service is unreachable, answers with an error status or is slower than 2 s the check is skipped (fail open) and the warning `Password breach check unavailable` is logged with a `reason` only (never the hash prefix, the hash or the password). Length rules are unchanged (min 10, max 255, confirmation).

### GET /api/v1/health/ready (new, public)

- Auth: none. Scope: none (no company data). Limited by its own `health-ready` bucket (120 per minute per IP); it does not share the guest `api` bucket (60 per minute per IP).
- Checks: one `SELECT 1` on the default database connection and a short cache read and write.
- Response 200 `{ "status": "ok" }`. Response 503 `{ "status": "unavailable" }`. The body never names the failing component, a version, a host or an exception; the reason (`Readiness check failed`, check name only) is logged with the request id, at most one line per failing check per minute. Queue workers and the scheduler are NOT part of readiness.
- `GET /api/v1/health` and `/up` are unchanged (liveness, no database).
- Route matrix: `GET api/v1/health/ready` is `public` (the public list is now exactly: `health`, `health/ready`, `auth/login`, `auth/forgot-password`, `auth/reset-password`, `auth/accept-invitation`).

### Session behaviour

- Fail-closed: a request with a user but no session is refused. Precisely, a request on which an authenticated user is resolved but that has no session is refused with `401 {"message":"Unauthenticated."}` (the epoch check now fails closed; Phase 6 follow-up). No legitimate client is affected because there is no token login.

### Production configuration (not HTTP behaviour, recorded here because the API refuses to serve without it)

The production rules apply in EVERY environment except `local`, `testing` and `loadtest` (`APP_ENV` is trimmed and compared case-insensitive, so `production`, `staging`, `prod`, `live` and `Production` are all covered; an unknown or empty name is production). While `php artisan portal:check-config` would fail, the application answers every HTTP request with `500 {"message":"Server Error"}` and writes the `critical` log line naming the failed rules (names, never values) at most once per distinct rule set per minute (`LogThrottle`: one `Cache::add` key with a 60 s TTL; if the cache is unavailable it fails open and logs). UNSAFE rules (error, exit 1): `APP_DEBUG` true, `APP_URL` not https, `APP_URL_WEB` missing, not https or localhost, `SESSION_SECURE_COOKIE` not true, `CORS_ALLOWED_ORIGINS` or `SANCTUM_STATEFUL_DOMAINS` containing `*`, `null`, a non-https origin, or missing the SPA origin, `TRUSTED_HOSTS` empty, `TRUSTED_PROXIES` empty while `TRUSTED_PROXIES_NONE` is not the boolean true, `LOG_LEVEL=debug`, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `MAIL_MAILER` `log` or `array`. WARNINGS (never block, fail only under `--strict`): `CACHE_STORE=file`, `QUEUE_CONNECTION=sync`, `INVOICING_SELLER_NAME` still a placeholder (invoice issuing keeps its own refusal, which exempts only `local` and `testing`, so `loadtest` is NOT exempt there). `portal:check-config --json` prints `{ environment, enforced, ok, errors, warnings }` (`TRUSTED_PROXIES` is listed in `errors`, no longer in `warnings`). Console commands are never blocked; the deploy step runs `portal:check-config`.

### Changes to earlier contracts (made by this phase)

- Phase 1: rate-limit table gains the limiters above; `GET /sanctum/csrf-cookie` and the other public routes now carry the header set above. The Phase 1 note "Origin or Referer decides statefulness" is unchanged, hence `Referrer-Policy: origin`.
- Phase 2: `page` is additionally bounded by the window; `GET /products` is under `catalog-search`.
- Phase 3: `POST /orders` accepts `expected_subtotal_cents` (409); `GET /orders` with `q` is under `order-search` (shared with `GET /quotes` and `GET /invoices`, see the rate-limit table); reorder has its own limiter; the "unindexed PO search" performance note stays until the EXPLAIN review decides on a trigram index.
- Phase 4: `GET /quotes` with `q` is under the shared `order-search` bucket; `DELETE` of an attachment is under `quote-attachment-delete`; staff purge (above, always audited); the mail layout header link now points at `app.web_url` (no API origin appears in any email); `POST /quotes/{number}/accept` joins `orders-create`.
- Phase 5: `GET /invoices` with `q` is under the shared `order-search` bucket.
- Phase 6: the "known gap" of per-product import audit rows is closed for HTTP imports; the epoch check fails closed.

### Phase 7 endpoint summary (additions only)

| Method | Path | Guest | Customer | Staff | Admin |
| --- | --- | --- | --- | --- | --- |
| GET | /api/v1/health/ready | 200, 503, 429 | same | same | same |
| POST | /api/v1/orders | 401 | 201, 409, 422, 429 | 403 | 403 |
| DELETE | /api/v1/quotes/{number}/attachments/{attachment} | 401 | 204, 404, 422, 429 | 204, 404, 429 (any quote status, always audited) | same |
| GET | / (API host) | 404 | 404 | 404 | 404 |
