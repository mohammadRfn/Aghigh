#!/usr/bin/env bash
# Task 10: resource sampler. One JSON line per tick into <outfile> until it is killed (run-scenario.sh does that).
#   sampler.sh <outfile.ndjson> <interval seconds>
# Each line: docker stats per container, Redis INFO memory used_memory of BOTH instances, the Postgres connection
# count, PgBouncer SHOW POOLS (cl_active, cl_waiting, sv_active, sv_idle, maxwait), per Reverb replica the
# ESTABLISHED sockets on :8080 and the open fds of PID 1, and aghigh_metrics_series from /internal/metrics.
# A source that cannot be read is null for that tick (never 0). The sampler itself costs a few `docker exec` per
# tick on the same host: keep the interval at 10 s or more for latency baselines, or disable it with SAMPLER=0.
set -uo pipefail
# shellcheck source=loadtests/scripts/lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
set +e

OUT="${1:?usage: sampler.sh <outfile.ndjson> <interval seconds>}"
INTERVAL="${2:-10}"
trap 'exit 0' TERM INT

BYTES='def bytes: (capture("^(?<n>[0-9.]+)\\s*(?<u>[A-Za-z]+)$")? // null) as $m | if $m == null then null else (($m.n | tonumber) * ({"B": 1, "kB": 1000, "KB": 1000, "KiB": 1024, "MB": 1000000, "MiB": 1048576, "GB": 1000000000, "GiB": 1073741824, "TiB": 1099511627776}[$m.u] // 1) | floor) end;'

num() { case "$1" in ''|*[!0-9.]*) echo null ;; *) echo "$1" ;; esac; }

sample() {
  local ids docker_json rq rc pg pools reverb series now
  now="$(date -u +%s)"

  ids="$(dc ps -q 2>/dev/null)"
  docker_json='[]'
  if [ -n "$ids" ]; then
    # shellcheck disable=SC2086
    docker_json="$(docker stats --no-stream --format '{{json .}}' $ids 2>/dev/null | jq -sc "$BYTES"'[.[] | {name: .Name, cpu_pct: (.CPUPerc | rtrimstr("%") | tonumber? // null), mem_bytes: (.MemUsage | split(" / ")[0] | bytes), mem_limit_bytes: (.MemUsage | split(" / ")[1] | bytes), pids: (.PIDs | tonumber? // null)}]' 2>/dev/null)"
    [ -n "$docker_json" ] || docker_json='[]'
  fi

  rq="$(redis_queue INFO memory 2>/dev/null | awk -F: '$1 == "used_memory" {print $2}')"
  rc="$(redis_cache INFO memory 2>/dev/null | awk -F: '$1 == "used_memory" {print $2}')"
  pg="$(psql_migrator -tAc 'select count(*) from pg_stat_activity where datname = current_database()' 2>/dev/null | tr -d '\r ' | head -1)"

  # SHOW POOLS with its header row, so column positions never matter (they differ between PgBouncer versions).
  pools="$(psql_pgbouncer -A -F '|' --pset footer=off -c 'SHOW POOLS' 2>/dev/null | tr -d '\r' | awk -F'|' '
    NR == 1 { for (i = 1; i <= NF; i++) h[i] = $i; next }
    { printf "%s{", (n++ ? "," : ""); for (i = 1; i <= NF; i++) printf "%s\"%s\":\"%s\"", (i > 1 ? "," : ""), h[i], $i; printf "}" }')"
  pools="$(echo "[$pools]" | jq -c 'map(select(.database != "pgbouncer")) | if length == 0 then null else {
      cl_active: (map(.cl_active | tonumber? // 0) | add), cl_waiting: (map(.cl_waiting | tonumber? // 0) | add),
      sv_active: (map(.sv_active | tonumber? // 0) | add), sv_idle: (map(.sv_idle | tonumber? // 0) | add),
      maxwait: (map(.maxwait | tonumber? // 0) | max) } end' 2>/dev/null)"
  [ -n "$pools" ] || pools=null

  reverb='[]'
  for c in $(dc ps -q reverb 2>/dev/null); do
    local name est fds
    name="$(docker inspect -f '{{.Name}}' "$c" 2>/dev/null | sed 's#^/##')"
    est="$(docker exec "$c" php -r '$n = 0; foreach (["/proc/net/tcp", "/proc/net/tcp6"] as $f) { foreach (@file($f) ?: [] as $i => $l) { if ($i === 0) { continue; } $p = preg_split("/\s+/", trim($l)); if (str_ends_with($p[1], ":1F90") && $p[3] === "01") { $n++; } } } echo $n;' 2>/dev/null)"
    fds="$(docker exec "$c" sh -c 'ls /proc/1/fd 2>/dev/null | wc -l' 2>/dev/null | tr -d ' \r')"
    reverb="$(echo "$reverb" | jq -c --arg n "$name" --argjson e "$(num "$est")" --argjson f "$(num "$fds")" '. + [{container: $n, established: $e, fds: $f}]')"
  done

  series="$(metrics_snapshot | grep -E '^aghigh_metrics_series(\{[^}]*\})? ' | awk '{print $NF}' | head -1)"

  jq -cn --argjson ts "$now" --arg t "$(date -u +%Y-%m-%dT%H:%M:%SZ)" --argjson docker "$docker_json" \
    --argjson rq "$(num "$rq")" --argjson rc "$(num "$rc")" --argjson pg "$(num "$pg")" --argjson pools "$pools" \
    --argjson reverb "$reverb" --argjson series "$(num "$series")" \
    '{ts: $ts, t: $t, docker: $docker, redis: {queue_used_memory: $rq, cache_used_memory: $rc}, postgres: {connections: $pg}, pgbouncer: $pools, reverb: $reverb, metrics_series: $series}' >> "$OUT"
}

: > "$OUT"
while true; do
  sample
  sleep "$INTERVAL" &
  wait $!
done
