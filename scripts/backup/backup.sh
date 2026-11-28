#!/usr/bin/env bash
# Task 11: pgBackRest inside the running postgres container (image docker/postgres/Dockerfile). The repository is
# OFF-server (S3-compatible or SFTP) and encrypted; its settings live in /opt/aghigh/shared/.env.pgbackrest.
#   backup.sh stanza-create     once, after the first start with archive_mode=on
#   backup.sh check             archive_command + repository round trip
#   backup.sh full              weekly  (systemd: aghigh-backup-full.timer)
#   backup.sh incr              daily   (systemd: aghigh-backup-incr.timer)
#   backup.sh info              list backups and the WAL range (= the PITR window)
#   backup.sh expire            apply retention now (also runs after every backup)
# Why pgBackRest and not WAL-G: one tool covers archive-push, full/incremental backups, retention, repository
# encryption, restore to a point in time and `check`, it speaks S3 and SFTP, and it is packaged for the Debian
# base of the official postgres image (PGDG), so the custom image is one apt install on a pinned base.
set -Eeuo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/deploy/lib.sh
. "$HERE/../deploy/lib.sh"
LOG_TAG=backup
action="${1:-}"
export APP_IMAGE_TAG="${APP_IMAGE_TAG:-$(current_sha)}"
[ -n "$APP_IMAGE_TAG" ] || die "nothing is deployed yet ($STATE_DIR/current_sha is empty)"

pgbr() { dc exec -T -u postgres postgres pgbackrest "$@"; }

run() {
  local what=$1 started out rc=0
  shift
  started="$(date +%s)"
  log "$what: start"
  out="$("$@" 2>&1)" || rc=$?
  printf '%s\n' "$out" >&2
  if [ "$rc" -eq 0 ]; then
    "$HERE/notify.sh" ok "pgBackRest $what" "took $(($(date +%s) - started)) s"
  else
    "$HERE/notify.sh" fail "pgBackRest $what" "$(printf '%s\n' "$out" | tail -n 15)"
  fi
  return "$rc"
}

case "$action" in
  stanza-create) run stanza-create pgbr stanza-create ;;
  check) run check pgbr check ;;
  full) run "full backup" pgbr --type=full backup ;;
  incr) run "incremental backup" pgbr --type=incr backup ;;
  expire) run expire pgbr expire ;;
  info) pgbr info ;;
  *) die "usage: backup.sh stanza-create|check|full|incr|expire|info" ;;
esac
