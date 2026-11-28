#!/usr/bin/env bash
# Task 11: connection budget DURING a roll. Old and new app containers overlap and both go through PgBouncer
# (pool = PGBOUNCER_POOL_SIZE, default 20; docs/queues.md counts 15 clients in steady state). Do not assume the
# sum: this samples pg_stat_activity and PgBouncer SHOW POOLS while deploy.sh runs and prints the peaks.
# Compare them with docs/benchmarks/capacity-handoff.md rows 4 to 6 (measured under load on a laptop, not during
# a deploy). The peaks of a real deploy are TODO(measure) until the first rehearsal: docs/runbook.md has the cell.
#   conn-budget.sh sample  <logfile>     loop until killed (deploy.sh starts and stops it)
#   conn-budget.sh summary <logfile>     peaks per series
set -Eeuo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/deploy/lib.sh
. "$HERE/lib.sh"
LOG_TAG=conn-budget
mode="${1:-}"
file="${2:-}"
[ -n "$mode" ] && [ -n "$file" ] || die "usage: conn-budget.sh sample|summary <logfile>"
interval="${CONN_BUDGET_INTERVAL:-2}"

sample_once() {
  local ts pg_user pg_db app_user app_pass
  ts="$(date -u +%FT%TZ)"
  pg_user="$(env_get POSTGRES_USER)"
  pg_db="$(env_get POSTGRES_DB)"
  app_user="$(env_get DB_APP_USER)"
  app_pass="$(env_get DB_APP_PASSWORD)"
  # Server connections per role on the application database.
  dc exec -T postgres psql -U "$pg_user" -d "$pg_db" -AtF ' ' -c \
    "select 'pg_activity', coalesce(usename, '-'), count(*) from pg_stat_activity where datname = current_database() group by 2" 2>/dev/null \
    | sed "s/^/$ts /" >> "$file" || true
  dc exec -T postgres psql -U "$pg_user" -d "$pg_db" -AtF ' ' -c \
    "select 'pg_total', 'all', count(*) from pg_stat_activity where backend_type = 'client backend'" 2>/dev/null \
    | sed "s/^/$ts /" >> "$file" || true
  # PgBouncer console (the app user is in STATS_USERS): cl_active, cl_waiting, sv_active, sv_idle, maxwait.
  dc exec -T -e PGPASSWORD="$app_pass" pgbouncer psql -h 127.0.0.1 -p 5432 -U "$app_user" -d pgbouncer -AtF ' ' -c 'SHOW POOLS' 2>/dev/null \
    | awk -v ts="$ts" -v db="$pg_db" '$1 == db { print ts, "pgbouncer", "cl_active", $3; print ts, "pgbouncer", "cl_waiting", $4; print ts, "pgbouncer", "sv_active", $7 }' >> "$file" || true
}

case "$mode" in
  sample)
    : > "$file"
    trap 'exit 0' TERM INT
    while :; do
      sample_once
      sleep "$interval"
    done
    ;;
  summary)
    [ -s "$file" ] || { log "no samples in $file (psql missing in a container, or the deploy was too short)"; exit 0; }
    log "peaks during this deploy (sampled every ${interval}s; a shorter peak can be missed):"
    awk '{ k = $2 " " $3; if (!(k in m) || $4 > m[k]) m[k] = $4 } END { for (k in m) printf "  %-28s peak %s\n", k, m[k] }' "$file" | sort >&2
    log "PgBouncer column positions follow SHOW POOLS of the pinned image; check one raw line if a value looks wrong (UNTESTED)"
    ;;
  *) die "usage: conn-budget.sh sample|summary <logfile>" ;;
esac
