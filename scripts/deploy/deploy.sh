#!/usr/bin/env bash
# Task 11: deploy one image (tag = git SHA) with zero HTTP downtime, or roll back automatically.
#
#   deploy.sh <40-hex sha> [--skip-migrate] [--allow-infra-recreate] [--force] [--rollback]
#
# Order (docs/runbook.md "Deploy"):
#   pull -> remember the previous SHA -> verify the merged compose model -> environment guards -> infra up
#   -> one-off migration container (expand-only, migrator role, direct to Postgres)
#   -> Horizon (horizon:terminate, then recreate with stop_grace_period) and scheduler
#   -> rolling restart of app, then reverb (scripts/deploy/roll.sh)
#   -> smoke test -> record the release, prune old images.
# Any failure after the pull triggers scripts/deploy/rollback.sh <previous SHA>. A rollback never runs
# migrate:rollback: the previous release must work against the new schema (docs/migration-policy.md).
#
# Workers before web: new code must understand jobs queued by the old release (one release of overlap), while old
# workers cannot know job classes that only the new release dispatches.
# Exit codes: 0 deployed, 1 failed and the previous release is serving (or nothing was changed), 2 failed AND the
# rollback failed: manual intervention (docs/runbook.md "Failed deploy").
set -Eeuo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUNDLE_ROOT="$(cd "$HERE/../.." && pwd)"
# shellcheck source=scripts/deploy/lib.sh
. "$HERE/lib.sh"
# shellcheck source=scripts/deploy/roll.sh
. "$HERE/roll.sh"

SHA=""
MODE=deploy
SKIP_MIGRATE=0
ALLOW_INFRA_RECREATE="${ALLOW_INFRA_RECREATE:-0}"
for arg in "$@"; do
  case "$arg" in
    --skip-migrate) SKIP_MIGRATE=1 ;;
    --allow-infra-recreate) export ALLOW_INFRA_RECREATE=1 ;; # exported: an automatic rollback must be allowed to recreate too
    --force) export ROLL_FORCE=1 ;;
    --rollback) MODE=rollback; SKIP_MIGRATE=1 ;;
    -*) die "unknown option $arg" ;;
    *) SHA="$arg" ;;
  esac
done
valid_sha "$SHA" || die "usage: deploy.sh <40-hex git sha> [--skip-migrate] [--allow-infra-recreate] [--force]"
LOG_TAG="$MODE"

INFRA_SERVICES=(postgres pgbouncer redis-queue redis-cache)
SAMPLER_PID=""

step() {
  local name=$1
  shift
  log "==> $name"
  if "$@"; then return 0; fi
  log "FAILED: $name"
  return 1
}

# Guards on the server-side env file. compose.prod.yml already forces APP_ENV, APP_DEBUG and the multiplier for
# the containers; this catches a wrong file before anything starts and covers what compose cannot force.
preflight_env() {
  local v bad=0
  [ "$(env_get APP_ENV)" = "production" ] || { log "APP_ENV must be production"; bad=1; }
  [ "$(env_get APP_DEBUG)" = "false" ] || { log "APP_DEBUG must be false"; bad=1; }
  [ -z "$(env_get RATE_LIMIT_MULTIPLIER)" ] || { log "RATE_LIMIT_MULTIPLIER must be empty (the app refuses to boot otherwise)"; bad=1; }
  [ -n "$(env_get APP_KEY)" ] || { log "APP_KEY is empty"; bad=1; }
  for v in APP_URL JWT_ISSUER JWT_AUDIENCE CORS_ALLOWED_ORIGINS AUTH_FRONTEND_URL; do
    case "$(env_get "$v")" in
      https://*) ;;
      *) log "$v must be an https:// URL of the real domain (now: '$(env_get "$v")')"; bad=1 ;;
    esac
    case "$(env_get "$v")" in *localhost* | *127.0.0.1*) log "$v still points at localhost"; bad=1 ;; esac
  done
  # Clients reach Reverb through Traefik on 443 (wss). REVERB_HOST/PORT/SCHEME are the SERVER-side publishing
  # target (config/broadcasting.php, config/reverb.php): they stay reverb:8080 over http on the backend network.
  [ "$(env_get VITE_REVERB_SCHEME)" = "https" ] || { log "VITE_REVERB_SCHEME must be https (clients use wss on 443)"; bad=1; }
  [ "$(env_get VITE_REVERB_PORT)" = "443" ] || { log "VITE_REVERB_PORT must be 443"; bad=1; }
  [ "$(env_get REVERB_SCHEME)" = "http" ] || { log "REVERB_SCHEME must stay http: it is the internal publishing target reverb:8080, /apps is not routed publicly"; bad=1; }
  if compgen -G "$PROJECT_DIR/.env.loadtest*" >/dev/null; then
    log "loadtest env files found in $PROJECT_DIR: remove them"
    bad=1
  fi
  case "${COMPOSE_FILE:-}" in *loadtest*) log "COMPOSE_FILE references the loadtest overlay"; bad=1 ;; esac
  if [ "$(env_get PAYMENT_GATEWAY)" = "fake" ] || [ -z "$(env_get PAYMENT_GATEWAY)" ]; then
    log "WARNING: PAYMENT_GATEWAY=fake. There is NO real payment gateway in this codebase; the fake one declines by design (PAYMENT_FAKE_FAILURE_RATE=$(env_get PAYMENT_FAKE_FAILURE_RATE))."
  fi
  if [ -n "$(env_get SENTRY_LARAVEL_DSN)" ]; then
    case "$(env_get SENTRY_TRACES_SAMPLE_RATE)" in
      "" | 0 | 0.0 | 0.00) ;;
      *) log "WARNING: Sentry tracing is on. An unreachable DSN held sampled requests ~12 s in one exploratory run; keep SENTRY_HTTP_TIMEOUT short and re-measure (docs/runbook.md)." ;;
    esac
  fi
  [ "$bad" = "0" ]
}

