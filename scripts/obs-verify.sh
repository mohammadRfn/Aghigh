#!/usr/bin/env bash
# Task 9: verify the observability profile against the REAL stack (like verify-chat.sh). Steps:
#   1. Grafana answers, provisioning produced no error, the 6 dashboards and 2 datasources exist.
#   2. /internal/* is denied at the edge (Traefik), with AND without a valid token; Prometheus (backend) reaches
#      app:8000/internal/metrics with the token; the app target is up in Prometheus.
#   3. Traffic burst: make k6 s=smoke + make verify-chat (skip with OBS_SKIP_TRAFFIC=1), then one scrape interval.
#   4. Every PromQL query of dashboards + rules runs against Prometheus; empty only if allowlisted (with a reason).
#   5. Inventory of what this host really exposes (cAdvisor/node series, reverb TCP series, one Traefik access-log
#      line, one app log line with request_id) -> docs/perf/obs-inventory.txt, for the docs' "verified" statements.
#   6. Rules are loaded and healthy.
set -euo pipefail
DC="docker compose --env-file .env.docker"
OBS="$DC --profile observability"
env_get() { grep -E "^$1=" .env.docker | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'" || true; }
GRAFANA_PORT="$(env_get GRAFANA_PORT)"; GRAFANA_PORT="${GRAFANA_PORT:-3000}"
PROM_PORT="$(env_get PROMETHEUS_PORT)"; PROM_PORT="${PROM_PORT:-9090}"
HTTP_PORT="$(env_get HTTP_PORT)"; HTTP_PORT="${HTTP_PORT:-8000}"
GRAFANA="http://127.0.0.1:${GRAFANA_PORT}"
PROM="http://127.0.0.1:${PROM_PORT}"
EDGE="http://localhost:${HTTP_PORT}"
ADMIN_PW="$(env_get GRAFANA_ADMIN_PASSWORD)"
TOKEN="$(env_get METRICS_TOKEN)"
[ -n "$ADMIN_PW" ] || { echo "GRAFANA_ADMIN_PASSWORD is empty: run make obs-keys"; exit 1; }
[ -n "$TOKEN" ] || { echo "METRICS_TOKEN is empty: run make obs-keys and recreate app/horizon/reverb/scheduler"; exit 1; }
fail=0
bad() { echo "   FAIL: $*"; fail=1; }

echo "== 1) Grafana up, provisioning clean, dashboards + datasources present"
for i in $(seq 1 60); do
  if curl -sf "$GRAFANA/api/health" >/dev/null 2>&1; then break; fi
  sleep 2; [ "$i" -lt 60 ] || { bad "Grafana did not answer on $GRAFANA within 120 s"; }
done
if $OBS logs --no-log-prefix --no-color grafana 2>/dev/null | grep -iE 'provisioning' | grep -iE 'level=(error|crit)' | head -5 | grep -q .; then
  $OBS logs --no-log-prefix --no-color grafana | grep -iE 'provisioning' | grep -iE 'level=(error|crit)' | head -5
  bad "Grafana provisioning logged errors"
else
  echo "   OK: no provisioning errors in the Grafana log"
fi
dash="$(curl -sf -u "admin:${ADMIN_PW}" "$GRAFANA/api/search?type=dash-db&tag=aghigh" || echo '[]')"
for uid in aghigh-api aghigh-db aghigh-redis aghigh-queues aghigh-websocket aghigh-orders; do
  printf '%s' "$dash" | grep -q "\"uid\":\"$uid\"" && echo "   OK: dashboard $uid" || bad "dashboard $uid missing"
done
ds="$(curl -sf -u "admin:${ADMIN_PW}" "$GRAFANA/api/datasources" || echo '[]')"
for uid in prometheus loki; do
  printf '%s' "$ds" | grep -q "\"uid\":\"$uid\"" && echo "   OK: datasource $uid" || bad "datasource $uid missing"
done

echo "== 2) /internal is denied at the edge, reachable by Prometheus on the backend network"
code="$(curl -s -o /dev/null -w '%{http_code}' "$EDGE/internal/metrics")"
[ "$code" != "200" ] && echo "   OK: edge without token -> $code" || bad "edge served /internal/metrics without a token"
code="$(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $TOKEN" "$EDGE/internal/metrics")"
[ "$code" != "200" ] && echo "   OK: edge WITH valid token -> $code (Traefik deny-all)" || bad "edge served /internal/metrics with the token: the Traefik internal-deny router is not working"
code="$(curl -s -o /dev/null -w '%{http_code}' "$EDGE/internal/anything")"
[ "$code" != "200" ] && echo "   OK: edge /internal/anything -> $code" || bad "/internal/anything reachable"
if $OBS exec -T prometheus wget -qO- --header="Authorization: Bearer $TOKEN" http://app:8000/internal/metrics 2>/dev/null | grep -q '^aghigh_metrics_'; then
  echo "   OK: prometheus container reads app:8000/internal/metrics with the token"
