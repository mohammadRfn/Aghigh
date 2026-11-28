#!/usr/bin/env bash
# Task 11: roll back to a previously deployed SHA. THE manual rollback is this one command:
#   /opt/aghigh/app/scripts/deploy/rollback.sh            (previous SHA from /opt/aghigh/state/previous_sha)
#   /opt/aghigh/app/scripts/deploy/rollback.sh <sha>      (any of the last KEEP_RELEASES SHAs: state/history)
# or from a workstation with the restricted deploy key:   ssh deploy@<host> rollback
#
# It restores the compose files and scripts OF THAT SHA from its image and re-runs the release WITHOUT migrations.
# It never runs migrate:rollback: every release ships expand-only migrations, so the previous code works against
# the newer schema (docs/migration-policy.md).
set -Eeuo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/deploy/lib.sh
. "$HERE/lib.sh"
LOG_TAG=rollback
need docker
acquire_lock

SHA="${1:-$(previous_sha)}"
[ -n "$SHA" ] || die "no previous release recorded in $STATE_DIR/previous_sha"
valid_sha "$SHA" || die "not a 40-hex git sha: $SHA"
if [ -s "$STATE_DIR/history" ] && ! grep -qxF "$SHA" "$STATE_DIR/history"; then
  log "WARNING: $SHA is not one of the last $KEEP_RELEASES deployed releases; its image must still be in the registry"
fi

image="$(app_image_repo):$SHA"
docker image inspect "$image" >/dev/null 2>&1 || pull_image "$image" || die "image $image is neither local nor pullable"

bundle="$AGHIGH_ROOT/bundle.rollback"
extract_bundle "$SHA" "$bundle" || die "cannot read the deploy bundle out of $image"
[ -x "$bundle/scripts/deploy/deploy.sh" ] || die "$image predates the deploy scripts: restore it by hand (docs/runbook.md)"
exec "$bundle/scripts/deploy/deploy.sh" "$SHA" --rollback
