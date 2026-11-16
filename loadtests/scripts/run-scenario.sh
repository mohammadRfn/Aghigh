#!/usr/bin/env bash
# Task 10: run ONE scenario variant end to end. Called by the `make k6-<scenario>` targets.
#   run-scenario.sh <scenario> <variant>
#   1. writes the per-run overrides to .env.loadtest.variant and recreates the app containers when they changed
#   2. guards the environment (local/loadtest only) and the observability stack (must be down for baselines)
#   3. prepares the scenario (product + stock, cold cache, limits read from the running app, ...)
#   4. starts the resource sampler, runs k6 (scripts read-only, results in loadtests/results/)
#   5. runs after-run.sh: invariants, drain time, metrics snapshot, host info -> merged into the result JSON
# Exit code: non-zero when an invariant is violated (except the EXPECTED naive oversell), an assertion or a
# BASELINE threshold fails, or k6 itself fails. A missed PROVISIONAL threshold is reported but does not fail.
# Parameters are plain environment variables (make passes command-line variables through): RATE, DURATION, USERS...
set -euo pipefail
# shellcheck source=loadtests/scripts/lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

SCENARIO="${1:?usage: run-scenario.sh <scenario> <variant>}"
VARIANT="${2:-default}"
[ -f "loadtests/scenarios/$SCENARIO.js" ] || die "unknown scenario '$SCENARIO' (see loadtests/scenarios/)"
need jq "the result JSON is merged with it"
mkdir -p "$RESULTS_DIR"

RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)"
variant_lines=()
k6_env=()
after_args=()
SAMPLER_INTERVAL="${SAMPLER_INTERVAL:-10}"
WANT_OBS="down"
STORM_PID=""

# ---------------------------------------------------------------- 1. per-variant container environment
case "$SCENARIO" in
  catalog)
    case "$VARIANT" in naive|optimized-cold|optimized-warm) ;; *) die "catalog variants: naive | optimized-cold | optimized-warm" ;; esac
    ;;
  order-flood)
    case "$VARIANT" in naive|atomic|locking) ;; *) die "order-flood variants (STRATEGY): naive | atomic | locking" ;; esac
    delay=0
    [ "$VARIANT" = naive ] && delay="${NAIVE_DELAY_MS:-50}" # widens the naive read->write window; a parameter, not a result
    # Payment failures release stock mid-flood; switch them off so "orders == stock" is about the race only.
    variant_lines+=("STOCK_STRATEGY=$VARIANT" "STOCK_NAIVE_DELAY_MS=$delay" "PAYMENT_FAKE_FAILURE_RATE=0" "PAYMENT_FAKE_TIMEOUT_RATE=0")
    ;;
  spike) ;;
  soak) SAMPLER_INTERVAL="${SAMPLER_INTERVAL_SOAK:-30}" ;;
  websocket-chat)
    case "$VARIANT" in ceiling|fanout|reconnect-jitter|reconnect-immediate) ;; *) die "websocket-chat modes: ceiling | fanout | reconnect-jitter | reconnect-immediate" ;; esac
    ;;
  auth-abuse)
    VARIANT=default
    # The limiters must fire at their configured values: an EMPTY multiplier is "unset" (RateLimitMultiplier::factor).
    variant_lines+=("RATE_LIMIT_MULTIPLIER=")
    ;;
  metrics-overhead)
    case "$VARIANT" in
      metrics-off) variant_lines+=("METRICS_ENABLED=false" "SENTRY_TRACES_SAMPLE_RATE=0.0") ;;
      metrics-on) variant_lines+=("METRICS_ENABLED=true" "SENTRY_TRACES_SAMPLE_RATE=0.0") ;;
      traces-on) variant_lines+=("METRICS_ENABLED=true" "SENTRY_TRACES_SAMPLE_RATE=0.05") ;;
      obs-up) variant_lines+=("METRICS_ENABLED=true" "SENTRY_TRACES_SAMPLE_RATE=0.0"); WANT_OBS="up" ;;
      *) die "metrics-overhead variants: metrics-off | metrics-on | traces-on | obs-up" ;;
    esac
    ;;
  *) die "no orchestration for scenario '$SCENARIO'" ;;
esac

say "$SCENARIO / $VARIANT (run $RUN_ID): container environment"
new_env="$(mktemp)"
{
  echo "# Written by loadtests/scripts/run-scenario.sh for $SCENARIO/$VARIANT. Git-ignored. Read only by compose.loadtest.yaml."
  for line in ${variant_lines[@]+"${variant_lines[@]}"}; do echo "$line"; done
} > "$new_env"
recreate=()
if ! [ -f "$VARIANT_ENV" ] || ! diff -q <(grep -v '^#' "$VARIANT_ENV") <(grep -v '^#' "$new_env") >/dev/null 2>&1; then
  recreate=(--force-recreate)
