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
- Numbers: `*_mm` are JSON numbers (max 2 decimals), `price_cents` is an integer or `null`, never a string. Money is integer minor units; the currency is not on the product (see `/products/filters`).

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

Rules: exactly these keys (no `active`, `description`, `part_number_search`, `category_id`, timestamps). `price_on_request = true` implies `price_cents = null`; `price_on_request = false` implies `price_cents` is an integer >= 0. `seal_type` is one of `open|2rs|zz`. `stock_status` is one of `in_stock|low_stock|out_of_stock|on_order`. `ProductDetailResource` (detail endpoint) is the same plus `"description": string|null`.

### GET /api/v1/products
- Auth: `auth:sanctum`, any role. Scope: none (shared catalog, see Phase 2 rules).
- Request: query string only.

| Param | Type and rule | Meaning |
| --- | --- | --- |
| `q` | string, trimmed, max 64. Empty or absent means no search. | Part-number search, see below |
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
  - 422 standard shape; the `errors` key is always the query parameter name (for example `sort`, `brand`, `outer_diameter_min`, `per_page`), never `brand.0`. Triggers: unknown `sort`, enum value not allowed, unknown category slug, malformed or out-of-range number, min greater than max, `per_page` outside 1..100, `page` below 1, `q` longer than 64, array syntax, a value with an embedded NUL byte (for example `brand=S%00KF`) or invalid UTF-8 (`%FF`). A lone leading or trailing `%00` (for example `brand=%00`) is trimmed by Laravel's `TrimStrings` and treated as empty: 200, same as the parameter absent.
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
