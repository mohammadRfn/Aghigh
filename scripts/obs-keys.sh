#!/usr/bin/env bash
# Task 9 (dev only): fill EMPTY observability secrets in .env.docker with random values (same pattern as
# scripts/reverb-keys.sh: existing non-empty values are never touched, re-running is safe) and write the metrics
# bearer token to docker/observability/secrets/metrics_token for Prometheus (git-ignored, must equal METRICS_TOKEN).
set -euo pipefail
FILE="${1:-.env.docker}"
SECRET_DIR="docker/observability/secrets"
[ -f "$FILE" ] || { echo "missing $FILE (run make init)"; exit 1; }
[ -z "$(tail -c1 "$FILE")" ] || echo >> "$FILE"   # glue guard: end the last line before appending
if grep -qE '^APP_ENV=("|)?production' "$FILE"; then
  echo "refusing: $FILE has APP_ENV=production (generate real secrets with your secret manager)"; exit 1
fi
rand_hex() { od -An -N"$1" -tx1 /dev/urandom | tr -d ' \n'; }
# Letters and digits only for Postgres passwords: they are interpolated into SQL by 02-observability-roles.sh.
rand_alnum() { LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom | head -c "$1"; }
fill() {
  local name="$1" value="$2"
  if grep -qE "^${name}=(\"\"|'')?\s*$" "$FILE"; then
    sed -i.bak -E "s|^${name}=.*$|${name}=${value}|" "$FILE" && rm -f "$FILE.bak"
    echo "set ${name}"
  elif ! grep -qE "^${name}=" "$FILE"; then
    printf '%s=%s\n' "$name" "$value" >> "$FILE"
    echo "added ${name}"
  else
    echo "kept ${name} (already set)"
  fi
}
fill METRICS_TOKEN "$(rand_hex 32)"
fill GRAFANA_ADMIN_PASSWORD "$(rand_alnum 24)"
fill GLITCHTIP_SECRET_KEY "$(rand_hex 32)"
fill GLITCHTIP_DB_PASSWORD "$(rand_alnum 24)"
fill DB_MONITOR_PASSWORD "$(rand_alnum 24)"

# Prometheus reads the token from a file (authorization.credentials_file). Mode 0644: the container runs as
# `nobody` and a bind mount keeps host permissions (dev-only secret, directory is git-ignored).
mkdir -p "$SECRET_DIR"
token="$(grep -E '^METRICS_TOKEN=' "$FILE" | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'")"
printf '%s' "$token" > "$SECRET_DIR/metrics_token"
chmod 0644 "$SECRET_DIR/metrics_token"
echo "wrote $SECRET_DIR/metrics_token (same value as METRICS_TOKEN)"
echo "Next: make monitoring-role (existing Postgres volume), then recreate the containers that read .env.docker:"
echo "  docker compose --env-file .env.docker up -d --force-recreate app horizon reverb scheduler"
echo "  make obs-up"
