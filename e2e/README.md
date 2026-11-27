# End-to-end tests (Playwright)

Headless Chromium drives the real SPA against the real API. The suite starts its own, isolated stack.

| What | Value |
| --- | --- |
| API | `php -S 127.0.0.1:8100` (from `api/public`, with the Laravel router script) |
| SPA | Vite dev server on `http://localhost:5273` (proxies `/api` and `/sanctum` to 8100) |
| Database | `portal_e2e` on `127.0.0.1:5433` (Docker Postgres), reseeded with `migrate:fresh --seed` on every run |
| Mail | Mailpit SMTP `127.0.0.1:1025`, API `http://127.0.0.1:8025` (only the invitation test reads it) |
| Queue | `sync` (no worker needed) |

The dev servers (8000, 5173) and the `portal` / `portal_test` databases are never used. `global-setup.ts` aborts before anything
destructive unless the API resolves to `pgsql|127.0.0.1|5433|portal_e2e`, whatever `api/.env` says.

## Run locally

Prerequisites: `docker compose up -d db` with the `portal_e2e` database (see the main README), PHP 8.3 on `PATH`
(or set `E2E_PHP` to the full path of `php`), `api/.env` with an `APP_KEY`, `npm ci` in `web/`, and free ports 8100 and 5273.

```
cd e2e
npm ci
npx playwright install chromium     # once, about 315 MB download
npx playwright test
npx playwright show-report          # HTML report; traces of failures are in test-results/
```

Mailpit (`docker compose up -d mailpit`, if your compose file has it) is optional: without it the invitation test is skipped
locally (and fails when `CI` is set).

## Environment variables (all optional)

| Variable | Effect |
| --- | --- |
| `E2E_PHP` | php binary (default `php`) |
| `E2E_CHANNEL` | `msedge` or `chrome`: use the installed browser instead of the downloaded Chromium |
| `E2E_MAIL_MAILER` | `log` instead of `smtp` when no Mailpit is running |
| `E2E_MAILPIT_URL` | Mailpit API (default `http://127.0.0.1:8025`) |
| `E2E_OPCACHE=0` | do not load opcache in the API server (default: loaded, requests are about 7x faster) |
| `E2E_API_LOG=1` | show the API server's request log and errors |
| `CI` | one retry, `forbidOnly`, GitHub reporter, Mailpit required |

## Tests

- `order-status.spec.ts`: the Phase 6 gate. Customer orders, staff confirms in the back office, customer sees "Confirmed" and the
  timeline entry, admin sees the audit row with the staff actor; customer 403 and repeated confirm 422.
- `admin-guards.spec.ts`: guest, customer, staff and admin see exactly what they may (UI and API).
- `user-deactivation.spec.ts`: admin deactivates a user, the open session is sent to login; invitation by email (Mailpit).
- `product-flow.spec.ts`: staff creates a product, it appears in the customer catalog; deactivating hides it.

Rules: selectors by role, label and accessible name only; no sleeps (waits on responses, URLs and web-first assertions);
unique data per test; the suite reseeds, so a failed run leaves nothing behind.
