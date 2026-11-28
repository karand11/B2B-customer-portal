# Phase 7 design: Security, performance and quality pass

HTTP contract changes: `docs/api-contract.md`, section "Phase 7". This file holds scope decisions, specifications, work packages, the test plan and open questions. Inputs: `CLAUDE.md`, `docs/PLAN.md` (Phase 7 and 8), the follow-up sections of `docs/phase-1..6-design.md`, `.github/workflows/ci.yml`, `api/bootstrap/app.php`, `api/config/*.php`, `web/index.html`, `web/vite.config.ts`, `e2e/`, `api/tests/Feature/Admin/RouteAuditTest.php`, `api/tests/Support/RouteMatrix.php`.

Rules used throughout: nothing here weakens a production limit; every NEW dependency or tool is listed in section 12 and needs the user's approval before use (CLAUDE.md); every secret and demo credential stays out of artifacts.

## 0. Summary of decisions

| # | Topic | Decision |
| --- | --- | --- |
| A1 | Scope split | Code, tests, CI and local scripts NOW. Anything that needs real infrastructure (real proxy IPs, TLS, Nginx, DB role creation on the real server, scheduler cron, production `.env`, backups executed against real storage) is Phase 8, but Phase 7 writes the config text, scripts and the verification so Phase 8 only executes. Register in section 1. |
| A2 | One config file | New `api/config/portal.php` reads every new env knob (`env()` must only appear in config files because of `config:cache`). |
| A3 | Fail loud in production | `App\Support\ProductionGuard` (pure function over config) plus `php artisan portal:check-config`. HTTP requests fail with 500 and a log line in EVERY environment except `local`, `testing` and `loadtest` (`APP_ENV` trimmed, case-insensitive, so `production`, `staging`, `prod`, `live` and an unknown or empty name are all covered) when the config is unsafe. Console commands are NOT blocked (image builds run `artisan package:discover`), the deploy step runs `portal:check-config`. |
| A4 | Headers | Two small global middleware, outermost: `RequestContext` (request id) and `SecurityHeaders`. API responses get the full set (error responses included). The SPA is static: exact Nginx header block defined here (section 3.3), enforced now by `vite preview` in e2e, Nginx file written in Phase 8. No CSP report endpoint in v1. |
| A5 | Pagination | Result window cap: `page * per_page <= 10,000` on every list endpoint (422 `page` otherwise). Replaces deep OFFSET risk with one rule. |
| A6 | Limiters | Add `catalog-search` (40/min), `order-search` (30/min customers, 60 staff, only when `q` is sent; ONE shared bucket per user for `GET /orders`, `GET /quotes` and `GET /invoices`), `orders-reorder` (10/min), `health-ready` (120/min per IP, own bucket), `quote-attachment-delete` (30/min per user); `POST /quotes/{n}/accept` joins `orders-create`. Limiter store in production: `database` cache (atomic), not `file`. |
| A7 | Checkout | `POST /orders` accepts optional `expected_subtotal_cents`; mismatch is 409 with the fresh cart. The SPA always sends it. |
| A8 | Stampede | `CatalogCache` gets single-flight (`Cache::lock`, fail-open) instead of `Cache::flexible`, because flushes (every product edit) also cause herds. |
| A9 | PO search index | No trigram index by default. Add `orders_po_reference_trgm` only if EXPLAIN at 50,000 orders shows more than 100 ms for the PO branch (migration snippet in 6.2). |
| A10 | Malware scanning | Won't do in Phase 7. Accepted risk, documented in `docs/SECURITY.md`; revisit when staff open attachments on workstations. Staff get a CSV warning; staff may purge attachments. |
| A11 | PDF | No caching. Concurrency protection by a dedicated PHP-FPM pool in Phase 8; a code semaphore only if the mixed load test (6.3) shows catalog p95 regressing more than 20% while PDFs render. |
| A12 | Password | `Password::uncompromised()` (HIBP k-anonymity) on reset and invitation acceptance, fail OPEN with 2 s timeout and a warning log, off in local/testing. New outbound network call: needs approval (open question Q3). |
| B1 | Error tracking | No Sentry SDK in Phase 7 (new dependency and a data processor: not assumed approved). Deliver structured JSON logs, request id, failed-job logging, health endpoints. Scrubbing contract for an optional SDK is specified (4.4) so approval is a small follow-up. |
| B2 | Health | `/up` and `GET /api/v1/health` stay liveness. New public `GET /api/v1/health/ready` (DB check, no details). Operator detail through `php artisan portal:health` (CLI only), scheduler heartbeat and queue monitor are log-based. |
| C1 | Backup | Plan + scripts in `ops/backup/` (bash, runs under Git Bash and Linux), local restore drill against Docker Postgres, CI job `ops-drill`. Production execution is Phase 8. |
| D1 | Accessibility tooling | Both, with distinct roles: `@axe-core/playwright` is the day-to-day regression (all pages, inside Playwright); `lighthouse` runs ONLY the literal gate (accessibility category on login, catalog, order history, desktop and mobile). Fallback if Lighthouse is not approved: manual Chrome DevTools Lighthouse run recorded by QA, axe stays. |
| E1 | Load tool | A Node script using the built-in `fetch` (Node 24): no new dependency, exact p95, runs on Windows and in CI. k6 documented as an alternative, not adopted. |
| E2 | Load rig | `ops/loadtest/compose.yml`: nginx + php-fpm 8.3 (opcache, `--no-dev`, cached config/routes) + Postgres 16 on Linux containers. `php -S` stays for e2e only. |
| E3 | Limits during load | Not weakened. 100 seeded load users, 50 virtual users with think time, each stays under every limiter. A final probe proves the intended 429 appears. |
| F1 | Journeys | Two long tests (one customer, one staff), each a single `test()` built from `test.step()`, own data (own product), setup actions of the other role through the API helpers, UI for the role under test. |
| F2 | e2e runs the build | CI runs the whole e2e suite against `vite build` + `vite preview` (production bundle, enforced CSP) instead of the dev server; local default stays dev (`E2E_WEB=preview` to switch). |
| G1 | CI | New: `audit` workflow (composer + npm audit with an expiring allowlist, weekly schedule), `ops-drill` job (backup/restore drill and DB role check), `load` workflow (manual and weekly, relaxed threshold), axe and Lighthouse inside the e2e job, Pest `--parallel` with race tests serial, Dependabot, concurrency and timeouts. |
| G2 | SECURITY.md | `docs/SECURITY.md` (GitHub recognises it). Outline in section 9.5; written by the main session after the controls exist. |

## 1. Backlog register (complete) with scope split

Status: NOW = built and verified in Phase 7. P8 = deployment-time item, Phase 7 delivers the text/script/check and Phase 8 executes it. NO = won't do (reason given). "Verified by" names the evidence the QA agent must collect.

### 1.1 Carried over from the Phase 1 to 6 reviews

| ID | Item | Status | Reason | Verified by |
| --- | --- | --- | --- | --- |
| S01 | Trusted proxies: env `TRUSTED_PROXIES`, never `*` | NOW (code) / P8 (real IPs) | Code and tests do not need infrastructure; the real proxy addresses do. Without it every user behind Nginx shares one IP bucket (login-ip 20/min for the whole site). | Pest `Security/ProxyTest`; P8 smoke: two requests with different forwarded IPs land in different buckets |
| S02 | `trustHosts` from env `TRUSTED_HOSTS` | NOW / P8 (values) | Host-header poisoning; links are built from `app.web_url` already, so this is defence in depth. Required (fail loud) in production. | Pest `ProxyTest` (400 for a foreign Host); `portal:check-config` |
| S03 | IP-keyed limiters behind a proxy, IPv6 /64 | NOW | `ClientIp::key` already reduces IPv6 to /64 and uses `$request->ip()`, so it is correct once S01 exists. Add the proof that a spoofed `X-Forwarded-For` from an untrusted peer changes nothing. | Pest `ProxyTest`, `RateLimitSweepTest` |
| S04 | `Referrer-Policy: origin` as a response header (API) | NOW | Contract Phase 6 token-hygiene note: must be `origin`, never `no-referrer` (Sanctum statefulness). | Pest `HeadersTest` asserts exactly `origin`; e2e login/reload test already catches a regression |
| S05 | `Referrer-Policy: origin` and other headers for the SPA (static host) | NOW (dev/preview) / P8 (Nginx) | Static files are not served by Laravel. | e2e `headers.spec.ts` on the preview build; P8 curl checklist (3.3) |
| S06 | CSP for the React + Tailwind SPA | NOW (policy, enforced in preview) / P8 (Nginx, Report-Only on staging, then enforce) | No inline script or style exists (verified: no `style={{`, no `dangerouslySetInnerHTML`, no external hosts). | e2e fixture fails any test that logs a CSP violation |
| S07 | HSTS | NOW (conditional) / P8 (enabled) | Only meaningful over HTTPS; sent only when the request is secure and the production rules apply (every environment except `local`, `testing` and `loadtest`). | Pest `HeadersTest` |
| S08 | `SESSION_SECURE_COOKIE=true`, same-site, lifetime, driver, `__Host-` cookie name | P8 (values) / NOW (guard and `docs/ops/env.production.example`) | `.env.example` must stay `false` for local HTTP. The guard refuses production without it. | `ProductionGuard` unit tests; P8 curl `Set-Cookie` check |
| S09 | `APP_DEBUG=false`, `LOG_LEVEL` | NOW (guard) / P8 (value) | Same mechanism. | `ProductionGuard` unit tests |
| S10 | `config('app.web_url')` fail loud in production | NOW | Must be set, `https`, not localhost. Invitation and reset links depend on it. | `ProductionGuard` unit tests |
| S11 | `Password::uncompromised()` | NOW | Cheap, high value. Fail open on outage. Needs user approval of the outbound call (Q3). | Pest with `Http::fake` |
| S12 | Non-queued or encrypted reset notification | DONE in Phase 6 (both mails `ShouldBeEncrypted`) | Keep a regression test: queued payload contains no token. | Existing test, re-run in the gate |
| S13 | Re-measure catalog search on a production-like server | NOW | Section 6.3. | Load script result file |
| S14 | Cap deep OFFSET / lower max `page` | NOW | Window cap (A5). | Pest `PaginationWindowTest` over every list route |
| S15 | Dedicated search limiter | NOW | `catalog-search`. | `RateLimitSweepTest` |
| S16 | Stampede protection on filters cache | NOW | A8. | Pest `CatalogCacheTest` |
| S17 | CSV/XLSX export escaping | NOW (rule + helper + structure test); no export exists | `grep fputcsv\|streamDownload` in `app/` finds only the attachment download. A helper without a caller is small and the structure test stops a future export from forgetting it. | Unit test, structure test |
| S18 | Checkout expected subtotal | NOW | A7. | Pest, Vitest, e2e journey |
| S19 | Dedicated limiters for reorder and order search | NOW | A6. | `RateLimitSweepTest` |
| S20 | Trigram index on `orders.po_reference` | Conditional | A9. Decide from EXPLAIN evidence, not guess. | EXPLAIN report |
| S21 | `APP_DEBUG=false` and secure cookie in every deployed environment | NOW (guard covers every environment except local, testing and loadtest) | Same as S08, S09. | Unit tests |
| S22 | Staff purge of attachments on any quote | NOW | Contract change (DELETE allowed for staff in any status; EVERY staff purge is audited, open or closed). Small. | Pest, Vitest |
| S23 | CSV warning in the staff UI | NOW (frontend) | Staff open customer CSVs in spreadsheets. | Vitest |
| S24 | Malware scanning | NO (accepted risk) | New service (ClamAV) and operational burden; mitigations exist (content allowlist, forced `attachment`, `nosniff`, sandbox CSP, private storage). Revisit if staff open files on workstations at scale. | Listed in `docs/SECURITY.md` |
| S25 | One-byte text files refused by libmagic | NO (document) | Relaxing means trusting the extension. SPA shows the server message; user guide (Phase 8) says "empty or tiny text files are not accepted". | Existing test |
| S26 | Email layout without the API-origin header link | NOW | Publish mail views, header uses `app.web_url`. | Pest renders every mail and asserts the API host is absent |
| S27 | Per-user/company storage monitoring | NOW | `quotes:storage-check` (section 3.9). | Pest, QA run on load data |
| S28 | App DB role without ownership/TRIGGER/TRUNCATE on invoice and audit tables | NOW (SQL + verify script) / P8 (apply on real DB) | Needs cluster roles; scripts can be proven on the Docker Postgres and in CI. | `ops/db/verify-roles.sh` in the `ops-drill` job |
| S29 | Cache generated PDFs / cap concurrent renders | NO now (A11) | Rare downloads, 20/min per user and 60/min per IP already. Decide by measurement. | Mixed load scenario |
| S30 | LGPL notice for dompdf libraries | NO (hosted service, not distributed) | Revisit if the app is ever shipped to customers. Recorded in `docs/SECURITY.md`. | n/a |
| S31 | Legal review of invoice wording, VAT rate, numbering | P8 (go-live blocker, user) | Not an engineering task. | Phase 8 checklist |
| S32 | `composer audit` for dompdf; `enable_remote` and `enable_php` stay false | NOW | Audit job (section 9.1). Add a structure test that `config/dompdf.php` has both false. | `Security/StructureTest`, audit job |
| S33 | DB error handler still in place (500 `Server Error`, placeholders only) | NOW (re-run) | `ErrorLeakTest` stays in the gate; the new log-hygiene test (4.3) extends it. | Existing test |
| S34 | Phase 4: orphaned attachment files, no sweeper | NOW | `quotes:storage-check --prune-orphans` (3.9). | Pest |
| S35 | Phase 4: parallel upload caps tested only sequentially | NOW (QA) | `e2e/tests/races.spec.ts`: 12 parallel uploads, at most 10 accepted; two parallel import applies, exactly one wins. | Playwright |
| S36 | Phase 3 note: DB trigger forbidding UPDATE/DELETE on `order_items` | NOW, low priority | Same pattern as the invoice triggers. Backend first verifies no code path updates or deletes items; if any exists, defer and report. TRUNCATE stays allowed (test cleanup). | `OrdersSchemaTest` additions |
| S37 | Phase 6: per-product audit rows for import changes | NOW | The HTTP import is already capped at 5,000 rows, so the "unbounded" objection does not hold: write one `product.import_updated` row per UPDATED product with a non-empty diff, in chunked inserts, same transaction. Created products stay in the aggregate row. The CLI import stays unaudited (operator tool). | Pest `ProductImportAuditTest` |
| S38 | Phase 6: fail-closed epoch check for session-less requests | NOW | One condition: an authenticated user on a request with no session is refused (401). No token auth exists, so nothing legitimate is affected. | Pest |
| S39 | Phase 6: pin Postgres and Mailpit images by digest; Playwright trace policy | NOW (main session) | CI hardening, section 9.2. | CI diff review |
| S40 | Phase 6: `AuditLog::$baseTransactionLevel` test seam | NOW | Make it inert at runtime: ignore a non-zero value unless running unit tests, and assert in a structure test that `app/` never assigns it. | Structure test |
| S41 | Phase 6: e2e uses dev server and `php -S` | NOW | F2: CI runs the preview build. | CI |
| S42 | Phase 6: `Route::get('/')` serves Laravel's welcome page on the API host | NOW | Information disclosure and pointless. Remove (404 JSON for non-api paths is not needed; plain 404). Route audit extended to ALL routes (3.10). | Route audit |
| S43 | Phase 5: `HEAD` on PDF is 405 | NO | Accepted limitation. | n/a |
| S44 | Frontend bundle warning (616 kB) | NOW | Route-level `React.lazy`, section 6.6. | `vite build` has no chunk warning |

