#!/usr/bin/env bash
# Prove that ops/db/roles.sql does what it claims, on a throwaway database with THROWAWAY ROLE NAMES.
#
#   USE_DOCKER=1 PGUSER=portal PGPASSWORD=secret ops/db/verify-roles.sh
#   PGHOST=127.0.0.1 PGPORT=5433 PGUSER=portal PGPASSWORD=secret ops/db/verify-roles.sh      (CI)
#
# Roles are cluster-wide, so this script never touches the real `portal_owner` / `portal_app`: it creates
# `portal_owner_v<ts>` / `portal_app_v<ts>` and drops them (and the scratch database) when it finishes.
# It refuses to run against a remote server (set VERIFY_ROLES_ALLOW_REMOTE=1 only if you really mean it).
#
# 1. create scratch database, apply roles.sql, migrate + seed it AS the owner role
# 2. re-apply roles.sql (idempotent; picks up the migrated tables)
# 3. assert as the app role: SELECT/INSERT/UPDATE work on the application tables, but TRUNCATE,
#    CREATE/DROP TRIGGER, ALTER TABLE, CREATE TABLE, and UPDATE/DELETE on audit_log are all refused
# Needs php + composer dependencies (api/vendor) for the migration step. Exit non-zero on any failure.
set -uo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/../backup/lib.sh"

if [ "${USE_DOCKER:-0}" != "1" ]; then
  case "${PGHOST:-127.0.0.1}" in
    127.0.0.1|localhost|::1|"") ;;
    *) [ "${VERIFY_ROLES_ALLOW_REMOTE:-0}" = "1" ] || die "PGHOST=$PGHOST is not local: this script creates and drops roles and databases. Set VERIFY_ROLES_ALLOW_REMOTE=1 to override." ;;
  esac
fi

stamp="$(date -u +%Y%m%d%H%M%S)"
db="portal_roles_$stamp"
owner="portal_owner_v$stamp"
app="portal_app_v$stamp"
export OWNER_PASSWORD="$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 24)own"
export APP_PASSWORD="$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 24)app"
export PGX_PASS_ENV="OWNER_PASSWORD APP_PASSWORD"
status=0
cleanup() {
  sql postgres "drop database if exists \"$db\" with (force)" >/dev/null 2>&1
  sql postgres "drop role if exists \"$app\"" >/dev/null 2>&1
  sql postgres "drop role if exists \"$owner\"" >/dev/null 2>&1
}
trap cleanup EXIT
fail() { echo "FAIL: $*"; status=1; }

apply_roles() {
  # roles.sql is read on the HOST and piped in, so it also works with USE_DOCKER=1 (no file mount needed).
  # Passwords reach psql through the environment (\getenv), never through the argument list.
  pgx psql -X -q $(pgflags) -d "$db" -v ON_ERROR_STOP=1 -v owner_role="$owner" -v app_role="$app" -f - < "$ROOT/ops/db/roles.sql" >/dev/null
}

sql postgres "create database \"$db\"" >/dev/null || die "cannot create scratch database"
apply_roles || die "roles.sql failed"

echo "== migrate and seed as $owner"
(cd api && env DB_DATABASE="$db" DB_HOST="${APP_DB_HOST:-127.0.0.1}" DB_PORT="${APP_DB_PORT:-5433}" DB_USERNAME="$owner" DB_PASSWORD="$OWNER_PASSWORD" APP_ENV=local "${PHP_BIN:-php}" artisan migrate:fresh --seed --force >/dev/null 2>&1) \
  || die "migration as the owner role failed (is php available and is api/vendor installed?)"
apply_roles || die "roles.sql re-apply failed"

as_app() { # run one statement as the app role; prints result or error
  PGPASSWORD="$APP_PASSWORD" pgx psql -X -At -h "${APP_PGHOST:-127.0.0.1}" -p "${APP_PGPORT:-5432}" -U "$app" -d "$db" -c "$1" 2>&1
}
[ "${USE_DOCKER:-0}" = "1" ] || { APP_PGHOST="${PGHOST:-127.0.0.1}"; APP_PGPORT="${PGPORT:-5433}"; }
# Inside the container the server listens on 5432; on a host connection use the published port.

expect_ok()  { out="$(as_app "$2")"; rc=$?; if [ "$rc" -ne 0 ] || echo "$out" | grep -qi "error\|denied"; then fail "$1 should work but (exit $rc): $out"; else echo "   ok:      $1"; fi; }
expect_deny(){ out="$(as_app "$2")"; rc=$?; if [ "$rc" -ne 0 ] && echo "$out" | grep -qi "permission denied\|must be owner"; then echo "   refused: $1"; else fail "$1 should be refused (exit $rc) but got: $out"; fi; }

echo "== $app privileges"
expect_ok   "SELECT on orders"                    "select count(*) from orders"
expect_ok   "INSERT+UPDATE in a transaction"      "begin; insert into cache(key,value,expiration) values ('verify-roles','x',0); update cache set value='y' where key='verify-roles'; delete from cache where key='verify-roles'; commit"
expect_deny "TRUNCATE orders"                     "truncate orders"
expect_deny "CREATE TRIGGER on orders"            "create trigger t_verify before insert on orders for each row execute function audit_log_forbid_change()"
expect_deny "DROP TRIGGER on audit_log"           "drop trigger audit_log_append_only on audit_log"
expect_deny "ALTER TABLE orders"                  "alter table orders add column verify_roles int"
expect_deny "CREATE TABLE in public"              "create table verify_roles_t (id int)"
expect_deny "UPDATE audit_log"                    "update audit_log set action = action"
expect_deny "DELETE FROM audit_log"               "delete from audit_log"
expect_deny "DROP TABLE orders"                   "drop table orders"

echo "== catalog check (as superuser)"
for p in "TRUNCATE" "TRIGGER" "REFERENCES"; do
  n="$(sql "$db" "select count(*) from information_schema.tables t where table_schema='public' and table_type='BASE TABLE' and has_table_privilege('$app', format('%I.%I', table_schema, table_name), '$p')")"
  if [ "$n" = "0" ]; then echo "   no table grants $p to the app role"; else fail "$n table(s) grant $p to the app role"; fi
done
n="$(sql "$db" "select count(*) from pg_tables where schemaname='public' and tableowner <> '$owner'")"
[ "$n" = "0" ] && echo "   every table is owned by the owner role" || fail "$n table(s) not owned by the owner role"
n="$(sql "$db" "select count(*) from pg_roles r where r.rolname not in ('$owner','$app') and r.rolcanlogin and not r.rolsuper and has_database_privilege(r.rolname, '$db', 'CONNECT')")"
[ "$n" = "0" ] && echo "   CONNECT is not granted to unrelated login roles (revoked from PUBLIC)" || fail "$n unrelated login role(s) can still CONNECT"

[ "$status" = "0" ] && echo "ROLES OK" || echo "ROLES FAILED"
exit "$status"
