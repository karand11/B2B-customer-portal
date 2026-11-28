# End-to-end tests (Playwright)

Headless Chromium drives the real SPA against the real API. The suite starts its own, isolated stack.

| What | Value |
| --- | --- |
| API | `php -S 127.0.0.1:8100` (from `api/public`, with the Laravel router script) |
| SPA | `E2E_WEB=dev` (default): Vite dev server on `http://localhost:5273`. `E2E_WEB=preview`: `vite build` + `vite preview` on the same port, the production bundle with the enforced CSP and the other SPA headers of `web/security-headers.ts` (CI uses this) |
| Both proxy | `/api` and `/sanctum` to 8100 |
| Database | `portal_e2e` on `127.0.0.1:5433` (Docker Postgres), reseeded with `migrate:fresh --seed` on every run |
| Mail | Mailpit SMTP `127.0.0.1:1025`, API `http://127.0.0.1:8025` (invitation test and the customer journey read it) |
| Queue | `sync` (no worker needed) |

The dev servers (8000, 5173), the load rig (8200, 5434) and the `portal` / `portal_test` databases are never used.
`global-setup.ts` aborts before anything destructive unless the API resolves to `pgsql|127.0.0.1|5433|portal_e2e`, whatever `api/.env` says.

## Run locally

Prerequisites: `docker compose up -d db` with the `portal_e2e` database (see the main README), PHP 8.3 on `PATH`
(or set `E2E_PHP` to the full path of `php`), `api/.env` with an `APP_KEY`, `npm ci` in `web/`, Node 22.19 or newer
(Lighthouse; CI uses 24), and free ports 8100 and 5273.

```
cd e2e
npm ci
npx playwright install chromium     # once, about 315 MB download
npx playwright test                 # dev server
E2E_WEB=preview npx playwright test # production build + enforced CSP (runs `npm run build` in web/ first)
npx playwright show-report          # HTML report
npm run axe                         # only the accessibility specs (a11y/)
npm run lighthouse                  # the Lighthouse gate (see below)
```

Preview mode builds `web/` itself (`npm run build && npm run preview`, build output in the git-ignored `web/dist`). When you have just
built it, `E2E_SKIP_BUILD=1` reuses `dist/`.

Mailpit (`docker compose up -d mailpit`, if your compose file has it) is optional: without it the invitation test is skipped and the
mail steps of the customer journey are left out locally (and both fail when `CI` is set).

## Environment variables (all optional)