### 1.2 Deployment-time items (Phase 8 owns execution; Phase 7 hands over the text)

| ID | Item | Where Phase 7 puts the handover |
| --- | --- | --- |
| D01 | Real proxy IPs in `TRUSTED_PROXIES` (or, only when clients reach the app with no proxy, `TRUSTED_PROXIES_NONE=true`), `TRUSTED_HOSTS`, TLS, redirect 80 to 443 | `docs/ops/env.production.example`, section 3.1, 3.3 |
| D02 | Nginx: security headers for the SPA locations, `client_max_body_size 12m` on `/api`, `server_tokens off`, gzip, immutable cache for `/assets/`, `no-cache` for `index.html`, `fastcgi_hide_header X-Powered-By`, coarse `limit_req` | Exact text in 3.3; `ops/loadtest/nginx.conf` is the working head start |
| D03 | PHP: `expose_php=Off`, `memory_limit=256M`, `upload_max_filesize=11M`, `post_max_size=12M`, opcache on with `validate_timestamps=0`, dedicated small FPM pool for `/api/v1/invoices/*/pdf` | 3.3, 6.3 |
| D04 | Apply `ops/db/roles.sql` on the real server and RE-RUN it (and verify) after every migration that creates tables: a hard deploy step, see 5.3; migration role with CREATE for `pg_trgm` | S28 |
| D05 | Scheduler cron `* * * * * php artisan schedule:run` (jobs: `quotes:expire`, `invoices:mark-overdue`, `imports:prune`, `quotes:storage-check`, heartbeat, `queue:monitor`, `queue:prune-failed`), queue worker under Supervisor | 4.5 |
| D06 | Production `.env` (secrets outside git, `APP_KEY` stored separately, `SESSION_*`, `CACHE_STORE=database`, `LOG_*`, `MAIL_*`, `INVOICING_*`, `QUOTES_NOTIFY_ADDRESS`) | `docs/ops/env.production.example` (written by the main session, no secrets) |
| D07 | Backups executed on real storage with offsite copy and a restore into a fresh DB | Section 5 |
| D08 | Error-tracking decision (B1) | 4.4 |
| D09 | Audit retention decision and privileged purge | Phase 6 note; Phase 8 |
| D10 | First admin creation procedure, announce that all sessions end at deploy (no stored epoch in old sessions) | Phase 6 note; Phase 8 `DEPLOY.md` |
| D11 | Report-Only CSP on staging, then enforce | 3.3 |
| D12 | HTTP-to-HTTPS: curl checklist for headers, cookies, 413 HTML vs JSON, `Host` rejection, XFF overwrite | 10.3 |

## 2. Cross-cutting rules for implementers

- Contract first: `docs/api-contract.md` Phase 7 section is the source of truth. If a behaviour must differ, stop and report.
- Tests first, per CLAUDE.md. Gate tests live in `api/tests/Feature/Security/` (new directory, auto-included by the `Feature` suite).
- Helper function names in tests are global in Pest: prefix every new helper (`sec...`, `matrix...`) and reuse `makeUser`, `orderWorld`, `quoteWorld`, `makeInvoice`, `RouteMatrix`.
- No `putenv` in tests (it leaks between parallel tests): use `config([...])`. `env()` is read only in `config/*.php`.
- The API never echoes client input in errors beyond what the contract says.

## 3. Backend specifications (`api/`, owner: backend-engineer)

### 3.1 Trusted proxies and hosts (S01, S02, S03)

- `config/portal.php`: `trusted_proxies` (list parsed from `TRUSTED_PROXIES`, comma separated IPs or CIDRs, whitespace trimmed, empty by default = trust nobody), `trusted_hosts` (list from `TRUSTED_HOSTS`, exact hostnames, optional).
- `app/Support/TrustedProxies.php`: `fromEnvString(?string): array` validates every entry with `filter_var` (IP) or a CIDR check; the literals `*` and `**`, empty tokens and anything unparsable throw `InvalidArgumentException` at boot, in every environment (never silently trusting everything).
- `bootstrap/app.php`: `$middleware->trustProxies(at: TrustedProxies::...(), headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT)`. `X-Forwarded-Host` and `X-Forwarded-Prefix` are NOT trusted. `$middleware->trustHosts(at: fn () => config('portal.trusted_hosts'), subdomains: false)` active only when the list is non-empty. `ProductionGuard` makes the list mandatory in every environment except local, testing and loadtest.
- Verified in the framework: the stock `TrustHosts` middleware is inert in `local` and in tests (it only acts outside them), so it could not be tested and would silently do nothing in development. The project therefore uses its own `TrustConfiguredHosts` middleware, which reads `config('portal.trusted_hosts')` on every request (so `config([...])` in a test takes effect) and is active whenever the list is non-empty. The static proxy and host lists the framework keeps are reset in `TestCase::tearDown` (section 9.2).
- As built, `TRUSTED_PROXIES` (`App\Support\TrustedProxies`, an `InvalidConfiguration` at boot in every environment):
  - Entries are IPs or CIDRs. `*`, `**`, empty tokens and anything unparsable throw. CIDR floors: an IPv4 prefix shorter than /8 and an IPv6 prefix shorter than /16 throw like `*` (`0.0.0.0/1` is half the internet). The message names the variable, never the value.
  - An EMPTY list is an UNSAFE error outside local, testing and loadtest (`portal:check-config` exit 1, HTTP guard 500), because behind a proxy every visitor would share the proxy's IP and so one login bucket and one guest bucket. The only way to run with no list is `TRUSTED_PROXIES_NONE=true` ("clients reach the app directly, no proxy"), honoured only as a real boolean true (`config('portal.trusted_proxies_none') === true`). Set it ONLY when no proxy exists; otherwise list the proxy IPs or CIDRs.
  - Known limit: `TRUSTED_PROXIES_NONE=true` together with a non-empty `TRUSTED_PROXIES` is not flagged; the list wins and the flag is ignored.
- As built, `TRUSTED_HOSTS` (`App\Support\TrustedHosts`): host names only. A scheme, port, path, wildcard, IPv6 literal or trailing dot is invalid, because it could never equal a `Host` header and would answer 400 to every visitor. The list is validated at boot (`InvalidConfiguration`; the message names the variable, never the value) and a bad list stops the boot in every environment EXCEPT for `portal:check-config`, which must run to report it (the list is then empty and the guard reports `TRUSTED_HOSTS`). The exception is detected by looking for `portal:check-config` in `$_SERVER['argv']` (`TrustedHosts::runningCheckConfig()`). KNOWN SMALL HACK: it is an exact match of one `argv` element, so it works for a direct `php artisan portal:check-config` call (options after it are fine) and for nothing else, for example not for `Artisan::call()` from another command. A bad `TRUSTED_PROXIES` has no such exception: it stops every boot, check-config included.
- A boot-time `InvalidConfiguration` happens before the logger exists, so `bootstrap/app.php` reports it with `error_log()` (stderr or the PHP error log), and the operator sees the real message instead of a bare 500.
- Health probes from the proxy must send an allowed `Host` (Phase 8 note).
- A host outside the list answers `400 {"message":"Bad Request."}` (an exception render for `SuspiciousOperationException` on `api/*` and `sanctum/*`, so the reflected host value never appears in the body).
- Behind a proxy `$request->ip()` is the forwarded client IP only when the TCP peer is in the trusted list. The proxy must OVERWRITE the header (`proxy_set_header X-Forwarded-For $remote_addr`, or the `realip` module when a CDN is in front), never append a client-supplied value. This is a Phase 8 smoke test (10.3).

### 3.2 Security headers, request id (S04, S07)

- `app/Http/Middleware/RequestContext.php`, first global middleware (`prepend`): accept an inbound `X-Request-Id` only if it matches `^[A-Za-z0-9._-]{8,64}$`, otherwise generate a UUIDv4; `Context::add('request_id', $id)` (Laravel Context: included in every log record and carried into queued jobs; verified: the queued payload carries it under `illuminate:log:context`); echo the id on the response, error responses included; add `user_id` (integer only, never email or name) to the context in `EnsureUserIsActive` after the user is known. CORS `exposed_headers` gains `X-Request-Id`.
- `app/Http/Middleware/SecurityHeaders.php`, global, `prepend`ed so it wraps `HandleCors` (preflight responses get the headers too). Work happens after `$next()`:
  - always: `X-Content-Type-Options: nosniff`, `Referrer-Policy: origin`, `X-Frame-Options: DENY`, `Permissions-Policy: accelerometer=(), camera=(), geolocation=(), gyroscope=(), microphone=(), payment=(), usb=()`, `Cross-Origin-Resource-Policy: same-site`.
  - `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'` only if the response has none (the attachment download AND the invoice PDF download keep their own `default-src 'none'; sandbox`).
  - `Cache-Control: no-store` on `api/*` and `sanctum/*` unless the response already contains `no-store` (attachments and PDF already send `private, no-store`).
  - `Strict-Transport-Security: max-age=<portal.hsts_max_age>` (default 31536000, `0` disables) only when `$request->isSecure()` (evaluated after the proxy is resolved) and the production rules apply (`ProductionGuard::applies()`: every environment except `local`, `testing` and `loadtest`, `APP_ENV` trimmed and case-insensitive, so `prod`, `live` and `Production` get it too). No `includeSubDomains` and no `preload` (Q4).
  - removes `X-Powered-By` (`header_remove` is not enough with FPM, so also `$response->headers->remove`; real fix is `expose_php=Off`, D03).
  - never sends `X-XSS-Protection` (obsolete).
- Because the headers come from the outermost middleware, an exception rendered by the handler still gets them. A test proves it for 401, 404, 405, 413, 419, 422, 429 and 500.
- Duplicate-header rule (Phase 8): Nginx adds the SPA header block only on SPA locations; on `/api` and `/sanctum` the headers come from Laravel, except HSTS where one source is chosen (set `PORTAL_HSTS_MAX_AGE=0` if Nginx sends it).

### 3.3 SPA headers: exact definition (S05, S06, D02, D11)

Source of truth in the repo: `web/security-headers.ts` (frontend-engineer) exporting the object below; `web/vite.config.ts` uses it as `preview.headers`. The dev server (`server.headers`) sends none because HMR needs inline script and websockets. Phase 8 copies the same strings into the Nginx snippet (a Vitest test asserts every header value from `security-headers.ts` appears in `docs/ops/nginx-spa-headers.conf`, written by the main session).

```
Content-Security-Policy:
  default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:;
  font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self';
  form-action 'self'; frame-ancestors 'none'; manifest-src 'self'; upgrade-insecure-requests
X-Content-Type-Options: nosniff
Referrer-Policy: origin
X-Frame-Options: DENY
Permissions-Policy: accelerometer=(), camera=(), geolocation=(), gyroscope=(), microphone=(), payment=(), usb=()
Cross-Origin-Opener-Policy: same-origin
Cross-Origin-Resource-Policy: same-origin
```

Notes:
- `upgrade-insecure-requests` is omitted from the preview object (plain HTTP in e2e) and present in the Nginx text.
- HSTS: Nginx only, `max-age=31536000`, after HTTPS is verified on staging (Q4).
- `connect-src 'self'` assumes ONE origin: Nginx serves the SPA and proxies `/api` and `/sanctum` (the same shape as the Vite proxy). A separate API origin would need `connect-src` and CORS changes (Q5, default: same origin).
- The PDF and attachment downloads use `fetch` + `Blob` + `a[download]`, which CSP does not block. `<meta name="referrer" content="origin">` stays as a fallback. A `<meta http-equiv="Content-Security-Policy">` is NOT added (it cannot express `frame-ancestors` and would break the Vite dev server).
- Rollout: enforced on the preview build in CI now (a violation fails the test, 8.1); `Content-Security-Policy-Report-Only` on staging in Phase 8 with the same Playwright suite and a DevTools console check; enforce at the launch gate. No report endpoint: it would be a public unauthenticated write route (decision, Q20).
- As built (F1): `web/security-headers.ts` is the single source of the header values; `vite.config.ts` imports it for `preview.headers`, and `vite preview` therefore ENFORCES the CSP (`Content-Security-Policy`, not Report-Only) in e2e and CI. The Vitest sync test against `docs/ops/nginx-spa-headers.conf` is in place.
- Nginx gotcha for Phase 8: `add_header` in a `location` REPLACES the parent's headers; put the block in one `include` and repeat it in every SPA location (`/`, `/assets/`).

### 3.4 CORS and CSRF (S-CORS, S-CSRF)

Current state (verified): `config/cors.php` uses an explicit origin list from `CORS_ALLOWED_ORIGINS` with exact-match patterns (so the library answers `Access-Control-Allow-Origin` only for those origins), `supports_credentials=true`, restricted methods and headers, no wildcard. `SANCTUM_STATEFUL_DOMAINS` is a separate list (host:port); they must describe the same origin.

Changes:
- `ProductionGuard` rules: no `*`, no `null`, every `CORS_ALLOWED_ORIGINS` entry is `https://` whenever the production rules apply (3.11), and the origin of `app.web_url` is in the list; `SANCTUM_STATEFUL_DOMAINS` host equals the web origin host and contains no `*`.
- Tests (Pest `Security/CorsCsrfTest`):
  - preflight: allowed origin gets `Allow-Origin` (exact) and `Allow-Credentials: true`; `http://evil.test`, `null`, `http://localhost:5173.evil.test` and `http://localhost:5174` get no `Allow-Origin`; a preflight for a disallowed method or header gets none.
  - a simple request with a foreign `Origin` and a valid session cookie is treated as a guest (Sanctum statefulness): protected GET 401, protected writes 401 (never 2xx); login 419 (existing).
  - CSRF seam: the framework skips CSRF validation when running unit tests (`VerifyCsrfToken::runningUnitTests()`), so the existing tests cannot see 419. Add `tests/Support/CsrfEnforced.php` that rebinds `ValidateCsrfToken` to a subclass overriding `runningUnitTests()` to `false`. Sweep: every non-GET `api/*` route, stateful `Origin`, valid session, no `X-XSRF-TOKEN` => 419 `{"message":"CSRF token mismatch."}` (public routes included except none are exempt); a wrong token => 419; the correct token passes the CSRF layer.
  - `GET /sanctum/csrf-cookie` sets `XSRF-TOKEN` (not HttpOnly) and the session cookie (HttpOnly, `SameSite=Lax`).
- Session decisions (documented in `docs/ops/env.production.example`, enforced by the guard where marked): `SESSION_DRIVER=database`; `SESSION_LIFETIME=120` (idle); `SESSION_SECURE_COOKIE=true` (guard); `SESSION_SAME_SITE=lax` (strict would drop the cookie on links from emails, for example a quote link; lax still blocks cross-site POST); `SESSION_HTTP_ONLY=true`; `SESSION_DOMAIN` empty (host-only cookie); `SESSION_COOKIE=__Host-portal_session` (requires Secure, Path `/`, no Domain: all true in production); `SESSION_ENCRYPT=true`. `.env.example` keeps its local values.

### 3.5 Rate limits (S03, S15, S19, review)

Review of the current set: login (3 keys), `password-reset` (shared by forgot, reset and accept-invitation per IP: acceptable, but an office behind one NAT address can hit 5 per minute: documented), `api` 60/240, `orders-create`, `quotes-create`, `quote-uploads` (+ hourly), `invoice-pdf` (+ IP), `invoices-write`, `admin-*`. Gaps and decisions:

