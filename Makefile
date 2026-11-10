.RECIPEPREFIX := >
SHELL := /bin/bash
-include .env.docker
DC := docker compose --env-file .env.docker
.DEFAULT_GOAL := help

.PHONY: help init build up down ps logs shell install migrate test lint stan

help: ## Show this help
> @grep -hE '^[a-zA-Z_-]+:.*?## ' Makefile | awk 'BEGIN {FS = ":.*?## "}; {printf "  %-9s %s\n", $$1, $$2}'

init: ## Create .env.docker from the example (once)
> @test -f .env.docker || (cp .env.docker.example .env.docker && echo "created .env.docker - review the passwords")

build: init ## Build the app image
> $(DC) build app

REVERB_SCALE ?= 1
up: init ## Start everything in the background (REVERB_SCALE=2 make up: two Reverb replicas)
> $(DC) up -d --scale reverb=$(REVERB_SCALE)

down: ## Stop everything (data volumes are kept)
> $(DC) down

ps: ## Show service status
> $(DC) ps

logs: ## Follow logs (make logs s=app)
> $(DC) logs -f --tail=100 $(s)

shell: ## Open a shell in the app container
> $(DC) exec app bash

install: init ## composer install in a one-off container
> $(DC) run --rm --no-deps app composer install

migrate: ## Run migrations as the DDL role, directly against Postgres
> @$(DC) exec -e DB_HOST=postgres -e DB_USERNAME=$(DB_MIGRATOR_USER) -e DB_PASSWORD=$(DB_MIGRATOR_PASSWORD) app php artisan migrate --force

test: ## Run the test suite (sqlite in memory; never touches Postgres)
> $(DC) exec -e APP_ENV=testing -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e CACHE_STORE=array -e CACHE_LIMITER=array -e QUEUE_CONNECTION=sync -e SESSION_DRIVER=array -e RATE_LIMIT_SLIDING_DRIVER=memory -e CHAT_STATE_DRIVER=memory -e BROADCAST_CONNECTION=null -e RATE_LIMIT_MULTIPLIER=1000 -e LOG_CHANNEL=null -e LOG_JSON_STREAM=php://stdout -e METRICS_DRIVER=memory -e METRICS_TOKEN= app php artisan test --testsuite=Unit,Feature,Arch

lint: ## Check code style (Pint)
> $(DC) exec app ./vendor/bin/pint --test

stan: ## Static analysis (Larastan)
> $(DC) exec app ./vendor/bin/phpstan analyse --memory-limit=1G

.PHONY: pull fresh-seed k6 seed-catalog psql explain-catalog

explain-catalog: ## Save real EXPLAIN evidence (LABEL=before or after; see performance-notes first)
> @mkdir -p docs/perf
> @{ date -u; uname -a; git rev-parse HEAD; } > docs/perf/$(or $(LABEL),plan).txt
> @set -o pipefail; $(DC) exec -T -e PGPASSWORD=$(DB_MIGRATOR_PASSWORD) postgres psql -h 127.0.0.1 -U $(DB_MIGRATOR_USER) -d $(POSTGRES_DB) -v ON_ERROR_STOP=1 < docs/perf/query-shapes.sql 2>&1 | tee -a docs/perf/$(or $(LABEL),plan).txt

N ?= 100000

seed-catalog: ## Bulk catalog fixtures as migrator, direct Postgres (make seed-catalog N=100000)
> @$(DC) exec -e DB_HOST=postgres -e DB_USERNAME=$(DB_MIGRATOR_USER) -e DB_PASSWORD=$(DB_MIGRATOR_PASSWORD) app php artisan catalog:seed-bulk --products=$(N)

psql: ## Interactive psql as migrator directly against Postgres
> @$(DC) exec -e PGPASSWORD=$(DB_MIGRATOR_PASSWORD) postgres psql -h 127.0.0.1 -U $(DB_MIGRATOR_USER) -d $(POSTGRES_DB)

pull: ## Pull every non-built image (verifies the pinned tags exist; honours REGISTRY_PREFIX)
> $(DC) --profile loadtest --profile observability pull --ignore-buildable

fresh-seed: ## DROP all tables, migrate and seed (dev only)
> @$(DC) exec -e DB_HOST=postgres -e DB_USERNAME=$(DB_MIGRATOR_USER) -e DB_PASSWORD=$(DB_MIGRATOR_PASSWORD) app php artisan migrate:fresh --seed --force

k6: ## Run a k6 script from loadtests/ (make k6 s=smoke)
> $(DC) --profile loadtest run --rm k6 run /scripts/$(or $(s),smoke).js

.PHONY: test-db test-int

test-db: ## Create the aghigh_test database (idempotent)
> @$(DC) exec -T postgres psql -U postgres -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname='aghigh_test'" | grep -q 1 || $(DC) exec -T postgres psql -U postgres -d postgres -c "CREATE DATABASE aghigh_test OWNER $(DB_MIGRATOR_USER)"