else
  bad "prometheus container cannot read app:8000/internal/metrics (token mismatch? run make obs-keys and recreate app)"
fi
if $OBS exec -T prometheus wget -qO- http://app:8000/internal/metrics 2>/dev/null | grep -q '^aghigh_metrics_'; then
  bad "app:8000/internal/metrics answered WITHOUT a token"
else
  echo "   OK: app:8000/internal/metrics refuses a missing token"
fi
up="$(curl -s "$PROM/api/v1/query?query=up%7Bjob%3D%22app%22%7D" | grep -o '"value":\[[^]]*\]' | head -1)"
printf '%s' "$up" | grep -q '"1"' && echo "   OK: Prometheus target app is up ($up)" || bad "Prometheus target app is not up: $up"

if [ "${OBS_SKIP_TRAFFIC:-0}" != "1" ]; then
  echo "== 3) traffic burst (make k6 s=smoke, make verify-chat), then wait one scrape interval"
  make k6 s=smoke || bad "make k6 s=smoke failed"
  make verify-chat || bad "make verify-chat failed"
  ./scripts/obs-orders.sh || bad "order traffic failed"
  sleep 20
else
  echo "== 3) traffic burst skipped (OBS_SKIP_TRAFFIC=1)"
fi

echo "== 4) every PromQL query of dashboards + rules against Prometheus (empty only if allowlisted)"
$DC exec -T app php scripts/obs-query-check.php || bad "query check failed (see above)"

echo "== 5) inventory of this host (docs/perf/obs-inventory.txt)"
mkdir -p docs/perf
{
  echo "# make obs-verify inventory: $(date -u) $(uname -a) $(git rev-parse --short HEAD 2>/dev/null)"
  echo "## scrape targets"; curl -s "$PROM/api/v1/targets" | tr ',' '\n' | grep -E '"job"|"health"' | paste - - | sort | uniq -c
  echo "## series per job"; for j in app traefik node cadvisor postgres pgbouncer redis-queue redis-cache loki alloy grafana prometheus; do
    n="$(curl -s "$PROM/api/v1/series?match[]=%7Bjob%3D%22$j%22%7D" | grep -o '"__name__"' | wc -l | tr -d ' ')"; echo "$j: $n series"; done
  echo "## cadvisor metric names present"; curl -s "$PROM/api/v1/label/__name__/values?match[]=%7Bjob%3D%22cadvisor%22%7D" | tr ',' '\n' | tr -d '"[]{}' | grep -E '^container_' | sort
  echo "## cadvisor compose services seen"; curl -s "$PROM/api/v1/label/container_label_com_docker_compose_service/values" | tr -d '"[]{}' | tr ',' '\n' | sed 's/^/  /'
  echo "## reverb ESTABLISHED via cadvisor tcp (empty = gap on this host)"; curl -s "$PROM/api/v1/query?query=container_network_tcp_usage_total%7Bcontainer_label_com_docker_compose_service%3D%22reverb%22%2Ctcp_state%3D%22established%22%7D"; echo
  echo "## node_exporter filesystems"; curl -s "$PROM/api/v1/query?query=node_filesystem_size_bytes" | tr '{' '\n' | grep -o '"mountpoint":"[^"]*"' | sort -u | sed 's/^/  /'
  echo "## one Traefik access-log line (check the request-id field name)"; $DC logs --no-log-prefix --no-color --tail=200 traefik 2>/dev/null | grep -m1 'RequestPath' || echo "  (none yet)"
  echo "## one app JSON log line (check where request_id lives: extra vs context)"; $DC logs --no-log-prefix --no-color --tail=500 app 2>/dev/null | grep -m1 'request_id' || echo "  (none yet)"
  echo "## alerts (state)"; curl -s "$PROM/api/v1/alerts" | tr '{' '\n' | grep -oE '"alertname":"[^"]+"|"state":"[^"]+"' | paste - - | sort | uniq -c
} > docs/perf/obs-inventory.txt 2>&1 || true
sed -n '1,120p' docs/perf/obs-inventory.txt

echo "== 6) rules loaded and healthy"
rules="$(curl -s "$PROM/api/v1/rules")"
groups="$(printf '%s' "$rules" | grep -o '"name":"aghigh-' | wc -l | tr -d ' ')"
[ "$groups" -ge 6 ] && echo "   OK: $groups rule groups loaded" || bad "expected 6 rule groups, found $groups"
printf '%s' "$rules" | grep -q '"health":"err"' && bad "a rule is unhealthy (see $PROM/rules)" || echo "   OK: no unhealthy rule"

[ "$fail" -eq 0 ] && echo "== obs-verify: PASS" || { echo "== obs-verify: FAIL"; exit 1; }
