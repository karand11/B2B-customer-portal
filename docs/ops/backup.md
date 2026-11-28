# Backup and restore

Scripts live in `ops/backup/`; the role set-up in `ops/db/`. Phase 7 builds and proves them locally and in CI
(job `ops-drill`); Phase 8 runs them on the real server and storage.

## What is backed up

| Asset | How | Notes |
| --- | --- | --- |
| PostgreSQL (everything, including `audit_log`, invoices and `invoice_number_counters`, sessions) | `pg_dump --format=custom --no-owner --no-privileges` | Roles and grants are NOT in the dump: apply `ops/db/roles.sql` after a restore. |
| Quote attachments `storage/app/private/quotes` | `tar.gz` | `imports` are ephemeral (pruned hourly) and excluded. |
| Secrets (`.env`, `APP_KEY`) | NOT in the archive | Keep in the secret store or password manager. Losing `APP_KEY` invalidates every session, all queued encrypted jobs and encrypted casts: keep a sealed copy separately. |

Order: **database first, then files.** A file created after the dump is a harmless orphan (reported by
`php artisan quotes:storage-check`); the reverse order could leave database rows that point at missing files.
Backups contain personal and financial data: `backup.sh` refuses an output directory inside the repository, creates the files readable by the owner only (umask 077, directory 0700) and removes a partial dump if it fails. **Encrypt before any copy leaves the host** (`age` or `gpg`, or provider-side encryption).

## Commands

```bash
# back up (writes <ts>.dump, <ts>-files.tar.gz, <ts>.sha256)
PGHOST=... PGUSER=... PGPASSWORD=... PGDATABASE=portal OUT_DIR=/secure/backups ops/backup/backup.sh

# restore into a NEW database (refuses a non-empty target unless --force)
ops/backup/restore.sh /secure/backups/<ts>.dump portal_restored
OWNER_PASSWORD=... APP_PASSWORD=... psql -d portal_restored -f ops/db/roles.sql   # re-apply grants (passwords from the environment, never on the command line)

# drill: back up, restore into a scratch DB, compare everything, boot the app against it
SOURCE_DB=portal ops/backup/restore-drill.sh
```

Local development without PostgreSQL client tools on the host: `USE_DOCKER=1 PGUSER=portal PGPASSWORD=secret` runs the
tools inside the compose `db` service. On Windows use Git Bash (`"C:\Program Files\Git\bin\bash.exe" ops/backup/restore-drill.sh`),
not WSL.

## The restore drill (what it proves)

1. Backup of the source database and files, checksum verified.
2. Restore into `portal_drill_<timestamp>`.
3. Same tables, same row count and content hash per table, same number of triggers (the audit and invoice
   protections survive) and indexes, same `migrations` rows.
4. Files identical (sha256 manifest).
5. `php artisan migrate:status` shows nothing pending against the restored database and `php artisan portal:health` is healthy (the plain check: `--strict` also demands a scheduler heartbeat, which a freshly restored copy has not produced yet).
6. Scratch database dropped. Any difference prints the table and exits non-zero.

The source database must be QUIET while the drill runs (stop the app, or drill a copy): a live database that changes between
the backup and the comparison produces false differences.

Run it monthly and after every release that contains a migration; record each run in `docs/ops/restore-log.md`.

## Restore safety rules (`restore.sh`)

- The dump must match the checksum file written next to it (`ALLOW_UNVERIFIED=1` to skip).
- Never restores into `postgres`, `template0`, `template1`, nor into the database named by `PGDATABASE` or `DB_DATABASE`: restore into a NEW name, check it, then switch the app over.
- A non-empty target is dropped and recreated only with `--force`, `CONFIRM_DROP=<that database name>`, and nobody else connected.

## After a real restore (checklist)

1. Apply `ops/db/roles.sql` (passwords from the environment) and check the grants against the new database as `verify-roles.sh` does (grants are not in the dump).
2. **Sessions and deactivations:** the dump brings back old `sessions` rows and the `users.active` / `session_epoch` values of the backup moment. Run `TRUNCATE sessions;` (everyone logs in again), then re-apply every user deactivation, role change and password reset made after the backup (read them from the audit log of the live system or your own records).
3. Compare `invoice_number_counters` with the newest invoice number the business has sent (invoice rule below) and run `php artisan quotes:storage-check`.
4. Files are restored by extracting the archive into an EMPTY directory with `tar --no-same-owner -xzf`, never as root and never from an archive whose checksum you did not verify.

## Policy placeholders (the business decides; Phase 8 sets real values)

- RPO 24 h (nightly dump). 15 minutes needs WAL archiving or managed point-in-time recovery (a hosting decision).
- RTO 4 h.
- Retention 7 daily, 4 weekly, 12 monthly. Offsite copy in another account or region. Encrypt files leaving the
  host (`age` or `gpg`) or use provider-side encryption.
- **Invoice rule:** restoring an older backup loses invoice numbers issued after it. After any restore compare
  `invoice_number_counters` with the newest invoice number the business has actually sent. A restore must never
  lower a counter below the highest sent number: raise it manually and document it in the restore log.

## Database roles (least privilege)

`ops/db/roles.sql` creates `portal_owner` (owns the tables; migrations only) and `portal_app` (runtime: no TRUNCATE,
no TRIGGER, no ALTER, no UPDATE/DELETE on `audit_log`). Apply it as a superuser with `OWNER_PASSWORD` and `APP_PASSWORD` in the
environment (never on the command line); role names are psql variables (`-v owner_role=... -v app_role=...`). Roles are
cluster-wide: running it resets the passwords of the roles it names. **Deploy rule: re-run `roles.sql` and verify after EVERY
migrate** (the `audit_log` REVOKE only holds until a migration recreates the table). `ops/db/verify-roles.sh` proves the
restrictions on a scratch database with throwaway role names (job `ops-drill`); it refuses a non-local `PGHOST` unless
`VERIFY_ROLES_ALLOW_REMOTE=1`.
