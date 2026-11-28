# B2B Customer Portal - Build Plan (React + Laravel)

Goal: a portal where industrial customers browse a bearings/parts catalog, request quotes, track orders and download invoices, while internal staff manage everything behind a role-based login.

Rule: one phase at a time. A phase is finished only when every box in its test gate is ticked. Update this file as you go.

## Master prompt (paste into Claude Code)

> Read CLAUDE.md and docs/PLAN.md. Work on Phase N. Enter plan mode and propose the steps, files and tests; wait for my approval. Use the subagents: `architect` writes the API contract first, then `backend-engineer` and `frontend-engineer` work in parallel against it, then `qa-engineer` runs the test gate, then `security-reviewer` reviews the diff. Implement test-first and send any failures or high/medium security findings back to the right agent until the gate is green and the review is clean. Then commit, tick off this file, and stop and wait for me to say "next".

## Stack
- Frontend: React + TypeScript, Vite, React Router, TanStack Query, React Hook Form + Zod, Tailwind
- Backend: Laravel REST API, PHP 8.3, Sanctum SPA auth, spatie/laravel-permission
- DB: PostgreSQL 16 (decided)
- PDF: dompdf. Queue: database driver. Mail: Mailpit locally.
- Tests: Pest, Vitest + React Testing Library, Playwright
- CI: GitHub Actions

## Scope
v1: roles login, searchable/filterable catalog (size, type, part number), order history, quote requests, invoices with PDF download, staff back office.
Out of scope for v1: online payment, ERP sync, multi-currency, live stock reservation.

## Open questions (settle before Phase 0)
- [x] MySQL or PostgreSQL? PostgreSQL 16
- [ ] Existing product list (Excel/ERP export) to import?
- [ ] Currency, VAT rules, invoice legal requirements (language, bank details)?
- [ ] Hosting and email provider/sender domain?
- [ ] Multiple users per company with different permissions?

---

## Phase 0 - Project setup and CI
Build: monorepo, Laravel skeleton with `/api/v1/health`, Vite React TS app, ESLint/Prettier/Pint, `.env.example`, Docker Compose (db, mailpit) or Sail, GitHub Actions (lint + tests), README.

Test gate
- [x] Pest: `GET /api/v1/health` returns 200 and `{status: ok}`
- [x] `pint --test`, `npm run lint`, `npm test` pass locally
- [x] Web page shows health status from the API (manual)
- [x] CI green on a pushed branch
- [x] Fresh clone starts using only the README steps

## Phase 1 - Authentication and roles
Build: `companies`, `users` (user belongs to company), roles customer/staff/admin, Sanctum login/logout, password reset by email, `/api/v1/me`, policies, React login, auth context, protected routes, role-based navigation, demo seeders.

Test gate
- [x] Pest: valid login works, wrong password 422, logout invalidates session
- [x] Pest: login rate-limited (429 after repeated failures)
- [x] Pest: unauthenticated `/me` 401; customer on staff-only endpoint 403
- [x] Pest: password-reset email sent (Mail fake) and token works once
- [x] Vitest: login validation; protected route redirects to /login; customers never see staff menu
- [x] Manual: customer vs staff navigation differs; refresh keeps session

## Phase 2 - Product catalog and search
Build: `categories`, `products` (+ attributes). Bearing fields: part number, brand, type, inner diameter, outer diameter, width, material, seal type, price or price on request, stock status. `GET /api/v1/products` with search, filters, sort, pagination; product detail; CSV import command; 200+ seeded products; indexes; React catalog with filter sidebar synced to URL; detail page.

Test gate
- [x] Pest: search by exact and partial part number (e.g. `6205`)
- [x] Pest: each filter alone and combined (type + diameter range + brand)
- [x] Pest: pagination correct; invalid sort/filter returns 422
- [x] Pest: constant query count (no N+1)
- [x] Pest: CSV import idempotent and reports bad rows
- [x] Vitest: filters update URL and restore from URL; empty and error states
- [x] Manual: 5,000 products list in under 300 ms locally

## Phase 3 - Cart and orders with history
Build: `orders`, `order_items` (snapshot price/part number/name), statuses pending/confirmed/shipped/delivered/cancelled, cart, `POST /orders`, `GET /orders` (own company), order detail with status timeline, re-order, history filters by status and date.

Test gate
- [x] Pest: customer A cannot read, list or cancel customer B's order
- [x] Pest: later price changes do not alter old orders
- [x] Pest: totals calculated server-side; tampered client total ignored
- [x] Pest: invalid quantity or unknown product returns 422
- [x] Pest: only allowed status transitions work
- [x] Vitest: cart add/remove/update, persists on reload; history filters work

## Phase 4 - Quote requests (RFQ)
Build: `quote_requests`, `quote_items` (free-text lines allowed), statuses submitted/in_review/quoted/accepted/declined/expired, staff pricing and validity date, queued emails both ways, accept converts to order, attachment upload (max 10 MB).

Test gate
- [x] Pest: customer creates quote with catalog and free-text lines; other companies cannot see it
- [x] Pest: staff prices and sends; customer gets queued email
- [x] Pest: accepting creates exactly one order; second accept rejected
- [x] Pest: expired quote cannot be accepted
- [x] Pest: upload rejects wrong MIME and over 10 MB; files only downloadable by authorized users
- [x] Vitest: dynamic quote form validates; status badges render
- [x] Manual: full round trip in Mailpit

## Phase 5 - Invoices and PDF download
Build: `invoices`, `invoice_items`, gap-free invoice numbers, issue/due dates, tax, totals, statuses open/paid/overdue, create from order, Blade PDF template, `GET /invoices`, `GET /invoices/{id}/pdf`, overdue highlighting, scheduled overdue command.

