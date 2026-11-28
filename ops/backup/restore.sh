#!/usr/bin/env bash
# Restore a dump into a target database.
#
#   ops/backup/restore.sh <dump-file> <target-db> [--force]
#
# Safety rules (a restore script is also a drop-database script, so it is deliberately hard to misuse):
#  - the dump's checksum (<ts>.sha256 next to it, written by backup.sh) must verify; ALLOW_UNVERIFIED=1 skips this
#  - system databases (postgres, template0, template1) and the database this shell/app is configured to use
#    (PGDATABASE, DB_DATABASE) are never valid targets
#  - a target that already contains tables is dropped and recreated only with --force AND CONFIRM_DROP=<target-db>,
#    and only when nobody else is connected to it
# Roles and grants are not part of the dump: apply ops/db/roles.sql afterwards (docs/ops/backup.md).
set -euo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

dump="${1:?usage: restore.sh <dump-file> <target-db> [--force]}"
target="${2:?usage: restore.sh <dump-file> <target-db> [--force]}"
force="${3:-}"
[ -f "$dump" ] || die "no such file: $dump"
case "$target" in *[!a-zA-Z0-9_]*|"") die "target database name must match [a-zA-Z0-9_]+" ;; esac
case "$target" in postgres|template0|template1) die "refusing to restore into the system database '$target'" ;; esac
for configured in "${PGDATABASE:-}" "${DB_DATABASE:-}"; do
  [ -z "$configured" ] || [ "$configured" != "$target" ] || die "refusing to restore into '$target': it is the database this shell is configured to use (PGDATABASE/DB_DATABASE). Restore into a new name, check it, then switch over."
done

# checksum
dir="$(cd "$(dirname "$dump")" && pwd)"; name="$(basename "$dump")"; sums="$dir/${name%.dump}.sha256"
if [ -f "$sums" ]; then
  # tr strips CRs (a checksum file written on Windows); awk picks this dump's line, with or without sha256sum's binary-mode `*`.
  ( cd "$dir" && tr -d '\r' < "$sums" | awk -v n="$name" '{ f = $2; sub(/^\*/, "", f); if (f == n) print }' | sha256sum -c - >/dev/null ) || die "checksum of $name does not match $sums: the dump is damaged or was modified"
elif [ "${ALLOW_UNVERIFIED:-0}" != "1" ]; then
  die "no checksum file ($sums). Set ALLOW_UNVERIFIED=1 to restore an unverified dump."
fi

exists="$(sql postgres "select count(*) from pg_database where datname='$target'")"
if [ "$exists" = "1" ]; then
  tables="$(sql "$target" "select count(*) from information_schema.tables where table_schema='public'")"
  if [ "$tables" != "0" ]; then
    [ "$force" = "--force" ] || die "target $target is not empty ($tables tables). Use --force (with CONFIRM_DROP=$target) to drop and recreate it."
    [ "${CONFIRM_DROP:-}" = "$target" ] || die "--force drops database '$target'. Confirm by setting CONFIRM_DROP=$target."
    others="$(sql postgres "select count(*) from pg_stat_activity where datname='$target' and pid <> pg_backend_pid()")"
    [ "$others" = "0" ] || die "$others other session(s) are connected to '$target': stop the application first."
    sql postgres "drop database \"$target\"" >/dev/null
    exists=0
  fi
fi
[ "$exists" = "1" ] || sql postgres "create database \"$target\"" >/dev/null

echo "restoring $dump into $target ..."
# Restore through SQL (pg_restore -f -) instead of `pg_restore -d`: a client NEWER than the server (pg_dump/pg_restore 17+
# against a 16 server, common on CI runners and admin laptops) writes `SET transaction_timeout = 0;`, which an older
# server rejects ("unrecognized configuration parameter") and `--exit-on-error` would then abort the whole restore.
# That one parameter is dropped; every other error still stops the restore (psql ON_ERROR_STOP, single transaction).
# With `pipefail` a failure in pg_restore itself (damaged archive) also fails the pipeline.
pgx pg_restore --no-owner --no-privileges -f - < "$dump" \
  | sed '/^SET transaction_timeout = /d' \
  | pgx psql -X -q --single-transaction -v ON_ERROR_STOP=1 $(pgflags) -d "$target" >/dev/null
echo "ok"
