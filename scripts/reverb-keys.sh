#!/usr/bin/env bash
# Task 8 (dev only): fill EMPTY REVERB_APP_ID / REVERB_APP_KEY / REVERB_APP_SECRET in .env.docker with random values.
# Existing non-empty values are never touched, so re-running is safe. Refuses to run on a production env file.
set -euo pipefail
FILE="${1:-.env.docker}"
[ -f "$FILE" ] || { echo "missing $FILE (run make init)"; exit 1; }
[ -z "$(tail -c1 "$FILE")" ] || echo >> "$FILE"   # glue guard: end the last line before appending
if grep -qE '^APP_ENV=("|)?production' "$FILE"; then
  echo "refusing: $FILE has APP_ENV=production (generate real secrets with your secret manager)"; exit 1
fi
rand_hex() { od -An -N"$1" -tx1 /dev/urandom | tr -d ' \n'; }
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
fill REVERB_APP_ID "$(( (RANDOM << 15 | RANDOM) % 900000 + 100000 ))"
fill REVERB_APP_KEY "$(rand_hex 16)"
fill REVERB_APP_SECRET "$(rand_hex 32)"
echo "Recreate the containers that read them: docker compose --env-file .env.docker up -d --force-recreate app horizon reverb scheduler"
