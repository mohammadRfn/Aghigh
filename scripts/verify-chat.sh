#!/usr/bin/env bash
# Task 8: prove the chat stack end to end against the REAL compose stack (Traefik :8000 -> app / reverb), like
# verify-trusted-proxy.sh. Run after `make up` (and again after `REVERB_SCALE=2 make up` for cross-replica fan-out).
#   1. /apps/ (Reverb's signed HTTP API) is NOT reachable through Traefik; /app/ is.
#   2. k6 (loadtests/chat-smoke.js): customer + agent log in, open real WebSockets, authorize the private channel
#      through POST /api/broadcasting/auth, post by REST, the event arrives with the right seq within 2 s;
#      a disallowed Origin is refused (pusher:error 4009); another customer's channel is denied (403).
#   3. While k6 holds extra sockets, count ESTABLISHED sockets per Reverb replica (observed distribution, not assumed).
#   4. Reverb's Pusher-compatible connection count over the backend network (Task 9 hand-off).
# Needs: BROADCAST_CONNECTION=reverb + REVERB_* keys (make reverb-keys), APP_ENV=local (creates smoke users).
set -euo pipefail
BASE="${BASE_URL:-http://localhost:8000}"
DC="docker compose --env-file .env.docker"
PASSWORD="${CHAT_SMOKE_PASSWORD:-Smoke-Password-123!}"
APP_ID="$(grep -E '^REVERB_APP_ID=' .env.docker | tail -1 | cut -d= -f2- | tr -d '"' || true)"
APP_KEY="$(grep -E '^REVERB_APP_KEY=' .env.docker | tail -1 | cut -d= -f2- | tr -d '"' || true)"
[ -n "$APP_ID" ] || { echo "REVERB_APP_ID is missing in .env.docker (own line?): run make reverb-keys and recreate app/horizon/reverb"; exit 1; }
[ -n "$APP_KEY" ] || { echo "REVERB_APP_KEY is empty: run make reverb-keys and recreate app/horizon/reverb"; exit 1; }

echo "== 1) /apps/ must not reach Reverb through Traefik (expect the APP's 404 with X-Request-Id, not Reverb's 401)"
headers="$(curl -sS -o /dev/null -D - "$BASE/apps/${APP_ID}/connections")"
status="$(printf '%s' "$headers" | head -1 | awk '{print $2}')"
echo "   status=$status"
if printf '%s' "$headers" | grep -qi '^x-request-id:' && [ "$status" = "404" ]; then
  echo "   OK: answered by Laravel (Traefik routed /apps/ to the app, not to Reverb)"
else
  echo "   FAIL: /apps/ looks routed to Reverb"; exit 1
fi
echo "   /app/ still routed to Reverb (plain GET without Upgrade; any non-Laravel answer is fine):"
curl -sS -o /dev/null -w '   status=%{http_code}\n' "$BASE/app/${APP_KEY}?protocol=7" || true

echo "== smoke users (customer A, customer B, agent) and a clean slate for them"
$DC exec -T -e SMOKE_PASSWORD="$PASSWORD" app php artisan tinker --execute '
$hash = Illuminate\Support\Facades\Hash::make(getenv("SMOKE_PASSWORD"));
$ids = [];
foreach (["chat-smoke-a@example.test" => "customer", "chat-smoke-b@example.test" => "customer", "chat-smoke-agent@example.test" => "support_agent"] as $email => $role) {
    $user = Modules\Identity\Models\User::query()->firstOrNew(["email" => $email]);
    $user->forceFill(["name" => "Chat smoke ".$role, "password" => $hash, "role" => $role, "email_verified_at" => now()])->save();
    $ids[] = (string) $user->id;
}
$now = now();
$open = Illuminate\Support\Facades\DB::table("conversations")->whereIn("customer_id", $ids)->where("status", "<>", "closed")->pluck("id");
Illuminate\Support\Facades\DB::table("assignments")->whereIn("conversation_id", $open)->whereNull("released_at")->update(["released_at" => $now, "reason" => "closed"]);
Illuminate\Support\Facades\DB::table("conversations")->whereIn("id", $open)->update(["status" => "closed", "closed_at" => $now, "updated_at" => $now]);
foreach ($ids as $id) { Illuminate\Support\Facades\Redis::connection("state")->del("chat:conn:".$id); }
echo "ok ".count($ids)." users, closed ".count($open)." stale conversations, connection slots reset\n";
'
$DC exec -T app php artisan chat:rebuild-queue

echo "== 2) k6 chat smoke through Traefik (holds extra sockets for the distribution sample)"
$DC --profile loadtest run --rm -e REVERB_APP_KEY="$APP_KEY" -e CHAT_SMOKE_PASSWORD="$PASSWORD" -e HOLD_SOCKETS="${HOLD_SOCKETS:-12}" \
  k6 run /scripts/chat-smoke.js &
K6_PID=$!
sleep "${SAMPLE_AFTER:-6}"

echo "== 3) observed ESTABLISHED sockets on :8080 per Reverb replica (Docker DNS round robin via Traefik; NOT assumed even)"
for c in $($DC ps -q reverb); do
  n="$(docker exec "$c" php -r '$n=0; foreach (["/proc/net/tcp","/proc/net/tcp6"] as $f) { foreach (@file($f) ?: [] as $i => $l) { if ($i === 0) continue; $p = preg_split("/\s+/", trim($l)); if (str_ends_with($p[1], ":1F90") && $p[3] === "01") $n++; } } echo $n;')"
  echo "   $(docker inspect -f '{{.Name}}' "$c"): $n"
done

echo "== 4) Reverb connection count over the backend network (signed Pusher HTTP API, app container -> reverb:8080)"
$DC exec -T app php artisan tinker --execute 'try { $r = app(Illuminate\Broadcasting\BroadcastManager::class)->connection("reverb")->getPusher()->get("/connections"); echo json_encode($r), "\n"; } catch (Throwable $e) { echo "connections API failed: ", $e::class, "\n"; }' || true

wait "$K6_PID"
echo "verify-chat: OK"
