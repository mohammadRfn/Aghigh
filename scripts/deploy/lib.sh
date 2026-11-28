#!/usr/bin/env bash
# Task 11: shared helpers for the deploy scripts (sourced, never executed). docs/runbook.md explains the layout.
#
#   /opt/aghigh/app      compose project directory (compose files, docker/, scripts/), copied out of the app image
#   /opt/aghigh/shared   .env.production, .env.pgbackrest, deploy.env, jwt/, redis/auth.conf   (never in the repo)
#   /opt/aghigh/state    current_sha, previous_sha, history (last KEEP_RELEASES SHAs), releases.log, deploy.lock
# shellcheck shell=bash

AGHIGH_ROOT="${AGHIGH_ROOT:-/opt/aghigh}"
SHARED_DIR="${SHARED_DIR:-$AGHIGH_ROOT/shared}"
# Optional, non-secret knobs (COMPOSE_PROFILES, APP_SCALE, ROLL_* ...). Shell syntax, written by Ansible.
if [ -f "$SHARED_DIR/deploy.env" ]; then
  set -a
  # shellcheck disable=SC1091
  . "$SHARED_DIR/deploy.env"
  set +a
fi
PROJECT_DIR="${PROJECT_DIR:-$AGHIGH_ROOT/app}"
STATE_DIR="${STATE_DIR:-$AGHIGH_ROOT/state}"
ENV_FILE="${ENV_FILE:-$SHARED_DIR/.env.production}"
PGBACKREST_ENV_FILE="${PGBACKREST_ENV_FILE:-$SHARED_DIR/.env.pgbackrest}"
KEEP_RELEASES="${KEEP_RELEASES:-5}"
MIN_COMPOSE_VERSION="${MIN_COMPOSE_VERSION:-2.24.4}" # `!override` (Compose release notes); verify-compose.sh is the real proof
export SHARED_DIR ENV_FILE PGBACKREST_ENV_FILE

log() { printf '%s [%s] %s\n' "$(date -u +%FT%TZ)" "${LOG_TAG:-deploy}" "$*" >&2; }
die() { log "ERROR: $*"; exit 1; }

need() {
  local bin
  for bin in "$@"; do
    command -v "$bin" >/dev/null 2>&1 || die "missing command: $bin"
  done
}

# Value of KEY in an env file (last assignment wins, surrounding quotes removed). Never prints anything else.
env_get() {
  local key=$1 file=${2:-$ENV_FILE}
  [ -r "$file" ] || return 0
  awk -v k="$key" 'index($0, k "=") == 1 { v = substr($0, length(k) + 2) } END { print v }' "$file" \
    | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'\$/\1/"
}

dc() {
  docker compose --project-directory "$PROJECT_DIR" --env-file "$ENV_FILE" \
    -f "$PROJECT_DIR/compose.yaml" -f "$PROJECT_DIR/compose.prod.yml" "$@"
}

require_compose_version() {
  local have
  have="$(docker compose version --short 2>/dev/null | sed 's/^v//')"
  [ -n "$have" ] || die "docker compose plugin not found"
  if [ "$(printf '%s\n%s\n' "$MIN_COMPOSE_VERSION" "$have" | sort -V | head -n1)" != "$MIN_COMPOSE_VERSION" ]; then
    die "docker compose $have is older than $MIN_COMPOSE_VERSION (compose.prod.yml uses !override)"
  fi
}

valid_sha() { [[ "$1" =~ ^[0-9a-f]{40}$ ]]; }

app_image_repo() {
  local repo="${APP_IMAGE_REPO:-$(env_get APP_IMAGE_REPO)}"
  [ -n "$repo" ] || die "APP_IMAGE_REPO is not set in $ENV_FILE"
  printf '%s\n' "$repo"
}

pull_image() {
  local image=$1 try
  for try in 1 2 3 4 5; do
    if docker pull --quiet "$image" >/dev/null; then return 0; fi
    log "pull of $image failed (attempt $try/5)"
    sleep $((try * 5))
  done
  return 1
}

