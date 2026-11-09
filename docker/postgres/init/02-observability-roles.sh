#!/bin/bash
# Task 9. Runs once on an empty volume (docker-entrypoint-initdb.d) AND on demand for existing volumes through
# `make monitoring-role`, so every statement is idempotent. Creates:
#   - DB_MONITOR_USER: read-only monitoring role for postgres_exporter (pg_monitor + CONNECT, nothing else),
#   - GLITCHTIP_DB_USER / GLITCHTIP_DB_NAME: GlitchTip's own role and database (owner), isolated from `aghigh`.
# Passwords are simple (letters/digits) like the others: they are interpolated into SQL.
set -euo pipefail

: "${DB_MONITOR_USER:=aghigh_monitor}"
: "${GLITCHTIP_DB_USER:=glitchtip}"
: "${GLITCHTIP_DB_NAME:=glitchtip}"
if [ -z "${DB_MONITOR_PASSWORD:-}" ] || [ -z "${GLITCHTIP_DB_PASSWORD:-}" ]; then
  echo "02-observability-roles: DB_MONITOR_PASSWORD / GLITCHTIP_DB_PASSWORD are empty (run make obs-keys); skipping" >&2
  exit 0
fi

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<SQL
DO \$\$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '${DB_MONITOR_USER}') THEN
    CREATE ROLE ${DB_MONITOR_USER} LOGIN PASSWORD '${DB_MONITOR_PASSWORD}' CONNECTION LIMIT 3;
  ELSE
    ALTER ROLE ${DB_MONITOR_USER} WITH LOGIN PASSWORD '${DB_MONITOR_PASSWORD}' CONNECTION LIMIT 3;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '${GLITCHTIP_DB_USER}') THEN
    CREATE ROLE ${GLITCHTIP_DB_USER} LOGIN PASSWORD '${GLITCHTIP_DB_PASSWORD}';
  ELSE
    ALTER ROLE ${GLITCHTIP_DB_USER} WITH LOGIN PASSWORD '${GLITCHTIP_DB_PASSWORD}';
  END IF;
END
\$\$;

GRANT pg_monitor TO ${DB_MONITOR_USER};
GRANT CONNECT ON DATABASE ${POSTGRES_DB} TO ${DB_MONITOR_USER};
SQL

# CREATE DATABASE cannot run inside a transaction block or a DO body: test first.
if ! psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres -tAc "SELECT 1 FROM pg_database WHERE datname = '${GLITCHTIP_DB_NAME}'" | grep -q 1; then
  psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres -c "CREATE DATABASE ${GLITCHTIP_DB_NAME} OWNER ${GLITCHTIP_DB_USER}"
fi
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres <<SQL
REVOKE ALL ON DATABASE ${GLITCHTIP_DB_NAME} FROM PUBLIC;
GRANT ALL ON DATABASE ${GLITCHTIP_DB_NAME} TO ${GLITCHTIP_DB_USER};
SQL
echo "02-observability-roles: ${DB_MONITOR_USER} (pg_monitor) and ${GLITCHTIP_DB_USER}/${GLITCHTIP_DB_NAME} are in place"
