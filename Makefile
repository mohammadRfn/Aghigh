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

up: init ## Start everything in the background
> $(DC) up -d

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
> $(DC) exec -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e CACHE_STORE=array -e CACHE_LIMITER=array -e QUEUE_CONNECTION=sync -e SESSION_DRIVER=array -e LOG_CHANNEL=null app php artisan test

lint: ## Check code style (Pint)
> $(DC) exec app ./vendor/bin/pint --test

stan: ## Static analysis (Larastan)
> $(DC) exec app ./vendor/bin/phpstan analyse --memory-limit=1G

.PHONY: pull fresh-seed k6

pull: ## Pull every non-built image (verifies the pinned tags exist; honours REGISTRY_PREFIX)
> $(DC) --profile loadtest pull --ignore-buildable

fresh-seed: ## DROP all tables, migrate and seed (dev only)
> @$(DC) exec -e DB_HOST=postgres -e DB_USERNAME=$(DB_MIGRATOR_USER) -e DB_PASSWORD=$(DB_MIGRATOR_PASSWORD) app php artisan migrate:fresh --seed --force

k6: ## Run a k6 script from loadtests/ (make k6 s=smoke)
> $(DC) --profile loadtest run --rm k6 run /scripts/$(or $(s),smoke).js

.PHONY: test-db test-int

test-db: ## Create the aghigh_test database (idempotent)
> @$(DC) exec -T postgres psql -U postgres -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname='aghigh_test'" | grep -q 1 || $(DC) exec -T postgres psql -U postgres -d postgres -c "CREATE DATABASE aghigh_test OWNER $(DB_MIGRATOR_USER)"

test-int: test-db ## Concurrency suite on real Postgres + Redis (aghigh_test, direct to Postgres, isolated Redis dbs)
> @$(DC) exec -e APP_ENV=testing -e DB_CONNECTION=pgsql -e DB_HOST=postgres -e DB_DATABASE=aghigh_test -e DB_USERNAME=$(DB_MIGRATOR_USER) -e DB_PASSWORD=$(DB_MIGRATOR_PASSWORD) -e REDIS_DB=10 -e REDIS_CACHE_DB=11 -e REDIS_STATE_DB=12 -e CACHE_LIMITER=state -e LOG_CHANNEL=null app php artisan test --testsuite=Concurrency
