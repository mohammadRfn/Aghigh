#!/usr/bin/env bash
# Task 11: zero-downtime roll of a scaled compose service behind Traefik's FILE provider (sourced by deploy.sh).
#
# Why not the vendored docker-rollout (docs/adr/0011-zero-downtime-roll.md): it needs a proxy that discovers
# containers and filters them by health through the Docker API (Traefik's docker provider or nginx-proxy), which
# means mounting the Docker socket (root-equivalent, docs/observability.md) into the only internet-facing container
# or adding one more third-party image in front of it. This repository routes with the file provider and no
# socket. The mechanics below are the same as docker-rollout's (scale up with --no-recreate, wait for healthy,
# drain, remove the old containers) and they meet the same requirements: the rolled service has NO published
# ports, NO container_name and a healthcheck.
#
# Steps for service S (N = running containers, T = target scale):
#   1. pin Traefik to the IPs of the N old containers   (DNS would also return containers that are still starting)
#   2. docker compose up --no-recreate --scale S=N+T     (new containers use the new image; old ones are untouched)
#   3. wait until every new container is healthy         (failure: remove the new ones, restore DNS, old keep serving)
#   4. pin Traefik to the new IPs, wait for the reload, drain in-flight requests
#   5. stop (stop_grace_period) and remove the old containers
#   6. restore the DNS form of upstreams.yml             (steady state survives reboots: container IPs are not stable)
# UNTESTED until the first rehearsal: scripts/zero-downtime-check.sh is the proof, not this comment.
# shellcheck shell=bash

COMPOSE_PROJECT="${COMPOSE_PROJECT_NAME:-aghigh}"
EDGE_NETWORK="${EDGE_NETWORK:-${COMPOSE_PROJECT}_edge}"
UPSTREAMS_FILE="${UPSTREAMS_FILE:-$PROJECT_DIR/docker/traefik/prod/dynamic/upstreams.yml}"
ROLL_HEALTH_TIMEOUT="${ROLL_HEALTH_TIMEOUT:-180}"   # seconds a new container may take to become healthy
ROLL_RELOAD_SECONDS="${ROLL_RELOAD_SECONDS:-5}"     # Traefik file watch + providersThrottleDuration (2 s default)
ROLL_DRAIN_SECONDS="${ROLL_DRAIN_SECONDS:-15}"      # time for requests already sent to an old container

APP_UPSTREAMS="http://app:8000"
REVERB_UPSTREAMS="http://reverb:8080"

set_upstreams() {
  case "$1" in
    app) APP_UPSTREAMS="$2" ;;
    reverb) REVERB_UPSTREAMS="$2" ;;
    *) die "roll: unknown service $1" ;;
  esac
}

dns_upstream() {
  case "$1" in
    app) printf 'http://app:8000' ;;
    reverb) printf 'http://reverb:8080' ;;
  esac
}

UPSTREAMS_GENERATION=""
write_upstreams() {
  local dir tmp url
  dir="$(dirname "$UPSTREAMS_FILE")"
  tmp="$dir/.upstreams.tmp" # no .yml extension: the file provider ignores it until the rename
  UPSTREAMS_GENERATION="generation-$(date +%s%N)"
  {
    printf '# Managed by scripts/deploy/roll.sh (%s). Steady state = Docker DNS names; explicit IPs only during a roll.\n' "$UPSTREAMS_GENERATION"
    printf 'http:\n  services:\n    app:\n      loadBalancer:\n        servers:\n'
    for url in $APP_UPSTREAMS; do printf '          - url: "%s"\n' "$url"; done
    printf '    reverb:\n      loadBalancer:\n        servers:\n'
    for url in $REVERB_UPSTREAMS; do printf '          - url: "%s"\n' "$url"; done
  } > "$tmp"
  chmod 0644 "$tmp"
  mv -f "$tmp" "$UPSTREAMS_FILE"
}

# The directory is bind-mounted, so Traefik must see the new file; then give the watcher time to apply it.
wait_traefik_reload() {
  if [ -n "$(dc ps -q traefik 2>/dev/null)" ]; then
    if ! dc exec -T traefik grep -q "$UPSTREAMS_GENERATION" /etc/traefik/dynamic/upstreams.yml; then
      log "roll: Traefik does not see the new upstreams.yml"
      return 1
    fi
  fi
  sleep "$ROLL_RELOAD_SECONDS"
}

