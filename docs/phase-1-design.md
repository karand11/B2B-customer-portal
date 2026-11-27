# Phase 1 design - Authentication and roles

HTTP contract: `docs/api-contract.md` (Phase 1 section). This file holds schema, config, seeders, dependencies, CI/test-DB changes and the test plan. Written by the architect; backend, frontend and QA agents build from both files.

## 1. Dependencies this phase adds (user must approve, per CLAUDE.md)

| Package | Where | Why |
| --- | --- | --- |
| `laravel/sanctum` ^4 | api (composer) | SPA cookie auth (`auth:sanctum`, stateful middleware, `/sanctum/csrf-cookie`). Already named in CLAUDE.md. |
| `spatie/laravel-permission` latest stable major that supports Laravel 12 and PHP 8.3 (^6.x at time of writing) | api (composer) | Roles customer/staff/admin. Already named in CLAUDE.md. |
| `react-router-dom` | web (npm) | Routing and route guards. In the CLAUDE.md stack, not yet installed. |
| `react-hook-form`, `zod`, `@hookform/resolvers` | web (npm) | Forms and validation. In the CLAUDE.md stack, not yet installed. |

Not added: axios (use a ~40 line `fetch` wrapper that handles cookies, XSRF header, 401/419/429), any mail package, any rate-limit package, `personal_access_tokens` table (Sanctum's token migration is NOT published or run; SPA cookies only).
`@tanstack/react-query` is already installed.

## 2. Role model

- Three roles in spatie `roles` with `guard_name = 'web'`: `customer`, `staff`, `admin`. Exactly one role per user (enforced in seeders, factories and the future invite flow; tested).
- Spatie permissions are NOT used in Phase 1 (no rows in `permissions`). Guards are role checks only: middleware `role:staff|admin` and `role:admin`, aliased in `bootstrap/app.php`. Fine-grained permissions can be added later without schema change.
- Spatie "teams" mode is OFF. `company_id` is not a spatie team, because staff/admin are cross-company.
- `company_id` rule:
  - `customer`: `company_id` NOT NULL (belongs to one company).
  - `staff`, `admin`: `company_id` NULL. Reason: they are the portal operator's own employees, not tenants; they see all companies, so a company binding would be misleading and would invite accidental scoping bugs. Customer queries are scoped with `where company_id = auth user's company_id`; staff code paths are separate and gated by role.
  - This invariant cannot be a DB CHECK (role lives in another table). It is enforced by: factories/seeders, a `User` model helper used by the future invite flow, and a Pest test that asserts it for every seeded user.
- Mass assignment: `User::$fillable = ['name', 'email', 'password']`. `company_id` and role are never fillable and never read from request input. Seeders assign them explicitly.
- Policies: Phase 1 has no company-owned resources, so there is nothing for a Policy to protect yet. Authorization is role middleware. The first `Policy` (e.g. `OrderPolicy`) arrives in Phase 3. PLAN.md lists "policies" under Phase 1; this is a deliberate deviation (see open questions).

## 3. Database

Existing from the skeleton (migration `0001_01_01_000000_create_users_table.php`, do not edit it): `users`, `password_reset_tokens`, `sessions`, `cache`, `jobs`. Phase 1 adds new migrations only (never edit an applied one).

### 3.1 `companies` (new migration)

| Column | Type | Constraints |
| --- | --- | --- |
| id | bigint, identity PK | |
| name | varchar(255) | NOT NULL |
| created_at, updated_at | timestamp | nullable (Laravel default) |

Indexes: PK; `companies_name_index` (btree on `name`, for Phase 6 search/sort).
Relation: `Company hasMany User`. No soft delete in Phase 1.

### 3.2 `users` (alter migration, new file)

Existing: `id`, `name` varchar(255), `email` varchar(255) unique, `email_verified_at` timestamp null, `password` varchar(255), `remember_token`, timestamps.

Add:

| Column | Type | Constraints |
| --- | --- | --- |
| company_id | bigint | NULL; FK -> `companies.id` ON DELETE RESTRICT (a company with users cannot be deleted) |

Indexes: `users_company_id_index` (btree on `company_id`; every customer query filters by it). Existing unique index on `email` stays. Emails are lowercased and trimmed by an attribute mutator and by request normalisation, so the case-sensitive unique index behaves case-insensitively.
Relations: `User belongsTo Company` (nullable). Role via spatie `HasRoles`.
Not added: `is_active`, `phone`, `last_login_at` (not needed yet; Phase 6 may add `is_active`).

### 3.3 Spatie tables (published migration, teams = false, defaults)

`permissions` (id, name, guard_name, timestamps, unique(name, guard_name)); `roles` (id, name, guard_name, timestamps, unique(name, guard_name)); `model_has_permissions` (permission_id, model_type, model_id, PK of the three, index on model_id+model_type); `model_has_roles` (role_id, model_type, model_id, same PK and index); `role_has_permissions` (permission_id, role_id, PK of both). All FKs cascade on delete. Use the package's own published migration unchanged. Config `permission.php` defaults, `teams => false`.

### 3.4 `password_reset_tokens` (exists, unchanged)

`email` varchar PK, `token` varchar (stored HASHED by Laravel), `created_at` timestamp null. One row per email, so a new request replaces the previous token. Token lifetime 60 minutes (`auth.passwords.users.expire = 60`), per-user resend throttle 60 s (`throttle = 60`). Row is deleted on successful reset (this is what makes the token single use). Phase 6 made this hold under concurrency: `App\Services\SingleUseToken::consume()` runs inside the broker callback in one transaction (lock the user row, re-check the token under the lock, delete it, then change the password and bump `session_epoch`), so parallel requests with the same token give exactly one 200 and the identical generic 422 for the rest. The queued `ResetPasswordNotification` is `ShouldBeEncrypted` (no token in the `jobs` payload).

### 3.5 `sessions`, `personal_access_tokens`

`sessions` exists but local dev uses `SESSION_DRIVER=file` (as in `.env.example`); tests use `array`. Production session store (database or redis) is decided in Phase 8. `personal_access_tokens`: not created (see section 1).

## 4. Configuration

`.env.example` changes (keep it current, no secrets):

```
APP_URL=http://localhost:8000
APP_URL_WEB=http://localhost:5173
SANCTUM_STATEFUL_DOMAINS=localhost:5173
SESSION_DRIVER=file
SESSION_LIFETIME=120
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=false
SESSION_SAME_SITE=lax
SESSION_HTTP_ONLY=true
CORS_ALLOWED_ORIGINS=http://localhost:5173
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=1025
MAIL_FROM_ADDRESS="no-reply@portal.test"
```

(`APP_URL` was `http://localhost`; it must become `http://localhost:8000` so generated URLs and Sanctum are right. `config/app.php` gets `'web_url' => env('APP_URL_WEB')`.)

| Item | Value | Why |
| --- | --- | --- |
| `bootstrap/app.php` | `$middleware->statefulApi();` and `alias(['role' => RoleMiddleware::class])` | Adds Sanctum's `EnsureFrontendRequestsAreStateful` to the api group so cookie/session/CSRF run for SPA requests. |
| API JSON rendering | `$exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*', 'sanctum/*') \|\| $request->expectsJson())` | Guests get 401 JSON, never a redirect to a non-existent `login` route. |
| `auth:sanctum` guard | `config/sanctum.php` `guard => ['web']`, `stateful` from `SANCTUM_STATEFUL_DOMAINS` (`localhost:5173`) | Session-cookie auth for the SPA. Do not list `localhost:8000`. Use `localhost`, not `127.0.0.1`, in the browser. |
| Session cookie | `HttpOnly`, `SameSite=Lax`, `Secure=false` locally and `true` in production, domain null (host-only), `SESSION_LIFETIME=120` min | `localhost:5173` and `localhost:8000` are same-site, so Lax is enough. Note: cookies do not isolate by port on `localhost`; harmless here. |
| Vite proxy (`web/vite.config.ts`) | proxy both `/api` and `/sanctum` to `http://localhost:8000`, no `changeOrigin` | Currently only `/api` is proxied; `/sanctum/csrf-cookie` would 404 on the Vite server. Frontend agent must add it. Leaving `changeOrigin` off keeps Host/Origin as `localhost:5173`, which is in the stateful list. |
| `config/cors.php` (publish with `php artisan config:publish cors`) | `paths = ['api/*', 'sanctum/csrf-cookie']`; `allowed_origins = [env CORS_ALLOWED_ORIGINS split on commas]` (exactly `http://localhost:5173`); `allowed_origins_patterns` = exact-match regexes of the allowed origins (e.g. `#^http://localhost:5173$#`, one per allowed origin); `allowed_methods = ['GET','POST','PUT','PATCH','DELETE','OPTIONS']`; `allowed_headers = ['Accept','Content-Type','X-Requested-With','X-XSRF-TOKEN']`; `exposed_headers = ['Retry-After']`; `max_age = 600`; `supports_credentials = true` | `*` is forbidden with credentials. With the Vite proxy the browser sends same-origin requests and CORS is not exercised in dev, but it must be correct for a direct :8000 call and for production (Phase 7 locks it to the real origin). Why the patterns are not empty: with a single allowed origin and no patterns the CORS library answers every origin with `Access-Control-Allow-Origin`; the exact-match pattern list is what makes unlisted origins (e.g. `http://evil.example`) get no header (test 22). |
| Password rules | min 10, max 255, confirmed (reset only). `BCRYPT_ROUNDS=12` (4 in tests, already in phpunit.xml) | |
| Login limiter | `RateLimiter` keyed `lower(email)\|ip`, 5 failures / 60 s, checked before any credential check; plus an email-only limiter keyed `lower(trim(email))`, 20 failures / 900 s, any IP, not cleared by a successful login; plus `throttle:login-ip` named limiter 20/min by IP. Order: 419 check, validation, email+IP limiter, email-only limiter, credentials. Every per-IP key (api, login-ip, password-reset, email+IP) uses one IP key: IPv6 reduced to its /64 prefix, IPv4 unchanged, IPv4-mapped IPv6 treated as IPv4. | See contract table. Trade-off: 20 failures from anywhere can lock a victim's email out for up to 15 minutes (accepted by the user to stop distributed guessing). |
| Global throttling | `$middleware->throttleApi()` plus `throttle:api` appended to the `web` middleware group (`$middleware->web(append: ['throttle:api'])`) | `throttleApi()` covers the `api` group; the append is how `/sanctum/csrf-cookie` (a `web`-group route) gets the 60/min limit. |
| Reset limiters | named limiters `password-reset`: 5/min by IP on forgot and reset routes | |
| Reset email URL | `ResetPassword::createUrlUsing(fn ($user, $token) => config('app.web_url').'/reset-password?token='.$token.'&email='.urlencode($user->getEmailForPasswordReset()))` in `AppServiceProvider`; notification class implements `ShouldQueue` | Link targets the SPA. Email goes to Mailpit (`127.0.0.1:1025`, UI `http://localhost:8025`). |
| Login handler | Limiter check first, before anything else. Then look up the user by email and run `Hash::check` (one hash operation whether or not the user exists, for timing parity), then `Auth::guard('web')->login($user)` and `$request->session()->regenerate()`; logout: `Auth::guard('web')->logout(); session()->invalidate(); session()->regenerateToken();` | Prevents session fixation; old cookie dead after logout. |

Routes live in `routes/api.php` inside the existing `Route::prefix('v1')` group. `/sanctum/csrf-cookie` is registered by Sanctum itself.

## 5. Test database (PostgreSQL, no sqlite)

`pdo_sqlite` is unusable here, and Postgres-specific behaviour is wanted anyway. `phpunit.xml` already points at `DB_HOST=127.0.0.1`, `DB_PORT=5433`, `DB_DATABASE=portal_test`; username/password come from `.env` (`portal` / `secret`).

1. Create the database in the existing compose container (one-off, existing volume):
   `docker compose exec db psql -U portal -d portal -c "CREATE DATABASE portal_test;"`
2. So a fresh clone gets it automatically, add `docker/postgres/init/01-create-test-db.sql` containing `CREATE DATABASE portal_test;` and mount it in `docker-compose.yml` on the `db` service: `- ./docker/postgres/init:/docker-entrypoint-initdb.d:ro`. Init scripts only run on an empty volume; existing volumes use step 1. These two files are outside `docs/`, `api/`, `web/`, `e2e/`: the main session or user must apply them. README gets the one-liner.
3. Pest: in `tests/Pest.php` enable `Illuminate\Foundation\Testing\RefreshDatabase` for the `Feature` directory (currently commented out). Migrations run once per run, each test in a transaction.
4. Safety guard (cheap, important): `RefreshDatabase` wipes the database. In `tests/TestCase.php` `setUp()`, assert `DB::connection()->getDatabaseName()` ends with `_test`, else throw. This stops a misconfigured run from erasing the dev `portal` database.
5. Roles are not created by migrations. Tests call `$this->seed(RolesSeeder::class)` in a `beforeEach` (add a `loginAs($role)` / `makeUser($role, $company = null)` helper in `tests/Pest.php`).
6. Test helper: send `Origin: http://localhost:5173` and `Accept: application/json` on every request (stateful mode). CSRF verification is skipped by Laravel while running unit tests, so 419 is verified manually/QA, not in Pest.
7. Gotcha to document in the test helper: after logout, Laravel's test app caches the resolved user on the guard. To prove the session is invalid, call `$this->app['auth']->forgetGuards()` (or re-create the app) before the next request, and also assert the session store no longer contains the login key.

### CI change (`.github/workflows/ci.yml`, not in my write scope; flag to main session)

The `api` job currently has no database, so every Phase 1 test would fail. It needs:

```yaml
    services:
      postgres:
        image: postgres:16
        env:
          POSTGRES_DB: portal_test
          POSTGRES_USER: portal
          POSTGRES_PASSWORD: secret
        ports:
          - 5433:5432
        options: >-
          --health-cmd "pg_isready -U portal -d portal_test"
          --health-interval 5s --health-timeout 3s --health-retries 10
```

and `setup-php` gets `extensions: pdo_pgsql`. Port 5433 is used on purpose so `.env.example` and `phpunit.xml` work unchanged. `cp .env.example .env` then gives `DB_USERNAME=portal`, `DB_PASSWORD=secret`. Keep the action SHA pins as they are.

## 6. Demo seeders (local/dev only)

- `RolesSeeder`: `Role::firstOrCreate` for `customer`, `staff`, `admin` (guard `web`). Idempotent. Safe in every environment (production needs it too).
- `DemoSeeder` (allowlisted to `APP_ENV` `local` and `testing` only: `DatabaseSeeder` calls it only in those environments, and `DemoSeeder` itself aborts in any other environment; `RolesSeeder` runs everywhere). Idempotent (`updateOrCreate` by email / company name):

| User | Email | Role | Company |
| --- | --- | --- | --- |
| Ada Admin | `admin@portal.test` | admin | null |
| Sam Staff | `staff@portal.test` | staff | null |
| Alice Customer | `alice@northwind.test` | customer | Northwind Machinery Ltd |
| Bob Buyer | `bob@northwind.test` | customer | Northwind Machinery Ltd |
| Carol Customer | `carol@globex.test` | customer | Globex Industrial Supply |

All five users: password `Demo-Passw0rd!`, `email_verified_at = now()`. These credentials are DEMO ONLY: they are published in the repo, must never exist in staging/production, and the README must say so. `.test` is a reserved TLD, no real mailbox can receive mail for these. Two customers in Northwind and one in Globex give the A-vs-B isolation tests real data and test "several users per company".
- Factories: `CompanyFactory`; `UserFactory` with states `customer(Company $c = null)`, `staff()`, `admin()` that also assign the role and set/clear `company_id`.

## 7. Test plan

Gate items from `docs/PLAN.md` Phase 1 are copied verbatim as the first block of each list, then extras. All backend tests: Pest Feature tests on `portal_test`, `RefreshDatabase`, `Mail`/`Notification`/queue as noted.

### 7.1 Backend (Pest) - `backend-engineer`

Gate items:
1. Valid login works: POST login with seeded customer returns 200 and exact UserResource JSON; response sets the session cookie; following `GET /me` returns the same user.
2. Wrong password returns 422 with `errors.email`; unknown email returns the identical body (same message).
3. Logout invalidates session: login, `POST logout` -> 204, then (after `forgetGuards()`) `GET /me` -> 401 and `GET /staff/ping` -> 401.
4. Login rate limit: 5 wrong passwords -> 422 each, 6th -> 429 with `Retry-After` header > 0; then the CORRECT password for the same email -> still 429; a different email from the same IP is not blocked; after `travel(61)->seconds` login works; a successful login resets the counter (4 failures, success, 4 more failures, no 429).
5. Unauthenticated `GET /me` -> 401 JSON (no redirect, `Location` header absent); customer on `GET /staff/ping` -> 403; guest on it -> 401.
6. Password reset email is sent: `POST forgot-password` for a known user returns 200 and the generic message; the queued reset notification is delivered (use the `array` mailer, `QUEUE_CONNECTION=sync`, or `Notification::fake()` with `assertSentTo`); the link starts with `http://localhost:5173/reset-password?token=` and contains `email=` URL-encoded, and does NOT contain the API host.
7. Reset token works once, also under concurrency (Phase 6 addition): first `POST reset-password` -> 200, password changed (old password login 422, new password login 200); the same token again -> 422 invalid-link body; N parallel requests with the same token (real processes, see Phase 6 design 9.1 item 11) -> exactly one 200, the rest the identical generic 422. An old session of that user gets 401 on its next request (`session_epoch` bumped in the same save).

Role guards:
8. `GET /staff/ping`: guest 401, customer 403, staff 200 `{status: ok}`, admin 200. `GET /admin/ping`: guest 401, customer 403, staff 403, admin 200.

Password reset extras:
9. `forgot-password` for an unknown email -> 200, identical body to the known-email response, no notification sent. Missing/invalid email -> 422.
10. Expired token (`travel(61)->minutes`) -> 422 invalid-link body. Wrong token, wrong email, tampered token -> same 422 body. Password too short (<10) or confirmation mismatch -> 422 with `errors.password`.
11. Second `forgot-password` within 60 s returns the same 200 (broker throttle not revealed) and sends no second email.
12. Rate limits: 6th `forgot-password` and 6th `reset-password` from one IP within 60 s -> 429.

Login validation and tampering:
13. Missing email, missing password, malformed email, non-string password, >255 chars -> 422 with the standard shape and the right `errors` keys.
14. Tampered body: login as the seeded customer with extra `role=admin` and `company_id=2` -> 200, `/me` still `role: customer`, company Northwind; DB `users.company_id` and roles unchanged. Same for `forgot-password` and `reset-password` bodies carrying `role`/`company_id`.
15. `POST /api/v1/auth/register` -> 404 (also with a body including `role=admin`); no user row created.
16. Unit test: `User::make(['name'=>..., 'company_id'=>1])` and `fill(['role'=>'admin'])` do not set `company_id` or a role (mass-assignment).

`/me` shape and isolation:
17. `/me` JSON has exactly the keys `id, name, email, role, company` under `data`; company has exactly `id, name`; staff and admin have `company: null`; no `password`, `remember_token`, `company_id`, timestamps.
18. Customer A (Northwind) and customer B (Globex) each see only their own user and company on `/me`; A's `/me?id=<B id>` or `?company_id=<B company>` still returns A. Two customers of the same company see the same company id but their own user id.

Data and seeding:
19. Invariant test over seeded and factory users: customers have a company and role `customer`; staff/admin have `company_id` null; every user has exactly one role.
20. `DemoSeeder` run twice -> still 5 users, 2 companies, 3 roles; throws/aborts when `APP_ENV` is `production`, `staging`, `development` or `qa` (no users created); `RolesSeeder` creates exactly the 3 roles.
21. DB: inserting a user with a non-existent `company_id` fails (FK); deleting a company that has users fails (RESTRICT); `users.company_id` index exists.

Config and security:
22. CORS preflight `OPTIONS /api/v1/me` with `Origin: http://localhost:5173` -> `Access-Control-Allow-Origin: http://localhost:5173` and `Access-Control-Allow-Credentials: true`; with `Origin: http://evil.example` -> no `Access-Control-Allow-Origin` header; the value is never `*`.
23. Session id differs before and after login (regeneration).
24. Any unknown API path -> 404 JSON; `POST` on a GET-only route -> 405 JSON; no stack trace in any error body when `APP_DEBUG=false`.
25. Phase 0 `GET /api/v1/health` still returns 200 `{status: ok}` for guests (no regression).
26. `pint --test` clean.
27. Non-stateful login: `POST /api/v1/auth/login` with no `Origin`/`Referer`, and again with an `Origin` outside `SANCTUM_STATEFUL_DOMAINS` (e.g. `http://evil.example`) -> 419 `{"message":"CSRF token mismatch."}`, same body as a CSRF mismatch; no session user is logged in (`/me` afterwards is 401), and the 419 is returned before rate limiting and authentication (the per-email failure counter is not incremented, even with wrong credentials).
28. Guests without `Accept: application/json`: `GET /api/v1/me`, `GET /api/v1/staff/ping` and a `sanctum/*` request -> 401 JSON `{"message":"Unauthenticated."}`; no `Location` header, never 302 or 500.
29. Email-only login limiter: 20 wrong passwords for one email from varying IPs -> 422 each, the 21st -> 429 with `Retry-After` > 0 (also with the correct password); a different email is unaffected; after `travel(901)->seconds` (frozen clock) login works again; a successful login from another IP does not reset the counter; unknown emails count like known ones.
30. IP key: two IPv6 addresses in the same /64 share the per-IP limits (e.g. 6th `forgot-password` from the second address -> 429); addresses in different /64s do not; `::ffff:1.2.3.4` and `1.2.3.4` share one bucket.
31. Old session dead after password reset: log in (session A), reset the password via a valid token, then `GET /me` with session A's cookie (after `forgetGuards()`) -> 401.

### 7.2 Frontend (Vitest + RTL) - `frontend-engineer`

Mock the network (e.g. `vi.stubGlobal('fetch')` or MSW if later approved; no new dependency is requested for tests). Wrap in `QueryClientProvider` + `MemoryRouter`.

Gate items:
1. Login validation: empty submit shows "required" errors for email and password and does not call the API; invalid email format shows an error; fields have associated labels; errors are announced (`role="alert"` or `aria-describedby`).
2. Protected route redirects to `/login`: guest (`/me` mocked 401) visiting `/` ends at `/login` with `next=/`; no flash of protected content while the `/me` request is pending (spinner/skeleton instead).
3. Customers never see the staff menu: with `/me` role `customer`, nav has Home only; no "Staff area" or "Admin area" link in the DOM (`queryByText` null). Staff: Home + Staff area, no Admin area. Admin: all three.

More:
4. Customer visiting `/staff` or `/admin` directly sees the 403 "Not allowed" page, and the staff page content is not rendered; staff visiting `/admin` likewise; admin can open both.
5. Successful login: calls csrf-cookie then login with the `X-XSRF-TOKEN` header read from the cookie; stores user; navigates to `/`; honours `next=/staff` only for a safe relative path. `next=https://evil.example` and `next=//evil.example` are ignored (go to `/`).
6. Server 422 on login shows `errors.email[0]` under the form; 429 shows a rate-limit message including seconds from `Retry-After`; network error shows a generic error; submit button disabled while pending.
7. Logout: calls `POST /auth/logout`, clears the query cache, navigates to `/login`; protected route is again guarded.
8. A 401 on any later API call clears auth state and redirects to `/login?next=...`; a 419 triggers one csrf-cookie refetch and one retry.
9. Refresh keeps session: on mount with `/me` returning 200 the user is shown without visiting `/login`; `/me` 500 shows an error state with retry, not the login page.
10. Logged-in user visiting `/login` is redirected to `/`.
11. Forgot-password: invalid email blocked client-side; on 200 shows the generic message (same text for any email); 429 handled.
12. Reset-password: reads `token` and `email` from the URL; missing token or email shows an invalid-link message; password under 10 chars or mismatch blocked client-side; 422 invalid-link shown from `errors.email[0]`; success shows a message and link/redirect to `/login`.
13. Header shows name, role and company name for customers, no company for staff/admin.
14. `npm run lint`, `tsc -b` and `npm test` pass.

### 7.3 QA (`qa-engineer`)

Run: `cd api && ./vendor/bin/pest && ./vendor/bin/pint --test`; `cd web && npm run lint && npm test`; `cd web && npm run build`. Confirm the test database is `portal_test` and the dev `portal` database was untouched. Confirm CI is green with the new postgres service. Then manual (against `docker compose up -d`, `php artisan migrate:fresh --seed`, `php artisan serve`, `php artisan queue:listen`, `npm run dev`):

1. Gate manual item: log in as `alice@northwind.test` (customer) and `staff@portal.test` (staff) and `admin@portal.test`; navigation differs as in the contract; reload the page (F5) on `/` and `/staff`: still logged in. Close and reopen the tab: still logged in until the 120 min lifetime ends.
2. Customer opens `/staff` and `/admin` by URL: 403 page. Staff opens `/admin`: 403 page.
3. Devtools cookies: `portal_session` is HttpOnly and SameSite=Lax; `XSRF-TOKEN` readable; session id changes after login; after logout the old cookie value replayed with curl gets 401 on `/api/v1/me`.
4. CSRF (cannot be asserted in Pest): `curl` POST login with a cookie jar but without `X-XSRF-TOKEN` returns 419; with it, succeeds.
5. Rate limit through the UI: 6 wrong passwords -> message with wait time; correct password during lockout is still refused; works after 60 s.
6. Password reset end to end in Mailpit (`http://localhost:8025`): request from the UI, email arrives, link host is `localhost:5173`, form works, link used a second time shows the invalid-link error, old password no longer logs in. Request for an unknown address shows the same message and Mailpit gets nothing. Link older than 60 min (adjust clock or token row `created_at`) fails.
7. Self-registration: there is no register link in the UI, and `curl -X POST /api/v1/auth/register` returns 404.
8. Two browsers, customers Alice (Northwind) and Carol (Globex): each header shows their own company. Alice and Bob (same company) show the same company.
9. Log out in tab 1; any action in tab 2 sends the user to `/login`.
10. Report only; do not fix feature code.

### 7.4 security-reviewer focus (after QA)

Open-redirect on `next`; login enumeration (message, status and timing parity incl. rate limit); session fixation; CSRF on logout and reset; CORS `*` with credentials; mass assignment of `role`/`company_id`; token storage hashed; reset link host; demo seeder cannot run in production; role middleware on every non-public route; error bodies free of stack traces.

## 8. Phase 1 file plan (for plan-mode approval)

- api: `composer.json` (+2 packages), `bootstrap/app.php`, `config/{sanctum,cors,permission,auth,app}.php`, migrations (companies, users.company_id, spatie), `app/Models/{Company,User}.php`, `app/Http/Controllers/Api/V1/{AuthController,MeController,PasswordResetController}`, Form Requests (`LoginRequest`, `ForgotPasswordRequest`, `ResetPasswordRequest`), `app/Http/Resources/UserResource` (+ `CompanyResource`), `app/Notifications/ResetPasswordNotification`, `app/Providers/AppServiceProvider.php` (limiters, reset URL), `routes/api.php`, seeders and factories, `tests/Feature/Auth/*`, `tests/Feature/RoleGuardTest`, `tests/Pest.php`, `tests/TestCase.php`, `.env.example`.
- web: `vite.config.ts` (proxy `/sanctum`), `src/lib/api.ts` (fetch wrapper), `src/auth/*` (context/hook, `ProtectedRoute`, `RoleRoute`), pages (Login, ForgotPassword, ResetPassword, Home, Staff, Admin, Forbidden), nav config, tests, `package.json`.
- outside agent scope (main session/user): `docker-compose.yml` + `docker/postgres/init/01-create-test-db.sql`, `.github/workflows/ci.yml`, README (demo credentials warning, test DB step), `docs/PLAN.md` ticks.

## 9. Open items

- Password-reset email layout: the header still shows the app-name link to the API host (`APP_URL`); the reset button link correctly points at the SPA. Fixing it needs published mail views, deferred to Phase 8 email branding unless the user decides otherwise.
- Reset-password timing for a known user with no pending token is not equalised with other invalid-link cases (accepted).
