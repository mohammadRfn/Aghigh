#!/usr/bin/env bash
# Task 10: runs after every k6 scenario. Collects the facts k6 cannot see and merges them into the result JSON:
#   queue:drain-time --json (first, when --drain)   orders:verify-invariants --json (always)
#   chat:verify-invariants --rebuild --json (--chat) failed-job count, /internal/metrics snapshot (no Prometheus needed)
#   sampler peaks and slopes, host and version info, scenario extras (stock ledger of the flooded product, auth)
# and decides the exit code:
#   - any invariant violation fails the target
#   - EXCEPT with --expect-violations (the naive stock strategy): then violation_count MUST be > 0 and is recorded.
#     The flag is honoured only when the run really was the naive strategy (variant AND the app container's
#     STOCK_STRATEGY); on anything else it is an error, so it can never turn a violating atomic/locking run into a pass.
#   - a failed assertion or BASELINE threshold fails the target; a missed PROVISIONAL threshold only warns.
set -euo pipefail
# shellcheck source=loadtests/scripts/lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
need jq "the result JSON is merged with it"

SCENARIO=""; VARIANT=""; RUN_ID=""; K6_EXIT=0; K6_ENDED=""; SAMPLES=""; PRODUCT=""; AUTH_JSON=""; SECURITY_SINCE=""
DRAIN=0; CHAT=0; EXPECT=0; METRICS_DB_INFO=0
while [ $# -gt 0 ]; do
  case "$1" in
    --scenario) SCENARIO="$2"; shift 2 ;;
    --variant) VARIANT="$2"; shift 2 ;;
    --run-id) RUN_ID="$2"; shift 2 ;;
    --k6-exit) K6_EXIT="$2"; shift 2 ;;
    --k6-ended) K6_ENDED="$2"; shift 2 ;;
    --samples) SAMPLES="$2"; shift 2 ;;
    --product) PRODUCT="$2"; shift 2 ;;
    --auth-json) AUTH_JSON="$2"; shift 2 ;;
    --security-since) SECURITY_SINCE="$2"; shift 2 ;;
    --drain) DRAIN=1; shift ;;
    --chat) CHAT=1; shift ;;
    --metrics-db) METRICS_DB_INFO=1; shift ;;
    --expect-violations) EXPECT=1; shift ;;
    *) die "after-run.sh: unknown argument $1" ;;
  esac
done
[ -n "$SCENARIO" ] && [ -n "$VARIANT" ] && [ -n "$RUN_ID" ] || die "after-run.sh needs --scenario, --variant and --run-id"

RESULT="$RESULTS_DIR/$SCENARIO-$VARIANT-$RUN_ID.json"
HERE="$(dirname "${BASH_SOURCE[0]}")"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
INV="$WORK/invariants.json"
echo '{}' > "$INV"
notes=()
failures=()

put() { jq --arg k "$1" --slurpfile v "$2" '.[$k] = $v[0]' "$INV" > "$WORK/inv.tmp" && mv "$WORK/inv.tmp" "$INV"; }

# Runs an artisan command that prints ONE JSON document; keeps the JSON even when the command exits non-zero
# (verify-invariants exits 1 on violations). Anything else becomes {"error": ...} so a broken check is never a pass.
artisan_json() {
  local out="$1"; shift
  local raw
  raw="$(artisan "$@" 2>/dev/null | tr -d '\r' | grep -E '^\{' | tail -1 || true)"
  if [ -n "$raw" ] && echo "$raw" | jq -e . >/dev/null 2>&1; then echo "$raw" > "$out"; else jq -cn --arg c "$*" '{error: ("no JSON from: php artisan " + $c)}' > "$out"; fi
}

