# Security

## Reporting a vulnerability

Contact: **TODO: the project owner supplies a security contact address** (use a monitored mailbox, not a personal one).
Please send a description, steps to reproduce and the affected version. We acknowledge within 3 business days, give
a fix target by severity (critical: days, high: 2 weeks, medium: next release) and agree disclosure timing with the
reporter. Only the currently deployed release is supported. Please do not test against production data you do not own.

## Controls summary

| Area | Control | Where / how it is verified |
| --- | --- | --- |
| Authentication and sessions | Sanctum cookie sessions, login throttling per email+IP and per email, password min length (+ breached-password check, fail-open), single-use hashed reset/invitation tokens, session epoch (deactivation, role change and password reset end sessions) | Pest Auth/Admin suites, parallel race tests, QA live checks |
| CSRF | Stateful-domain Sanctum flow, `X-XSRF-TOKEN` on every write, 419 otherwise | `CorsCsrfTest` with the CSRF seam (`tests/Support/CsrfEnforced.php`) |
| CORS | Explicit origin allowlist, credentials only for the portal origin, no wildcard | `CorsCsrfTest`, `ProductionGuard` |
| Authorization | Role middleware before limiters and lookups; company scoping (`visibleTo` + policies, foreign records answer an exact 404); staff/admin tiers | `RouteAuditTest` + `RouteMatrix` (every route), `CrossCompanyMatrixTest` (every company-scoped route), Phase 1-6 isolation tests |
| Input handling | Form Requests, strict types, NUL/control-character rules, 422 never 400/500, mass assignment blocked: `User` has only `name`, `email` and `password` fillable; `role` and `company_id` are never mass-assignable and are set only through `User::assign()` / `User::provision()` with server-side values; other models are built with `forceFill` from server values (prices, totals, numbers) | per-phase tests, mass-assignment sweep |
| Money and numbers | Integer cents, server-side totals, price cap, gap-free invoice numbers, DB CHECKs and triggers | Pest schema and invoice tests, parallel numbering tests |
| Rate limits | Per-user and per-IP limiters on login, catalog search (40/min), order, quote and invoice search (ONE shared bucket per user: 30/min customers, 60/min staff, 30 searches in total across the three lists), PDF, uploads and attachment deletes (30/min), imports, invites, order creation, reorder; the public readiness probe has its own bucket (120/min per IP). A wrong role gets 403 before any 429. Counters live in the cache store: `database` in production (`file` is not atomic; `array` is refused) | rate-limit sweep test, QA 429 checks |
| Headers | API: nosniff, `Referrer-Policy: origin`, frame denial, permissions policy, request id, HSTS over HTTPS; SPA: CSP and the same set (`web/security-headers.ts`, `docs/ops/nginx-spa-headers.conf`) | header tests, e2e run against the production build with the enforced CSP |
| Uploads and files | Content-sniffed allowlist, size and count caps (per quote and per company), private storage, authorized download only, `attachment` + `nosniff` + CSP | Pest upload tests, QA live checks |
| Audit log | Append-only (DB trigger), written in the same transaction, allowlist redaction, admin-only viewer | Pest audit tests |
| Secrets | Never in the repository; `.env` ignored. `portal:check-config` (and the HTTP guard, which answers every request with 500) refuse an unsafe configuration in EVERY environment except `local`, `testing` and `loadtest` (`APP_ENV` trimmed, case-insensitive: `prod`, `live` and `Production` are covered). It checks, among others, debug off, https, secure cookie, `TRUSTED_HOSTS` set, `TRUSTED_PROXIES` listed (an empty list is an error unless `TRUSTED_PROXIES_NONE=true`, for installs with no proxy), no `*` in CORS or Sanctum, no `log` or `array` mailer. Bad `TRUSTED_PROXIES` or `TRUSTED_HOSTS` values (a wildcard, a CIDR shorter than /8 or /16, a scheme or port in a host) stop the boot and the message never contains the value | CI, `ProductionGuard` tests |
| Logging | Structured JSON with request id and integer user id; no bound SQL values, no passwords, tokens, request bodies or notes. Email addresses are masked as `[email]` in exception messages, but OTHER free text echoed in an exception message is still logged, cut at 300 characters: never put a secret or personal data into an exception message. The exception reporter keeps the masked message plus a frames-only trace (file:line and function, no arguments). Repeated failures are rate limited in the log (one line per rule set or check per minute) | log hygiene test |
| Database | Least-privilege roles (`ops/db/roles.sql`): the runtime role cannot TRUNCATE, create triggers, alter tables or touch audit rows. This is true ONLY after `roles.sql` has been applied to the real server AND re-run after every migration that creates tables (the `audit_log` revoke holds only until a migration recreates the table). It also revokes `CONNECT` from PUBLIC | `ops/db/verify-roles.sh` in CI (`ops-drill`) on a scratch database; on the real server by re-running the checks after each migrate (Phase 8 deploy step) |
| Backups | Database first then files, checksummed (restore refuses a mismatch), restore drilled (`ops/backup/restore-drill.sh`, quiet source database), files created `0600` in a `0700` directory, partial dumps removed, restore refuses system and configured databases and needs `--force` plus `CONFIRM_DROP`; encryption is REQUIRED before any copy leaves the host (the script does not encrypt); invoice-counter rule | CI `ops-drill`, `docs/ops/restore-log.md` |
| Dependencies | `composer audit` and `npm audit` fail CI on high/critical (expiring allowlist `.audit-allowlist.json`), weekly schedule, Dependabot, actions pinned by SHA, service images pinned by digest (workflow `services:` digests bumped by hand); the audit check fails CLOSED (a missing, empty or errored report fails the job instead of passing) | `.github/workflows/audit.yml`, `ci.yml` |