# The image carries its own compose files, proxy config and deploy scripts (the Dockerfile copies the repository).
# Copying them out means the server never needs git, and the compose model always matches the image that runs.
extract_bundle() {
  local image dest=$2 cid path
  image="$(app_image_repo):$1"
  rm -rf "$dest"
  mkdir -p "$dest"
  cid="$(docker create "$image" true)" || return 1
  for path in compose.yaml compose.prod.yml docker scripts; do
    if ! docker cp "$cid:/app/$path" "$dest/$path"; then
      docker rm "$cid" >/dev/null
      return 1
    fi
  done
  docker rm "$cid" >/dev/null
}

# Copy a bundle over the project directory. Nothing is deleted: server-only files (for example
# docker/observability/secrets/metrics_token) stay. `cp` rewrites files in place, so single-file bind mounts
# (prometheus.yml ...) see the new content; upstreams.yml goes back to its DNS form (no roll is running: lock).
sync_bundle() {
  local src=$1
  [ -f "$src/compose.prod.yml" ] || die "bundle $src has no compose.prod.yml"
  mkdir -p "$PROJECT_DIR"
  cp -a "$src/." "$PROJECT_DIR/"
}

current_sha() { cat "$STATE_DIR/current_sha" 2>/dev/null || true; }
previous_sha() { cat "$STATE_DIR/previous_sha" 2>/dev/null || true; }

# $1 becomes the current release; the release it replaces becomes `previous_sha`; history keeps KEEP_RELEASES SHAs.
record_release() {
  local sha=$1 action=${2:-deploy} cur
  mkdir -p "$STATE_DIR"
  cur="$(current_sha)"
  if [ -n "$cur" ] && [ "$cur" != "$sha" ]; then
    printf '%s\n' "$cur" > "$STATE_DIR/previous_sha"
  fi
  printf '%s\n' "$sha" > "$STATE_DIR/current_sha"
  {
    printf '%s\n' "$sha"
    grep -vxF "$sha" "$STATE_DIR/history" 2>/dev/null || true
  } | head -n "$KEEP_RELEASES" > "$STATE_DIR/history.tmp"
  mv -f "$STATE_DIR/history.tmp" "$STATE_DIR/history"
  printf '%s %s %s\n' "$(date -u +%FT%TZ)" "$action" "$sha" >> "$STATE_DIR/releases.log"
}

# Retention: remove app images whose tag is not one of the last KEEP_RELEASES deployed SHAs. The previous SHA is
# always inside that window, so `rollback` still finds its image locally afterwards.
prune_images() {
  local repo tag
  repo="$(app_image_repo)"
  [ -s "$STATE_DIR/history" ] || return 0
  while read -r tag; do
    [ -n "$tag" ] && [ "$tag" != "<none>" ] || continue
    if ! grep -qxF "$tag" "$STATE_DIR/history"; then
      log "retention: removing image $repo:$tag"
      docker image rm "$repo:$tag" >/dev/null 2>&1 || log "retention: $repo:$tag is still in use, kept"
    fi
  done < <(docker image ls --format '{{.Tag}}' "$repo")
  docker image prune -f >/dev/null 2>&1 || true
  tail -n 200 "$STATE_DIR/releases.log" > "$STATE_DIR/releases.log.tmp" 2>/dev/null \
    && mv -f "$STATE_DIR/releases.log.tmp" "$STATE_DIR/releases.log"
}

acquire_lock() {
  [ "${AGHIGH_LOCK_HELD:-0}" = "1" ] && return 0
  mkdir -p "$STATE_DIR"
  exec 9>"$STATE_DIR/deploy.lock"
  flock -n 9 || die "another deploy or rollback is running (lock: $STATE_DIR/deploy.lock)"
  export AGHIGH_LOCK_HELD=1
}