if [ ! -s "$RESULT" ] || ! jq -e . "$RESULT" >/dev/null 2>&1; then
  warn "k6 did not write $RESULT (exit $K6_EXIT): writing a skeleton so the failure is on record"
  jq -n --arg s "$SCENARIO" --arg v "$VARIANT" --arg id "$RUN_ID" --argjson code "$K6_EXIT" \
    '{schema_version: 1, scenario: $s, variant: $v, run: {id: $id, git_sha: null, git_dirty: null, started_at: null, finished_at: null, duration_seconds: null, k6_version: null, base_url: null, params: {}, env: {}},
      host: null, metrics: {p50: null, p90: null, p95: null, p99: null, rps: null, error_rate: null, unit: "ms", http: null, phases: {}, iterations: {count: null, rate: null, dropped: null}, counters: {}, rates: {}, trends: {}, gauges: {}, derived: {}},
      thresholds: [], checks: [], invariants: null, notes: ["k6 exited with code \($code) before writing a summary: no measurements in this file."]}' > "$RESULT"
  failures+=("k6 wrote no summary (exit $K6_EXIT)")
fi

# ---- queues first: the drain time is measured from the moment k6 stopped
if [ "$DRAIN" = 1 ]; then
  say "after-run: queue:drain-time"
  waited=null
  [ -n "$K6_ENDED" ] && waited="$(( $(date -u +%s) - K6_ENDED ))"
  artisan_json "$WORK/drain.json" queue:drain-time --json "--timeout=${DRAIN_TIMEOUT:-900}"
  failed_jobs="$(tinker '$f = app("queue.failer"); echo method_exists($f, "count") ? $f->count() : count($f->all());' 2>/dev/null || true)"
  jq --argjson w "$waited" --arg f "$failed_jobs" '. + {seconds_between_k6_end_and_measurement_start: $w, failed_jobs: ($f | tonumber? // null)}' "$WORK/drain.json" > "$WORK/queues.json"
  put queues "$WORK/queues.json"
  [ "$(jq -r '.drained // false' "$WORK/queues.json")" = true ] || failures+=("queues did not drain (queue:drain-time)")
fi

# ---- invariants
say "after-run: orders:verify-invariants"
artisan_json "$WORK/orders.json" orders:verify-invariants --json
put orders "$WORK/orders.json"
ORDERS_OK="$(jq -r 'if has("error") then "error" else (.ok | tostring) end' "$WORK/orders.json")"
ORDERS_VIOLATIONS="$(jq -r '.violation_count // "null"' "$WORK/orders.json")"

if [ "$CHAT" = 1 ]; then
  say "after-run: chat:verify-invariants --rebuild"
  artisan_json "$WORK/chat.json" chat:verify-invariants --rebuild --json
  put chat "$WORK/chat.json"
  [ "$(jq -r 'if has("error") then "error" else (.ok | tostring) end' "$WORK/chat.json")" = true ] || failures+=("chat invariants violated or not verifiable")
fi

# ---- order-flood: the stock ledger of the flooded product, straight from the tables (facts, not judgement)
if [ -n "$PRODUCT" ]; then
  tinker 'try { $p = getenv("LT_P"); $db = Illuminate\Support\Facades\DB::class; $item = $db::table("stock_items")->where("product_id", $p)->first(["initial", "available", "reserved", "sold"]); $res = $db::table("stock_reservations")->where("product_id", $p)->selectRaw("status, count(*) as reservations, coalesce(sum(quantity), 0) as quantity")->groupBy("status")->get(); $dup = $db::table("orders")->select("user_id")->groupBy("user_id")->havingRaw("count(*) > 1")->get()->count(); echo json_encode(["product_id" => $p, "stock_item" => $item, "reservations_by_status" => $res, "orders_total" => $db::table("orders")->count(), "users_with_more_than_one_order" => $dup]); } catch (Throwable $e) { echo json_encode(["error" => $e->getMessage()]); }' -e "LT_P=$PRODUCT" > "$WORK/stock.raw" 2>/dev/null || true
  if jq -e . "$WORK/stock.raw" >/dev/null 2>&1; then
    jq '. + (if .stock_item then {units_reserved_or_sold: ([.reservations_by_status[]? | select(.status != "released" and .status != "expired" and .status != "cancelled") | (.quantity | tonumber)] | add // 0)} else {} end)
        | . + (if .stock_item and .units_reserved_or_sold != null then {units_beyond_initial_stock: ([0, (.units_reserved_or_sold - (.stock_item.initial | tonumber))] | max)} else {} end)' "$WORK/stock.raw" > "$WORK/stock.json"
  else
    echo '{"error": "could not read the stock ledger"}' > "$WORK/stock.json"
  fi
  put stock "$WORK/stock.json"