fi
[ "${FORCE_RECREATE:-0}" = 1 ] && recreate=(--force-recreate)
cp "$new_env" "$VARIANT_ENV"; rm -f "$new_env"
up_app ${recreate[@]+"${recreate[@]}"}

# ---------------------------------------------------------------- 2. guards
guard_env
[ "$LT_APP_ENV" = loadtest ] || warn "APP_ENV=$LT_APP_ENV, not loadtest: copy .env.loadtest.example to .env.loadtest (make loadtest-init) before recording numbers"

OBS="$(obs_state)"
if [ "$WANT_OBS" = up ] && [ "$OBS" != up ]; then die "variant obs-up needs the observability stack: make obs-up"; fi
if [ "$WANT_OBS" = down ] && [ "$OBS" = up ]; then
  [ "${ALLOW_OBS:-0}" = 1 ] || die "the observability stack is up: run 'make obs-down' before a baseline (ALLOW_OBS=1 overrides; the result is then marked observability_stack=up)"
  warn "observability stack is up: latency numbers of this run are NOT baseline material"
fi

# Effective environment of the app container (what really ran, not what was asked for).
IFS='|' read -r E_APP_ENV E_DEBUG E_LOG E_STRATEGY E_DELAY E_METRICS E_MULT E_TRACES E_DSN < <(
  dc exec -T app sh -c 'printf "%s|%s|%s|%s|%s|%s|%s|%s|%s\n" "${APP_ENV:-}" "${APP_DEBUG:-}" "${LOG_LEVEL:-}" "${STOCK_STRATEGY:-atomic}" "${STOCK_NAIVE_DELAY_MS:-0}" "${METRICS_ENABLED:-true}" "${RATE_LIMIT_MULTIPLIER:-}" "${SENTRY_TRACES_SAMPLE_RATE:-}" "$([ -n "${SENTRY_LARAVEL_DSN:-}" ] && echo true || echo false)"' | tr -d '\r'
)
[ "$E_DEBUG" = false ] || warn "APP_DEBUG=$E_DEBUG in the app container: debug must be off for measurements (.env.loadtest)"
if [ "$SCENARIO" = order-flood ] && [ "$E_STRATEGY" != "$VARIANT" ]; then die "app container runs STOCK_STRATEGY=$E_STRATEGY, expected $VARIANT (is STOCK_STRATEGY forced somewhere else?)"; fi
if [ "$SCENARIO" = auth-abuse ] && [ -n "$E_MULT" ]; then die "RATE_LIMIT_MULTIPLIER=$E_MULT is still set in the app container; auth-abuse needs it unset"; fi

GIT_SHA="$(git rev-parse HEAD 2>/dev/null || echo unknown)"
if [ -n "$(git status --porcelain 2>/dev/null || true)" ]; then GIT_DIRTY=true; else GIT_DIRTY=false; fi
K6_VERSION="$(dc --profile loadtest run --rm --no-deps -T k6 version 2>/dev/null | head -1 | tr -d '\r' || true)"

k6_env+=(
  -e "LT_SCENARIO=$SCENARIO" -e "LT_RUN_ID=$RUN_ID" -e "LT_GIT_SHA=$GIT_SHA" -e "LT_GIT_DIRTY=$GIT_DIRTY" -e "LT_K6_VERSION=$K6_VERSION"
  -e "LT_ENV_APP_ENV=$E_APP_ENV" -e "LT_ENV_APP_DEBUG=$E_DEBUG" -e "LT_ENV_LOG_LEVEL=$E_LOG" -e "LT_ENV_STOCK_STRATEGY=$E_STRATEGY"
  -e "LT_ENV_STOCK_NAIVE_DELAY_MS=$E_DELAY" -e "LT_ENV_METRICS_ENABLED=$E_METRICS" -e "LT_ENV_RATE_LIMIT_MULTIPLIER=$E_MULT"
  -e "LT_ENV_REVERB_SCALE=$REVERB_SCALE" -e "LT_ENV_SENTRY_TRACES_SAMPLE_RATE=$E_TRACES" -e "LT_ENV_SENTRY_DSN_SET=$E_DSN" -e "LT_ENV_OBSERVABILITY=$OBS"
)

