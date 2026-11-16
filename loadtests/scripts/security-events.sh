#!/usr/bin/env bash
# Task 10: counts `security` channel log events since a UTC timestamp, grouped by message (login_failed, lockout,
# rate_limit_hit, refresh_reuse_detected, ...). Prints one JSON document.
#   security-events.sh 2025-01-31T14:00:00
# Source 1 (default): the JSON log files the containers write (LOG_JSON_STREAM = storage/logs/<service>.jsonl, the
#   files Alloy tails), read on the host through the ./:/app bind mount. Lines are Monolog JsonFormatter:
#   {"message","context","level","level_name","channel","datetime","extra"}; datetime is compared as UTC text.
# Source 2 (fallback when no file exists): `docker compose logs --since` of app and horizon (stdout logging).
# With the observability stack up the same count is available in Loki (labels: service, channel, level):
#   sum by (message) (count_over_time({service=~"app|horizon", channel="security"} | json message [15m]))
#   UNTESTED here; the file count above is the one recorded in the result.
# Note: rate_limit_hit is SAMPLED above RATE_LIMIT_LOG_SAMPLE_AFTER hits per window (config/rate-limits.php), so its
# count is a lower bound of the real number of 429s; lt_http_429 in the k6 result is the exact client-side count.
set -uo pipefail
# shellcheck source=loadtests/scripts/lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
set +e

SINCE="${1:?usage: security-events.sh <UTC timestamp YYYY-MM-DDTHH:MM:SS>}"
SINCE="${SINCE:0:19}"
FILTER='select(type == "object" and .channel == "security" and ((.datetime // "")[0:19] >= $since)) | .message'

files=()
for service in app horizon; do
  [ -f "storage/logs/$service.jsonl" ] && files+=("storage/logs/$service.jsonl")
done

if [ "${#files[@]}" -gt 0 ]; then
  source_name="files: ${files[*]}"
  messages="$(cat "${files[@]}" 2>/dev/null | jq -R -r --arg since "$SINCE" "fromjson? | $FILTER" 2>/dev/null)"
else
  source_name="docker compose logs --since ${SINCE}Z app horizon"
  messages="$(dc logs --no-color --no-log-prefix --since "${SINCE}Z" app horizon 2>/dev/null | jq -R -r --arg since "$SINCE" "fromjson? | $FILTER" 2>/dev/null)"
fi

printf '%s\n' "$messages" | jq -R . | jq -s --arg source "$source_name" --arg since "$SINCE" \
  'map(select(. != "")) | {since_utc: $since, source: $source, total: length, by_message: (group_by(.) | map({key: .[0], value: length}) | from_entries),
    note: "rate_limit_hit is sampled above RATE_LIMIT_LOG_SAMPLE_AFTER: a lower bound, not the number of 429s."}'