fi

# ---- /internal/metrics snapshot (kept next to the result) and the series gauge
SNAPSHOT="$RESULTS_DIR/$SCENARIO-$VARIANT-$RUN_ID.metrics.prom"
metrics_snapshot > "$SNAPSHOT" || true
if [ -s "$SNAPSHOT" ]; then
  series="$(grep -E '^aghigh_metrics_series(\{[^}]*\})? ' "$SNAPSHOT" | awk '{print $NF}' | head -1 || true)"
  jq -n --arg f "$(basename "$SNAPSHOT")" --arg s "$series" --argjson lines "$(grep -vc '^#' "$SNAPSHOT" || true)" \
    '{snapshot_file: $f, sample_lines: $lines, aghigh_metrics_series: ($s | tonumber? // null)}' > "$WORK/metrics.json"
else
  rm -f "$SNAPSHOT"
  echo '{"snapshot_file": null, "aghigh_metrics_series": null, "reason": "empty answer: METRICS_TOKEN empty, METRICS_ENABLED=false, or the app is down"}' > "$WORK/metrics.json"
fi
if [ "$METRICS_DB_INFO" = 1 ]; then
  # Redis has no per-db memory figure: sum MEMORY USAGE over the keys of the metrics db (a small keyspace by design).
  keys="$(redis_queue -n "$METRICS_DB" DBSIZE | awk '{print $NF}' || true)"
  bytes="$(dc exec -T redis-queue sh -c "redis-cli -n $METRICS_DB --scan | while read -r k; do redis-cli -n $METRICS_DB MEMORY USAGE \"\$k\"; done | awk '{s += \$1} END {print s + 0}'" 2>/dev/null | tr -d '\r' || true)"
  jq --arg k "$keys" --arg b "$bytes" --arg db "$METRICS_DB" '. + {metrics_redis_db: ($db | tonumber), metrics_redis_keys: ($k | tonumber? // null), metrics_redis_memory_bytes: ($b | tonumber? // null)}' "$WORK/metrics.json" > "$WORK/metrics.tmp" && mv "$WORK/metrics.tmp" "$WORK/metrics.json"
fi
put app_metrics "$WORK/metrics.json"

# ---- sampler: peaks (Task 11 capacity hand-off) and least-squares slopes (leak detection)
if [ -n "$SAMPLES" ] && [ -s "$SAMPLES" ]; then
  jq -s -f "$HERE/samples.jq" --arg file "$(basename "$SAMPLES")" "$SAMPLES" > "$WORK/resources.json" 2>/dev/null || echo '{"error": "could not aggregate the sampler file"}' > "$WORK/resources.json"
  put resources "$WORK/resources.json"
fi

# ---- auth-abuse extras: cross-IP login and security log events
if [ -n "$AUTH_JSON" ] && [ -s "$AUTH_JSON" ]; then
  cp "$AUTH_JSON" "$WORK/auth.json"
  if [ -n "$SECURITY_SINCE" ]; then
    "$HERE/security-events.sh" "$SECURITY_SINCE" > "$WORK/security.json" 2>/dev/null || echo '{"error": "could not count security events"}' > "$WORK/security.json"
    jq --slurpfile s "$WORK/security.json" '. + {security_events: $s[0]}' "$WORK/auth.json" > "$WORK/auth.tmp" && mv "$WORK/auth.tmp" "$WORK/auth.json"
  fi
  put auth "$WORK/auth.json"
  [ "$(jq -r '.legit_login_other_ip.ok' "$WORK/auth.json")" = true ] || failures+=("a legitimate user could NOT log in from another IP while the attacking IP was limited")
  rm -f "$AUTH_JSON"
