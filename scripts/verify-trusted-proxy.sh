#!/usr/bin/env bash
# Task 7: prove the trusted-proxy setup against the REAL compose stack (Traefik on :8000), with curl.
# Expectations:
#   1. through Traefik without forged headers  -> ip == your host's address on the edge network (not Traefik's own IP)
#   2. through Traefik WITH a forged X-Forwarded-For -> same ip as (1): Traefik overwrites client-supplied XFF
#      (forwardedHeaders.insecure=false, the default). If you see 203.0.113.9 here, Traefik is misconfigured.
#   3. from an UNTRUSTED peer inside the backend network (the horizon container) with a forged header
#      -> ip == that container's address, from_trusted_proxy == false: Laravel ignored the header.
# Requires APP_ENV=local (the _debug route only exists in UnsafeEnvironment).
set -euo pipefail
BASE="${BASE_URL:-http://localhost:8000}"
DC="docker compose --env-file .env.docker"

echo "1) via Traefik, clean:"
curl -sS "$BASE/api/v1/_debug/client-ip"; echo
echo "2) via Traefik, forged X-Forwarded-For (must NOT be 203.0.113.9):"
curl -sS -H 'X-Forwarded-For: 203.0.113.9' "$BASE/api/v1/_debug/client-ip"; echo
echo "3) direct app:8000 from an untrusted container, forged header (must NOT be 203.0.113.9, from_trusted_proxy=false):"
$DC exec -T horizon sh -c "curl -sS -H 'X-Forwarded-For: 203.0.113.9' http://app:8000/api/v1/_debug/client-ip"; echo
echo "4) 429 headers through Traefik (RATE_LIMIT_* at their real values; expect RateLimit-* on 200 too):"
curl -sSI "$BASE/api/v1/products" | grep -i -E '^(ratelimit-|retry-after|x-ratelimit)' || true
