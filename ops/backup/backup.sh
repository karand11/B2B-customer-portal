#!/usr/bin/env bash
# Back up the database and the private quote files.
#
#   PGDATABASE=portal OUT_DIR=/secure/backups [STORAGE_DIR=api/storage/app/private/quotes] ops/backup/backup.sh
#
# Writes <ts>.dump (pg_dump custom format), <ts>-files.tar.gz and <ts>.sha256 into OUT_DIR, readable by the
# owner only (umask 077, directory 0700): the dump holds personal data, invoices, the audit log and sessions.
# Encrypt before any copy leaves the host (docs/ops/backup.md).
# The database is dumped FIRST, then the files: a file created after the dump is a harmless orphan,
# the reverse order could leave database rows that point at missing files.
# Secrets (.env, APP_KEY) are NOT part of the archive: keep them in the secret store (docs/ops/backup.md).
set -euo pipefail
umask 077
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

: "${PGDATABASE:?set PGDATABASE}"
: "${OUT_DIR:?set OUT_DIR (a directory outside the repository)}"
STORAGE_DIR="${STORAGE_DIR:-api/storage/app/private/quotes}"

mkdir -p "$OUT_DIR"
chmod 700 "$OUT_DIR" 2>/dev/null || echo "warning: could not set mode 0700 on $OUT_DIR (not supported on this filesystem?)" >&2
OUT_ABS="$(cd "$OUT_DIR" && pwd)"
case "$OUT_ABS/" in
  "$ROOT"/*) [ "${ALLOW_IN_TREE:-0}" = "1" ] || die "OUT_DIR is inside the repository ($OUT_ABS): backups contain personal and financial data. Set ALLOW_IN_TREE=1 to override." ;;
esac

ts="$(date -u +%Y%m%dT%H%M%SZ)"
dump="$OUT_ABS/$ts.dump"
files="$OUT_ABS/$ts-files.tar.gz"
sums="$OUT_ABS/$ts.sha256"
ok=0
# A failed run must not leave a partial dump that looks like a backup.
trap '[ "$ok" = "1" ] || rm -f "$dump" "$files" "$sums"' EXIT

echo "dumping database $PGDATABASE ..."
pgx pg_dump --format=custom --no-owner --no-privileges $(pgflags) -d "$PGDATABASE" > "$dump"
[ -s "$dump" ] || die "empty dump"

echo "archiving $STORAGE_DIR ..."
if [ -d "$STORAGE_DIR" ]; then
  tar -czf "$files" -C "$STORAGE_DIR" .
else
  echo "(no storage directory: writing an empty archive)"
  tmp="$(mktemp -d)"; tar -czf "$files" -C "$tmp" .; rmdir "$tmp"
fi

( cd "$OUT_ABS" && sha256sum "$ts.dump" "$ts-files.tar.gz" > "$ts.sha256" )
chmod 600 "$dump" "$files" "$sums"
ok=1
echo "ok"
echo "  $dump"
echo "  $files"
echo "  $sums"
