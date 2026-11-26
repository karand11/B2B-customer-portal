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