| Limiter | Applies to | Limit and key | Notes |
| --- | --- | --- | --- |
| `catalog-search` (new) | `GET /products` | 40 per minute per user (`PORTAL_CATALOG_SEARCH_PER_MINUTE`) | Stacked with `api`. Heaviest read; shared budget left for cart and filters. Customer UAT may need a raise (Q7). `/products/filters` and detail are not covered. |
| `order-search` (new) | `GET /orders`, `GET /quotes` and `GET /invoices`, only when `q` is non-empty | 30 per minute per user for customers, 60 for staff and admin, ONE shared bucket (key `order-search:<user>`) | The limiter closure returns `Limit::none()` when `q` is empty or absent, so those requests are not counted (verified: `Limit::none()` exists in Laravel 12.74). Because the bucket is shared, a customer gets 30 searches per minute IN TOTAL across the three lists, not 30 each. |
| `health-ready` (new) | `GET /health/ready` | 120 per minute per IP (`ClientIp::key`), own bucket | The route drops the guest `api` throttle (`withoutMiddleware('throttle:api')`), so probes of a load balancer cannot starve, and cannot be starved by, other guest traffic. |
| `quote-attachment-delete` (new) | `DELETE /quotes/{number}/attachments/{attachment}` | 30 per minute per user | Customer deletes and staff purges share it. |
| `orders-reorder` (new) | `POST /orders/{number}/reorder` | 10 per minute per user, every attempt counts | Writes the cart under the user lock. |
| `orders-create` (extended) | also `POST /quotes/{number}/accept` | shared 10 per minute per user | Accept creates an order. |

- Limiter keys stay: user id when authenticated, `ClientIp::key()` otherwise. Do NOT key on a raw header.
- Limiter store: atomic counters are required, `file` is not atomic under several PHP workers. `docs/ops/env.production.example`: `CACHE_STORE=database`; Redis is a possible later upgrade (new service, not proposed now). The guard WARNS (not fails) on `file` and fails on `array` in production.
- Role 403 before 429 stays unchanged (priority list in `bootstrap/app.php`). Every new limiter keeps the standard 429 body and `Retry-After`.
- Sweep test: section 10.1 (`RateLimitSweepTest`).

### 3.6 Pagination window (S14)

- `config/portal.php`: `max_window` = 10000 (`PORTAL_MAX_WINDOW`).
- One shared rule (a small `PageWindow` rule or trait used by `ListProductsRequest`, `ListOrdersRequest`, the quote and invoice list requests and `AdminListRequest`): after `page` and `per_page` are individually valid, `page * per_page > max_window` is 422 on the key `page`, message "Only the first 10,000 results can be paged through. Narrow the search with filters." The old individual caps stay (products `page` max 1,000,000 becomes moot but is left to avoid churn).
- The total in `meta` is still the real total; `last_page` is unchanged; the SPA disables "Next" when the next page would leave the window and shows the hint. As built (F3): the SPA clamps the page at URL parse time to the window for the endpoint's fixed `per_page`: catalog page 416 (24 per page), order, quote and invoice lists page 500 (20 per page), admin lists page 400 (25 per page); a larger `page` in the URL is clamped, never sent. At 5,000 products and per_page 24 the window is never hit; staff list pages narrow by status or date.
- Existing tests that request huge pages (for example "page beyond last is 200") use pages inside the window; the backend adapts them and reports each change.

### 3.7 Checkout expected subtotal (S18)

- `POST /orders` body gains optional `expected_subtotal_cents` (integer, 0 to 99,999,999,999,999). Absent or null = no check (API clients). Wrong type or negative = 422 `expected_subtotal_cents`.
- Order of checks inside the existing transaction under the user lock: `po_reference` (422) before reading the cart; cart empty or unavailable lines (422 `cart`); then, if the value is present and differs from the freshly computed subtotal, answer 409 with the current cart and create nothing; otherwise create the order. The attempt counts toward `orders-create`.
- Limitation, accepted: equal subtotals hide offsetting changes of two lines; the order detail always shows the snapshot.
- Frontend, as built (F2): `Place order` ALWAYS sends `expected_subtotal_cents`; on 409 the cart query data is replaced, the alert shows the new and the old subtotal, the PO reference the user typed is kept in the form, and the order is NOT resubmitted automatically (a second click is required). `ApiError.requestId` (from `X-Request-Id`) is shown as ` Reference: <id>` appended to GENERIC error messages only (500 and unknown failures), never on validation (422), conflict (409) or auth errors.
- Frontend behaviour: section 4 of the contract; `Place order` sends the subtotal of the cart it is showing; on 409 it replaces the cart data with the body's `cart`, shows `role="alert"` text "Prices in your cart changed. The new subtotal is X (was Y). Review and place the order again." and does NOT resubmit automatically.

### 3.8 Password policy (S11)

- One shared rule used by `ResetPasswordRequest` and the accept-invitation request: `Password::min(10)->max(255)` plus `->uncompromised(3)` when `config('portal.password_breach_check')` is true. Default: true in production, staging and local-with-env, false when `APP_ENV` is `local` or `testing` (`AUTH_PASSWORD_BREACH_CHECK`). `phpunit.xml` sets it false; the dedicated test turns it on with `Http::fake`.
- Failure mode (verified and built): the stock `Illuminate\Validation\NotPwnedVerifier` has a 30 s timeout and, on a failed request, reports the exception, whose message holds the URL with the hash prefix of the password. It is therefore NOT used as is. `App\Support\FailOpenPwnedVerifier` extends it and overrides `search()`: timeout and connect timeout 2 s (`portal.password_breach_timeout`), any outage, timeout or non-2xx answer SKIPS the check (FAIL OPEN, blocking every reset during a third-party outage is worse than skipping one check) and logs `Log::warning('Password breach check unavailable', ['reason' => 'status'|'exception'])`. The context is the `reason` only: no hash prefix, no hash, no password, no exception object.
- Binding (as built): the verifier is registered with `$this->app->extend(UncompromisedVerifier::class, ...)`, not with a plain `bind()`. The framework's deferred validation provider registers its own binding when the abstract is first resolved and would overwrite a plain `bind()` made in `AppServiceProvider`; `extend()` wraps whatever the framework resolves, so the override always wins. A test resolves the abstract from the container and asserts the class.
- Data protection: only the first five characters of the SHA-1 of the password leave the server, no email, no user id, the server IP is visible to the provider. Needs the user's approval (Q3). Composition rules are not added (length plus breach check follows current NIST guidance).
- Error: 422 key `password` (message text not contractual). The existing 422 shapes are unchanged.

### 3.9 Uploads and storage (S22, S25, S27, S34, uploads review)

Review result: sizes, types, content sniffing, per-quote and per-company caps, private disk, forced `attachment`, `nosniff`, sandbox CSP and limiters are in place. Gaps closed now:

- Structure test: no route serves `storage/*` (`filesystems.disks.local.serve=false`, no `public` disk used by any upload code, `public/storage` link absent).
- `php artisan quotes:storage-check` (new, one command for S27 and S34):
  - report: bytes and file count per company and in total for `quotes`, bytes of the `imports` disk, free disk space of the storage path; `--json` for scripts.
  - orphans: files on disk with no `quote_attachments` row (older than 24 h) and rows whose file is missing; listed, never deleted by default.
  - `--prune-orphans --force` deletes orphan FILES only (never rows). Without `--force` it is a dry run.
  - `Log::warning` when a company is above 80% of `quotes.attachments.max_company_bytes`, when free space is below `PORTAL_STORAGE_MIN_FREE_BYTES` (default 5 GiB) or when orphans exist. Scheduled daily (read-only form). Pest covers all branches with `Storage::fake`.
- Staff purge (contract, as built): `DELETE /quotes/{n}/attachments/{id}` by staff and admin is allowed in ANY quote status; customers keep today's rule and still get 422 on a quote that is not open. EVERY staff purge writes the audit row `quote.attachment_purged` in the same transaction, on an open quote and on a closed one (metadata `file_name`, `size_bytes`, `sha256`; no content). A customer deleting their own file stays unaudited, as before. Row and file are deleted as today (row in the transaction, file after commit). The `AuditLog` action list and the audit viewer filter list get the new action.
- CSV attachments in the staff UI: next to the download link of every `.csv` attachment, staff and admin see "CSV files can contain spreadsheet formulas. Open it in a text editor, or disable formulas and links in your spreadsheet." Customers do not see it.
- `AttachmentInspector` rules and the 1-byte limitation are unchanged (S25, documented only).
- Malware scanning: not built (S24). The `SECURITY.md` risk entry names the trigger for revisiting.

### 3.10 Route audit and structure tests (gate item 1)

The Phase 6 `Admin/RouteAuditTest.php` already proves the gate: matrix equals router in both directions, real access derived from middleware, every `/admin` route is staff or admin, answers per caller (guest 401, customer 403 and so on), malformed ids 404. Do not duplicate it. Extensions, in the same file or `Security/RouteAuditExtraTest.php`:

1. Audit ALL routes, not only `api/*`: the only allowed non-api routes are `up` and `sanctum/csrf-cookie` (`Route::get('/')` is removed, S42). A `storage/*` route fails the test.
2. Exactly these `public` entries are allowed: `GET api/v1/health`, `GET api/v1/health/ready`, `POST api/v1/auth/login`, `.../forgot-password`, `.../reset-password`, `.../accept-invitation`. The list is a literal in the test so adding a public route is a visible diff.
3. Every `api/*` route has at least one `throttle:` middleware, and every `throttle:` name that appears on a route is declared in the rate-limit table (10.1).
4. Every `api/*` route has `EnsureFrontendRequestsAreStateful` in its resolved middleware (so CSRF applies to stateful browsers) and every non-GET public route is covered by a limiter.
5. `Security/StructureTest.php` (grep-style, in the manner of `AdminStructureTest`): no `fputcsv` outside a file that also references `CsvCell`; `AuditLog::$baseTransactionLevel` is never assigned under `app/`; `config/dompdf.php` has `enable_remote=false` and `enable_php=false`; no `->get(` on `$this->request` in form requests (existing rule stays); no `env(` call outside `config/` and `bootstrap/`; no `request()->all()` passed to `create`, `update`, `fill` or `forceFill` under `app/`.

### 3.11 Remaining backend items (one line each)

- `ProductionGuard` + `portal:check-config` (A3), as built. `ProductionGuard::check()` is a pure function over a settings array and returns `errors` and `warnings`, both lists of environment variable NAMES, never values. The rules apply in EVERY environment except `local`, `testing` and `loadtest` (`ProductionGuard::EXEMPT_ENVIRONMENTS`; `APP_ENV` is trimmed and compared case-insensitive), so `production`, `staging`, `prod`, `live`, `Production` and an unknown or empty name are all enforced. HSTS follows the same rule (3.2).
  - UNSAFE (errors, exit 1): `APP_DEBUG` not false; `APP_URL` not https; `APP_URL_WEB` missing, not https or a local host; `SESSION_SECURE_COOKIE` not true; `SESSION_DRIVER=array`; `CACHE_STORE=array`; `CORS_ALLOWED_ORIGINS` empty, containing a non-https or wildcard entry, or missing the origin of `APP_URL_WEB`; `SANCTUM_STATEFUL_DOMAINS` with an empty or `*` entry or missing the web host; `TRUSTED_HOSTS` empty; `TRUSTED_PROXIES` empty while `TRUSTED_PROXIES_NONE` is not the boolean true (3.1); `LOG_LEVEL=debug`; `MAIL_MAILER` `log` or `array`.
  - WARNING (does not fail, exit 0): `CACHE_STORE=file` (limiters need an atomic store); `QUEUE_CONNECTION=sync`; `INVOICING_SELLER_NAME` still containing `PLACEHOLDER`. (`TRUSTED_PROXIES` moved from warning to error: an empty list behind a proxy puts every visitor in one login and one guest bucket.)
  - Seller placeholder: it is a WARNING here, not an error, because refusing every HTTP request over a missing seller would take the whole portal down although only invoice issuing is affected. The invoice issuing guard (`InvoiceSnapshotBuilder`) keeps its own refusal. `portal:check-config --strict` turns every warning, the seller placeholder included, into exit 1 (the deploy step of a go-live uses `--strict`; routine deploys may use the default).
  - `php artisan portal:check-config [--strict] [--json]`: prints `UNSAFE: <NAME>` and `WARNING: <NAME>` lines; `--json` prints one object `{ environment, enforced, ok, errors[], warnings[] }`. `--json` keeps exactly those five keys, with `TRUSTED_PROXIES` now in `errors`. In `local`, `testing` and `loadtest` it exits 0 and only lists what a production environment would report. A bad `TRUSTED_HOSTS` value does not stop this command from booting (3.1): it is reported as `TRUSTED_HOSTS`.
  - HTTP guard (`EnforceProductionConfig`, global middleware): outside local, testing and loadtest, while an error rule fails, every request answers `500 {"message":"Server Error"}` and the `critical` log line naming the failed rules (names only) is written through `LogThrottle`: at most once per distinct rule set per minute, so an outage does not flood the log. Warnings never block.
  - `App\Support\LogThrottle::allow($key): bool` (`Cache::add('log-throttle:'.$key, 1, 60)`, 60 s TTL, FAILS OPEN: if the cache is down it returns true, a repeated line is better than a missing one). Used by `EnforceProductionConfig` (key: md5 of the failed rule set) and by `HealthReadyController` (key `ready:<check>`: one `Readiness check failed` line per failing check per minute, 4.2).
  - Known limits: (1) `TRUSTED_PROXIES_NONE=true` with a non-empty `TRUSTED_PROXIES` is not flagged, the list wins (3.1); (2) the seller-placeholder check inside `InvoiceSnapshotBuilder` keeps its OWN exemption, `local` and `testing` only, so `loadtest` is NOT exempt there (invoice issuing in the load rig needs real seller values) and `staging`-like names are enforced. Console commands are never blocked (image builds run `artisan package:discover`).
- `CatalogCache` single-flight (A8): on a miss take `Cache::lock('catalog:filters:build', 10)`, `block(3)`, re-check the key, compute, store; if the lock times out compute without storing twice (fail open). Same for `categories`. TTL stays 600 s; `flush()` stays after commit from `ProductAdmin`, the import service and `products:import` (verified in Phase 6 tests, re-run).
- `CsvCell::safe(mixed): mixed` (`App\Support`): strings starting with `=`, `+`, `-`, `@`, tab or CR (also after leading spaces) get a leading `'`; non-strings pass through. Unit test with the OWASP prefix list.
- Fail-closed epoch (S38): `EnsureUserIsActive`: an authenticated user and `! $request->hasSession()` throws `AuthenticationException`, so a request with a user but no session is 401.
- Import audit rows (S37), as built: `ProductImportService::apply` collects the per-product diff for updated rows and writes a `product.import_updated` row ONLY for a real diff of an AUDITED field (the fields `product.updated` audits: `price_cents`, `price_on_request`, `active`, `name`, `stock_status` and the other business fields of the existing diff helper). A change that touches only non-audited fields, for example the description, produces an empty diff and NO per-product row. CREATED products write no per-product row, only the aggregate `product.imported` row. The rows are inserted by `AuditLog::recordMany` in chunks of 500 inside the apply transaction, with `metadata` `{import_id, file_name}`, subject = product, `company_id` null. A failure rolls the import back. The redactor and CHECK constraints are respected (backend verifies the action format regex and whitelisted metadata keys; if a CHECK forbids it, add a migration).
- Email layout (S26): `php artisan vendor:publish --tag=laravel-mail`; `resources/views/vendor/mail/html/header.blade.php` and `text/header.blade.php` render the app name as plain text linking to `config('app.web_url')`, never `config('app.url')`; no other published file is changed.
- `AuditLog` seam (S40): `$baseTransactionLevel` is honoured only when `app()->runningUnitTests()`.
- `Route::get('/')` removed (S42).
- Order-items trigger (S36), conditional as stated.
- Slow query log: `DB::listen` in `AppServiceProvider` logs `Log::warning('Slow query', ['ms', 'sql' (placeholders), 'connection'])` when the duration is above `PORTAL_SLOW_QUERY_MS` (default 500, `0` disables), never bindings.
- Health, logging, queue hooks: section 4.
- Mass assignment, CSRF, headers, limiter and cross-company sweeps: section 10.1.

