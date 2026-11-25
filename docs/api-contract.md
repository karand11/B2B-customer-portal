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
- Errors: none expected. Any unhandled failure returns 500 with no stack trace (APP_DEBUG=false in production).

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
- Unknown emails must not return noticeably faster than known ones: use Laravel's `Password::sendResetLink` and ignore its status; do not add an existence check with an early return. The reset notification is queued (`ShouldQueue`, database queue) so SMTP time never shows in the response time. Local dev needs `php artisan queue:listen` running (part of `composer dev`); tests use the sync queue.
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
- Response 200: `{ "message": "Your password has been reset." }`. The user is NOT logged in by this call; SPA sends them to /login. The token is deleted (single use). Other tokens for that email are gone too. The user's other sessions are invalidated (Sanctum `AuthenticateSession`): an old session cookie from before the reset gets 401 on `GET /api/v1/me`.
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
  "company": { "id": 1, "name": "Northwind Machinery Ltd" },
  "placed_by": { "name": "Alice Customer" },
  "created_at": "2026-11-25T10:15:00Z",
  "updated_at": "2026-11-25T10:15:00Z"
}
```

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

- Array syntax (`status[]=x`), an INTERIOR NUL (`q=a%00b`) and invalid UTF-8 are 422 on that parameter. Leading or trailing NUL/control characters are stripped by `TrimStrings` first, so `q=%00`, `q=abc%00` and `status=%00` are treated as empty or trimmed (200, no filter for that parameter), never as an error. Unknown parameters are ignored.
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
  3. The raw value must be valid UTF-8 and contain no NUL byte (`\0`); otherwise 422. This check runs BEFORE trimming (PHP `trim()` would strip a trailing NUL and hide it).
  4. Trimmed of leading and trailing whitespace. A result of `""` (empty or whitespace-only) is stored as `NULL`, not as an empty string.
  5. At most 64 characters after trimming (65 is 422).
  6. Must match `^[A-Za-z0-9][A-Za-z0-9 ._/#-]*$` (anchored with `\z`/`D`, so a trailing newline cannot slip through): it starts with an ASCII letter or digit and contains only ASCII letters, digits, space (U+0020) and the five characters `.` `-` `_` `/` `#`. Anything else is 422: a leading `-`, `=`, `+`, `@`, `#`, `.`, `_` or `/`; and any of `%`, quotes, `<`, `>`, `&`, `;`, `,`, tabs, newlines, non-ASCII letters, emoji, control characters anywhere. The leading-character rule also keeps future CSV/XLSX exports safe from formula injection (`=`, `+`, `-`, `@`, tab, CR cannot start the value).
  - Stored value is the trimmed string, case preserved, internal spacing unchanged.
  - Validated before the cart is read: a bad `po_reference` is 422 `po_reference` even when the cart is also empty or invalid (then `errors` contains only `po_reference`), and nothing is written. Role middleware (403) still runs first. The attempt counts toward `orders-create`.
  - Immutable: no endpoint changes `po_reference` after creation in Phase 3 (cancel, status, reorder ignore it if sent). Reorder does not copy it into the cart.
  - It is customer-supplied data and is never used for any decision (authorization, scoping, pricing, status). It is returned as plain text and must be rendered as text, never HTML.
- Behaviour (one DB transaction): lock the user's row (serialises this user's cart and checkout actions), read the cart with the products, validate every line, snapshot each line from the current product row, compute `line_total = unit_price * quantity`, `subtotal = sum`, `tax = 0`, `shipping = 0`, `total = subtotal`, insert order (`status = pending`), items and the first history entry (`null -> pending`, actor = user), delete the user's cart lines. All or nothing.
- Double submit and concurrency: two simultaneous requests are serialised by the lock; the first creates the order and empties the cart, the second finds an empty cart and returns 422 `cart`. At most one order is ever created per cart content. No `Idempotency-Key` header in Phase 3.
- Response 201: `{ "data": <OrderDetailResource> }` (status `pending`, `history` has one entry, `po_reference` is the stored value or `null`). The cart is empty afterwards.
- Errors:
  - 401 guest. 403 staff, admin, or a customer with no company. 429 (`api` or `orders-create`).
  - 422 `po_reference` (array with one message): not a string, NUL byte or invalid UTF-8, longer than 64 characters after trimming, bad leading character, or a disallowed character (see rules above). Nothing is created and the cart is NOT changed. Invalid UTF-8 can only reach validation in a form-encoded body (`po_reference=%FF`); a JSON body that is not valid JSON is not decoded by the framework and is treated as having no fields, which is not a contract case.
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
- Checkout has an optional "PO reference" text input (`maxLength` 64, label, hint "Optional. Letters, digits, spaces and . - _ / #"). Client-side Zod validation mirrors the server rules (trim; empty is omitted from the body; max 64; leading letter or digit; allowed characters), invalid blocks the request and shows an inline `role="alert"` error linked with `aria-describedby`/`aria-invalid`; a server 422 `errors.po_reference` is shown on the same field. The body is `{}` when empty, else `{ "po_reference": "<trimmed>" }`. `po_reference` is shown as plain text (a React text node, never `dangerouslySetInnerHTML`) in a "PO reference" column of the history table (an em dash when null) and on the order detail page (row hidden or "None" when null). The `/orders` search input label becomes "Order number or PO reference".
- 422 on `POST /orders` with `errors.cart`: show the messages, refetch the cart, stay on the checkout page. 429: Phase 1 "try again in N seconds" message.