Test gate
- [x] Pest: invoice numbers unique and sequential, also under concurrency
- [x] Pest: totals/VAT/rounding match hand-calculated fixtures (integer cents)
- [x] Pest: PDF endpoint returns `application/pdf`, non-empty, correct filename
- [x] Pest: other company's invoice PDF 403/404; guest 401
- [x] Pest: overdue set by scheduled command (frozen clock)
- [x] Vitest: invoice list statuses; download button works
- [x] Manual: PDF layout, special characters, page breaks on long invoice

## Phase 6 - Staff back office
Build: `/admin` area: product CRUD + CSV import + activate/deactivate, customers/companies and users (invite by email), all orders with status changes, quote queue, invoices, dashboard (open quotes, orders this week, overdue invoices), audit log.

Test gate
- [x] Pest: every `/admin` endpoint 403 for customers, 401 for guests (loop over all admin routes)
- [x] Pest: status changes write audit log rows with actor
- [x] Pest: only admins manage users and roles
- [x] Pest: part-number uniqueness; deactivated products hidden from catalog but kept on old orders
- [x] Vitest: admin routes guarded; tables sort and paginate
- [x] Playwright: staff changes order status, customer sees it

## Phase 7 - Security, performance and quality pass
Build: carried over from Phase 1 review: trusted proxies set to the real proxy IPs only (`trustProxies(at: ...)`, never `*`) plus `trustHosts`, `Referrer-Policy: origin` (reset links carry a token in the URL), `SESSION_SECURE_COOKIE=true`, `APP_DEBUG=false`, fail loudly if `APP_URL_WEB` is unset in production, consider `Password::uncompromised()` and a non-queued or encrypted reset notification. From the Phase 2 review: re-measure catalog search on a production-like server, lower the max `page` or cap deep OFFSET, consider a dedicated search limiter (e.g. 30/min) and stampede protection (`Cache::flexible`/lock) on the filters cache, escape leading `= + - @` in any CSV/XLSX export. From the Phase 3 review: send an expected subtotal with checkout (or confirm price changes) so a price change between viewing and placing is never silent, dedicated limiters for reorder and order search, trigram index on `orders.po_reference` if volume grows, require `APP_DEBUG=false` and `SESSION_SECURE_COOKIE=true` in every deployed environment. From the Phase 4 review: staff purge of attachments on closed quotes and a CSV warning in the staff UI, malware scanning of uploads, one-byte text files refused by libmagic (document or relax), dedicated email layout without the API-origin header link, per-user/company storage monitoring. From the Phase 5 review: run the app database role without ownership/TRIGGER/TRUNCATE privileges on the invoice tables, cache generated PDFs per (invoice, status, updated_at) or cap concurrent renders if PDF load grows, LGPL notice for dompdf libraries if the app is ever distributed, have the legal wording, VAT rate and numbering format reviewed. Then: security headers, CORS locked to portal origin, CSRF for SPA, rate limits (login/search/PDF), upload hardening, route authorization audit, `EXPLAIN` review of indexes, caching of filter options, accessibility pass, error tracking, structured logging, backup plan.

Test gate
- [x] Pest: route-audit test (every non-public route has auth; every `/admin` route has a role)
- [x] Pest: IDOR tests across two companies for orders, quotes, invoices, attachments, PDFs
- [x] Pest: rate limits return 429; mass-assignment of `role`/`company_id` ignored
- [x] `composer audit` and `npm audit`: no high/critical issues
- [x] Lighthouse accessibility 90+ on login, catalog, order history
- [x] Playwright: full customer journey and full staff journey pass
- [x] Load check: catalog search p95 under 500 ms at 50 concurrent users

## Phase 8 - Deployment and go-live
Build: production environment (real `INVOICING_*` seller details and VAT rate, PHP memory_limit 256M, hourly scheduler for `invoices:mark-overdue`, writable `storage/fonts`, `APP_DEBUG=false`, log files treated as sensitive; PHP `upload_max_filesize=11M`/`post_max_size=12M`, Nginx `client_max_body_size 12m`, monitored `QUOTES_NOTIFY_ADDRESS` mailbox, scheduler cron for `quotes:expire`, queue worker for mail, backups include the private `storage/app/private/quotes` files together with the database; never run demo seeders; `RolesSeeder` and `CategorySeeder` only; deploy checklist: `APP_ENV=production`, `APP_DEBUG=false`; the migration role needs CREATE on the database for `pg_trgm`, verify on managed Postgres in staging), Nginx + PHP-FPM, HTTPS, secrets outside git, queue worker (Supervisor), scheduler cron, automated DB backups with tested restore, CD workflow, staging with realistic data, user guides, real catalog data, launch checklist, `docs/DEPLOY.md`.

Test gate
- [ ] Deploy to staging from CI works; rollback works
- [ ] Smoke test: health, login, catalog, order, quote, PDF
- [ ] Real mail provider sends (SPF/DKIM pass); queue worker processes mail
- [ ] Backup restored into a fresh DB and app runs against it
- [ ] HTTPS enforced, secure cookies, `APP_DEBUG=false`, no stack traces
- [ ] 2-3 real users complete a task each (UAT); feedback logged

---

## Progress
- [x] Phase 0  - [x] Phase 1  - [x] Phase 2  - [x] Phase 3  - [x] Phase 4
- [x] Phase 5  - [x] Phase 6  - [x] Phase 7  - [ ] Phase 8

## Rules for every phase
- A failing test blocks the next phase.
- Every later bug gets a regression test first, then the fix.
- Aim for 80%+ coverage on business logic (orders, quotes, invoices, policies).
- Update this file and CLAUDE.md when a decision changes.