fi

# ---- host and versions
"$HERE/host-info.sh" > "$WORK/host.json" 2>/dev/null || echo '{"error": "host-info.sh failed"}' > "$WORK/host.json"

# ---- verdict
EXPECTED=false
if [ "$EXPECT" = 1 ]; then
  ran_strategy="$(jq -r '.run.env.STOCK_STRATEGY // "unknown"' "$RESULT")"
  if [ "$SCENARIO" != order-flood ] || [ "$VARIANT" != naive ] || [ "$ran_strategy" != naive ]; then
    failures+=("--expect-violations is only valid for order-flood/naive, but this run was $SCENARIO/$VARIANT with STOCK_STRATEGY=$ran_strategy")
  elif [ "$ORDERS_VIOLATIONS" != null ] && [ "$ORDERS_VIOLATIONS" -gt 0 ] 2>/dev/null; then
    EXPECTED=true
    notes+=("naive strategy oversold as expected: orders:verify-invariants reported $ORDERS_VIOLATIONS violation(s). This is the demonstration, not a defect.")
  else
    failures+=("naive strategy was expected to violate the invariants but violation_count=$ORDERS_VIOLATIONS (raise USERS or NAIVE_DELAY_MS, lower STOCK)")
  fi
elif [ "$ORDERS_OK" != true ]; then
  failures+=("orders:verify-invariants: ok=$ORDERS_OK, violation_count=$ORDERS_VIOLATIONS")
fi

enforced="$(jq '[.thresholds[] | select(.passed == false and .provisional == false)] | length' "$RESULT")"
provisional="$(jq '[.thresholds[] | select(.passed == false and .provisional == true)] | length' "$RESULT")"
[ "$enforced" -gt 0 ] && failures+=("$enforced assertion/baseline threshold(s) failed (see .thresholds)")
[ "$provisional" -gt 0 ] && notes+=("$provisional PROVISIONAL threshold(s) missed: recorded, not enforced (calibrate after the first baseline).")
case "$K6_EXIT" in
  0) ;;
  99) : ;; # thresholds crossed: judged above through provisional/enforced
  *) failures+=("k6 exited with code $K6_EXIT") ;;
esac

printf '%s\n' ${notes[@]+"${notes[@]}"} | jq -R . | jq -s 'map(select(. != ""))' > "$WORK/notes.json"
printf '%s\n' ${failures[@]+"${failures[@]}"} | jq -R . | jq -s 'map(select(. != ""))' > "$WORK/failures.json"
jq --slurpfile f "$WORK/failures.json" --argjson expected "$EXPECTED" --argjson code "$K6_EXIT" \
  '. + {verdict: {passed: (($f[0] | length) == 0), expected_violations: $expected, failures: $f[0], k6_exit_code: $code}}' "$INV" > "$WORK/inv.final"
jq --slurpfile inv "$WORK/inv.final" --slurpfile host "$WORK/host.json" --slurpfile notes "$WORK/notes.json" \
  '.invariants = $inv[0] | .host = $host[0] | .notes = ((.notes // []) + $notes[0])' "$RESULT" > "$WORK/result.json"
cat "$WORK/result.json" > "$RESULT"

say "result: $RESULT"
jq -r '"   p50=\(.metrics.p50) p95=\(.metrics.p95) p99=\(.metrics.p99) ms  rps=\(.metrics.rps)  error_rate=\(.metrics.error_rate)", "   invariants: orders ok=\(.invariants.orders.ok // "n/a") violations=\(.invariants.orders.violation_count // "n/a")  expected_violations=\(.invariants.verdict.expected_violations)"' "$RESULT"
if [ "${#failures[@]}" -gt 0 ]; then
  for failure in "${failures[@]}"; do echo "   FAIL: $failure" >&2; done
  exit 1
fi
echo "   verdict: OK"