# ---------------------------------------------------------------- 3. scenario preparation
FULL_VARIANT="$VARIANT"
case "$SCENARIO" in
  catalog)
    k6_env+=(-e "LT_CATALOG_PRODUCTS=$(tinker 'echo Illuminate\Support\Facades\DB::table("products")->count();')")
    if [ "$VARIANT" = optimized-cold ]; then
      say "cold cache: FLUSHDB on redis-cache db $CACHE_DB only"
      redis_cache -n "$CACHE_DB" FLUSHDB >/dev/null
    fi
    ;;
  order-flood)
    STOCK="${STOCK:-50}"
    PRODUCT="$(pick_products 1 "${PRODUCT_RANK:-0}")"
    [ -n "$PRODUCT" ] || die "no active product found: make loadtest-reset"
    artisan inventory:set-stock "$PRODUCT" "$STOCK"
    k6_env+=(-e "LT_PRODUCT_ID=$PRODUCT" -e "LT_STOCK=$STOCK")
    after_args+=(--drain --product "$PRODUCT")
    [ "$VARIANT" = naive ] && after_args+=(--expect-violations)
    ;;
  spike|soak|metrics-overhead)
    IDS="$(pick_products "${ORDER_PRODUCTS:-20}" 10)" # ranks 0..9 are left to order-flood
    [ -n "$IDS" ] || die "no active product found: make loadtest-reset"
    k6_env+=(-e "LT_PRODUCT_IDS=$IDS")
    after_args+=(--drain)
    [ "$SCENARIO" = metrics-overhead ] && after_args+=(--metrics-db)
    ;;
  websocket-chat)
    KEY="$(envval REVERB_APP_KEY)"
    [ -n "$KEY" ] || die "REVERB_APP_KEY is empty in $ENV_FILE: make reverb-keys, then recreate app/horizon/reverb"
    FULL_VARIANT="$VARIANT-r$REVERB_SCALE"
    replicas="$(dc ps -q reverb | wc -l | tr -d ' ')"
    [ "$replicas" = "$REVERB_SCALE" ] || die "expected $REVERB_SCALE Reverb replica(s), found $replicas"
    k6_env+=(-e "REVERB_APP_KEY=$KEY")
    case "$VARIANT" in
      reconnect-*)
        k6_env+=(-e "MODE=reconnect" -e "RECONNECT_POLICY=${VARIANT#reconnect-}")
        if [ "${STORM_TRIGGER:-server}" = server ]; then
          storm="${STORM_AT:-20s}"; storm="${storm%s}"
          # Server-side storm: restart every Reverb replica while the sockets are up (k6 needs a few seconds to start).
          ( sleep "$((storm + ${STORM_K6_START_SECONDS:-4}))"; echo "== storm: restarting reverb"; dc restart reverb >/dev/null 2>&1 || true ) &
          STORM_PID=$!
        fi
        ;;
      *) k6_env+=(-e "MODE=$VARIANT") ;;
    esac
    after_args+=(--chat)
    ;;
  auth-abuse)
    limits="$(tinker 'echo json_encode(["limit" => app(Modules\Shared\RateLimiting\LimiterRegistry::class)->get("auth-login")->limit, "attempts" => (int) config("identity.lockout.attempts"), "base" => (int) config("identity.lockout.base")]);')"
    echo "$limits" | jq -e '.limit and .attempts and .base' >/dev/null 2>&1 || die "could not read the login limit / lockout settings from the app: $limits"
    k6_env+=(-e "LT_AUTH_LOGIN_LIMIT=$(echo "$limits" | jq -r .limit)" -e "LT_LOCKOUT_ATTEMPTS=$(echo "$limits" | jq -r .attempts)" -e "LT_LOCKOUT_BASE_SECONDS=$(echo "$limits" | jq -r .base)")
    ;;
esac
k6_env+=(-e "LT_VARIANT=$FULL_VARIANT")

# Scenario parameters given on the make command line (or exported) are passed through as they are.
for name in RATE DURATION WARMUP USERS USER_OFFSET MAX_VUS PREALLOCATED_VUS PER_PAGE PAGE_DEPTH CATALOG_SEARCH_TERMS REPLAY_PCT GATE_MS \
  MAX_DURATION SPIKE_FACTOR BASELINE SPIKE SPIKE_RAMP SPIKE_DROP RECOVERY WINDOW ORDER_PCT VUS CONNS_PER_VU STEP_MS HOLD STOP_AFTER_FAILURES \
  CONVERSATIONS FANOUT_SOCKETS MESSAGES MSG_INTERVAL_MS SETTLE_MS SOCKETS_PER_VU STORM_AT OBSERVE STORM_TRIGGER RECONNECT_BASE_MS RECONNECT_CAP_MS \
  RECONNECT_MAX_ATTEMPTS HANDSHAKE_TIMEOUT_MS STUFFING_EMAILS STUFFING_BATCH ABUSE_USER_BASE LOCKOUT_WAIT_SECONDS ATTEMPTS_BEYOND_LIMIT \
  TOKEN_REFRESH_SKEW_SECONDS SETUP_TIMEOUT LOADTEST_USER_PASSWORD ALLOWED_ORIGIN; do
  [ -n "${!name:-}" ] && k6_env+=(-e "$name=${!name}")
