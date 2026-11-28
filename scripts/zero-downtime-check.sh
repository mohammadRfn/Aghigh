#!/usr/bin/env bash
# Task 11: PROVE zero downtime instead of claiming it. Sends a constant request rate to /up and to one public
# catalog read for the whole deploy, counts every non-2xx answer and every connection error or timeout, and exits
# non-zero if there was a single one.
#
#   BASE_URL=https://aghigh.example.com STOP_FILE=/tmp/zd.stop scripts/zero-downtime-check.sh &   # start
#   ... deploy ...
#   touch /tmp/zd.stop; wait $!                                                                    # verdict
#   BASE_URL=http://localhost:8000 DURATION=120 scripts/zero-downtime-check.sh                     # fixed duration
#
# Scope: HTTP only. A Reverb restart closes WebSocket connections by design and clients reconnect with backoff
# (docs/chat-protocol.md): that is excluded from the claim and not measured here.
# The catalog probe stays far below the public-catalog limiter (default 1 request/s from one IP against
# CATALOG_PUBLIC_RATE); a 429 would count as a failure, so do not raise CATALOG_EVERY's rate carelessly.
# From a CI runner the path includes the internet: a failure can be the network, not the deploy. Run it on the
# server too (RESOLVE_IP=127.0.0.1) when a result has to be attributed.
set -Eeuo pipefail
BASE_URL="${BASE_URL:?BASE_URL is required}"
BASE_URL="${BASE_URL%/}"
CATALOG_PATH="${CATALOG_PATH:-/api/v1/products?per_page=1}"
RATE="${RATE:-5}"                   # requests per second to /up
CATALOG_EVERY="${CATALOG_EVERY:-5}" # every Nth tick also reads the catalog (5 -> 1 request/s at RATE=5)
DURATION="${DURATION:-0}"           # seconds; 0 = until STOP_FILE appears or SIGINT/SIGTERM
STOP_FILE="${STOP_FILE:-}"
TIMEOUT="${TIMEOUT:-5}"
OUT="${OUT:-$(mktemp -d)}"
command -v curl >/dev/null || { echo "curl is required" >&2; exit 1; }
[ "$DURATION" != "0" ] || [ -n "$STOP_FILE" ] || echo "no DURATION and no STOP_FILE: running until interrupted" >&2

host="${BASE_URL#*://}"
host="${host%%/*}"
opts=(-s -o /dev/null -w '%{http_code}' --max-time "$TIMEOUT")
if [ -n "${RESOLVE_IP:-}" ]; then
  opts+=(--resolve "${host%%:*}:443:$RESOLVE_IP" --resolve "${host%%:*}:80:$RESOLVE_IP")
fi
if [ "${INSECURE:-0}" = "1" ]; then
  opts+=(-k)
fi

mkdir -p "$OUT"
results="$OUT/results.log"
: > "$results"
interval="$(awk -v r="$RATE" 'BEGIN { printf "%.3f", 1 / r }')"
stop=0
trap 'stop=1' INT TERM

probe() { # one request; curl prints 000 on connection errors and timeouts
  local name=$1 url=$2 code
  code="$(curl "${opts[@]}" "$url" 2>/dev/null || true)"
  printf '%s %s %s\n' "$(date -u +%FT%TZ)" "$name" "${code:-000}" >> "$results"
}

start="$(date +%s)"
tick=0
while [ "$stop" = "0" ]; do
  tick=$((tick + 1))
  probe up "$BASE_URL/up" &
  if [ $((tick % CATALOG_EVERY)) -eq 0 ]; then
    probe catalog "$BASE_URL$CATALOG_PATH" &
  fi
  sleep "$interval" || true
  if [ "$DURATION" != "0" ] && [ $(($(date +%s) - start)) -ge "$DURATION" ]; then break; fi
  if [ -n "$STOP_FILE" ] && [ -e "$STOP_FILE" ]; then break; fi
done
wait

total="$(wc -l < "$results" | tr -d ' ')"
errors="$(awk '$3 == "000"' "$results" | wc -l | tr -d ' ')"
non2xx="$(awk '$3 != "000" && $3 !~ /^2/' "$results" | wc -l | tr -d ' ')"
echo "zero-downtime check: $total requests in $(($(date +%s) - start)) s (rate $RATE/s to /up, catalog every ${CATALOG_EVERY}th tick)"
awk '{ c[$2 " " $3]++ } END { for (k in c) printf "  %-14s %d\n", k, c[k] }' "$results" | sort
echo "  connection errors/timeouts: $errors"
echo "  non-2xx responses:          $non2xx"
echo "  raw results:                $results"
if [ "$total" -eq 0 ]; then echo "FAILED: no request was sent"; exit 1; fi
if [ "$errors" -ne 0 ] || [ "$non2xx" -ne 0 ]; then
  echo "FAILED: downtime observed (first failures below)"
  awk '$3 == "000" || $3 !~ /^2/' "$results" | head -n 20
  exit 1
fi
echo "PASSED: no failed request (HTTP scope only; WebSockets reconnect by design)"
