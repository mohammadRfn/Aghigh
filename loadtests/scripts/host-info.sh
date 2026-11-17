#!/usr/bin/env bash
# Task 10: prints ONE JSON document describing the machine and versions of a run (the `host` block of a result).
# Used by after-run.sh and by `make loadtest-host-info`. Anything it cannot read is null or "unknown", with a hint.
set -uo pipefail
# shellcheck source=loadtests/scripts/lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
set +e

cpu_model="$(awk -F: '/model name/ {gsub(/^ +/, "", $2); print $2; exit}' /proc/cpuinfo 2>/dev/null)"
cpu_count="$(nproc 2>/dev/null)"
mem_kb="$(awk '/MemTotal/ {print $2}' /proc/meminfo 2>/dev/null)"
os="$(. /etc/os-release 2>/dev/null && echo "${PRETTY_NAME:-}")"
kernel="$(uname -sr 2>/dev/null)"

wsl=false
wslconfig='null'
if grep -qi microsoft /proc/version 2>/dev/null; then
  wsl=true
  # %UserProfile%\.wslconfig: WSLCONFIG_PATH overrides the guess. Only the [wsl2] limits are recorded.
  path="${WSLCONFIG_PATH:-}"
  if [ -z "$path" ]; then
    for candidate in /mnt/c/Users/*/.wslconfig; do [ -f "$candidate" ] && path="$candidate" && break; done
  fi
  if [ -n "$path" ] && [ -f "$path" ]; then
    wslconfig="$(tr -d '\r' < "$path" | grep -Ei '^\s*(memory|processors|swap|autoMemoryReclaim|networkingMode)\s*=' | jq -R 'split("=") | {key: (.[0] | gsub("\\s"; "")), value: (.[1:] | join("=") | gsub("^\\s+|\\s+$"; ""))}' | jq -sc 'from_entries + {path: "found"}')"
  else
    wslconfig='{"path": "not found: no .wslconfig means WSL defaults (50% of host RAM, all cores). Set WSLCONFIG_PATH if it lives elsewhere."}'
  fi
fi

# AC power: readable on bare-metal Linux only. In WSL and VMs it is unknown and must be confirmed by hand.
power="unknown (confirm AC power manually)"
for supply in /sys/class/power_supply/A*/online; do
  [ -f "$supply" ] && { [ "$(cat "$supply")" = 1 ] && power="ac" || power="battery"; break; }
done

docker_client="$(docker version --format '{{.Client.Version}}' 2>/dev/null)"
docker_server="$(docker version --format '{{.Server.Version}}' 2>/dev/null)"
compose_version="$(docker compose version --short 2>/dev/null)"
# Which engine: "Docker Desktop" (a VM on Windows/macOS) or an engine inside WSL/Linux. They are separate engines.
engine="$(docker info --format '{{.OperatingSystem}}|{{.Name}}|{{.NCPU}}|{{.MemTotal}}|{{.Driver}}' 2>/dev/null)"
context="$(docker context show 2>/dev/null)"

limits="$(dc config --format json 2>/dev/null | jq -c '[.services | to_entries[] | select(.value.cpus != null or .value.mem_limit != null) | {key: .key, value: {cpus: .value.cpus, mem_limit: .value.mem_limit}}] | from_entries' 2>/dev/null)"
[ -n "$limits" ] || limits=null

php_version="$(dc exec -T app php -r 'echo PHP_VERSION;' 2>/dev/null | tr -d '\r')"
laravel_version="$(dc exec -T app php artisan --version 2>/dev/null | tr -d '\r' | head -1)"
octane_workers="$(envdefault OCTANE_WORKERS 2)"
pg_version="$(psql_migrator -tAc 'show server_version' 2>/dev/null | tr -d '\r ' | head -1)"
redis_queue_version="$(redis_queue INFO server 2>/dev/null | awk -F: '$1 == "redis_version" {print $2}')"
redis_cache_version="$(redis_cache INFO server 2>/dev/null | awk -F: '$1 == "redis_version" {print $2}')"
pgbouncer_version="$(psql_pgbouncer -tAc 'SHOW VERSION' 2>/dev/null | tr -d '\r' | head -1)"

jq -n \
  --arg cpu_model "$cpu_model" --arg cpu_count "$cpu_count" --arg mem_kb "$mem_kb" --arg os "$os" --arg kernel "$kernel" \
  --argjson wsl "$wsl" --argjson wslconfig "$wslconfig" --arg power "$power" --arg ulimit_n "$(ulimit -n 2>/dev/null)" \
  --arg docker_client "$docker_client" --arg docker_server "$docker_server" --arg compose "$compose_version" --arg engine "$engine" --arg context "$context" \
  --argjson limits "$limits" --arg php "$php_version" --arg laravel "$laravel_version" --arg workers "$octane_workers" --arg pg "$pg_version" \
  --arg rq "$redis_queue_version" --arg rc "$redis_cache_version" --arg pgb "$pgbouncer_version" --arg scale "$REVERB_SCALE" \
  'def s: if . == "" then null else . end;
   {
     collected_at: (now | todate),
     cpu: {model: ($cpu_model | s), logical_cpus: ($cpu_count | tonumber? // null)},
     memory_total_bytes: (($mem_kb | tonumber? // null) | if . == null then null else . * 1024 end),
     os: ($os | s), kernel: ($kernel | s),
     wsl2: $wsl, wslconfig: $wslconfig,
     power_source: $power,
     host_ulimit_nofile: ($ulimit_n | s),
     docker: {
       client_version: ($docker_client | s), server_version: ($docker_server | s), compose_version: ($compose | s), context: ($context | s),
       engine: (($engine | s) as $e | if $e == null then null else ($e | split("|")) as $p | {operating_system: $p[0], name: $p[1], cpus: ($p[2] | tonumber? // null), memory_bytes: ($p[3] | tonumber? // null), storage_driver: $p[4]} end)
     },
     compose_limits: $limits,
     versions: {php: ($php | s), laravel: ($laravel | s), octane_workers: ($workers | tonumber? // null), postgres: ($pg | s), redis_queue: ($rq | s), redis_cache: ($rc | s), pgbouncer: ($pgb | s), reverb_replicas: ($scale | tonumber? // null)}
   }'
