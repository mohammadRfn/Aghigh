#!/usr/bin/env bash
# Task 11: resolve the digest every third-party tag points at TODAY (registry API, no docker needed) and print
# the lines for compose.prod.yml / docs/deploy/image-digests.md. Review the diff, never apply it blindly:
# a floating tag (frankenphp:1-php8.5, postgres:18, redis:8, traefik:v3.6) moves, that is why it is pinned.
#   scripts/deploy/pin-digests.sh            # Docker Hub + gcr.io directly
# Behind a mirror resolve them there instead: docker buildx imagetools inspect <mirror>/<image>:<tag>
# The helpers on the right-hand side always return 0, so `test && a || b` cannot run both branches.
# shellcheck disable=SC2015
set -Eeuo pipefail
command -v curl >/dev/null && command -v jq >/dev/null || { echo "curl and jq are required" >&2; exit 1; }

ACCEPT='application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.oci.image.manifest.v1+json, application/vnd.docker.distribution.manifest.v2+json'
IMAGES=(
  dunglas/frankenphp:1-php8.5
  library/postgres:18
  library/redis:8
  library/traefik:v3.6
  glitchtip/glitchtip:v6.0.3
  prom/prometheus:v3.16.0
  grafana/grafana:12.4.14
  grafana/loki:3.6.20
  grafana/alloy:v1.21.3
  prom/node-exporter:v1.12.1
  prometheuscommunity/postgres-exporter:v0.20.1
  prometheuscommunity/pgbouncer-exporter:v0.12.1
  oliver006/redis_exporter:v1.97.0-alpine
  grafana/k6:1.6.1
  gcr.io/cadvisor/cadvisor:v0.52.1
)

digest_of() {
  local ref=$1 repo tag host token
  tag="${ref##*:}"
  repo="${ref%:*}"
  case "$repo" in
    gcr.io/*)
      host=https://gcr.io
      repo="${repo#gcr.io/}"
      token="$(curl -fsS --max-time 30 "https://gcr.io/v2/token?service=gcr.io&scope=repository:$repo:pull" | jq -r .token)"
      ;;
    *)
      host=https://registry-1.docker.io
      token="$(curl -fsS --max-time 30 "https://auth.docker.io/token?service=registry.docker.io&scope=repository:$repo:pull" | jq -r .token)"
      ;;
  esac
  curl -fsSI --max-time 30 -H "Authorization: Bearer $token" -H "Accept: $ACCEPT" "$host/v2/$repo/manifests/$tag" \
    | tr -d '\r' | awk -F': ' 'tolower($1) == "docker-content-digest" { print $2 }'
}

printf '# resolved %s\n' "$(date -u +%FT%TZ)"
rc=0
for ref in "${IMAGES[@]}"; do
  digest="$(digest_of "$ref" || true)"
  if [ -z "$digest" ]; then
    printf '%s UNRESOLVED\n' "$ref"
    rc=1
  else
    printf '%s@%s\n' "${ref#library/}" "$digest"
  fi
done
exit "$rc"