## 4. Observability (B)

### 4.1 Structured logging

- `config/logging.php`: add channels `stderr_json` (Monolog `StreamHandler` on `php://stderr`, `JsonFormatter` with newline batch mode, `replace_placeholders`) and `daily_json` (daily files, `JsonFormatter`, `days` from `LOG_DAILY_DAYS`). `.env.example` keeps `single`; `docs/ops/env.production.example`: `LOG_CHANNEL=stack`, `LOG_STACK=daily_json` (or `stderr_json` under systemd or Docker), `LOG_LEVEL=info`, `LOG_DAILY_DAYS=14`.
- Every record carries `request_id` and (after authentication) `user_id` through `Context`. Rule: NEVER log email, name, token, password, request bodies, header values, bound SQL values, file contents. Allowed identifiers: user id, company id, resource public ids and numbers.
- Application logs (operational, rotate after 14 days, treated as sensitive files) versus the `audit_log` table (business record, append-only, admin UI) stay separate; nothing is written to both.
- Failed jobs: `Queue::failing` listener logs `Queue job failed` with job class, queue, attempts and the exception CLASS only (the message may contain addresses).
- Exception reporter (as built; final behaviour): the logged exception message has every email address masked as `[email]`; the stack trace is logged as FRAMES ONLY (`file:line` and function, no arguments, no object dumps) with a capped depth; a `QueryException` logs only the `sqlstate` and the placeholder SQL, never bindings. Final behaviour: masked message plus frames-only trace, capped depth. Only email addresses are masked: any OTHER free text an exception message echoes is still logged, cut at 300 characters (`ReportedException::MAX_MESSAGE`), so code must never put a secret or personal data into an exception message. A follow-up to restore the trace in exactly that form is recorded in section 16.

### 4.2 Health endpoints (B2)

- `/up` (framework) and `GET /api/v1/health` (existing, no DB, used by the SPA footer) stay liveness.
- New `GET /api/v1/health/ready`: public, limited by its OWN `health-ready` bucket (120 per minute per IP; the route drops the guest `api` throttle, 3.5), checks `SELECT 1` on the default connection and a cache write/read of a one-minute key. 200 `{"status":"ok"}` or 503 `{"status":"unavailable"}`; the body NEVER names the failing component, version, host or exception. The reason is logged (`Readiness check failed`, `check` name only), at most one line per failing check per minute (`LogThrottle`, 3.11), so a failing database probed every second does not flood the log. Queue and scheduler are NOT part of readiness (a late cron must not take a node out of rotation).
- `php artisan portal:health [--strict] [--json]` (CLI, trusted): database reachable, pending migrations (0 expected), cache read/write, storage writable, `failed_jobs` count, oldest pending job age, scheduler heartbeat age. Exit 0 healthy, 1 on failure; `--strict` also fails on a stale heartbeat (older than 3 minutes) or `failed_jobs > 0`. Used by the restore drill and the deploy step.

### 4.3 Log hygiene test

A Pest test attaches a Monolog `TestHandler` and drives failed login, reset request for a known email, a 422 with PII in the body, an upload, a forced `QueryException` and a failing job. It asserts that no record (message, context, extra) contains the email, a password, a token, an IBAN, a bound value, or a header value, and that every record has `request_id`.

### 4.4 Error tracking decision (B1, open question Q2)

Recommendation: do not add an SDK in Phase 7. Reasons: new dependency, a third-party data processor (DPA, region, retention), and exception payloads can contain personal data. What is delivered instead: JSON logs with request id, `X-Request-Id` visible to users on error screens (the SPA shows "Reference: <id>" on generic error states, so support can find the log line), failed-job logging, the slow-query log, and the health endpoints. Phase 8 wires alerting on log level `error` from the log files.

If the user approves an SDK later, the contract it must meet: DSN from env only, empty DSN = SDK inert; `send_default_pii=false`; `before_send` drops request bodies, cookies, `Authorization`, `Cookie`, `X-XSRF-TOKEN` headers and query strings; user context is the integer id only; SPA: no session replay, URL scrubbing for `token` and `email` query parameters, `connect-src` extended explicitly; EU region or self-hosted (GlitchTip) preferred; tests assert the scrubber with a fixture event. Candidates: `sentry/sentry-laravel` (MIT) and `@sentry/react` (MIT). Neither is added now.

### 4.5 Scheduler and queue hooks

`routes/console.php` additions (all built-in Laravel features, no dependency): `Schedule::call(fn () => Cache::put('scheduler:heartbeat', now()->timestamp, 3600))->everyMinute()`; `Schedule::command('queue:monitor database:default --max=100')->everyFiveMinutes()` with a `QueueBusy` listener that logs `Queue backlog` (queue, size); `Schedule::command('queue:prune-failed --hours=720')->daily()`; `Schedule::command('quotes:storage-check')->daily()`. Existing: `quotes:expire`, `invoices:mark-overdue`, `imports:prune`. Phase 8 runs the cron and the worker; the heartbeat is only meaningful then.

## 5. Backup plan and drill (C)

Owner: main session writes `ops/backup/*` and `docs/ops/backup.md`; qa-engineer runs the drill and records the result. Phase 7 proves it locally and in CI; Phase 8 executes on real storage.

### 5.1 What is backed up

| Asset | Method | Notes |
| --- | --- | --- |
| PostgreSQL (all tables, including `audit_log`, `invoices*`, `invoice_number_counters`, `sessions`) | `pg_dump --format=custom --no-owner --no-privileges` (roles and grants come from `ops/db/roles.sql`) | `jobs` and `cache` contents are disposable but harmless to include. |
| Private storage `storage/app/private/quotes` | `tar` + gzip | `imports` are ephemeral (pruned hourly) and excluded. |
| Secrets (`.env`, `APP_KEY`) | NOT in the archive; stored in the secret store or password manager | Losing `APP_KEY` invalidates sessions and every queued encrypted job and any `encrypted` cast; keep a sealed copy separately. |

Order and consistency: take the database dump first, then the file archive. Files created after the dump become orphans (harmless, reported by `quotes:storage-check`); the reverse order could leave rows without files. After a restore run `quotes:storage-check` and read both lists.

### 5.2 Policy placeholders (user decides, Q19)

- RPO: 24 hours with a nightly dump; 15 minutes only with WAL archiving or managed point-in-time recovery (a hosting decision).
- Retention: 7 daily, 4 weekly, 12 monthly. Encryption at rest and in transit: `age` or `gpg` for files leaving the host, or provider-side encryption; offsite copy in another account or region.
- RTO: 4 hours (placeholder).
- Restore test: monthly and after every release that contains a migration; the result is logged in `docs/ops/restore-log.md`.
- Invoice rule (Phase 5): restoring an older backup loses invoice numbers issued after it. The procedure requires comparing `invoice_number_counters` with the newest invoice number the business has sent; a restore never lowers a counter below the highest sent number (manual correction documented).

### 5.3 Scripts (`ops/backup/`)

- `backup.sh`: env `PGHOST PGPORT PGUSER PGPASSWORD PGDATABASE`, `STORAGE_DIR`, `OUT_DIR`; writes `<ts>.dump`, `<ts>-files.tar.gz`, `<ts>.sha256`; refuses to run when `OUT_DIR` is inside the repository working tree unless `ALLOW_IN_TREE=1`. `USE_DOCKER=1` runs the client tools through `docker compose exec -T db`. As built: `umask 077` and the output directory set to `0700` (the dump holds personal data, invoices, the audit log and sessions); a failed run removes its partial dump, archive and checksum file, so nothing that looks like a backup is left behind. The files are NOT encrypted by the script: ENCRYPTION IS REQUIRED BEFORE ANY COPY LEAVES THE HOST (`age` or `gpg`, or provider-side encryption; Phase 8).
- `lib.sh` (sourced by every script): with `USE_DOCKER=1` secrets are forwarded into the db container by NAME only (`docker exec -e PGPASSWORD`, plus the names in `PGX_PASS_ENV`), never as `NAME=value`, so they do not show up in the host process list.
- `restore.sh <dump> <target-db>` (a restore script is also a drop-database script, so it is hard to misuse): the dump's checksum must verify (`<ts>.sha256` next to it; `ALLOW_UNVERIFIED=1` skips, and is required when no checksum file exists); it refuses `postgres`, `template0`, `template1` and the database named by `PGDATABASE` or `DB_DATABASE` as a target (restore into a NEW name, check it, then switch the app over); a target that already holds tables needs `--force` AND `CONFIRM_DROP=<that database name>` AND no other session connected to it.
- `restore-drill.sh`: (1) backup of the source DB (default `portal_e2e` after a seed, or any DB named in env); (2) create scratch DB `portal_drill_<ts>`; (3) `pg_restore`; (4) verify: same list of tables, same row count per table, a content hash per table (`md5(string_agg(t::text, '' ORDER BY <pk>))`), same `migrations` rows, same count of non-internal triggers (`audit_log_forbid_change`, invoice triggers survive), same index count; (5) files: extract the archive to a temp dir and compare `sha256sum` manifests; (6) the app boots against the scratch DB: `DB_DATABASE=<scratch> php artisan portal:health` (the plain check, NOT `--strict`: strict also demands a scheduler heartbeat, which a freshly restored copy has not produced yet) exits 0 and `migrate:status` shows nothing pending; (7) drop the scratch DB (`--keep` keeps it); exit non-zero on any difference and print which table differs. The drill needs a QUIET source database: the fingerprint is taken after the backup, so a live database that keeps changing during the drill gives false differences (drill a copy, a restored backup, or a stopped application).
- `ops/db/roles.sql` and `ops/db/verify-roles.sh` (S28): roles `portal_owner` (migrations, owns tables) and `portal_app` (runtime). As built:
  - Role names are psql variables `owner_role` and `app_role` (defaults `portal_owner` and `portal_app`; `-v owner_role=... -v app_role=...`). Passwords come from the environment through `\getenv` (`OWNER_PASSWORD`, `APP_PASSWORD`), never from the command line (visible in process lists); a missing one stops the script (exit 3). The script runs `SET log_statement = 'none'` first, so the password statements stay out of the server log, and `REVOKE CONNECT ON DATABASE ... FROM PUBLIC` (only the two roles and superusers can connect).
  - Roles are CLUSTER-WIDE: running the script resets the passwords of the roles it names, on every database of the server. That is why `verify-roles.sh` never uses the real names.
  - It is idempotent and MUST BE RE-RUN AFTER EVERY MIGRATION THAT CREATES TABLES: default privileges cover new tables created by the owner, but the `REVOKE UPDATE, DELETE ON audit_log` only holds until a migration recreates that table, and `GRANT ... ON ALL TABLES` only covers tables that exist when it runs. Phase 8 deploy order (HARD STEP, not optional): migrate as the owner role, then re-run `roles.sql`, then run the verification checks, then switch the app over; a deploy where the verification fails is rolled back or blocked.
  - `verify-roles.sh` uses throwaway names `portal_owner_v<ts>` and `portal_app_v<ts>` and a scratch database `portal_roles_<ts>`, generates random passwords, applies `roles.sql`, migrates and seeds as the owner, re-applies `roles.sql` (the re-run step above), and drops its roles and database on exit (trap). It refuses a non-local `PGHOST` unless `VERIFY_ROLES_ALLOW_REMOTE=1` (it creates and drops roles and databases). It also asserts that no unrelated login role can CONNECT.
  - Privileges: `portal_app` gets `SELECT, INSERT, UPDATE, DELETE` on all tables and `USAGE, SELECT` on sequences, `ALTER DEFAULT PRIVILEGES` for future tables created by the owner, then `REVOKE TRUNCATE, TRIGGER, REFERENCES` on all tables, and on `audit_log` only `SELECT, INSERT`. The verify script (run against a scratch DB migrated by the owner) asserts through `has_table_privilege` that `portal_app` cannot TRUNCATE, cannot create or drop triggers (not owner), cannot ALTER TABLE, cannot UPDATE or DELETE `audit_log`, and still can run the application smoke queries (`SELECT` and `INSERT` on `orders`, `UPDATE invoices` for status).
- CI: job `ops-drill` (section 9.2) seeds a scratch source DB with `migrate --seed`, creates two dummy files in a temp storage dir, runs the drill and the role check. Duration about 3 minutes.

## 6. Performance (E)

### 6.1 Load data generator

`php artisan portal:loadtest-seed` (backend, `app/Console/Commands/SeedLoadTestData.php`).

As built (this supersedes the option table below where they differ):
- The generator builds everything the EXPLAIN review needs: companies, users (customers `load001@load.test` upwards, `loadstaff@load.test` and the admin `loadadmin@load.test`), products, orders with their lines and status history, quotes with lines and history, invoices and audit rows. Options `--products`, `--companies`, `--users`, `--orders`, `--quotes`, `--invoices`, `--audit` and `--scale` (multiplies products, orders, quotes, invoices and audit rows). Defaults: 5,000 products, 20 companies, 100 users, 50,000 orders, 10,000 quotes, 20,000 invoices, 200,000 audit rows. Part numbers are realistic (`6205-2RS`, `6305-ZZ`, `32208` style).
- By design quotes have NO attachments (a row without its file would break `quotes:storage-check` and the download), and invoices are created only for invoiceable orders, numbered 1..N per issue year without gaps (counters aligned afterwards). About 70% of orders get a valid PO reference of four realistic shapes (so the PO branch of order search can be reviewed on generated data).
- Seed bugs found by the QA run (D1, D2 in `docs/phase-7-results.md`) are FIXED: the `order_items` and `quote_items` joins no longer degrade into a nested loop, and `--products=50000 --orders=500000` now seeds in about 2 m 42 s instead of not finishing after 19 minutes.
- Guards, all enforced before anything is touched: (1) `APP_ENV` must be `local`, `testing` or `loadtest`; (2) the database name must end in `_load`, `_loadtest` or `_test` (`_test_<n>` per-worker databases are accepted); (3) `LOADTEST_PASSWORD` must be at least 12 characters (never a published demo password). Each refusal exits non-zero with a message and changes nothing.
- It is DESTRUCTIVE by design: inside one transaction it runs `TRUNCATE ... RESTART IDENTITY CASCADE` on users, companies and products (and, through the cascade, everything that references them). That is acceptable only because of guards 1 and 2; never point it at a database that holds data to keep.
- Ends with `ANALYZE`.

Original specification: guard: refuses unless `APP_ENV` is `local`, `testing` or `loadtest`; never runs in production or staging. Set-based SQL only (`INSERT ... SELECT generate_series(...)`, the technique of `bulkOpenInvoices` in `tests/Pest.php`): no Faker and no factories (they are dev dependencies and the load image installs `--no-dev`). Options and defaults:

| Option | Default | Notes |
| --- | --- | --- |
| `--products` | 5000 | realistic part numbers (`6205-2RS`, `6305-ZZ`, `32208`, ...), 12 categories, 40 brands, mixed stock, 3% price on request, 2% inactive; the GIN trigram index must be present |
| `--companies` / `--users` | 20 / 100 | customers `load001@load.test` to `load100@load.test`, password from env `LOADTEST_PASSWORD` (never the demo password), staff `loadstaff@load.test` |
| `--orders` | 50000 | with items (about 3 each) and history |
| `--quotes` | 10000 | mixed statuses |
| `--invoices` | 20000 | respects numbering counters, CHECKs and triggers (counters aligned after insert) |
| `--audit` | 200000 | |
| `--scale` | 1 | multiplies the volume options (growth probe 10x products = 50,000) |

