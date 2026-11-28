#!/usr/bin/env bash
# Task 11: PROVE the merged production model instead of trusting the override file. Runs on every deploy and
# locally:   ENV_FILE=.env.production PGBACKREST_ENV_FILE=.env.pgbackrest PROJECT_DIR=. scripts/deploy/verify-compose.sh
# (copy the two *.example files first; APP_IMAGE_TAG may be any 40-hex string for the check).
# `ok` and `bad` always return 0, so `test && ok || bad` cannot run both branches.
# shellcheck disable=SC2015
set -Eeuo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/deploy/lib.sh
. "$HERE/lib.sh"
LOG_TAG=verify-compose
need docker jq
require_compose_version
export APP_IMAGE_TAG="${APP_IMAGE_TAG:-$(env_get APP_IMAGE_TAG)}"
[ -n "$APP_IMAGE_TAG" ] || export APP_IMAGE_TAG=0000000000000000000000000000000000000000

fail=0
bad() { log "FAIL: $*"; fail=1; }
ok() { log "ok: $*"; }

default_json="$(COMPOSE_PROFILES='' dc config --format json)" || die "docker compose config failed"
obs_json="$(COMPOSE_PROFILES='' dc --profile observability config --format json)" || die "docker compose config (observability) failed"
q() { jq -r "$1" <<<"$default_json"; }

# 1. Default profile: Traefik is the only service with `ports`.
with_ports="$(q '[.services | to_entries[] | select((.value.ports // []) | length > 0) | .key] | sort | join(",")')"
[ "$with_ports" = "traefik" ] && ok "only traefik publishes ports" || bad "services with ports: '$with_ports' (expected: traefik)"

# 2. Traefik publishes exactly 80 and 443 on 0.0.0.0; anything else it publishes is on 127.0.0.1 (admin entrypoint).
public="$(q '[.services.traefik.ports[] | select((.host_ip // "0.0.0.0") != "127.0.0.1") | "\(.host_ip // "0.0.0.0"):\(.published)"] | sort | join(",")')"
[ "$public" = "0.0.0.0:443,0.0.0.0:80" ] && ok "public ports: $public" || bad "public ports are '$public' (expected 0.0.0.0:80 and 0.0.0.0:443)"
dev_port="$(q '[.services.traefik.ports[] | select((.target | tostring) == "80" and (.published | tostring) != "80")] | length')"
[ "$dev_port" = "0" ] && ok "the dev binding HTTP_BIND:HTTP_PORT is gone" || bad "the dev port mapping of traefik survived the merge"

# 3. With the observability profile every other published port stays on 127.0.0.1.
leaks="$(jq -r '[.services | to_entries[] | select(.key != "traefik") | .key as $s | (.value.ports // [])[] | select((.host_ip // "0.0.0.0") != "127.0.0.1") | "\($s):\(.published)"] | join(",")' <<<"$obs_json")"
[ -z "$leaks" ] && ok "admin tools publish on 127.0.0.1 only" || bad "published outside 127.0.0.1: $leaks"

# 4. No Mailpit, no loadtest/dev env files, no source bind mount, forced production environment.
[ "$(q '.services | has("mailpit")')" = "false" ] && ok "no mailpit" || bad "mailpit is part of the production model"
for svc in app horizon reverb scheduler; do
  files="$(q ".services[\"$svc\"].env_file // [] | map(if type == \"object\" then .path else . end) | map(split(\"/\") | last) | join(\",\")")"
  case ",$files," in
    *,.env,* | *,.env.docker,* | *loadtest*) bad "$svc env_file = $files" ;;
    *) ok "$svc env_file = $files" ;;
  esac
  [ "$(q ".services[\"$svc\"].volumes // [] | map(select(.target == \"/app\")) | length")" = "0" ] || bad "$svc still bind-mounts the source tree on /app"
  [ "$(q ".services[\"$svc\"].environment.APP_ENV")" = "production" ] || bad "$svc APP_ENV is not production"
  [ "$(q ".services[\"$svc\"].environment.APP_DEBUG")" = "false" ] || bad "$svc APP_DEBUG is not false"
  [ -z "$(q ".services[\"$svc\"].environment.RATE_LIMIT_MULTIPLIER // \"\"")" ] || bad "$svc RATE_LIMIT_MULTIPLIER is set"
  [ -n "$(q ".services[\"$svc\"].environment.REDIS_PASSWORD // \"\"")" ] || bad "$svc has no REDIS_PASSWORD"
done

# 5. TRUSTED_PROXIES equals the edge subnet (docs/rate-limiting.md).
subnet="$(q '.networks.edge.ipam.config[0].subnet')"
trusted="$(q '.services.app.environment.TRUSTED_PROXIES')"
[ "$subnet" = "$trusted" ] && ok "TRUSTED_PROXIES = edge subnet = $subnet" || bad "TRUSTED_PROXIES ($trusted) != edge subnet ($subnet)"

# 6. Traefik: dashboard off, redirect and ACME configured, no docker socket.
cmd="$(q '.services.traefik.command | join(" ")')"
for flag in --api.dashboard=false --api.insecure=false --entrypoints.web.http.redirections.entrypoint.to=websecure --certificatesresolvers.le.acme.httpchallenge=true; do
  case " $cmd " in *" $flag "*) ok "traefik $flag" ;; *) bad "traefik is missing $flag" ;; esac
done
[ "$(q '.services.traefik.volumes | map(select((.source // "") | test("docker.sock"))) | length')" = "0" ] && ok "traefik has no docker socket" || bad "traefik mounts the docker socket"

# 7. Every third-party image is pinned by digest (the two images built from this repository are tagged instead).
unpinned="$(jq -r --arg app "$(q '.services.app.image')" --arg pg "$(q '.services.postgres.image')" \
  '[.services | to_entries[] | select(.value.image != $app and .value.image != $pg) | select(.value.image | test("@sha256:[0-9a-f]{64}$") | not) | "\(.key)=\(.value.image)"] | join(" ")' <<<"$obs_json")"
[ -z "$unpinned" ] && ok "every third-party image is digest-pinned" || bad "not pinned by digest: $unpinned"

# 8. The one-off migrate service exists only behind its profile.
[ "$(q '.services | has("migrate")')" = "false" ] && ok "migrate is not part of a plain up" || bad "migrate would start with a plain up"

[ "$fail" = "0" ] || die "the merged compose model is not production-safe"
log "merged compose model verified"