| Variable | Effect |
| --- | --- |
| `E2E_WEB` | `dev` (default) or `preview` (production build, enforced CSP; the CSP and header specs only run in preview) |
| `E2E_SKIP_BUILD=1` | in preview mode, do not run `npm run build` first |
| `E2E_PHP` | php binary (default `php`) |
| `E2E_CHANNEL` | `msedge` or `chrome`: use the installed browser instead of the downloaded Chromium |
| `E2E_MAIL_MAILER` | `log` instead of `smtp` when no Mailpit is running |
| `E2E_MAILPIT_URL` | Mailpit API (default `http://127.0.0.1:8025`) |
| `E2E_OPCACHE=0` | do not load opcache in the API server (default: loaded, requests are about 7x faster) |
| `E2E_API_LOG=1` | show the API server's request log and errors |
| `LH_CHROME` | Chrome or Edge executable for Lighthouse (default: Playwright's Chromium) |
| `LH_MIN_SCORE` | Lighthouse minimum score (default `0.9`) |
| `CI` | one retry, `forbidOnly`, GitHub reporter, Mailpit required |

Traces are recorded `on-first-retry` only (they hold cookies and the demo passwords), so locally add `--trace on` when you need one;
a screenshot is kept for every failure, and a failing test also gets a `browser-log` attachment (uncaught page errors, failed requests,
HTTP 4xx/5xx and console errors seen by its pages).

## Tests

- `tests/customer-journey.spec.ts` and `tests/staff-journey.spec.ts`: the two long journeys of the Phase 7 gate, one `test` each with
  `test.step` (docs/phase-7-design.md 8.2 and 8.3). Customer: login form, catalog search and filters, cart, checkout with the price-change 409,
  order history, quote with a PDF attachment, staff prices and sends it (API), accept, invoice (API) and PDF download, logout. Staff:
  login, dashboard, confirm an order, price and send a quote, create and pay an invoice, edit and deactivate a product (including a
  stale-version 409), company billing edit, CSV import wizard, audit log, role guards. Each creates its own product and PO reference.
- `tests/races.spec.ts`: 12 parallel uploads against the per-quote cap, two parallel import applies, five parallel checkouts, parallel
  accepts of one quote, a double click on "Place order". Windows note: `php -S` is single-threaded there (`PHP_CLI_SERVER_WORKERS` is
  Linux only), so the requests are served one after the other; the assertions about the outcome still hold, the lock interleaving is only
  exercised on Linux (CI) and by the Pest tests with real parallel processes.
- `tests/headers.spec.ts`: API response headers on success and error responses, request id echo and the strict acceptance pattern, CORS
  preflight from the portal origin and from foreign origins, a session cookie used from a foreign origin acts as a guest; in preview
  mode also the SPA headers compared with `web/security-headers.ts`, and a proof that the CSP watcher catches a violation.
- `tests/audit-filter.spec.ts`: `?action=product.import_updated` and `?action=quote.attachment_purged` (Phase 7 actions): the API request carries the action, the checkbox is ticked, the list shows only that action.
- `tests/order-status.spec.ts`, `admin-guards.spec.ts`, `user-deactivation.spec.ts`, `product-flow.spec.ts`: the Phase 6 specs.
- `a11y/axe.spec.ts`: axe-core (WCAG 2.0/2.1/2.2 A and AA plus best practice) on every page of docs/phase-7-design.md 7.2 for each role,
  dialogs open. Any serious or critical violation fails the test (there are no waivers).
  JSON per page and `summary.json` go to `a11y-reports/axe/` (git-ignored).
- `a11y/manual-checks.spec.ts`: the scriptable items of the manual checklist (7.3): skip link, landmarks, focus after navigation, unique
  titles, dialog focus trap and Escape, Tab reach and visible focus, no horizontal scroll at 375 / 320 / 640 px (any overflow fails the test; `KNOWN_OVERFLOW` is the place to record an open defect with its cause, and the test fails when a listed one is fixed so the entry gets deleted), reduced motion (every `animate-pulse` skeleton of 15 pages, with a control that proves the check sees an animation).
- `a11y/lighthouse.mjs` (`npm run lighthouse`): the Lighthouse accessibility gate, below.

### CSP watcher

Every spec imports `test` from `helpers/fixtures.ts`. An automatic fixture records every `securitypolicyviolation` event and every console
message naming the Content-Security-Policy on any page of the test (also pages created through `actor()` / `actorFor()`), and fails the test
at the end. The dev server sends no CSP, so it only has an effect in preview mode. There is no waiver list: any violation fails the test, unless the test announces it with `csp.allow(pattern)` before provoking it on purpose.

### Lighthouse

`npm run lighthouse` runs `a11y/lighthouse.mjs`: it starts the e2e stack in preview mode through Playwright (`a11y/lighthouse.spec.ts` is
only picked up by this command), then audits `/login` (guest), `/catalog` and `/orders` (customer, signed in through the real login API,
the session cookie is passed as an extra header) with the **accessibility category only**, desktop and mobile emulation. It writes
`a11y-reports/lighthouse/<page>-<form factor>.json` and `summary.json`, prints a table and exits non-zero when any score is below 0.90.
Chrome comes from `LH_CHROME`, else Playwright's Chromium, else an installed Chrome or Edge. To audit a stack that is already running:
`LH_BASE_URL=http://localhost:5273 node a11y/lighthouse.mjs --inner`.

## Rules

Selectors by role, label and accessible name only; no sleeps (waits on responses, URLs and web-first assertions, `expect.poll` on state);
unique data per test (the journeys create their own product, PO reference and quote); the suite reseeds, so a failed run leaves nothing
behind. Retries: 1 in CI, 0 locally; a test that only passes on retry is a flaky test and must be fixed, not quarantined.
