#!/usr/bin/env bash
# Task 10: `make loadtest-reset`. Puts the stack into the SAME known state before every run:
#   guard (APP_ENV local/loadtest only) -> fresh-seed -> loadtest:seed-users -> catalog:seed-bulk --truncate
#   -> inventory:seed-stock -> flush ONLY the `state` and `cache` Redis dbs -> wait for empty queues
#   -> clear failed jobs and Horizon state -> metrics-reset.
# NEVER FLUSHALL and never the queue db: queues, Horizon and Reverb pub/sub live on redis-queue db 0.
#   N=100000 USERS=500 AGENTS=10 STOCK_QTY=1000 loadtests/scripts/loadtest-reset.sh
set -euo pipefail
# shellcheck source=loadtests/scripts/lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

N="${N:-100000}"
USERS="${USERS_SEED:-500}"
AGENTS="${AGENTS:-10}"
STOCK_QTY="${STOCK_QTY:-1000}"
MIG_USER="$(envval DB_MIGRATOR_USER)"
MIG_PASS="$(envval DB_MIGRATOR_PASSWORD)"
as_migrator() { dc exec -T -e DB_HOST=postgres -e DB_USERNAME="$MIG_USER" -e DB_PASSWORD="$MIG_PASS" app php artisan "$@"; }

guard_env
say "loadtest-reset on APP_ENV=$LT_APP_ENV: products=$N users=$USERS agents=$AGENTS stock=$STOCK_QTY"

say "1/8 fresh-seed (DROP all tables, migrate, seed) as the migrator role"
as_migrator migrate:fresh --seed --force

say "2/8 loadtest:seed-users (verified accounts, known password, non-production only)"
artisan loadtest:seed-users "--count=$USERS" "--agents=$AGENTS"

say "3/8 catalog:seed-bulk --truncate --products=$N"
as_migrator catalog:seed-bulk --truncate "--products=$N"

say "4/8 inventory:seed-stock --quantity=$STOCK_QTY"
artisan inventory:seed-stock "--quantity=$STOCK_QTY"

say "5/8 flush ONLY redis-queue db $STATE_DB (state) and redis-cache db $CACHE_DB (cache)"
[ "$STATE_DB" != "$QUEUE_DB" ] || die "REDIS_STATE_DB ($STATE_DB) equals the queue db ($QUEUE_DB) on redis-queue: refusing to flush it"
[ "$STATE_DB" != "$METRICS_DB" ] || die "REDIS_STATE_DB equals REDIS_METRICS_DB: refusing (metrics have their own reset)"
redis_queue -n "$STATE_DB" FLUSHDB
redis_cache -n "$CACHE_DB" FLUSHDB
# Octane workers may hold values read from the state store (catalog versions): start them clean.
artisan octane:reload >/dev/null 2>&1 || warn "octane:reload failed (workers keep their in-memory state until max-requests)"

say "6/8 wait until the queues are empty (jobs of the previous run finish or fail)"
artisan queue:drain-time "--timeout=${DRAIN_TIMEOUT:-300}" || die "queues did not drain: look at Horizon before measuring"

say "7/8 clear failed jobs and Horizon state"
artisan queue:flush || warn "queue:flush failed"
artisan horizon:forget --all >/dev/null 2>&1 || warn "horizon:forget --all is not available in this Horizon version (failed jobs stay listed in Horizon)"
artisan horizon:clear-metrics >/dev/null 2>&1 || warn "horizon:clear-metrics is not available in this Horizon version"

say "8/8 metrics-reset (metrics Redis db $METRICS_DB only)"
artisan metrics:reset

products="$(tinker 'echo Illuminate\Support\Facades\DB::table("products")->count();')"
users="$(tinker 'echo Illuminate\Support\Facades\DB::table("users")->where("email", "like", "loadtest-%@example.test")->count();')"
say "loadtest-reset done: products=$products loadtest users=$users"
