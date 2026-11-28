#!/usr/bin/env bash
# Restore drill: prove a backup can actually be restored and the app boots against it.
#
#   SOURCE_DB=portal_e2e ops/backup/restore-drill.sh [--keep]
#
# 1. back up SOURCE_DB (+ the quote files) into a temp directory outside the repo
# 2. restore into a scratch database portal_drill_<ts>
# 3. verify: same tables, same row count and content hash per table, same migrations, same trigger
#    and index counts, same files (sha256 manifest)
# 4. boot check: `php artisan migrate:status` shows nothing pending against the scratch DB
#    and `portal:health` (database, migrations, cache, storage, queue) is healthy when that command exists; skipped when php is unavailable
#    unless REQUIRE_APP_CHECK=1
# 5. drop the scratch database (--keep keeps it)
# Exit status is non-zero on any difference; the table that differs is printed.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

SOURCE_DB="${SOURCE_DB:-portal_e2e}"
STORAGE_DIR="${STORAGE_DIR:-api/storage/app/private/quotes}"
keep=0; [ "${1:-}" = "--keep" ] && keep=1

work="$(mktemp -d)"
ts="$(date -u +%Y%m%dT%H%M%SZ)"
scratch="portal_drill_$(date -u +%Y%m%d%H%M%S)"
status=0
cleanup() {
  rm -rf "$work"
  if [ "$keep" = "0" ]; then sql postgres "drop database if exists \"$scratch\" with (force)" >/dev/null 2>&1; fi
}
trap cleanup EXIT

fail() { echo "FAIL: $*"; status=1; }

echo "== 1. backup of $SOURCE_DB"
PGDATABASE="$SOURCE_DB" OUT_DIR="$work/out" STORAGE_DIR="$STORAGE_DIR" "$ROOT/ops/backup/backup.sh" >"$work/backup.log" 2>&1 || { cat "$work/backup.log"; die "backup failed"; }
dump="$(ls "$work"/out/*.dump)"; archive="$(ls "$work"/out/*-files.tar.gz)"
( cd "$work/out" && sha256sum -c ./*.sha256 >/dev/null ) || die "checksum of the backup does not verify"
echo "   $(basename "$dump") ($(wc -c < "$dump") bytes), checksums ok"

echo "== 2. restore into $scratch"
"$ROOT/ops/backup/restore.sh" "$dump" "$scratch" >"$work/restore.log" 2>&1 || { cat "$work/restore.log"; die "restore failed"; }

echo "== 3. verify"
fingerprint() { # <db>: one line per table: name|rows|content-hash
  local db="$1" t
  for t in $(sql "$db" "select table_name from information_schema.tables where table_schema='public' and table_type='BASE TABLE' order by 1"); do
    printf '%s|' "$t"
    sql "$db" "select count(*) || '|' || coalesce(md5(string_agg(x::text, '' order by x::text)), '-') from \"$t\" x"
  done
}
fingerprint "$SOURCE_DB" > "$work/src.fp"
fingerprint "$scratch" > "$work/dst.fp"
if diff -u "$work/src.fp" "$work/dst.fp" > "$work/fp.diff"; then
  echo "   tables, row counts and content hashes identical ($(wc -l < "$work/src.fp") tables)"
else
  fail "table fingerprints differ:"; cat "$work/fp.diff"
fi
for q in "select count(*) from pg_trigger where not tgisinternal" \
         "select count(*) from pg_indexes where schemaname='public'" \
         "select count(*) from migrations"; do
  a="$(sql "$SOURCE_DB" "$q")"; b="$(sql "$scratch" "$q")"
  if [ "$a" = "$b" ]; then echo "   $q -> $a"; else fail "$q: source=$a restored=$b"; fi
done

echo "== files"
mkdir -p "$work/files"
tar -xzf "$archive" -C "$work/files"
manifest() { ( cd "$1" 2>/dev/null && find . -type f -print0 | sort -z | xargs -0 -r sha256sum ); }
if [ -d "$STORAGE_DIR" ]; then manifest "$STORAGE_DIR" > "$work/src.files"; else : > "$work/src.files"; fi
manifest "$work/files" > "$work/dst.files"
if diff -q "$work/src.files" "$work/dst.files" >/dev/null; then echo "   $(wc -l < "$work/src.files") file(s) identical"; else fail "file manifests differ"; diff "$work/src.files" "$work/dst.files"; fi

echo "== 4. app boot check"
if command -v "${PHP_BIN:-php}" >/dev/null 2>&1 && [ -f api/artisan ]; then
  appenv=(DB_DATABASE="$scratch" DB_HOST="${APP_DB_HOST:-127.0.0.1}" DB_PORT="${APP_DB_PORT:-5433}" DB_USERNAME="${PGUSER:-portal}" DB_PASSWORD="${PGPASSWORD:-secret}")
  out="$(cd api && env "${appenv[@]}" "${PHP_BIN:-php}" artisan migrate:status 2>&1)"
  if echo "$out" | grep -q "Pending"; then fail "migrations pending against the restored database"; echo "$out" | grep Pending; else echo "   migrate:status: nothing pending"; fi
  if (cd api && env "${appenv[@]}" "${PHP_BIN:-php}" artisan list 2>/dev/null | grep -q "portal:health"); then
    # Plain (not --strict): strict mode also demands a scheduler heartbeat, which a freshly restored copy has never produced.
    if (cd api && env "${appenv[@]}" "${PHP_BIN:-php}" artisan portal:health >/dev/null 2>&1); then echo "   portal:health: healthy"; else fail "portal:health failed against the restored database"; fi
  fi
elif [ "${REQUIRE_APP_CHECK:-0}" = "1" ]; then
  fail "php or api/artisan not available but REQUIRE_APP_CHECK=1"
else
  echo "   skipped (php not available)"
fi

echo "== 5. cleanup ($scratch dropped unless --keep)"
if [ "$status" = "0" ]; then echo "DRILL OK"; else echo "DRILL FAILED"; fi
exit "$status"
