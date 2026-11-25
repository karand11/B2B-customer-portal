# Portal project rules

B2B customer portal for an industrial-parts / bearings business.
Customers: browse catalog, request quotes, see order history, download invoices (PDF).
Staff: manage products, orders, quotes, invoices, customers.

## Stack and layout
- Monorepo: `api/` (Laravel 11/12, PHP 8.3), `web/` (React + TypeScript + Vite), `e2e/` (Playwright), `docs/`.
- API: REST under `/api/v1`, JSON only, Sanctum SPA cookie auth, spatie/laravel-permission (roles: customer, staff, admin).
- Frontend: React Router, TanStack Query, React Hook Form + Zod, Tailwind.
- PDF: barryvdh/laravel-dompdf. Queue: database driver. Mail: Mailpit locally.

## Working rules
- Work on ONE phase at a time from `docs/PLAN.md`. Never start the next phase without my approval.
- Use plan mode first: show the plan, files and tests, then wait for approval.
- Test-first: write tests with every feature, then code, then run until green.
- Small commits, one phase per branch or commit series. Clear messages.
- Ask before adding a new dependency and say why it is needed.
- Never commit `.env` or secrets. Keep `.env.example` current.
- Use factories and seeders for demo data. Never use real customer data in tests.

## Backend conventions
- Controllers stay thin. Validation in Form Requests. Authorization in Policies. Output via API Resources.
- Customers may only ever see their own company's data: scope every query by `company_id`.
- Never trust the client: check auth and role/ownership server-side on every endpoint.
- Never mass-assign `role`, `company_id` or prices from request input.
- Orders, quotes and invoices snapshot product data (part number, name, price) at creation time.
- Store money as integer cents. Compute totals server-side only.
- Avoid N+1 queries (eager load). Add indexes for searched and filtered columns.
- Uploads: validate MIME and size, store outside the public web root, serve only via authorized endpoints.
- Invalid input returns 422, never 500. Missing or foreign resources return 404/403.

## Frontend conventions
- TypeScript strict. No `any` unless justified in a comment.
- Server state via TanStack Query; forms via React Hook Form + Zod.
- Route guards by role; hide UI the user cannot use, but never rely on hiding for security.
- Every list has loading, empty and error states. Filters are synced to the URL.
- Accessible by default: labels, keyboard navigation, sufficient contrast.

## Test and quality commands (run before finishing any task)
- API: `cd api && ./vendor/bin/pest && ./vendor/bin/pint --test`
- Web: `cd web && npm run lint && npm test`
- E2E (when present): `cd e2e && npx playwright test`
- A phase is done only when its test gate in `docs/PLAN.md` is fully green.

## Definition of done for a task
1. Tests written and passing.
2. Lint and format checks pass.
3. Authorization checked for every new endpoint (customer, staff, guest).
4. `docs/PLAN.md` updated (tick off items).
5. Summary of changes and anything I must check manually.

## Subagents (see `.claude/agents/`)
- `architect`: contracts and schema, read-only plus docs.
- `backend-engineer`: works only in `api/`.
- `frontend-engineer`: works only in `web/`.
- `qa-engineer`: runs gates and e2e tests, reports failures, does not fix feature code.
- `security-reviewer`: read-only review of each phase's diff after QA passes; high and medium findings must be fixed before the phase is done.
- Parallel agents must not edit the same files. The API contract in `docs/api-contract.md` is the source of truth.