done

# Separate load generator: prepare this machine, print the flags for `k6 run` on the other one, and stop here.
# Afterwards copy the result JSON into loadtests/results/ and run after-run.sh by hand (loadtests/README.md).
if [ "${PREPARE_ONLY:-0}" = 1 ]; then
  [ -n "$STORM_PID" ] && kill "$STORM_PID" 2>/dev/null || true
  say "prepared $SCENARIO / $FULL_VARIANT (run id $RUN_ID). Flags for k6 on the load generator:"
  printf '%q ' "${k6_env[@]}"; echo
  exit 0
fi

# ---------------------------------------------------------------- 4. sampler + k6
SAMPLES="$RESULTS_DIR/$SCENARIO-$FULL_VARIANT-$RUN_ID.samples.ndjson"
SAMPLER_PID=""
if [ "${SAMPLER:-1}" = 1 ]; then
  "$(dirname "${BASH_SOURCE[0]}")/sampler.sh" "$SAMPLES" "$SAMPLER_INTERVAL" &
  SAMPLER_PID=$!
fi
cleanup() {
  [ -n "$SAMPLER_PID" ] && kill "$SAMPLER_PID" 2>/dev/null || true
  [ -n "$STORM_PID" ] && kill "$STORM_PID" 2>/dev/null || true
}
trap cleanup EXIT

STARTED_ISO="$(date -u +%Y-%m-%dT%H:%M:%S)"
say "k6: $SCENARIO / $FULL_VARIANT"
set +e
# shellcheck disable=SC2086
dc --profile loadtest run --rm "${k6_env[@]}" k6 run ${K6_ARGS:-} "/scripts/scenarios/$SCENARIO.js"
K6_EXIT=$?
set -e
K6_ENDED="$(date -u +%s)"

# auth-abuse: the same account k6 just got limited on must still log in from ANOTHER IP (the host, through the
# published port), immediately, while the k6 IP is still inside its limiter window.
if [ "$SCENARIO" = auth-abuse ]; then
  host_base="http://127.0.0.1:$(envdefault HTTP_PORT 8000)"
  legit="$(printf 'loadtest-%05d@example.test' "$(( ${ABUSE_USER_BASE:-401} + 90 ))")"
  password="${LOADTEST_USER_PASSWORD:-Loadtest-Passphrase-123!}"
  status="$(curl -sS -o /dev/null -m 15 -w '%{http_code}' -X POST "$host_base/api/v1/auth/login" -H 'Accept: application/json' -H 'Content-Type: application/json' \
    -d "$(jq -cn --arg e "$legit" --arg p "$password" '{email: $e, password: $p}')" 2>/dev/null || echo 000)"
  host_ip="$(curl -sS -m 10 "$host_base/api/v1/_debug/client-ip" 2>/dev/null | jq -r '.ip // empty' 2>/dev/null || true)"
  AUTH_JSON="$(mktemp)"
  jq -cn --arg s "$status" --arg ip "$host_ip" --arg a "$legit" --arg since "$STARTED_ISO" \
    '{legit_login_other_ip: {account: $a, status: ($s | tonumber), client_ip_seen_by_app: (if $ip == "" then null else $ip end), ok: ($s == "200")}, security_events_since: $since}' > "$AUTH_JSON"
  after_args+=(--auth-json "$AUTH_JSON" --security-since "$STARTED_ISO")
fi

cleanup
[ -n "$SAMPLER_PID" ] && wait "$SAMPLER_PID" 2>/dev/null || true
[ -n "$STORM_PID" ] && wait "$STORM_PID" 2>/dev/null || true
trap - EXIT

# ---------------------------------------------------------------- 5. after-run (invariants, merge, verdict)
exec "$(dirname "${BASH_SOURCE[0]}")/after-run.sh" --scenario "$SCENARIO" --variant "$FULL_VARIANT" --run-id "$RUN_ID" \
  --k6-exit "$K6_EXIT" --k6-ended "$K6_ENDED" --samples "$SAMPLES" ${after_args[@]+"${after_args[@]}"}