Ends with `ANALYZE`. Idempotent: truncates the generated tables first (only the load database). Pest test on a tiny scale (`--products=50 --orders=100 ...`) asserts counts, CHECK and trigger satisfaction, and the production refusal.

### 6.2 EXPLAIN review

How: on the load database (Docker Postgres, `ALTER DATABASE portal_load SET session_preload_libraries='auto_explain'` plus `auto_explain.log_min_duration=0`, `log_analyze=on`, `log_buffers=on`, `log_nested_statements=off`), run each endpoint once warm and twice measured, read the plans from `docker compose logs db`. No application code is needed. Run at two scales: gate scale (5,000 products, 50,000 orders, 10,000 quotes, 20,000 invoices, 200,000 audit rows) and growth scale (50,000 products, 500,000 orders). QA writes the results to `docs/phase-7-results.md` (plan node, time, verdict).

Queries and acceptance (execution time on the Postgres side, warm cache; every endpoint total DB time below 100 ms at gate scale unless stated):

| Query | Expectation |
| --- | --- |
| `GET /products?q=6205` (list and count) | `products_part_number_search_trgm` used for q of 3+ characters; 1 to 2 character q may seq-scan: under 50 ms at 5,000, under 150 ms at 50,000 |
| `GET /products` with category, brand, seal and size ranges, sorts, page 1 to 5 | under 40 ms; sort by `part_number_search` uses the unique index or an in-memory sort of a small set |
| `catalog:filters` rebuild and categories counts | under 30 ms at 5,000; if above 100 ms at 50,000 the single-flight (A8) is justified |
| `GET /orders` customer: company + created_at, + status, + `q` (PO ILIKE and order number) | index scan on `(company_id, created_at)`; PO branch: decision rule A9 |
| `GET /orders` staff: status + created_at over all companies; staff `q` | index on `(status, created_at)`; PO branch under 100 ms at 50,000 |
| `GET /quotes` (including `expiring_within_days`) | `(company_id, ...)`, `(status, created_at)` and the partial `valid_until` index used |
| `GET /invoices` customer and staff (`status=open,overdue&sort=due_date`, `due_within_days`) | `(status, due_date)` used; effective-status predicate does not force a seq scan on 20,000 rows |
| `GET /admin/dashboard` (4 aggregates) | each under 100 ms |
| `GET /admin/companies` (list with 4 count subqueries for 20 companies) | under 100 ms total (Phase 6 note) |
| `GET /admin/users` with `q`, `GET /admin/audit-log` default, by actor, by subject, by company, by action, date range | audit default uses `audit_log_occurred_idx`; total count over 200,000 rows under 150 ms; if above, report to the user before changing the `meta.total` contract |
| order and quote detail, history, items | primary key and FK index lookups, under 10 ms |
| `sessions` GC delete, cache and limiter increments (`cache` table), `jobs` poll | no seq scan on `sessions` (index on `last_activity` exists); limiter increments under 5 ms |

Index candidates (new migrations only, each needs a plan that shows the problem; reversible; `pg_indexes` assertion added to the schema test):
1. `CREATE INDEX orders_po_reference_trgm ON orders USING gin (po_reference gin_trgm_ops) WHERE po_reference IS NOT NULL;` only if the PO branch exceeds 100 ms at 50,000 orders (A9, S20). MEASURED (`docs/phase-7-results.md`): the staff `q` search is 74 to 90 ms at 50,000 orders (budget 100 ms) and about 260 to 270 ms at 500,000. This candidate ALONE would not remove the sequential scan: the filter is `replace(order_number, '-', '') LIKE ... OR po_reference ILIKE ...` and Postgres can use a BitmapOr only when BOTH branches are indexable, so a fix needs a trigram index on the expression `replace(order_number, '-', '')` as well (or an application change that searches the order number by prefix). No index is added now (rule below); MONITORED: revisit when real order volume approaches 50,000 or the staff search p95 exceeds 100 ms.
2. `orders (company_id, status, created_at DESC)`, `quote_requests (company_id, status, created_at DESC)`, `invoices (company_id, status, due_date)` only if status-filtered customer lists sort or filter inefficiently at the gate scale.
3. Drop candidates reported, not dropped: after the load run read `pg_stat_user_indexes`; the Phase 2 low-cardinality product indexes (`material`, `seal_type`, `stock_status`) with `idx_scan = 0` are listed for the user (they are small; default keep).
Rule: no index is added "just in case".

Filter-option caching verification (A8): TTL 600 s, key global and user-independent (no per-company data, so no cross-company leak), flushed after commit on product create, update, activate, deactivate, import apply and CLI import (non-dry). Pest asserts each flush and the single-flight. Staleness bound if a future write forgets to flush: 10 minutes.

### 6.3 Load check scenario (gate item 7)

Tool: `e2e/loadtest/catalog-search.mjs` (owner qa-engineer, Node 24 built-in `fetch`, no dependency). Rig: `ops/loadtest/` (owner main session).

