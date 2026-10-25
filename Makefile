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
> $(DC) build

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
> $(DC) exec -e DB_HOST=postgres -e DB_USERNAME=$(DB_MIGRATOR_USER) -e DB_PASSWORD=$(DB_MIGRATOR_PASSWORD) app php artisan migrate --force

test: ## Run the test suite (sqlite in memory; never touches Postgres)
> $(DC) exec -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e CACHE_STORE=array -e QUEUE_CONNECTION=sync -e SESSION_DRIVER=array app php artisan test

lint: ## Check code style (Pint)
> $(DC) exec app ./vendor/bin/pint --test

stan: ## Static analysis (Larastan)
> $(DC) exec app ./vendor/bin/phpstan analyse --memory-limit=1G
