#!/usr/bin/env bash
# Task 11: post-deploy smoke test over HTTP only (works from the server, a workstation or CI).
#   BASE_URL=https://aghigh.example.com scripts/smoke-test.sh
#   BASE_URL=https://aghigh.example.com RESOLVE_IP=127.0.0.1 scripts/smoke-test.sh   # on the server: local Traefik, real SNI
#   SMOKE_INSECURE=1 ...        # rehearsal with the Let's Encrypt STAGING CA (untrusted certificate)
# Checks: /up, one public catalog read (PgBouncer + Postgres + cache Redis behind it), security headers,
# debug output off, /internal and /horizon closed, HTTP -> HTTPS redirect.
# NOT a failure: failed jobs. The fake payment gateway declines a share of charges by design, so `card_declined`
# entries in failed_jobs are expected in production and this script does not look at the queue at all.
# Not covered here: forged X-Forwarded-For (needs an echo of the client IP: `make verify-proxy` on the real stack).
# The helpers on the right-hand side always return 0, so `test && a || b` cannot run both branches.
# shellcheck disable=SC2015
set -Eeuo pipefail
BASE_URL="${BASE_URL:?BASE_URL is required, e.g. https://aghigh.example.com}"
BASE_URL="${BASE_URL%/}"
CATALOG_PATH="${CATALOG_PATH:-/api/v1/products?per_page=1}"
RETRIES="${SMOKE_RETRIES:-30}" # x 2 s: a first deploy waits for the certificate
command -v curl >/dev/null && command -v jq >/dev/null || { echo "curl and jq are required" >&2; exit 1; }

host="${BASE_URL#*://}"
host="${host%%/*}"
opts=(-sS --max-time 10)
if [ -n "${RESOLVE_IP:-}" ]; then
  opts+=(--resolve "${host%%:*}:443:$RESOLVE_IP" --resolve "${host%%:*}:80:$RESOLVE_IP")
fi
if [ "${SMOKE_INSECURE:-0}" = "1" ]; then
  opts+=(-k)
fi

fail=0
pass() { printf 'ok    %s\n' "$*"; }
flunk() { printf 'FAIL  %s\n' "$*"; fail=1; }
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

# 1. Liveness, with retries.
code=000
for _ in $(seq 1 "$RETRIES"); do
  code="$(curl "${opts[@]}" -o /dev/null -w '%{http_code}' "$BASE_URL/up" 2>/dev/null || true)"
  [ "$code" = "200" ] && break
  sleep 2
done
if [ "$code" = "200" ]; then pass "/up -> 200"; else flunk "/up -> $code"; fi

# 2. Public catalog read + 3. security headers on the same response.
code="$(curl "${opts[@]}" -D "$tmp/headers" -o "$tmp/body" -w '%{http_code}' -H 'Accept: application/json' "$BASE_URL$CATALOG_PATH" 2>/dev/null || true)"
if [ "$code" = "200" ] && jq -e . "$tmp/body" >/dev/null 2>&1; then pass "catalog read -> 200 JSON"; else flunk "catalog read $CATALOG_PATH -> $code"; fi
header() { tr -d '\r' < "$tmp/headers" | awk -F': ' -v h="$1" 'tolower($1) == h { print $2; exit }'; }
case "$(header strict-transport-security)" in *max-age=*) pass "Strict-Transport-Security" ;; *) flunk "Strict-Transport-Security missing" ;; esac
[ "$(header x-content-type-options)" = "nosniff" ] && pass "X-Content-Type-Options: nosniff" || flunk "X-Content-Type-Options missing"
[ -n "$(header x-frame-options)" ] && pass "X-Frame-Options" || flunk "X-Frame-Options missing"
[ -n "$(header referrer-policy)" ] && pass "Referrer-Policy" || flunk "Referrer-Policy missing"
[ -n "$(header x-request-id)" ] && pass "X-Request-Id" || flunk "X-Request-Id missing"
[ -z "$(header x-powered-by)" ] && pass "no X-Powered-By" || flunk "X-Powered-By is exposed"

# 4. Debug off: an error answer must be a bare problem document (no trace, exception class or file path).
code="$(curl "${opts[@]}" -o "$tmp/missing" -w '%{http_code}' -H 'Accept: application/json' "$BASE_URL/api/v1/__smoke_missing__" 2>/dev/null || true)"
if [ "$code" = "404" ] && ! grep -Eq '"(trace|exception|file)"[[:space:]]*:' "$tmp/missing"; then
  pass "error body carries no debug details"
else
  flunk "404 probe -> $code or the body leaks debug details"
fi

# 5. Never routed publicly.
for path in /internal/metrics /horizon; do
  code="$(curl "${opts[@]}" -o /dev/null -w '%{http_code}' "$BASE_URL$path" 2>/dev/null || true)"
  [ "$code" = "403" ] && pass "$path -> 403" || flunk "$path -> $code (expected 403 from the edge)"
done

# 6. Plain HTTP redirects to HTTPS.
case "$BASE_URL" in
  https://*)
    location="$(curl "${opts[@]}" -o /dev/null -w '%{http_code} %{redirect_url}' "http://$host/up" 2>/dev/null || true)"
    case "$location" in
      30[18]\ https://*) pass "http -> https ($location)" ;;
      *) flunk "http://$host/up -> '$location' (expected 301/308 to https)" ;;
    esac
    ;;
esac

[ "$fail" = "0" ] || { echo "smoke test FAILED"; exit 1; }
echo "smoke test passed"