# Recreating Postgres, PgBouncer, Redis or Traefik is NOT zero-downtime. Compare the config hash compose would
# apply with the label on the running container and stop unless the operator asked for it. UNTESTED.
check_infra_drift() {
  local svc cid want have drift=0
  for svc in traefik "${INFRA_SERVICES[@]}"; do
    cid="$(dc ps -q "$svc" 2>/dev/null | head -n1)"
    [ -n "$cid" ] || continue
    want="$(dc config --hash "$svc" 2>/dev/null | awk '{print $2}')"
    have="$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.config-hash" }}' "$cid" 2>/dev/null || true)"
    if [ -n "$want" ] && [ "$want" != "$have" ]; then
      log "infra drift: the configuration of '$svc' changed in this release"
      drift=1
    fi
  done
  [ "$drift" = "0" ] && return 0
  if [ "$ALLOW_INFRA_RECREATE" = "1" ]; then
    log "infra drift accepted (--allow-infra-recreate): the services above WILL be recreated, expect a short outage"
    return 0
  fi
  log "refusing to recreate infrastructure during a zero-downtime deploy; rerun with --allow-infra-recreate in a maintenance window"
  return 1
}

infra_up() {
  if [ "$ALLOW_INFRA_RECREATE" = "1" ]; then
    dc up -d --wait --wait-timeout 180 "${INFRA_SERVICES[@]}"
  else
    dc up -d --no-recreate --wait --wait-timeout 180 "${INFRA_SERVICES[@]}"
  fi
}

# requirepass must really be active: an unauthenticated PING answering PONG means the auth file was not loaded.
verify_redis_auth() {
  local svc out
  for svc in redis-queue redis-cache; do
    out="$(dc exec -T "$svc" sh -c 'unset REDISCLI_AUTH; redis-cli ping' 2>&1 || true)"
    case "$out" in
      *PONG*) log "$svc answers without a password"; return 1 ;;
    esac
    dc exec -T "$svc" sh -c 'redis-cli ping | grep -q PONG' || { log "$svc rejects REDIS_PASSWORD"; return 1; }
  done
}

migrate() {
  dc --profile tools run --rm --no-deps migrate
}

update_horizon() {
  local cid image
  image="$(app_image_repo):$SHA"
  cid="$(dc ps -q horizon 2>/dev/null | head -n1)"
  if [ -n "$cid" ]; then
    if [ "${ROLL_FORCE:-0}" != "1" ] && [ "$(docker inspect -f '{{.Config.Image}}' "$cid")" = "$image" ]; then
      log "horizon already runs $image"
      return 0
    fi
    # Ask the master to finish running jobs and exit; the recreate below then sends SIGTERM and waits up to
    # stop_grace_period (110 s > the longest job timeout, config/horizon.php). Queued jobs stay in Redis.
    dc exec -T horizon php artisan horizon:terminate --wait || log "horizon:terminate failed (continuing: SIGTERM is graceful too)"
  fi
  dc up -d --no-deps --wait --wait-timeout 240 horizon
}

update_scheduler() {
  dc up -d --no-deps scheduler
}

