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
  404 (unknown route or resource): the message text is non-contractual (Laravel's default); only the status code is contractual.
  ```json
  { "message": "Not Found." }
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
