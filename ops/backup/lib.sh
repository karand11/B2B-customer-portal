#!/usr/bin/env bash
# Shared helpers for the backup scripts. Source it: `. ops/backup/lib.sh`
#
# Connection: PGHOST PGPORT PGUSER PGPASSWORD (standard libpq variables).
# USE_DOCKER=1 runs the PostgreSQL client tools inside the compose `db` service instead
# (local development: no client tools needed on the host). Run from anywhere; paths resolve from the repo root.
#
# Secrets are forwarded into the container by NAME only (`docker exec -e NAME`), never as `NAME=value`, so they
# do not show up in the host process list. Extra variable names to forward: PGX_PASS_ENV="OWNER_PASSWORD APP_PASSWORD".

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT" || exit 2

# pgx <tool> [args...]: run a PostgreSQL client tool on the host or inside the db container.
pgx() {
  if [ "${USE_DOCKER:-0}" = "1" ]; then
    local envflags=(-e PGPASSWORD) n
    for n in ${PGX_PASS_ENV:-}; do envflags+=(-e "$n"); done
    docker compose exec -T "${envflags[@]}" db "$@"
  else
    "$@"
  fi
}

# Common connection flags (user only; host/port come from PG* variables or the container socket).
pgflags() { if [ -n "${PGUSER:-}" ]; then printf -- '-U %s' "$PGUSER"; fi; }

# sql <db> <statement>: tuples-only, unaligned output.
sql() { pgx psql -X -At $(pgflags) -d "$1" -v ON_ERROR_STOP=1 -c "$2"; }

die() { echo "ERROR: $*" >&2; exit 1; }
