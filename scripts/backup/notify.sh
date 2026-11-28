#!/usr/bin/env bash
# Task 11: report hook for backups and restore tests: always a log line, plus a webhook and/or mail when configured
# in /opt/aghigh/shared/deploy.env (BACKUP_WEBHOOK_URL, BACKUP_MAIL_TO). Never fails the caller.
#   notify.sh <ok|fail> <subject> [details]
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/deploy/lib.sh
. "$HERE/../deploy/lib.sh"
LOG_TAG=backup-notify
status="${1:-fail}"
subject="${2:-aghigh backup}"
details="${3:-}"
log "$status: $subject ${details:+- $details}"

if [ -n "${BACKUP_WEBHOOK_URL:-}" ] && command -v jq >/dev/null 2>&1; then
  payload="$(jq -n --arg s "$status" --arg t "[$(hostname)] $subject" --arg d "$details" '{status: $s, text: ($t + (if $d == "" then "" else "\n" + $d end))}')"
  curl -fsS --max-time 15 -H 'Content-Type: application/json' -d "$payload" "$BACKUP_WEBHOOK_URL" >/dev/null \
    || log "webhook delivery failed"
fi
if [ -n "${BACKUP_MAIL_TO:-}" ] && command -v mail >/dev/null 2>&1; then
  printf '%s\n' "$details" | mail -s "[$(hostname)] [$status] $subject" "$BACKUP_MAIL_TO" || log "mail delivery failed"
fi
exit 0
