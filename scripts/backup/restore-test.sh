#!/usr/bin/env bash
# Task 11: weekly AUTOMATED restore test (systemd: aghigh-restore-test.timer). A backup that was never restored is
# a hope, not a backup.
#   1. restore the latest backup from the off-server repository into a scratch volume (archiving disabled on the
#      copy: it must never push WAL into the production repository),
#   2. start a scratch Postgres on ANOTHER port (5433) on the internal backend network, replaying archived WAL,
#   3. sanity queries on the `aghigh` database,
#   4. php artisan orders:verify-invariants against the restored copy (migrate service image, migrator role),
#   5. report success or failure: log + webhook/mail hook (scripts/backup/notify.sh), then remove the scratch copy.
# The production database is only read by pgBackRest's repository, never touched. UNTESTED until the first run.
set -Eeuo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/deploy/lib.sh
. "$HERE/../deploy/lib.sh"
LOG_TAG=restore-test
need docker
export APP_IMAGE_TAG="${APP_IMAGE_TAG:-$(current_sha)}"
[ -n "$APP_IMAGE_TAG" ] || die "nothing is deployed yet ($STATE_DIR/current_sha is empty)"

NAME="${RESTORE_TEST_NAME:-aghigh-restore-test}"
PORT="${RESTORE_TEST_PORT:-5433}"
PROJECT="${COMPOSE_PROJECT_NAME:-aghigh}"
IMAGE="$(env_get POSTGRES_BACKUP_IMAGE)"
PG_USER="$(env_get POSTGRES_USER)"
PG_DB="$(env_get POSTGRES_DB)"
# default = replay every archived WAL segment (tests the backup AND the archive PITR depends on);
# immediate = stop at the end of the backup (faster, tests only the backup).
RESTORE_TYPE="${RESTORE_TEST_TYPE:-default}"
START_TIMEOUT="${RESTORE_TEST_START_TIMEOUT:-1800}"
[ -n "$IMAGE" ] && [ -n "$PG_USER" ] && [ -n "$PG_DB" ] || die "POSTGRES_BACKUP_IMAGE / POSTGRES_USER / POSTGRES_DB missing in $ENV_FILE"

report=""
note() { report="$report$*"$'\n'; log "$*"; }
cleanup() {
  docker rm -f "$NAME" >/dev/null 2>&1 || true
  docker volume rm "$NAME" >/dev/null 2>&1 || true
}
finish() {
  local rc=$?
  trap - EXIT
  if [ "$rc" -eq 0 ]; then
    "$HERE/notify.sh" ok "restore test passed" "$report"
  else
    "$HERE/notify.sh" fail "restore test FAILED (exit $rc)" "$report$(docker logs --tail 30 "$NAME" 2>&1 || true)"
  fi
  cleanup
  exit "$rc"
}
trap finish EXIT
cleanup
started="$(date +%s)"

note "restoring the latest backup (type=$RESTORE_TYPE) with $IMAGE"
docker volume create "$NAME" >/dev/null
restore_args=(--archive-mode=off)
if [ "$RESTORE_TYPE" = "immediate" ]; then
  restore_args+=(--type=immediate --target-action=promote)
fi
# The restore needs the internet (repository), so it runs on the egress network, as the postgres OS user.
docker run --rm --name "$NAME-restore" --network "${PROJECT}_egress" --env-file "$PGBACKREST_ENV_FILE" \
  --user postgres -v "$NAME:/var/lib/postgresql" -v "$SHARED_DIR/pgbackrest:/etc/pgbackrest/keys:ro" --entrypoint bash "$IMAGE" \
  -ec 'mkdir -p "$PGDATA" && chmod 700 "$PGDATA" && pgbackrest "$@" restore' restore "${restore_args[@]}"
note "restore finished after $(($(date +%s) - started)) s"

# The scratch instance listens on another port on the internal network; nothing is published on the host.
docker run -d --name "$NAME" --network "${PROJECT}_backend" --env-file "$PGBACKREST_ENV_FILE" \
  -v "$NAME:/var/lib/postgresql" -v "$SHARED_DIR/pgbackrest:/etc/pgbackrest/keys:ro" "$IMAGE" postgres -p "$PORT" -c archive_mode=off -c listen_addresses='*' >/dev/null
if [ "$RESTORE_TYPE" != "immediate" ]; then
  # WAL replay fetches segments from the repository: the scratch container needs the way out as well.
  docker network connect "${PROJECT}_egress" "$NAME"
fi

psql_scratch() { docker exec "$NAME" psql -X -U "$PG_USER" -d "$PG_DB" -p "$PORT" -v ON_ERROR_STOP=1 -AtF ' ' "$@"; }
deadline=$(($(date +%s) + START_TIMEOUT))
until [ "$(psql_scratch -c 'select not pg_is_in_recovery()' 2>/dev/null || true)" = "t" ]; do
  [ "$(docker inspect -f '{{.State.Running}}' "$NAME" 2>/dev/null || echo false)" = "true" ] || { note "the scratch Postgres stopped during recovery"; exit 1; }
  [ "$(date +%s)" -lt "$deadline" ] || { note "recovery did not finish within ${START_TIMEOUT}s"; exit 1; }
  sleep 5
done
note "scratch Postgres is up and promoted on port $PORT after $(($(date +%s) - started)) s"

# Sanity: the schema is there, the migration table is populated, and how fresh the newest order is.
missing="$(psql_scratch -c "select string_agg(t, ',') from unnest(array['migrations','users','products','orders','order_items','payments','stock_items','outbox']) t where to_regclass('public.' || t) is null")"
[ -z "$missing" ] || { note "tables missing in the restored copy: $missing"; exit 1; }
migrations="$(psql_scratch -c 'select count(*) from migrations')"
[ "${migrations:-0}" -gt 0 ] || { note "the migrations table is empty"; exit 1; }
note "sanity: all expected tables exist, $migrations migrations recorded"
note "sanity: orders=$(psql_scratch -c 'select count(*) from orders'), newest order $(psql_scratch -c "select coalesce(to_char(max(created_at), 'YYYY-MM-DD\"T\"HH24:MI:SSOF'), 'none') from orders"), restored copy stops at $(psql_scratch -c "select coalesce(to_char(pg_last_xact_replay_timestamp(), 'YYYY-MM-DD\"T\"HH24:MI:SSOF'), 'n/a')")"
if [ -n "${RESTORE_TEST_MAX_AGE_HOURS:-}" ]; then
  fresh="$(psql_scratch -c "select coalesce(max(created_at) > now() - interval '${RESTORE_TEST_MAX_AGE_HOURS} hours', false) from orders")"
  [ "$fresh" = "t" ] || { note "newest order is older than ${RESTORE_TEST_MAX_AGE_HOURS} h: the backup or the WAL archive is stale"; exit 1; }
fi

# Domain invariants on the restored data (exit code 1 = violations). Uses the one-off migrate service definition:
# current app image, migrator credentials, backend network; only host and port point at the scratch instance.
note "running orders:verify-invariants against the restored copy"
dc --profile tools run --rm --no-deps -e DB_HOST="$NAME" -e DB_PORT="$PORT" -e LOG_JSON_STREAM=/app/storage/logs/restore-test.jsonl \
  migrate php artisan orders:verify-invariants
note "orders:verify-invariants passed; total $(($(date +%s) - started)) s"
