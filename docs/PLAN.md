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
- [ ] Manual: customer vs staff navigation differs; refresh keeps session

## Phase 2 - Product catalog and search
Build: `categories`, `products` (+ attributes). Bearing fields: part number, brand, type, inner diameter, outer diameter, width, material, seal type, price or price on request, stock status. `GET /api/v1/products` with search, filters, sort, pagination; product detail; CSV import command; 200+ seeded products; indexes; React catalog with filter sidebar synced to URL; detail page.

Test gate
- [ ] Pest: search by exact and partial part number (e.g. `6205`)
- [ ] Pest: each filter alone and combined (type + diameter range + brand)
- [ ] Pest: pagination correct; invalid sort/filter returns 422
- [ ] Pest: constant query count (no N+1)
- [ ] Pest: CSV import idempotent and reports bad rows
- [ ] Vitest: filters update URL and restore from URL; empty and error states
- [ ] Manual: 5,000 products list in under 300 ms locally

## Phase 3 - Cart and orders with history
Build: `orders`, `order_items` (snapshot price/part number/name), statuses pending/confirmed/shipped/delivered/cancelled, cart, `POST /orders`, `GET /orders` (own company), order detail with status timeline, re-order, history filters by status and date.

Test gate
- [ ] Pest: customer A cannot read, list or cancel customer B's order
- [ ] Pest: later price changes do not alter old orders
- [ ] Pest: totals calculated server-side; tampered client total ignored
- [ ] Pest: invalid quantity or unknown product returns 422
- [ ] Pest: only allowed status transitions work
- [ ] Vitest: cart add/remove/update, persists on reload; history filters work

## Phase 4 - Quote requests (RFQ)
Build: `quote_requests`, `quote_items` (free-text lines allowed), statuses submitted/in_review/quoted/accepted/declined/expired, staff pricing and validity date, queued emails both ways, accept converts to order, attachment upload (max 10 MB).

Test gate
- [ ] Pest: customer creates quote with catalog and free-text lines; other companies cannot see it
- [ ] Pest: staff prices and sends; customer gets queued email
- [ ] Pest: accepting creates exactly one order; second accept rejected
- [ ] Pest: expired quote cannot be accepted
- [ ] Pest: upload rejects wrong MIME and over 10 MB; files only downloadable by authorized users
- [ ] Vitest: dynamic quote form validates; status badges render
- [ ] Manual: full round trip in Mailpit

## Phase 5 - Invoices and PDF download
Build: `invoices`, `invoice_items`, gap-free invoice numbers, issue/due dates, tax, totals, statuses open/paid/overdue, create from order, Blade PDF template, `GET /invoices`, `GET /invoices/{id}/pdf`, overdue highlighting, scheduled overdue command.

Test gate
- [ ] Pest: invoice numbers unique and sequential, also under concurrency
- [ ] Pest: totals/VAT/rounding match hand-calculated fixtures (integer cents)
- [ ] Pest: PDF endpoint returns `application/pdf`, non-empty, correct filename
- [ ] Pest: other company's invoice PDF 403/404; guest 401
- [ ] Pest: overdue set by scheduled command (frozen clock)
- [ ] Vitest: invoice list statuses; download button works
- [ ] Manual: PDF layout, special characters, page breaks on long invoice

## Phase 6 - Staff back office
Build: `/admin` area: product CRUD + CSV import + activate/deactivate, customers/companies and users (invite by email), all orders with status changes, quote queue, invoices, dashboard (open quotes, orders this week, overdue invoices), audit log.

Test gate
- [ ] Pest: every `/admin` endpoint 403 for customers, 401 for guests (loop over all admin routes)
- [ ] Pest: status changes write audit log rows with actor
- [ ] Pest: only admins manage users and roles
- [ ] Pest: part-number uniqueness; deactivated products hidden from catalog but kept on old orders
- [ ] Vitest: admin routes guarded; tables sort and paginate
- [ ] Playwright: staff changes order status, customer sees it

## Phase 7 - Security, performance and quality pass
Build: carried over from Phase 1 review: trusted proxies set to the real proxy IPs only (`trustProxies(at: ...)`, never `*`) plus `trustHosts`, `Referrer-Policy: no-referrer` (reset links carry a token in the URL), `SESSION_SECURE_COOKIE=true`, `APP_DEBUG=false`, fail loudly if `APP_URL_WEB` is unset in production, consider `Password::uncompromised()` and a non-queued or encrypted reset notification. Then: security headers, CORS locked to portal origin, CSRF for SPA, rate limits (login/search/PDF), upload hardening, route authorization audit, `EXPLAIN` review of indexes, caching of filter options, accessibility pass, error tracking, structured logging, backup plan.

Test gate
- [ ] Pest: route-audit test (every non-public route has auth; every `/admin` route has a role)
- [ ] Pest: IDOR tests across two companies for orders, quotes, invoices, attachments, PDFs
- [ ] Pest: rate limits return 429; mass-assignment of `role`/`company_id` ignored
- [ ] `composer audit` and `npm audit`: no high/critical issues
- [ ] Lighthouse accessibility 90+ on login, catalog, order history
- [ ] Playwright: full customer journey and full staff journey pass
- [ ] Load check: catalog search p95 under 500 ms at 50 concurrent users

## Phase 8 - Deployment and go-live
Build: production environment (never run demo seeders; `RolesSeeder` only), Nginx + PHP-FPM, HTTPS, secrets outside git, queue worker (Supervisor), scheduler cron, automated DB backups with tested restore, CD workflow, staging with realistic data, user guides, real catalog data, launch checklist, `docs/DEPLOY.md`.

Test gate
- [ ] Deploy to staging from CI works; rollback works
- [ ] Smoke test: health, login, catalog, order, quote, PDF
- [ ] Real mail provider sends (SPF/DKIM pass); queue worker processes mail
- [ ] Backup restored into a fresh DB and app runs against it
- [ ] HTTPS enforced, secure cookies, `APP_DEBUG=false`, no stack traces
- [ ] 2-3 real users complete a task each (UAT); feedback logged

---

## Progress
- [x] Phase 0  - [ ] Phase 1  - [ ] Phase 2  - [ ] Phase 3  - [ ] Phase 4
- [ ] Phase 5  - [ ] Phase 6  - [ ] Phase 7  - [ ] Phase 8

## Rules for every phase
- A failing test blocks the next phase.
- Every later bug gets a regression test first, then the fix.
- Aim for 80%+ coverage on business logic (orders, quotes, invoices, policies).
- Update this file and CLAUDE.md when a decision changes.
