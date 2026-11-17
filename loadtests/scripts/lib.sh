#!/usr/bin/env bash
# Task 10: shared shell helpers. Sourced by the other scripts in this directory (never run directly).
# Every compose call goes through dc(): base file + the loadtest overlay, same project, same .env.docker.
set -euo pipefail

LT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$LT_ROOT"

ENV_FILE="${ENV_FILE:-.env.docker}"
RESULTS_DIR="loadtests/results"
VARIANT_ENV=".env.loadtest.variant" # per-run overrides, written by run-scenario.sh, read ONLY by compose.loadtest.yaml
REVERB_SCALE="${REVERB_SCALE:-1}"
APP_SERVICES=(app horizon reverb scheduler)

die() { echo "loadtest: $*" >&2; exit 1; }
warn() { echo "loadtest: WARNING: $*" >&2; }
say() { echo "== $*"; }
need() { command -v "$1" >/dev/null 2>&1 || die "'$1' is required on the host ($2)"; }

[ -f "$ENV_FILE" ] || die "$ENV_FILE is missing (run: make init)"
[ -f compose.loadtest.yaml ] || die "compose.loadtest.yaml is missing"

dc() { docker compose --env-file "$ENV_FILE" -f compose.yaml -f compose.loadtest.yaml "$@"; }

# Value of KEY in .env.docker (last occurrence, quotes stripped). Empty when absent.
envval() { { grep -E "^$1=" "$ENV_FILE" || true; } | tail -1 | cut -d= -f2- | tr -d '"\r'; }
envdefault() { local value; value="$(envval "$1")"; echo "${value:-$2}"; }

artisan() { dc exec -T app php artisan "$@"; }

# tinker '<php>' [-e KEY=VALUE ...]: last output line of a one-liner. Pass values with -e and read them with getenv().
tinker() {
  local code="$1"; shift
  dc exec -T "$@" app php artisan tinker --execute "$code" | tail -1 | tr -d '\r'
}

app_env() { tinker 'echo app()->environment();'; }

# Guard: destructive or unsafe helpers run only against local or loadtest (never staging/production).
guard_env() {
  local env
  env="$(app_env 2>/dev/null)" || die "cannot read APP_ENV from the app container. Is the stack up? (make loadtest-up)"
  case "$env" in
    local|loadtest) LT_APP_ENV="$env" ;;
    *) die "refusing to run: the app container reports APP_ENV='$env' (allowed: local, loadtest)" ;;
  esac
}

obs_state() {
  if [ -n "$(dc --profile observability ps -q prometheus 2>/dev/null || true)" ]; then echo up; else echo down; fi
}

# Bring the app containers up with the overlay (APP_ENV=loadtest from .env.loadtest) and wait until healthy.
# up_app [--force-recreate]
up_app() {
  dc up -d --no-deps --scale "reverb=$REVERB_SCALE" "$@" "${APP_SERVICES[@]}"
  # scheduler has its healthcheck disabled, so --wait would fail on it: wait only for services that have one
  dc up -d --no-deps --wait --wait-timeout "${UP_TIMEOUT:-300}" --scale "reverb=$REVERB_SCALE" app horizon reverb
  # no --scale here: reverb is not selected, and compose then answers "no such service: reverb: disabled"
  dc up -d --no-deps --wait --wait-timeout "${UP_TIMEOUT:-300}" traefik
}

# The N cheapest active products starting at rank OFFSET (deterministic: price, then id), comma separated.
pick_products() {
  tinker 'echo Illuminate\Support\Facades\DB::table("products")->where("status", "active")->orderBy("price_minor")->orderBy("id")->offset((int) getenv("LT_O"))->limit((int) getenv("LT_N"))->pluck("id")->implode(",");' -e "LT_N=$1" -e "LT_O=${2:-0}"
}

redis_queue() { dc exec -T redis-queue redis-cli "$@" | tr -d '\r'; }
redis_cache() { dc exec -T redis-cache redis-cli "$@" | tr -d '\r'; }

STATE_DB="$(envdefault REDIS_STATE_DB 2)"
QUEUE_DB="$(envdefault REDIS_DB 0)"
CACHE_DB="$(envdefault REDIS_CACHE_DB 0)"
METRICS_DB="$(envdefault REDIS_METRICS_DB 3)"

# Raw text of GET /internal/metrics, fetched INSIDE the app container (the route is denied at Traefik) with the
# container's own METRICS_TOKEN. Works without Prometheus. Empty output = token empty (endpoint 404) or metrics off.
metrics_snapshot() {
  dc exec -T app php -r '$t = (string) getenv("METRICS_TOKEN"); if ($t === "") { exit(0); } $c = stream_context_create(["http" => ["header" => "Authorization: Bearer ".$t, "timeout" => 10, "ignore_errors" => true]]); echo (string) @file_get_contents("http://127.0.0.1:8000/internal/metrics", false, $c);' 2>/dev/null | tr -d '\r' || true
}

psql_migrator() {
  dc exec -T -e PGPASSWORD="$(envval DB_MIGRATOR_PASSWORD)" postgres psql -h 127.0.0.1 -U "$(envval DB_MIGRATOR_USER)" -d "$(envval POSTGRES_DB)" "$@"
}

# PgBouncer admin console as the app user (it is in STATS_USERS, compose.yaml).
psql_pgbouncer() {
  dc exec -T -e PGPASSWORD="$(envval DB_APP_PASSWORD)" postgres psql -h pgbouncer -p 5432 -U "$(envval DB_APP_USER)" -d pgbouncer "$@"
}