test-int: test-db ## Concurrency suite on real Postgres + Redis (aghigh_test, direct to Postgres, isolated Redis dbs)
> @$(DC) exec -e APP_ENV=testing -e DB_CONNECTION=pgsql -e DB_HOST=postgres -e DB_DATABASE=aghigh_test -e DB_USERNAME=$(DB_MIGRATOR_USER) -e DB_PASSWORD=$(DB_MIGRATOR_PASSWORD) -e REDIS_DB=10 -e REDIS_CACHE_DB=11 -e REDIS_STATE_DB=12 -e CACHE_LIMITER=state -e RATE_LIMIT_SLIDING_DRIVER=redis -e CHAT_STATE_DRIVER=redis -e BROADCAST_CONNECTION=null -e RATE_LIMIT_MULTIPLIER=1000 -e LOG_CHANNEL=null -e LOG_JSON_STREAM=php://stdout -e METRICS_ENABLED=true -e METRICS_DRIVER=redis -e REDIS_METRICS_DB=13 app php artisan test --testsuite=Concurrency $(ARGS)

verify-proxy: ## Prove TrustProxies against the real Traefik stack (curl, no fake proxy)
> ./scripts/verify-trusted-proxy.sh

.PHONY: reverb-keys reverb-loop verify-chat
reverb-keys: init ## Dev only: write random REVERB_APP_ID/KEY/SECRET into .env.docker where they are empty
> @./scripts/reverb-keys.sh .env.docker
reverb-loop: ## Print the ReactPHP event loop class Reverb uses (ExtUvLoop = no 1024 fd ceiling)
> @$(DC) exec -T reverb php -r 'require "vendor/autoload.php"; echo get_class(React\EventLoop\Loop::get()), PHP_EOL;'
verify-chat: ## Real-stack chat smoke through Traefik with k6 (REVERB_SCALE=2 make up verify-chat: cross-replica)
> ./scripts/verify-chat.sh

# ---- Task 9: observability (compose profile `observability`, docs/observability.md) ----
.PHONY: obs-up obs-down obs-ps obs-logs obs-keys monitoring-role obs-rules-test obs-verify metrics-reset
OBS := $(DC) --profile observability
OBS_SERVICES := prometheus grafana loki alloy cadvisor node-exporter postgres-exporter pgbouncer-exporter redis-exporter-queue redis-exporter-cache glitchtip glitchtip-worker glitchtip-migrate

obs-keys: init ## Dev only: random METRICS_TOKEN, Grafana admin, GlitchTip and monitor-role secrets into .env.docker (empty values only) + Prometheus token file
> @./scripts/obs-keys.sh .env.docker

monitoring-role: ## Create/refresh the monitoring role and GlitchTip db/role on an EXISTING Postgres volume (idempotent; runs as the container superuser like test-db)
> @$(DC) exec -T -e DB_MONITOR_USER=$(DB_MONITOR_USER) -e DB_MONITOR_PASSWORD=$(DB_MONITOR_PASSWORD) -e GLITCHTIP_DB_USER=$(GLITCHTIP_DB_USER) -e GLITCHTIP_DB_NAME=$(GLITCHTIP_DB_NAME) -e GLITCHTIP_DB_PASSWORD=$(GLITCHTIP_DB_PASSWORD) postgres bash /docker-entrypoint-initdb.d/02-observability-roles.sh

obs-up: init ## Start the default stack + the observability profile (needs make obs-keys and make monitoring-role first)
> @test -f docker/observability/secrets/metrics_token -a -s docker/observability/secrets/metrics_token || { echo "docker/observability/secrets/metrics_token is missing: run make obs-keys"; exit 1; }
> @for v in GRAFANA_ADMIN_PASSWORD GLITCHTIP_SECRET_KEY GLITCHTIP_DB_PASSWORD DB_MONITOR_PASSWORD METRICS_TOKEN; do grep -qE "^$$v=.+" .env.docker || { echo "$$v is empty in .env.docker: run make obs-keys"; exit 1; }; done
> $(OBS) up -d --scale reverb=$(REVERB_SCALE)

obs-down: ## Stop and remove ONLY the observability services (default stack and data volumes stay). Run before a latency baseline.
> $(OBS) rm -sf $(OBS_SERVICES)

obs-ps: ## Status of the observability services
> $(OBS) ps $(OBS_SERVICES)

obs-logs: ## Follow logs of one observability service (make obs-logs s=alloy)
> $(OBS) logs -f --tail=100 $(s)

obs-rules-test: ## promtool check + unit-test the alert rules with the pinned Prometheus image (no host install)
> $(OBS) run --rm --no-deps -T --entrypoint promtool prometheus check rules /etc/prometheus/rules/aghigh.rules.yml
> $(OBS) run --rm --no-deps -T --entrypoint promtool prometheus test rules /etc/prometheus/rules/aghigh.rules.test.yml
> $(OBS) run --rm --no-deps -T --entrypoint promtool prometheus check config /etc/prometheus/prometheus.yml

obs-verify: ## Prove the stack: Grafana provisioning, /internal denied at the edge, every dashboard/rule query non-empty after a traffic burst
> ./scripts/obs-verify.sh

metrics-reset: ## Wipe the metrics Redis db (refused in production); Task 10's loadtest-reset calls this
> $(DC) exec -T app php artisan metrics:reset