## Accepted risks (documented decisions)

- No malware scanning of uploaded files (allowed types are PDF, PNG, JPEG, TXT, CSV; downloads are forced attachments with `nosniff` and a sandbox CSP).
- No CSP report endpoint (it would be a public unauthenticated write route).
- LGPL libraries (dompdf and friends) are used unmodified on a server we operate and are not distributed.
- `HEAD` on the invoice PDF endpoint answers 405 by design.
- The checkout price-change check compares the subtotal the customer saw with the server's subtotal; it does not detect equal-sum changes.
- One-byte text attachments can be refused by content sniffing.
- Staff and admin may purge attachments of OPEN quotes (the customer's file can disappear while the quote is under review). Every staff purge is audited (`quote.attachment_purged`: file name, size, SHA-256).
- Upload content types (PDF, PNG, JPEG, TXT, CSV) can carry active content for whoever opens the file locally. Mitigations: the download is forced as `attachment` with `nosniff` and a sandbox CSP, and the staff UI warns about spreadsheet formulas next to every CSV. A workstation that opens a file with a vulnerable viewer is not protected by the server.
- `TRUSTED_PROXIES_NONE=true` together with a non-empty `TRUSTED_PROXIES` is not flagged by `portal:check-config` (the list wins).
- The invoice seller-placeholder check keeps its own exemption for `local` and `testing` only; `portal:check-config` reports a placeholder seller as a warning (an error under `--strict`).
- `TRUSTED_HOSTS` validation lets `portal:check-config` boot with a bad list by looking for the command name in `$_SERVER['argv']` (a small, known hack).
- The restore drill needs a quiet source database, and `roles.sql` resets the passwords of the roles it names (cluster-wide): both are operator procedures, documented in `docs/ops/backup.md`.

## Third-party data flows

- Pwned Passwords range API (`api.pwnedpasswords.com`): receives the first 5 characters of a SHA-1 password hash only; skipped when unreachable and in local development/tests.
- Mail provider (Phase 8): receives message content and recipient addresses for transactional mail.
- Error tracking: none configured. If one is approved later, scrub PII per `docs/phase-7-design.md` 4.4 and update this list.
