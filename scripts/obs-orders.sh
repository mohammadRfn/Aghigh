#!/usr/bin/env bash
# Task 9: order traffic for make obs-verify. Idempotent: (re)creates the two smoke customers (same accounts as
# verify-chat), gives three cheap active products stock, then runs loadtests/orders-smoke.js through Traefik.
set -euo pipefail
DC="docker compose --env-file .env.docker"
PASSWORD="${CHAT_SMOKE_PASSWORD:-Smoke-Password-123!}"

echo "== order traffic: smoke customers + stock"
$DC exec -T -e SMOKE_PASSWORD="$PASSWORD" app php artisan tinker --execute '
$hash = Illuminate\Support\Facades\Hash::make(getenv("SMOKE_PASSWORD"));
foreach (["chat-smoke-a@example.test", "chat-smoke-b@example.test"] as $email) {
    $user = Modules\Identity\Models\User::query()->firstOrNew(["email" => $email]);
    $user->forceFill(["name" => "Order smoke", "password" => $hash, "role" => "customer", "email_verified_at" => now()])->save();
}
echo "ok\n";
' >/dev/null
IDS="$($DC exec -T app php artisan tinker --execute 'echo Illuminate\Support\Facades\DB::table("products")->where("status", "active")->orderBy("price_minor")->limit(3)->pluck("id")->implode(",");' | tail -1 | tr -d '\r')"
[ -n "$IDS" ] || { echo "no active product found (seed the catalog first)"; exit 1; }
for id in ${IDS//,/ }; do
  $DC exec -T app php artisan inventory:set-stock "$id" 1000 >/dev/null
done
echo "   products: $IDS"

echo "== order traffic: k6 orders-smoke"
$DC --profile loadtest run --rm -e PRODUCT_IDS="$IDS" -e SMOKE_PASSWORD="$PASSWORD" k6 run /scripts/orders-smoke.js