Rig (representative server):
- `ops/loadtest/compose.yml`: services `app` (php-fpm 8.3 built from `ops/loadtest/Dockerfile`: `composer install --no-dev -o`, extra extensions `pdo_pgsql` and `opcache` only (mbstring, dom, fileinfo, iconv are in the official image), `opcache.enable=1`, `validate_timestamps=0`, `memory_consumption=128`, `php artisan config:cache route:cache event:cache`, `pm=static`, `pm.max_children=16`), `web` (nginx 1.27 with the working config: SPA root, `/api` and `/sanctum` to FPM, `client_max_body_size 12m`, realip from the Docker network so the script's `X-Forwarded-For` is honoured exactly like behind a load balancer), `db` (Postgres 16 with `shared_buffers=256MB`, `auto_explain` loadable), no Mailpit (`MAIL_MAILER=log`). Environment: `APP_ENV=loadtest`, `APP_DEBUG=false`, `CACHE_STORE=database`, `SESSION_DRIVER=database`, `QUEUE_CONNECTION=database`, `LOG_LEVEL=warning`. Container CPU and memory limits are recorded in the result (Docker Desktop on Windows differs from Linux CI; state the host in the report). Images pinned by digest.
- Credentials and targets (as built): `compose.yml` REQUIRES `LOADTEST_PASSWORD` (12 characters or more; there is no published default, startup fails without it) and the same value must be given to `portal:loadtest-seed` and to the script. CI (`load.yml`) generates a random one per run and masks it in the log. `e2e/loadtest/catalog-search.mjs` also requires `LOADTEST_PASSWORD` and refuses a non-local `LOADTEST_BASE_URL` unless `LOADTEST_ALLOW_REMOTE=1` (it logs in 50 users and fires bursts, so it must not hit a real server by accident). A similar remote-target guard for `e2e/a11y/lighthouse.mjs` is done: `e2e/a11y/lighthouse.mjs` refuses non-local `LH_BASE_URL` unless `LH_ALLOW_REMOTE=1` (not part of this sync).
- Why not `php -S`: single process on Windows, no opcache by default; with `PHP_CLI_SERVER_WORKERS` (Linux only) it is only fit for the e2e suite, not for latency numbers.

Users and limiters (not weakened):
- 100 load customers exist (6.1). The script logs in 50 of them through the real flow (`GET /sanctum/csrf-cookie`, `POST /auth/login` with the XSRF header and `Origin`), each login carries a distinct `X-Forwarded-For` (`203.0.113.<n>`) so the per-IP login limiter (20/min) does not serialise setup. Cookies are kept per virtual user (parse `getSetCookie()`).
- Each virtual user paces itself: think time uniform 1.5 to 2.5 s, so about 30 requests per minute, under `catalog-search` (40) and `api` (60). The measured phase must contain ZERO 429.
- After the measured phase one extra user fires a burst (60 requests in 10 s) and the script asserts that the first 429 appears at request 41 with `Retry-After`. These intended 429s are reported separately and excluded from latency and error counts.

Scenario (50 virtual users, closed loop):
- Phases: ramp 10 s, warm-up 30 s (discarded; also warms opcache, buffer cache and the filters cache), measure 120 s locally and 60 s in CI.
- Request mix per iteration: 35% `GET /products?q=<fragment>&per_page=24` with fragments drawn from a fixed list of 30 (`6205`, `6205-2RS`, `62`, `6`, `2rs`, `32208`, `NU`, `16005`, ...: short fragments are the worst case); 25% category + brand + `seal_type` + `sort=-price` + `page` 1 to 5; 15% size ranges (`inner_diameter_min/max`, `outer_diameter_max`) + `page` 1 to 10; 10% `q` + `page` 2 to 8; 5% `GET /products/filters`; 5% `GET /products/{id}`; 5% `GET /categories`. Every 200 body is checked (`data` is an array, `meta.total` is an integer); anything else counts as an error.
- Metric group "catalog search" = all `GET /products` list requests. The gate applies to this group.

Acceptance (script exit code): p95 of the group below `LOADTEST_P95_MS` (default 500; CI relaxed 1000); error rate 0 (any non-200 except the intended probe); unintended 429 count 0; the script also prints p50, p90, p99, max, requests per second and the same table for all requests. Results JSON to `e2e/loadtest/results/<timestamp>.json` (git-ignored).

Mixed variant (decides A11): the same run with 3 of the 50 users each downloading an invoice PDF every 30 s; accept if the catalog-search p95 is within 20% of the pure run; otherwise implement the dedicated FPM pool (Phase 8) or the code semaphore.

If it fails: rerun once to rule out a cold start; check FPM saturation (`pm.max_children` versus 50 users, `pm.status`), opcache enabled, `config:cache`; take the slowest requests' ids from the slow-query log (`request_id`) and run `EXPLAIN (ANALYZE, BUFFERS)`; candidate fixes in this order: a missing or unused index, a cheaper count (cap the count query at the window), cache of `meta.total` for 30 s keyed by the normalised filter set, smaller default `per_page`, FPM sizing. Never raise a limiter to pass the test.

### 6.4 Frontend performance and bundle (S44)

Gate: none (Lighthouse performance is not a gate). Work:
- Route-level `React.lazy` + `Suspense` (fallback `role="status"` "Loading"): `features/admin/*` (one chunk), `features/quotes/*`, `features/invoices/*`; login, home, catalog, cart, checkout stay in the entry chunk. Vite 8 chunking: prefer `lazy()` first; add vendor chunking only if the warning remains (the option is `manualChunks` or the rolldown equivalent, the frontend engineer picks what Vite 8 supports). Target: `vite build` prints no chunk-size warning, login-route JavaScript under 150 kB gzip (measure and report).
- Existing route tests may need `findBy*` instead of `getBy*` after lazy loading; the frontend engineer adapts them.
- Cheap wins (Nginx, Phase 8): gzip or brotli, `Cache-Control: public, max-age=31536000, immutable` on hashed `/assets/`, `no-cache` on `index.html`, HTTP/2. In the SPA: longer TanStack Query `staleTime` for `filters` and `categories`, avoid refetch on window focus for lists the user is editing (check current defaults), `loading="lazy"` for any future image.
- Per-route `document.title` (see 7.4) doubles as a quick win for navigation history.

As built (frontend F5, measured): route-level code splitting is in place with a `RouteBoundary` (Suspense fallback with `role="status"` plus an error state with a "Reload page" button for a failed chunk load). `vite build` prints no chunk-size warning; the largest chunk is 218.75 kB (react). The login route loads about 157 kB gzip of JavaScript against the 150 kB goal: the goal is MISSED by about 7 kB. It was a goal, not a gate. Options if the goal is kept: lazy-load catalog, cart and checkout (they are in the entry chunk today), or replace zod. Decision for the user (section 14, Q22).

## 7. Accessibility (D)

### 7.1 Tooling decision

| Option | Pros | Cons |
| --- | --- | --- |
| Lighthouse CLI/CI only | Matches the gate literally; one score | One number; weights a subset of axe rules; slow (10 to 20 s per run); poor failure detail; needs a logged-in browser for two of three pages |
| `@axe-core/playwright` only | Deterministic, per-page violation lists with selectors, runs inside the existing journeys and storage states, fast, CI friendly | Not the literal "Lighthouse score" |
| Both (chosen) | axe for every page and every PR, Lighthouse only for the three gate pages | Two devDependencies (both need approval) |

Literal gate: `e2e/a11y/lighthouse.spec.ts` (project `lighthouse`, enabled by `E2E_LIGHTHOUSE=1`). It launches Chromium with `chromium.launchPersistentContext(tmpdir, { args: ['--remote-debugging-port=9223'] })`, loads the role's `storageState` cookies into that default context (Lighthouse attaches to the default context, not to a Playwright `newContext`), then calls Lighthouse's Node API with `{ port: 9223, onlyCategories: ['accessibility'], disableStorageReset: true, output: 'json' }` for `/login` (as guest), `/catalog` and `/orders` (as alice), for the desktop and the mobile preset. It asserts every category score is at least 0.90, writes the JSON reports to `e2e/a11y-reports/` (git-ignored, uploaded as CI artifact) and prints a score table. It targets the PREVIEW build (production bundle). Requires `lighthouse` (Node 22 or newer; CI uses 24; confirm the Node version on the user's machine). Fallback if the dependency is refused: QA records a manual DevTools Lighthouse accessibility score for the three pages (desktop and mobile) in `docs/phase-7-results.md`.

axe: `e2e/a11y/axe.spec.ts` runs `new AxeBuilder({ page }).withTags(['wcag2a','wcag2aa','wcag21a','wcag21aa']).analyze()` and fails on any `serious` or `critical` violation (moderate and minor are printed and tracked in the results file; target zero within the phase). Rules waived must be listed in the spec with a reason. Pages are scanned in a settled state (wait for the table or heading, not for time), with dialogs also scanned open.

### 7.2 Pages (axe on all, Lighthouse on the three marked G)

Guest: `/login` (G), `/forgot-password`, `/reset-password?token=x&email=a@b.test` (renders the form), `/accept-invitation?...`, a 404 page, `/forbidden`-style "Not allowed" page.
Customer: `/` home, `/catalog` (G, with results, with empty result, with filters open), `/catalog/:id`, `/cart` (empty and with lines), `/checkout`, `/orders` (G), `/orders/:number`, `/quotes`, `/quotes/new`, `/quotes/:number` (with attachments), `/invoices`, `/invoices/:id`.
Staff and admin: `/admin`, `/admin/orders`, `/admin/orders/:number` (status dialog open), `/admin/quotes/:number` (pricing form), `/admin/invoices/:id`, `/admin/products`, `/admin/products/new`, `/admin/products/import` (preview state), `/admin/companies/:id`, `/admin/users` and `/admin/users/:id` (invite dialog open), `/admin/audit-log`.

### 7.3 Manual checklist (QA, recorded in `docs/phase-7-results.md`)

1. Keyboard only, no mouse, on login, catalog, cart, checkout, quote form, order detail with the status dialog, invoice detail, admin product form and import: reachable in a logical order, visible focus ring everywhere, no keyboard trap, Escape closes dialogs and returns focus to the opener, Enter and Space work on buttons.
2. Skip link: first Tab stop is "Skip to main content" and moves focus into `main`.
3. Screen reader pass (NVDA with Firefox or Chrome on Windows) on login, catalog table (caption, sortable headers announce `aria-sort`), checkout (error summary read via `role="alert"`), order status timeline, and a dialog (announced as dialog with its title).
4. 375 px width (and 320 px) with 200% zoom: no horizontal page scroll except inside table wrappers, no clipped controls, targets at least 24 by 24 CSS px.
5. Colour: contrast of status badges (all quote, order, invoice statuses), overdue text, disabled and "stale" (`opacity-60` while loading) states, placeholder text, link colour versus surrounding text; forced-colors (Windows high contrast) still shows focus and borders.
6. Reduced motion respected; text resizes to 200% without loss.
7. Language: `lang="en"` on `html` (present); every page has a unique `title`.

### 7.4 Known issues to fix first (frontend-engineer; from reading `web/src`)

1. No skip link in `components/Layout.tsx` (add as the first focusable element, visible on focus, target `main` with `id` and `tabIndex={-1}`).
2. `document.title` is set only on the product detail page; every route keeps "B2B Customer Portal". Set it from `PageTitle` (`<h1 text> - B2B Customer Portal`) with a test.
3. Landmarks: `Layout` has `header`, `nav aria-label="Main"`, `main` and `footer`; the admin layout has its own `nav` named "Back office": check there are not two unlabelled `nav`s and that `main` is unique on admin pages (axe `landmark-*` rules).
4. Contrast: badge colours in `features/*/StatusBadge.tsx` and the overdue text were never measured; the loading state dims content with `opacity-60`, which can drop text below 4.5:1 while axe runs (scan settled pages, but consider `aria-busy` plus a smaller visual cue); `text-red-700`, `text-red-800` on white are fine, verify any `slate-500` or lighter on grey backgrounds.
5. Dialogs: `components/ConfirmDialog.tsx` is hand-rolled (`role="dialog"`, `aria-modal`): verify the focus trap, initial focus, Escape, return of focus, and that the background is inert (`inert` or `aria-hidden`); the Phase 6 Accessibility tests cover part of it, extend to the invoice and quote action dialogs and the import apply dialog.
6. Live regions: the loading `role="status"` and result-count `aria-live="polite"` exist in `DataTable`; check that cart add/remove, checkout 409 alert, upload progress and success messages are announced, and that `role="alert"` is not on elements that are always present (announces nothing on mount, or noisily).
7. Form errors: `FormParts` wires `aria-describedby` and `aria-invalid`; check the quote form, pricing form, import page and filters (not all use `FormParts`), and add an error summary or focus-on-first-error on submit failure.
8. Heading order: one `h1` per page (`PageTitle` takes focus) and no skipped levels in cards and panels (axe `heading-order`).
9. Tables: captions exist (`sr-only`), check the catalog `ProductTable`, cart, order lines and invoice lines for headers `scope`, and that "Add to cart" buttons have row-specific names (the e2e relies on the row name; keep it).
10. The HealthStatus footer text must not announce on every poll (`aria-live` off).

### 7.5 As built (frontend F3 and F4) and known issues left for QA

Built:
- Staff and admin see the CSV formula warning next to every `.csv` attachment, and a Purge button on attachments (DELETE, any quote status); customers see neither.
- Skip link as the first focusable element, moving focus to `main`; per-route `document.title` and a polite live announcement of the new page; focus moves to the page heading after navigation.
- `ConfirmDialog` extended: focus trap, initial focus, Escape, focus returned to the opener, background made `inert` while open.
- Contrast: a Vitest test reads the Tailwind colour tokens used for text and asserts at least 4.5:1; visible focus ring on every interactive element; interactive targets at least 24 by 24 CSS px; `prefers-reduced-motion` honoured.

Known and NOT fixed (QA must check them in the axe and Lighthouse runs and in the manual pass of 7.3):
1. The first Tab on a first page load can skip the skip link, because pages focus their `h1` on mount (focus is then already past the link). Reload or Shift+Tab reaches it.
2. Scrollable table wrappers may trigger axe `scrollable-region-focusable`.
3. The contrast test only checks text tokens against white or against a background token of the same element class; combinations across unrelated elements (badge on a tinted row, text over an `opacity-60` loading state) are not covered. Axe on settled pages is the check.
4. The dialog's `aria-describedby` points at the whole dialog body, so a screen reader reads all of it on open.

## 8. Playwright journeys (F)

Conventions kept from Phase 6: ports 8100 and 5273, database `portal_e2e`, `globalSetup` runs `migrate:fresh --seed`, storage states per role, role and label selectors only (no CSS, no `data-testid`), no sleeps (`waitForResponse`, `waitForURL`, web-first assertions), one worker, API helpers from `e2e/helpers/api.ts`, Mailpit for emails. Cache is `array` in e2e, so limiters are inert there (limits are proven by Pest and the load script).

### 8.1 Files and fixtures

- `e2e/tests/customer-journey.spec.ts`, `e2e/tests/staff-journey.spec.ts`, `e2e/tests/races.spec.ts` (S35), `e2e/tests/headers.spec.ts` (API and SPA headers, CORS preflight), `e2e/a11y/axe.spec.ts`, `e2e/a11y/lighthouse.spec.ts` (7.1).
- `e2e/helpers/fixtures.ts`: `test.extend` with a CSP watcher (`page.on('console')` and a `securitypolicyviolation` listener) that fails the test on any violation; `actor()` and `actorFor()` in `helpers/api.ts` attach the same watcher to pages they create.
- `e2e/helpers/mailpit.ts`: `waitForMail({ to, subject })` polling `MAILPIT_URL/api/v1/search` with a bounded retry (poll on state, not on time), skipped with `test.skip` when `E2E_MAIL_MAILER=log`.
- `e2e/helpers/data.ts`: `createProduct({ prefix })` through the admin API, unique per test (`J-C-<Date.now()>`), so journeys do not depend on `E2E-0001` or on each other; assertions on dashboard cards use before and after deltas from the API, never absolute counts (other specs also create orders).
- `playwright.config.ts`: `E2E_WEB=preview` starts `npm run build && npm run preview -- --port 5273 --strictPort` instead of the dev server (same URL, so every helper works); CI sets it. `trace: 'on-first-retry'` (S39: traces contain cookies and demo passwords, keep them only for retried tests, 3-day artifacts). Journey tests set `test.setTimeout(180_000)`. A second project `lighthouse` is added only when `E2E_LIGHTHOUSE=1`.

### 8.2 Customer journey (one `test`, steps with `test.step`)

Budget: 90 s locally, 150 s on CI.
1. Login through the real form (fresh context, no storage state): lands on `/`, the Main nav shows Catalog.
2. Catalog: search a seeded part fragment, apply a brand filter; assert the URL carries `q` and `brand`, the result count text, and the table row; open the product detail and return with the browser back button, filters preserved.
3. Add two products to the cart (the unique journey product and a seeded one) while waiting for each `POST /cart/items`; on `/cart` change a quantity (PATCH), remove one line, assert the exact subtotal in cents formatted by `Intl` (hand-calculated in the test).
4. Price-change guard (S18): through staff API change the unit price of a cart product; on `/checkout` fill `PO reference`, click `Place order`; expect the 409, the alert with the new subtotal, no order created (`GET /orders` count unchanged); click `Place order` again; expect 201 at the new price and redirect to `/orders/ORD-...`; summary shows Pending and the PO reference; the line price equals the new price.
5. Order history: `/orders` lists the order first; search by the PO reference via the `q` box (URL gets `q`); status filter `pending`; open the order from the list.
6. Quote: `/quotes/new`: pick the product through the picker, add a custom line, notes, attach a generated PDF (`setInputFiles` with an in-memory buffer starting `%PDF`), submit (201); the detail shows Submitted and the attachment; download it (`waitForEvent('download')`) and check the file name and the first bytes. Mail: Mailpit has the staff notification (skipped without Mailpit).
7. Staff API (setup, not UI): review, price both lines, set `valid_until`, send. Customer reloads: status Quoted with the total; Mailpit has the "quote sent" mail with a link to `app.web_url/quotes/Q-...` (when available).
8. Customer accepts with the revision confirm dialog; the page shows the created order number and link; follow it (order of the quote exists, Pending).
9. Staff API: confirm the original order and issue its invoice. Customer `/invoices` shows the new invoice Open; detail shows lines and totals; "Download PDF" returns a download whose name matches `INV-YYYY-NNNNNN.pdf`, first bytes `%PDF`, size above 1 KB.
10. Logout through the button: lands on `/login`; `GET /api/v1/me` through the same context is 401; navigating to `/orders` redirects to `/login?next=%2Forders`; the browser back button shows no private data (page asks for login again).
11. axe checkpoint (serious and critical only) at steps 2, 3, 4, 6 and 9 using the shared helper (full page list lives in `axe.spec.ts`).

### 8.3 Staff journey (one `test`)

Budget: 120 s locally, 200 s on CI. Setup through the customer API with a fresh context: a pending order (PO `J-S-<ts>`) and a submitted quote with one line and a note.
1. Login through the form as staff: dashboard heading, cards present (values compared to before/after API deltas later).
2. Orders: `/admin/orders?status=pending&q=<PO>`, open the order, "Mark as confirmed", Confirm; summary Confirmed; the dashboard "pending orders" card decreased by one compared to the API value read at step 1.
3. Quote: `/admin/quotes?status=submitted,in_review`, open the setup quote, start review, enter unit prices and `valid until`, save pricing, Send; the status is Quoted; staff note and internal note saved and the internal note is absent from the customer API view.
4. Invoice: from the confirmed order click create invoice (default due date), the invoice detail shows number, totals, buyer and seller; "Mark paid" with the confirm dialog; status Paid, buttons gone; download the PDF as staff.
5. Product: `/admin/products?q=<journey product>`, edit name and price; a second edit with the stale `version` (use a parallel API update in between) shows the 409 banner and "Reload latest"; after reload the save works; customer catalog (API) shows the new name and price; deactivate with the dialog; customer catalog no longer lists it; reactivate.
6. Company billing: `/admin/companies/<id>`, edit billing address and tax id, save (version increments), reload shows the values; a customer invoice issued earlier still has the old buyer snapshot (API assertion).
7. CSV import: `/admin/products/import`, upload a generated CSV (one price update of the journey product, one new product, one invalid row); preview shows counts and the line-numbered error as text; Apply is disabled until "Skip invalid rows" is ticked; confirm dialog; summary; the new product and the updated price exist (API).
8. Audit (admin context in the same test): `/admin/audit-log` filtered by actor staff shows rows for `order.status_changed`, `quote.sent` (and the other quote actions), `invoice.created`, `invoice.paid`, `product.updated`, `company.billing_updated`, `product.imported` and the per-product `product.import_updated` row with the exact before and after price (S37); every row's actor is Sam Staff with role staff; no row exists for the invalid CSV row.
9. Guards: staff has no Users or Audit log in the Back office nav; `/admin/users` shows "Not allowed"; staff logout.

### 8.4 Races (S35, `races.spec.ts`)

(a) Twelve parallel `POST /quotes/{n}/attachments` on one quote: at most 10 files exist afterwards and every rejected response is 422 `file`; (b) two parallel `apply` calls for the same import token: exactly one 200 and one 422; (c) two parallel `POST /orders` with the same cart: exactly one 201 and one 422 `cart`. API-level, no UI.

### 8.5 Flake policy and CI

No sleeps, no `networkidle` as a wait, no order dependence, unique data. Retries: 1 on CI, 0 locally. A test that passes only on retry is reported by Playwright as flaky: it must be fixed before sign-off (no quarantine). Stability gate before QA signs the phase: `npx playwright test customer-journey staff-journey --repeat-each=10` green locally. CI: the existing `e2e` job runs the suite against the preview build, then `E2E_LIGHTHOUSE=1 npx playwright test --project=lighthouse`; `timeout-minutes` 25. Expected total: journeys about 4 minutes, existing specs about 3, axe about 2, Lighthouse about 3.

## 9. CI and process (G, owner: main session)

### 9.1 Audit workflow (`.github/workflows/audit.yml`)

Triggers: `push`, `pull_request`, weekly `schedule` (advisories appear without code changes), `workflow_dispatch`. Job steps: checkout, setup-php, `composer audit --locked --format=json > composer-audit.json` (continue), setup-node, `npm audit --json` in `web` and in `e2e` (continue), then `node ops/audit/check-audit.mjs`. The script (no dependency) fails when:
- a finding is `high` or `critical` and is not in `.audit-allowlist.json`; a Composer advisory without a severity counts as `high` (fail safe);
- an allowlist entry is past its `expires` date, or its lifetime exceeds 60 days, or it has no `reason` and `owner`;
- (warning only) an allowlist entry matches no finding any more (remove it).
- FAIL CLOSED (exit 2, never a pass): no input files at all; a missing, empty or unreadable report; an npm report without `auditReportVersion` 2 and `metadata.vulnerabilities` (a registry or network error prints `{"error": ...}` and used to look like "zero findings"); a Composer report without an `advisories` key (a clean `composer audit --format=json` always prints it, empty). `node ops/audit/check-audit.mjs --self-test` covers these cases.
Allowlist format: `[{ "ecosystem": "composer|npm", "package": "...", "id": "GHSA-... or CVE-...", "reason": "no fix available, not reachable because ...", "owner": "...", "added": "YYYY-MM-DD", "expires": "YYYY-MM-DD" }]`. Policy for an advisory without a fix: first try a patched fork, an override or removing the feature; otherwise allowlist for at most 60 days with the reachability argument, review at expiry. Dev-only tooling findings are still audited (CI and developer machines run that code); abandoned packages are reported, not failed. Composer: `config.audit.abandoned` stays `report`. Output of the script is a table in the job summary.

### 9.2 Main CI (`ci.yml`) changes

- Top: `concurrency: { group: ci-${{ github.ref }}, cancel-in-progress: true }`; `timeout-minutes` on every job (api 25, web 10, e2e 25, ops-drill 10); `persist-credentials: false` on every checkout; keep `permissions: contents: read` and SHA pins (new actions: `actions/cache` for the Composer cache, the main session looks up the SHA).
- Service images pinned by digest: `postgres:16@sha256:...`, `axllent/mailpit@sha256:...` (S39). Dependabot's `docker` entry points at `/ops/loadtest` (the Dockerfile and compose file there). It does NOT update digests inside the workflow `services:` blocks: those are bumped BY HAND (pull the tag, read `docker image inspect --format '{{index .RepoDigests 0}}'`) when an advisory requires it; the rule is written at the top of `ci.yml`.
- `api` job: Composer cache; run Pest in two steps: `./vendor/bin/pest --parallel --processes=4 --exclude-group=race`, then `./vendor/bin/pest --group=race`. Requirements and safeguards:
  - Result (measured, as built): `./vendor/bin/pest --parallel --processes=4 --exclude-group=race` took 426 s, then `./vendor/bin/pest --group=race` 28 s, in total about 454 s against 1097 to 1186 s for the serial run: about 62% faster, well above the 35% acceptance bar, so parallel stays. Mechanics: one database per worker (`portal_test_test_1` to `portal_test_test_4`), so the DB user needs the CREATE DATABASE privilege (locally the Docker `portal` user, in CI the service superuser). The `TestCase` guard (refuses to run against a non-test database) and `tests/Support/race_child.php` accept a database name ending in `_test` or `_test_<n>`. The 20 race tests (`Admin/ConcurrencyTest`, `Invoices/InvoiceNumberingTest`) are tagged `group('race')` and run serially in the second step. Static trusted host and proxy lists (framework statics) are reset in `TestCase::tearDown`, so a test that sets them cannot leak into the next test of the same worker. The Linux CI job needs a `.env` (`cp .env.example .env`) before the tests, and the Postgres user needs CREATE DATABASE rights.
- `brianium/paratest` is already installed as a dependency of Pest 3 (verified in `composer.lock`): no new dependency.
  - Laravel creates one database per worker (`portal_test_test_1` ...): the CI Postgres user is the superuser, locally the Docker `portal` user is too. `phpunit.xml` keeps `DB_DATABASE=portal_test force="true"`; the backend verifies that workers get their own databases.
  - The race tests (`Admin/ConcurrencyTest`, `Invoices/InvoiceNumberingTest`) spawn real child PHP processes and `TRUNCATE` tables: tag them `->group('race')` (file-level `pest()->group('race')`), run them serially after the parallel step. They read the connection from the worker's config, so they would also work in parallel, but CPU contention makes timing-based races flaky.
  - Every test must not share state through static variables, files under `storage/` or fixed paths: `tests/Pest.php` has `static $n` counters in helpers (safe per process); any test writing real files uses `Storage::fake`. Memory: `memory_limit` 256M is in `phpunit.xml` and applies to each worker.
  - Acceptance: wall time at least 35% lower than the serial 15 minutes; otherwise revert to serial and keep the Composer cache. Measure three runs. `pint --test` runs in its own parallel job (`lint-api`) so it does not wait for the suite.
- `web` job: unchanged plus a step that fails if `npm run build` prints a chunk-size warning (`vite build 2>&1 | tee build.log; ! grep -i "chunks are larger" build.log`). The step uses `shell: bash` (an explicit shell enables `pipefail`, so a failing build is not hidden by `tee`).
- `e2e` job: `E2E_WEB=preview`, `E2E_LIGHTHOUSE` second step, artifact upload with `if: failure()` for traces and `always()` for `a11y-reports`, retention 3 days (traces and reports contain demo cookies and credentials of an ephemeral runner).
- `ops-drill` job: Postgres service, setup-php, `composer install`, `.env`, create scratch DB, `migrate --seed` (local env), `ops/backup/restore-drill.sh`, `ops/db/verify-roles.sh`.
- Optional later: `--ignore-scripts` for `npm ci` in the audit job.

### 9.3 Load workflow (`.github/workflows/load.yml`)

`workflow_dispatch` and weekly schedule on main, never on pull requests (noisy runners). Generates a random `LOADTEST_PASSWORD` for the run and masks it (`::add-mask::`), builds `ops/loadtest/compose.yml`, seeds with `portal:loadtest-seed`, runs `e2e/loadtest/catalog-search.mjs` with `LOADTEST_P95_MS=1000 LOADTEST_DURATION=60`. The CI result is a regression indicator; the authoritative gate run is local Docker (or Phase 8 staging) with threshold 500.

### 9.4 Dependabot and pins (`.github/dependabot.yml`)

Ecosystems: `github-actions` (`/`), `composer` (`/api`), `npm` (`/web`), `npm` (`/e2e`), `docker` (`/ops/loadtest`; the `services:` images of the workflows are bumped by hand, 9.2). Weekly, `open-pull-requests-limit: 5` each, grouped minor and patch updates, labels `dependencies`. Actions stay pinned by SHA with the version comment (Dependabot updates both). Repository settings the USER must enable (not files): Dependabot alerts and security updates, secret scanning with push protection, private vulnerability reporting, branch protection requiring the CI jobs.

### 9.5 `docs/SECURITY.md` outline (written by the main session after the controls exist)

Reporting: contact placeholder (the user supplies an address, Q13), acknowledgement within 3 business days, fix target by severity, coordinated disclosure; supported versions: the deployed release only. Controls summary table: authentication and sessions, CSRF, CORS, role and company scoping (route audit and the cross-company matrix), rate limits, headers and CSP, uploads, audit log, secrets handling, logging rules, backups, dependency policy. Accepted risks: no malware scanning (S24), no CSP report endpoint, LGPL libraries not distributed (S30), `HEAD` on PDF 405, equal-subtotal checkout check limit, staff may purge attachments of OPEN quotes (every purge is audited), and upload content types (PDF, PNG, JPEG, TXT, CSV) can carry active content for whoever opens the file locally (the download is forced as `attachment` with a sandbox CSP, and the staff UI warns about CSV formulas). The "least-privilege roles" control is true only after `ops/db/roles.sql` has been applied AND re-run after every migration that creates tables. Third-party data flows: HIBP prefix (if approved), mail provider, error tracker (if approved).

## 10. Test plan

### 10.1 Backend Pest (backend-engineer), gate items

New files in `api/tests/Feature/Security/` unless noted; helpers prefixed `sec`/`matrix`.

1. Gate 1, route audit: the existing `Admin/RouteAuditTest.php` plus the extensions of 3.10 (all routes, literal public list, throttle and stateful middleware on every `api/*` route).
2. Gate 2, `CrossCompanyMatrixTest` (consolidated IDOR matrix, enumerated by the router like `RouteMatrix`):
   - Extend `tests/Support/RouteMatrix.php` with `SCOPE` (route key to scope kind: `public`, `shared` (catalog, categories, health), `user` (cart, `me`), `company:order`, `company:quote`, `company:invoice`, `company:attachment`, `admin` (cross-company by design, staff only)) and `BODY` (a minimal valid body per write route so a 422 cannot mask a leak). A test asserts `SCOPE` keys equal the router, and that every route with a `number`, `invoice` or `attachment` parameter has a `company:*` scope: a new route cannot be forgotten.
   - World: companies A and B, each with an order, a sent quote with a customer attachment, an invoice (open) and a priced quote in states needed by the action routes; users a1, a2 (company A), b1 (B), a customer without company, staff, admin.
   - For every `company:*` route, as b1 with A's identifiers: status 404 and body exactly `NOT_FOUND`, identical to the response for an unknown id of the same shape (no existence oracle), and a before/after snapshot of A's rows (status, totals, attachment count, invoice status, history count) is unchanged. As a2 (same company) the read routes succeed. As the customer without a company: 404 or empty. Staff and admin: not 404 for existing ids. Guest: 401.
   - Lists as b1 (`GET /orders`, `/quotes`, `/invoices`) with `company_id=A`, `q=<A's PO or number>`, status filters and the largest page: the serialised JSON contains none of A's numbers, public ids, PO references or file names. PDF and attachment download as b1: 404 and no `%PDF`/file bytes.
   - Existing per-domain isolation tests stay (they test details); the matrix is the completeness guard.
3. Gate 3a, `RateLimitSweepTest`: `tests/Support/RateLimits.php` declares every limiter (name, route and method, who, max, window, key kind, body). The test asserts: every `throttle:` name on any route is in the table and every table name resolves with `RateLimiter::limiter()`; for each entry, `max` calls are not 429, call `max+1` is 429 with `Retry-After` and the standard body, a second user has an independent bucket (user-keyed) or a different IP does (IP-keyed, via `REMOTE_ADDR`), the window passes after `Carbon::setTestNow`, a wrong role gets 403 not 429. Includes the Phase 7 limiters (`catalog-search`, `order-search` on all three lists with its shared bucket, `orders-reorder`, `health-ready`, `quote-attachment-delete`) and the login keys (5/min email+IP, 20/15 min email, 20/min IP).
4. Gate 3b, `MassAssignmentSweepTest`: for every write route in the router (the same enumeration), send the valid `BODY` plus forged keys (`role`, `company_id`, `active`, `session_epoch`, `password`, `email_verified_at`, `invited_by_user_id`, `version`, `status`, `price_cents`, `unit_price_cents`, `subtotal_cents`, `total_cents`, `number`, `created_by_user_id`, `user_id`, `id`, `public_id`) and compare with a control request (inside a savepoint that is rolled back): same status, same observable rows (the actor's user row, the target row, totals and prices). Documented exceptions: `role` and `company_id` on invite and on `PUT /admin/users/{user}/assignment`, `company_id` on tier-A endpoints, `version` where documented, `price_cents` on product endpoints. Also the existing `UserMassAssignmentTest` stays.
5. Headers (`HeadersTest`): for every route and for 401, 404, 405, 413, 419, 422, 429, 500 and a CORS preflight, the exact header set of 3.2; HSTS absent over HTTP and in local, present for a secure request in production; attachment and PDF keep their own CSP and `Cache-Control`; `X-Powered-By` absent; `Referrer-Policy` is exactly `origin`.
6. Proxies and hosts (`ProxyTest`): `TrustedProxies` parsing table (empty, IPv4, CIDR, IPv6, `*`, `**`, garbage); untrusted peer with spoofed `X-Forwarded-For` keeps `REMOTE_ADDR`; trusted peer uses the forwarded address; limiter buckets follow; `X-Forwarded-Host` ignored; foreign `Host` 400 with the generic body when `TRUSTED_HOSTS` is set.
7. `ProductionGuardTest` (unit): each rule has a passing and a failing config; violations list key names and never values; `prod`, `live`, `Production` and ` staging ` are enforced while `local`, `testing`, `loadtest` are exempt; empty `TRUSTED_PROXIES` is an error unless `TRUSTED_PROXIES_NONE` is boolean true; `TrustedProxies` rejects CIDRs shorter than /8 (IPv4) and /16 (IPv6); `TrustedHosts` rejects a scheme, port, path, wildcard, IPv6 literal and trailing dot and its message never contains the value; `LogThrottle` allows once per key per minute and fails open when the cache throws; `portal:check-config` exit codes; a feature test that a request in production with unsafe config is a 500 and logs once.
8. Pagination (`PaginationWindowTest`): every list route (products, orders, quotes, invoices, admin products, companies, users, audit-log): `page*per_page` equal to 10,000 passes, 10,001 is 422 on `page`; per_page 100 page 100 passes, page 101 fails; `meta.total` still real.
9. Checkout (`CheckoutExpectedSubtotalTest`): equal subtotal 201; changed price 409 with the contract body, cart unchanged, no order, no history; absent field unchanged behaviour; wrong type 422; unavailable line still 422 `cart` first; concurrent price change and checkout (price updated inside the lock window) cannot create an order at a price different from the one validated; counts toward `orders-create`.
10. Password (`PasswordBreachTest`): compromised password 422 `password` on reset and accept-invitation; clean password passes; HIBP 500 and timeout pass with a warning log; disabled by config; the request contains only the 5-character prefix (`Http::assertSent`); `ShouldBeEncrypted` regression (queued reset payload has no token).
11. Uploads and storage: staff purge on an open and on a closed quote (audit row with the exact metadata in both cases, no audit row for a customer deleting their own file, customer still 422 on closed), `quotes:storage-check` (report numbers, orphan files, missing files, warning thresholds, `--prune-orphans` dry run vs `--force`), structure test for the public disk.
12. Misc: `CatalogCacheTest` (single flight with a held lock, fail-open on timeout, flush after commit for every write path), `CsvCellTest`, fail-closed epoch, `AuditLog` seam, email layout (every mail's HTML contains `web_url` and not `app.url`), import audit rows (3 changed + 2 unchanged + 1 created gives exactly 3 rows with exact before and after; rollback on audit failure; chunk query count), `Security/StructureTest` (3.10 item 5), `LogHygieneTest` (4.3), `HealthReadyTest` (200, 503 with a broken connection and no detail in body, limiter, route matrix entry), `portal:health` exit codes, `portal:loadtest-seed` (tiny scale and production refusal), `OrdersSchemaTest` for the `order_items` trigger if built.
13. Everything in the gate is run with the whole suite: `./vendor/bin/pest` and `./vendor/bin/pint --test`.

### 10.2 Frontend Vitest (frontend-engineer)

Checkout 409 flow (alert text, new subtotal rendered, no auto-resubmit, second click succeeds, request body contains `expected_subtotal_cents`); pagination window (Next disabled at the window, hint shown, URL unaffected); staff CSV warning (staff and admin see it on `.csv` attachments, customers do not); purge button for staff on closed quotes (calls DELETE, hidden for customers); request id shown on generic error states (`Reference: <id>` from `X-Request-Id`, `lib/api.ts` keeps the header on the thrown error); skip link (first Tab focuses it, activating it focuses `main`); per-route `document.title`; lazy routes render after load and show the status fallback (tests use `findBy*`); `security-headers.ts` values all appear in `docs/ops/nginx-spa-headers.conf`; `index.html` still has the referrer meta (existing test).

### 10.3 QA live and manual (qa-engineer, with the main session for infrastructure)

1. Full gate run: `pest`, `pint --test`, `npm run lint`, `npm test`, `npx playwright test`, audit job locally (`composer audit`, `npm audit` in `web` and `e2e`, then `node ops/audit/check-audit.mjs`).
2. IDOR matrix live: two browser contexts (company A and B) against the e2e stack: every company-scoped URL of A opened by B (UI and API, including PDF and attachment download) gives 404 or the Not found page; record the table.
3. Header checks with curl against the preview/Nginx rig (and later staging): API (`/api/v1/health`, a 401, a 404, a 419), SPA (`/`, `/assets/<file>`), preflight with an allowed and a foreign origin:
   `curl -sI http://localhost:5273/` and `curl -si -X OPTIONS -H "Origin: http://evil.test" -H "Access-Control-Request-Method: POST" http://localhost:5273/api/v1/auth/login`; compare with sections 3.2 and 3.3; no `X-Powered-By`; no `Server` version on Nginx.
4. CORS and CSRF: preflight matrix (3.4), a write without `X-XSRF-TOKEN` (419), a foreign Origin (guest), cookie flags (`HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS staging).
5. Rate-limit sweeps live against the Docker rig with real cache: login lockout, `catalog-search` burst (429 at request 41), order search, reorder, PDF, upload, import, invite; confirm `Retry-After`; confirm that 60 requests from two different forwarded IPs through Nginx use two buckets.
6. EXPLAIN runs at gate and growth scale (6.2) with the results table.
7. Load test (6.3) pure and mixed; attach the result JSON.
8. Lighthouse (or manual fallback) and axe runs; keyboard-only, screen reader and 375 px passes (7.3).
9. Backup restore drill locally (Docker Postgres) and read the CI drill result; restore a dump taken from a populated load DB and confirm `quotes:storage-check` output.
10. Races (S35) and the two journeys 10 times in a row.
11. Phase 8 smoke list (executed there): TLS and redirect, `Set-Cookie` flags and `__Host-` name, HSTS, CSP Report-Only then enforced, `Host` rejection (`curl -H "Host: evil.test"`), `X-Forwarded-For` overwrite check (send a forged header, confirm the app sees the TCP peer), 413 behaviour (Nginx HTML versus JSON at 12 MB), `/api/v1/health/ready` from the load balancer, a failed job in `failed_jobs` produces a log line, scheduler heartbeat advances.

### 10.4 Gate mapping

| PLAN gate item | Evidence |
| --- | --- |
| Route-audit test | 10.1 item 1 |
| IDOR across two companies | 10.1 item 2 + 10.3 item 2 |
| Rate limits 429, mass assignment ignored | 10.1 items 3 and 4 |
| `composer audit` and `npm audit` | audit workflow, 10.3 item 1 |
| Lighthouse accessibility 90+ | `lighthouse` project report (or manual fallback) |
| Playwright customer and staff journeys | 8.2, 8.3, stability gate |
| Load p95 under 500 ms at 50 users | 6.3 result file, local Docker run |

## 11. Work packages, owners, files and dependencies

Order of work: WP-B0 (tests infrastructure and seams) first, then B1 to B6 in parallel with F1 to F4 once the contract is accepted; QA starts on Q1 after B6 and F2; main session items M1 to M5 are independent. Parallel agents never edit the same files: `bootstrap/app.php`, `routes/api.php`, `AppServiceProvider.php`, `config/portal.php`, `tests/Support/RouteMatrix.php` and `tests/Pest.php` belong to the backend agent only, `vite.config.ts` and `Layout.tsx` to the frontend agent only.

| WP | Content | Agent | Files (new or changed) |
| --- | --- | --- | --- |
| B0 | `config/portal.php`, `RouteMatrix::SCOPE`/`BODY`, `RateLimits` table, `CsrfEnforced` helper, race group tags, Pest parallel check, `phpunit.xml` env (`AUTH_PASSWORD_BREACH_CHECK=false`) | backend | `api/config/portal.php`, `api/tests/Support/*`, `api/tests/Pest.php`, `api/phpunit.xml`, race tests |
| B1 | Proxies, hosts, request id, security headers, CORS exposure, `Route::get('/')` removal, 400 render | backend | `bootstrap/app.php`, `app/Support/TrustedProxies.php`, `app/Http/Middleware/RequestContext.php`, `SecurityHeaders.php`, `config/cors.php`, `routes/web.php`, `.env.example` |
| B2 | `ProductionGuard`, `portal:check-config`, session and env docs hand-off | backend | `app/Support/ProductionGuard.php`, `app/Console/Commands/CheckConfig.php`, `AppServiceProvider.php` |
| B3 | Limiters, pagination window, checkout 409, password rule | backend | `AppServiceProvider.php`, `routes/api.php`, list and order Form Requests, `app/Rules/*`, checkout service, `config/catalog.php` untouched |
| B4 | Uploads and storage: purge, `quotes:storage-check`, structure tests, email views | backend | `QuotePolicy`, attachment controller and service, `app/Console/Commands/QuotesStorageCheck.php`, `resources/views/vendor/mail/*`, `routes/console.php` |
| B5 | Cache single-flight, import audit rows, fail-closed epoch, `AuditLog` seam, `CsvCell`, order-items trigger, slow-query log | backend | `app/Support/CatalogCache.php`, `ProductImportService`, `EnsureUserIsActive`, `AuditLog`, `app/Support/CsvCell.php`, one migration (trigger, only if S36 stands) |
| B6 | Logging channels, health ready, `portal:health`, scheduler heartbeat, queue hooks, log hygiene, `portal:loadtest-seed` | backend | `config/logging.php`, `routes/api.php` (ready route), `routes/console.php`, `app/Console/Commands/*`, `Security/*` tests |
| B7 | Index migrations ONLY if the EXPLAIN report demands (reported by QA, decided by the architect) | backend | `database/migrations/*` |
| F1 | `security-headers.ts`, `preview.headers`, sync test | frontend | `web/security-headers.ts`, `web/vite.config.ts` |
| F2 | Checkout 409 UI, request id on error states | frontend | `features/cart/CheckoutPage.tsx`, `lib/api.ts`, shared error component, tests |
| F3 | Pagination window UI, staff CSV warning, staff purge button | frontend | `components/DataTable.tsx`, `features/catalog/Pagination.tsx`, `features/quotes/AttachmentsPanel.tsx`, tests |
| F4 | Accessibility fixes (7.4) | frontend | `components/Layout.tsx`, `PageTitle.tsx`, `ConfirmDialog.tsx`, badges, forms, tests |
| F5 | Code splitting and bundle target | frontend | `AppRoutes.tsx`, `vite.config.ts`, tests |
| Q1 | Journeys, races, headers spec, CSP fixture, config (`E2E_WEB`, trace policy), helpers | qa | `e2e/tests/*`, `e2e/helpers/*`, `e2e/playwright.config.ts` |
| Q2 | axe spec and Lighthouse project | qa | `e2e/a11y/*`, `e2e/package.json`, `e2e/.gitignore` |
| Q3 | Load script, EXPLAIN runs, results document | qa | `e2e/loadtest/catalog-search.mjs`, `docs/phase-7-results.md` |
| M1 | CI changes, audit workflow, load workflow, Dependabot, digests | main session | DONE: `.github/workflows/{ci,audit,load}.yml`, `.github/dependabot.yml` |
| M2 | Audit script and allowlist | main session | DONE: `ops/audit/check-audit.mjs`, `.audit-allowlist.json` (repo root), `.github/workflows/audit.yml` |
| M3 | Backup, restore drill, DB roles and verify | main session | DONE: `ops/backup/{lib,backup,restore,restore-drill}.sh`, `ops/db/{roles.sql,verify-roles.sh}`, `docs/ops/{backup.md,restore-log.md}` |
| M4 | Load rig | main session | DONE: `ops/loadtest/{compose.yml,Dockerfile,Dockerfile.dockerignore,nginx.conf,php.ini,fpm.conf}` |
| M5 | Ops docs and examples | main session | DONE: `docs/ops/env.production.example`, `docs/ops/nginx-spa-headers.conf`, `docs/SECURITY.md`, `CLAUDE.md` layout note for `ops/`; README additions and `.gitignore` entries as listed in the git status |

Main-session verification results: the restore drill ran locally against `portal_e2e` and the restored scratch database matched exactly (32 tables, 9 triggers, 95 indexes, 30 migrations identical). `ops/db/roles.sql` was verified on a scratch database. The load rig builds and answers through nginx with opcache on. `ops/db/verify-roles.sh` needs `php` and the Composer dependencies on the machine that runs it (it migrates the scratch database with the app).

## 12. New dependencies and tools (EVERY ONE needs the user's approval)

| Name | Purpose | Licence | Kind | Needed for | Fallback if refused |
| --- | --- | --- | --- | --- | --- |
| `@axe-core/playwright` (brings `axe-core`) | a11y regression in Playwright | MPL-2.0 | `e2e` devDependency | 7.1 | manual axe browser extension runs, no CI regression |
| `lighthouse` | the literal Lighthouse accessibility gate | Apache-2.0 | `e2e` devDependency (about 80 MB with transitive packages; needs Node 22 or newer) | gate item 5 | manual DevTools Lighthouse run by QA |
| New top-level directory `ops/` | scripts and rigs (backup, audit, loadtest, db roles) | n/a | repository layout change to CLAUDE.md | all ops items | put scripts under `docs/ops/` (awkward) |
| Docker images `php:8.3-fpm`, `nginx:1.27-alpine` (and `postgres:16`, `axllent/mailpit` already used) | representative load rig | PHP licence, BSD-2, PostgreSQL licence, MIT | build and CI only, pinned by digest | 6.3 | `php -S` with workers: indicator only |
| GitHub Action `actions/cache` | Composer cache | MIT | CI only | 9.2 | no cache (slower) |
| Dependabot (`.github/dependabot.yml`) | dependency updates | n/a (GitHub feature) | config only | 9.4 | manual updates |
| Outbound service `api.pwnedpasswords.com` | breach check, 5-character hash prefix only | n/a (third-party service) | runtime network call, NOT a package | 3.8 | `AUTH_PASSWORD_BREACH_CHECK=false`, length rule only |
| `age` or `gpg` (host tool) | backup encryption in Phase 8 | BSD-3 / GPL | ops tool, Phase 8 | 5.2 | provider-side encryption |
| PostgreSQL contrib `auto_explain` | EXPLAIN capture on the load DB | PostgreSQL | ships with the postgres image | 6.2 | run EXPLAIN by hand |

Not added: Sentry (`sentry/sentry-laravel`, `@sentry/react`), k6 (AGPL-3.0, optional alternative), Redis, ClamAV, `jest-axe`, a Pest parallel plugin (paratest is already present), a CSV library. Nothing in `api/composer.json` or `web/package.json` changes in this phase.

## 13. Linux CI hygiene notes

- Race tests: `->group('race')`, run serially after the parallel step; they use real processes, so they need the real Postgres (also locally: no SQLite). They `TRUNCATE` and must never run inside a worker pool together with other tests sharing one database.
- Unique helper function names: Pest helpers are global; prefix new ones. A duplicate name is a fatal error only on some load orders, which parallel runs expose.
- `memory_limit=256M` is set in `phpunit.xml` (the PDF tests are the heaviest); Phase 8 sets the same for FPM. Dompdf writes `storage/fonts`: the CI user must be able to write it.
- No `putenv`, no reading `env()` in app code (config cache), no dependence on the developer's `.env` (`testInvoicingConfig()` already fixes invoicing values; do the same for `portal.*`).
- Time: tests that depend on the clock use `Carbon::setTestNow` and reset it in `afterEach` (parallel workers are separate processes, but a leaked frozen clock breaks later tests in the same worker).
- Case-sensitive file system: paths in tests and `storage` names must match exactly (Windows hides errors).
- `PHP_CLI_SERVER_WORKERS` works only on Linux; e2e and the relaxed load check use it there.
- e2e artifacts contain demo cookies and passwords of an ephemeral runner: traces only on first retry, 3-day retention, never reuse real credentials.
- Postgres service port 5433 mapping and health checks as today; the new `ops-drill` job uses its own service container so it cannot interfere with the `api` job.

## 14. Open questions for the user (defaults in brackets)

1. New devDependencies `@axe-core/playwright` and `lighthouse` in `e2e/`, and the new `ops/` directory. [Yes to all; if Lighthouse is refused, manual DevTools score recorded by QA.]
2. Error tracking: no SDK now, structured logs and request ids only. [Yes. Decide on Sentry or GlitchTip with a DPA before launch, Phase 8.]
3. Breached-password check against the HIBP range API (outbound call, 5-character prefix, fail open, off in local and tests). [Yes.]
4. HSTS: `max-age=31536000` only, no `includeSubDomains`, no `preload`. [Yes; revisit after a month of HTTPS.]
5. Deployment shape: one origin (Nginx serves the SPA and proxies `/api` and `/sanctum`). [Yes. A separate API origin changes CSP `connect-src`, CORS and cookie settings.]
6. Result window cap of 10,000 rows on every list. [Yes.]
7. `catalog-search` at 40 per minute per user, order search 30 (staff 60), reorder 10. [Yes; revisit after UAT.]
8. Staff purge of attachments on closed quotes, manual only (no automatic retention purge). [Yes; set a retention period in Phase 8 if the business needs one.]
9. Malware scanning out of scope for v1. [Accepted risk, recorded.]
10. `expected_subtotal_cents` optional in the API (the SPA always sends it). [Optional.]
11. Pest parallelism kept only if it saves at least 35% wall time. [Yes.]
12. Load gate: authoritative local Docker run at 500 ms; weekly CI run at 1000 ms. [Yes.]
13. Contact address for `docs/SECURITY.md` (vulnerability reports). [User supplies; placeholder until then.]
14. Per-product audit rows for imports only for UPDATED products. [Yes.]
15. `order_items` immutability trigger. [Yes if no code path updates items; otherwise deferred.]
16. PDF protection: dedicated FPM pool in Phase 8, code semaphore only if the mixed load run requires it. [Yes.]
17. Production session cookie name `__Host-portal_session`, `SESSION_ENCRYPT=true`, session driver `database`. [Yes.]
18. Production cache and limiter store: `database` now, Redis later if needed (new service). [`database`.]
19. Backup policy numbers: RPO 24 h, RTO 4 h, retention 7 daily, 4 weekly, 12 monthly. [Placeholders; hosting decides.]
20. No CSP report endpoint in v1. [Yes.]
21. Node version on your machine for Lighthouse (22 or newer). [Assume 24 as in CI.]
22. Login-route bundle is about 157 kB gzip against the 150 kB goal (6.4). [Accept the miss; or lazy-load catalog, cart and checkout; or replace zod.]

## 15. Phase 8 handover (so nothing is lost)

Phase 8 executes: real proxy and host values, TLS and redirects, the Nginx file built from 3.3 and `ops/loadtest/nginx.conf`, PHP and FPM settings (D03) with the PDF pool, `ops/db/roles.sql` on the real server (with `OWNER_PASSWORD` and `APP_PASSWORD` in the environment, real role names via `-v owner_role=... -v app_role=...` if they differ) AND, as a hard deploy step, "re-run `roles.sql` and verify after every `migrate`" (the `audit_log` REVOKE only holds until a migration recreates the table; a deploy whose check fails does not go live), production `.env` from `docs/ops/env.production.example` (real proxy IPs in `TRUSTED_PROXIES`, or `TRUSTED_PROXIES_NONE=true` only when no proxy exists) and `portal:check-config` as the deploy gate, backups encrypted before any copy leaves the host, cron and Supervisor worker, backups with an offsite copy and a restore into a fresh DB (the drill script against real storage), CSP Report-Only on staging then enforce, the smoke list in 10.3 item 11, audit retention, first admin creation, legal review (S31), alerting on log level `error`, `failed_jobs` and the scheduler heartbeat, `docs/DEPLOY.md`.

## 16. As built: deviations and follow-ups (synced with the backend and frontend implementation)

Where the text above says "as built" it is the current truth; this section collects what differs from the original plan or is still open.

| # | Item | State |
| --- | --- | --- |
| 1 | Password breach check | `FailOpenPwnedVerifier` bound with `extend()` (3.8). Stock `NotPwnedVerifier` not used (30 s timeout, URL with hash prefix in its error). |
| 2 | Seller placeholder | WARNING in `portal:check-config`, failing only under `--strict`; invoice issuing keeps its own refusal, with its own exemption for `local` and `testing` only (`loadtest` is NOT exempt there) (3.11). |
| 3 | Exception logging | Masked emails (`[email]`), frames-only trace with capped depth, `QueryException` as sqlstate plus placeholder SQL (4.1). FOLLOW-UP: restore the capped trace in exactly that form. |
| 4 | `portal:loadtest-seed` | Built in full: companies, users, products, orders, quotes, invoices, audit rows and their options (6.1). The two seed bugs (D1 nested loop, D2 empty PO references) are fixed: 50,000 products and 500,000 orders seed in about 2 m 42 s. |
| 5 | Import audit | Per-product rows only for real diffs of audited fields; created products and description-only changes write none (3.11). |
| 6 | Pest parallel | 426 s + 28 s against 1097 to 1186 s serial, about 62% faster (9.2). |
| 7 | Bundle goal | Login route about 157 kB gzip, goal 150 kB missed by about 7 kB (6.4, Q22). |
| 8 | Accessibility | Known unfixed issues listed in 7.5 for the QA axe and Lighthouse runs. |
| 9 | Frontend tooling | `web/.prettierrc.json` uses `endOfLine: "auto"`. |
| 10 | QA | The live checks of 10.3 and `docs/phase-7-results.md` are the QA agent's work; this sync records no QA results. |
| 11 | Security-review fixes, backend | Production rules apply to every environment except local, testing and loadtest (3.11); `TRUSTED_PROXIES` empty is an error unless `TRUSTED_PROXIES_NONE=true`, CIDR floors /8 and /16 (3.1); `TRUSTED_HOSTS` hostnames only, validated at boot, `portal:check-config` still boots via the `argv` hack, `InvalidConfiguration` written with `error_log` (3.1); limiters `health-ready`, shared `order-search` on three lists, `quote-attachment-delete` (3.5); every staff purge audited (3.9); `LogThrottle` against log flooding (3.11, 4.2). |
| 12 | Security-review fixes, ops and CI | `roles.sql`, `verify-roles.sh`, `backup.sh`, `restore.sh`, `lib.sh`, `restore-drill.sh` (5.3); `check-audit.mjs` fails closed (9.1); load rig and script credentials and remote guards (6.3); CI hardening: `shell: bash`, 3-day artifacts, Dependabot docker path, digests by hand (9.2, 9.4). |
| 13 | Known limits (accepted) | (a) `TRUSTED_PROXIES_NONE=true` with a non-empty `TRUSTED_PROXIES` is not flagged, the list wins. (b) The `InvoiceSnapshotBuilder` seller-placeholder check exempts only `local` and `testing`. (c) `TrustedHosts::runningCheckConfig()` is a match on `$_SERVER['argv']`: a small hack. (d) The shared `order-search` bucket means 30 searches per minute in total across orders, quotes and invoices for a customer (60 for staff). (e) `roles.sql` only protects `audit_log` until the next migration that recreates it: re-run it after every migrate. (f) The restore drill needs a quiet source database. |