container_ip() {
  docker inspect -f "{{with index .NetworkSettings.Networks \"$EDGE_NETWORK\"}}{{.IPAddress}}{{end}}" "$1"
}

upstream_urls() {
  local port=$1 cid ip out=""
  shift
  for cid in "$@"; do
    ip="$(container_ip "$cid")"
    [ -n "$ip" ] || { log "roll: container $cid has no address on $EDGE_NETWORK"; return 1; }
    out="$out http://$ip:$port"
  done
  printf '%s' "${out# }"
}

wait_healthy() {
  local deadline cid status pending
  deadline=$(($(date +%s) + ROLL_HEALTH_TIMEOUT))
  while :; do
    pending=0
    for cid in "$@"; do
      status="$(docker inspect -f '{{.State.Status}}/{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$cid" 2>/dev/null || echo gone/none)"
      case "$status" in
        running/healthy) ;;
        running/starting) pending=1 ;;
        *) log "roll: container $cid is $status"; return 1 ;;
      esac
    done
    [ "$pending" = "0" ] && return 0
    [ "$(date +%s)" -lt "$deadline" ] || { log "roll: not healthy after ${ROLL_HEALTH_TIMEOUT}s"; return 1; }
    sleep 2
  done
}

# roll_service <service> <port> <target scale> <target image>
roll_service() {
  local svc=$1 port=$2 scale=$3 image=$4 cid same urls
  local -a old=() all=() new=()
  mapfile -t old < <(dc ps -q "$svc" 2>/dev/null)

  if [ "${#old[@]}" -eq 0 ]; then
    log "roll: $svc is not running, starting $scale container(s)"
    dc up -d --no-deps --scale "$svc=$scale" --wait --wait-timeout "$ROLL_HEALTH_TIMEOUT" "$svc" || return 1
    return 0
  fi

  if [ "${ROLL_FORCE:-0}" != "1" ] && [ "${#old[@]}" -eq "$scale" ]; then
    same=1
    for cid in "${old[@]}"; do
      [ "$(docker inspect -f '{{.Config.Image}}' "$cid")" = "$image" ] || same=0
    done
    if [ "$same" = "1" ]; then
      log "roll: $svc already runs $image, nothing to do"
      return 0
    fi
  fi

  log "roll: $svc ${#old[@]} old -> $scale new container(s) of $image"
  urls="$(upstream_urls "$port" "${old[@]}")" || return 1
  set_upstreams "$svc" "$urls"
  write_upstreams
  wait_traefik_reload || { set_upstreams "$svc" "$(dns_upstream "$svc")"; write_upstreams; return 1; }

  if ! dc up -d --no-deps --no-recreate --scale "$svc=$((${#old[@]} + scale))" "$svc"; then
    set_upstreams "$svc" "$(dns_upstream "$svc")"
    write_upstreams
    return 1
  fi
  mapfile -t all < <(dc ps -q "$svc")
  for cid in "${all[@]}"; do
    case " ${old[*]} " in
      *" $cid "*) ;;
      *) new+=("$cid") ;;
    esac
  done

  if [ "${#new[@]}" -eq 0 ] || ! wait_healthy "${new[@]}"; then
    log "roll: new $svc container(s) failed; the old ones keep serving"
    for cid in "${new[@]}"; do
      docker logs --tail 40 "$cid" >&2 || true
      docker rm -f "$cid" >/dev/null || true
    done
    # The old containers are the only ones left, so the DNS form resolves to exactly them again.
    set_upstreams "$svc" "$(dns_upstream "$svc")"
    write_upstreams
    return 1
  fi

  urls="$(upstream_urls "$port" "${new[@]}")" || return 1
  set_upstreams "$svc" "$urls"
  write_upstreams
  wait_traefik_reload || return 1
  log "roll: traffic moved to the new $svc container(s), draining the old ones for ${ROLL_DRAIN_SECONDS}s"
  sleep "$ROLL_DRAIN_SECONDS"

  docker stop "${old[@]}" >/dev/null || return 1 # SIGTERM, then the service's stop_grace_period
  docker rm "${old[@]}" >/dev/null || true

  set_upstreams "$svc" "$(dns_upstream "$svc")"
  write_upstreams
  log "roll: $svc done"
}