# First deploy: starts Traefik and everything in COMPOSE_PROFILES. Later deploys: creates nothing new and
# recreates nothing unless --allow-infra-recreate was given.
edge_and_rest() {
  if [ "$ALLOW_INFRA_RECREATE" = "1" ]; then
    dc up -d --no-deps traefik || return 1
  fi
  dc up -d --no-recreate
}

verify_debug_off() {
  local out
  out="$(dc exec -T app php artisan config:show app.debug 2>&1 || true)"
  case "$out" in
    *false*) return 0 ;;
    *) log "app.debug is not false inside the container: $out"; return 1 ;;
  esac
}

smoke() {
  local domain
  domain="$(env_get APP_DOMAIN)"
  BASE_URL="https://$domain" RESOLVE_IP="${SMOKE_RESOLVE_IP:-127.0.0.1}" \
    SMOKE_INSECURE="${SMOKE_INSECURE:-0}" "$BUNDLE_ROOT/scripts/smoke-test.sh"
}

start_sampler() {
  "$HERE/conn-budget.sh" sample "$STATE_DIR/conn-budget-$SHA.log" &
  SAMPLER_PID=$!
}

stop_sampler() {
  [ -n "$SAMPLER_PID" ] || return 0
  kill "$SAMPLER_PID" 2>/dev/null || true
  wait "$SAMPLER_PID" 2>/dev/null || true
  SAMPLER_PID=""
  "$HERE/conn-budget.sh" summary "$STATE_DIR/conn-budget-$SHA.log" || true
}

# Called inside `if`, where errexit is off: every step returns explicitly.
release() {
  local image
  image="$(app_image_repo):$SHA"
  step "verify merged compose model" "$HERE/verify-compose.sh" || return 1
  step "environment guards" preflight_env || return 1
  step "infrastructure drift check" check_infra_drift || return 1
  step "infrastructure up" infra_up || return 1
  step "redis requires a password" verify_redis_auth || return 1
  if [ "$SKIP_MIGRATE" = "0" ]; then
    step "migrate (one-off container, migrator role)" migrate || return 1
  else
    log "==> migrations skipped ($MODE)"
  fi
  start_sampler
  step "horizon" update_horizon || return 1
  step "scheduler" update_scheduler || return 1
  step "rolling restart: app" roll_service app 8000 "${APP_SCALE:-1}" "$image" || return 1
  # Scope of the zero-downtime claim is HTTP. Sockets on an old Reverb replica are closed by design; clients
  # reconnect with backoff (docs/chat-protocol.md). The roll only makes a new replica accept them immediately.
  step "rolling restart: reverb" roll_service reverb 8080 "${REVERB_SCALE:-1}" "$image" || return 1
  step "edge and remaining services" edge_and_rest || return 1
  step "APP_DEBUG is off" verify_debug_off || return 1
  step "smoke test" smoke || return 1
}

main() {
  local prev image
  need docker jq curl flock awk
  require_compose_version
  acquire_lock
  [ -r "$ENV_FILE" ] || die "$ENV_FILE is missing (Ansible role app writes it)"
  image="$(app_image_repo):$SHA"
  prev="$(current_sha)"
  export APP_IMAGE_TAG="$SHA"
  [ "$prev" = "$SHA" ] && export ROLL_FORCE=1 # same SHA again = configuration change: roll anyway
  log "$MODE $SHA (current: ${prev:-none})"

  docker image inspect "$image" >/dev/null 2>&1 || pull_image "$image" || die "cannot pull $image"
  if [ "$BUNDLE_ROOT" != "$PROJECT_DIR" ]; then
    sync_bundle "$BUNDLE_ROOT"
  fi

  if release; then
    stop_sampler
    record_release "$SHA" "$MODE"
    prune_images
    log "OK: $SHA is live"
    return 0
  fi
  stop_sampler

  if [ "$MODE" = "deploy" ] && [ -n "$prev" ] && [ "$prev" != "$SHA" ]; then
    log "deploy of $SHA failed: rolling back to $prev (no migrate:rollback, the schema stays)"
    if "$HERE/rollback.sh" "$prev"; then
      printf '%s failed %s (rolled back to %s)\n' "$(date -u +%FT%TZ)" "$SHA" "$prev" >> "$STATE_DIR/releases.log"
      log "rolled back to $prev; $SHA is NOT live"
      return 1
    fi
    log "ROLLBACK FAILED: manual intervention required (docs/runbook.md: Failed deploy)"
    return 2
  fi
  log "$MODE of $SHA failed; nothing to roll back to"
  return 1
}

main
