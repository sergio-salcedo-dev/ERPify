# ERPify — monorepo tasks (repo root).
# Compose files: compose.yaml + compose.override.yaml + compose.pwa-dev.yaml (dev) or + compose.prod.yaml (prod).
# Inspired by https://github.com/dunglas/symfony-docker/blob/main/docs/makefile.md
#
# Common commands:
#   make dev-up    — full dev stack, rebuild images, open browser
#   make up-wait   — stack up with health checks (no rebuild)
#   make down      — stop
#   make help      — all targets by section

# —— Paths & project ——————————————————————————————————————————————————————————
API_DIR := api
PWA_DIR := pwa
ROOT_DIR := $(abspath $(dir $(lastword $(MAKEFILE_LIST))))

# —— Docker Compose ———————————————————————————————————————————————————————————
STACK_PROJECT ?= erpify
export COMPOSE_PROJECT_NAME := $(STACK_PROJECT)

COMPOSE_DEV = -f compose.yaml -f compose.override.yaml -f compose.pwa-dev.yaml
COMPOSE_PROD = -f compose.yaml -f compose.prod.yaml

DOCKER_COMP = cd $(ROOT_DIR) && docker compose $(COMPOSE_DEV)
DOCKER_COMP_PROD = cd $(ROOT_DIR) && docker compose $(COMPOSE_PROD)
DC := @$(DOCKER_COMP)
DCP := @$(DOCKER_COMP_PROD)

# —— Optional overrides (environment / make CLI) ———————————————————————————————
HEALTH_URL ?= https://localhost/api/v1/health
MINK_BASE_URL ?= http://php
OPEN_BROWSER ?= 1
XDEBUG_MODE_OFF := off
XDEBUG_MODE_DEBUG := develop,debug

# —— Container helpers ————————————————————————————————————————————————————————
PHP_CONT = $(DOCKER_COMP) exec php
PHP_TEST = $(DOCKER_COMP) exec -e APP_ENV=test php
PHP_TEST_BEHAT = $(DOCKER_COMP) exec -e APP_ENV=test -e MINK_BASE_URL=$(MINK_BASE_URL) php
PHP = $(PHP_CONT) php
COMPOSER = $(PHP_CONT) composer
SYMFONY = $(PHP) bin/console

# PWA npm from Make: IDEs often run sh with a minimal PATH (no npm). Login zsh/bash loads nvm/fnm/Homebrew; else PATH + nvm.sh.
define pwa_cmd
	_pwa_l="$$(command -v zsh 2>/dev/null || command -v bash 2>/dev/null)"; \
	if [ -n "$$_pwa_l" ]; then \
		exec "$$_pwa_l" -lc "cd \"$(ROOT_DIR)/$(PWA_DIR)\" && $(strip $(1))"; \
	fi; \
	export PATH="$$PATH:/usr/local/bin:/opt/homebrew/bin:$$HOME/.local/bin:$$HOME/.fnm/shims:$$HOME/.local/share/fnm"; \
	[ -s "$$HOME/.nvm/nvm.sh" ] && . "$$HOME/.nvm/nvm.sh" 2>/dev/null || true; \
	cd "$(ROOT_DIR)/$(PWA_DIR)" && exec $(strip $(1))
endef

.DEFAULT_GOAL := help

.PHONY: help help-targets \
	build up down logs sh bash \
	up-wait stack-up stack-down stack-fresh stack-logs \
	dev-up prod-up open-local \
	restart reset ps health clean \
	api-up-http dev-local \
	composer vendor sf cc routes \
	db.migrate db.diff db.status db.validate db.fixtures db.alice db.reset db.shell \
	php.unit php.unit.install php.behat php.behat.install test \
	pwa.install pwa.dev pwa.build pwa.test pwa.e2e pwa.lint pwa.lint.fix pwa.format \
	xdebug.enable xdebug.disable xdebug.status \
	ci start

# =============================================================================
# Help
# =============================================================================

help: ## Show quick start, then all targets grouped by section
	@printf '\n\033[1mERPify\033[0m  %s\n' '$(ROOT_DIR)'
	@printf '\n\033[1mTypical commands\033[0m\n'
	@printf '  %-18s  %s\n' 'make dev-up' 'Dev stack: compose up --wait --build, open browser'
	@printf '  %-18s  %s\n' 'make up-wait' 'Stack up with health checks (no image rebuild)'
	@printf '  %-18s  %s\n' 'make prod-up' 'Prod overlay: needs APP_SECRET, CADDY_MERCURE_JWT_SECRET, POSTGRES_PASSWORD'
	@printf '  %-18s  %s\n' 'make down' 'Stop stack'
	@printf '  %-18s  %s\n' 'make health' 'GET $(HEALTH_URL)'
	@printf '  %-18s  %s\n' 'make dev-local' 'API+DB on :8000 + Next dev on host'
	@printf '\n  %-18s  %s\n' 'OPEN_BROWSER=0' 'Skip xdg-open / open with dev-up, prod-up, open-local'
	@printf '  %-18s  %s\n' 'STACK_PROJECT=name' 'Docker Compose project name (default erpify)'
	@$(MAKE) --no-print-directory help-targets

# Section headers use: ## —— Title ——
help-targets:
	@awk ' \
	/^## ——/ { \
		line = $$0; \
		sub(/^## ——[[:space:]]*/, "", line); \
		sub(/[[:space:]]—+.*$$/, "", line); \
		printf "\n\033[33m%s\033[0m\n", line; \
		next \
	} \
	/^help:/ || /^help-targets:/ { next } \
	/^\.PHONY:/ { next } \
	$$0 ~ /^[[:alpha:]][^#]*:.*##/ { \
		n = index($$0, "##"); \
		if (n == 0) next; \
		left = substr($$0, 1, n - 1); \
		desc = substr($$0, n + 2); \
		gsub(/^[[:space:]]+|[[:space:]]+$$/, "", desc); \
		c = index(left, ":"); \
		if (c == 0) next; \
		targets = substr(left, 1, c - 1); \
		gsub(/^[[:space:]]+|[[:space:]]+$$/, "", targets); \
		if (targets == "") next; \
		printf "  \033[32m%-26s\033[0m %s\n", targets, desc; \
		next \
	} \
	' $(MAKEFILE_LIST)

# =============================================================================
# Stack — FrankenPHP + Postgres + PWA (repo root Compose)
# =============================================================================

## —— Stack ——

dev-up: ## Dev: up --wait --build -d, then open http(s)://localhost (OPEN_BROWSER=0 to skip)
	$(DC) up --wait --build --detach
	@$(MAKE) open-local

prod-up: ## Prod: compose.yaml + compose.prod.yaml, up --wait --build -d, open browser (set secrets in env)
	$(DCP) up --wait --build --detach
	@$(MAKE) open-local

# Same recipe: historical name up-wait (symfony-docker) + stack-up alias
up-wait stack-up: ## Full stack up with --wait (no rebuild; use dev-up for --build)
	$(DC) up --wait --detach

stack-down down: ## Stop stack (stack-down and down are the same)
	$(DC) down --remove-orphans

stack-fresh: ## down then up-wait (no --build)
	$(DC) down --remove-orphans
	$(DC) up --wait --detach

stack-logs logs: ## Follow Compose logs (php, pwa, database, …)
	$(DC) logs --tail=0 --follow

open-local: ## Open http://localhost and https://localhost (OPEN_BROWSER=0 to skip)
	@if [ "$(OPEN_BROWSER)" = "0" ]; then echo "OPEN_BROWSER=0, skipping browser"; exit 0; fi
	@for url in http://localhost https://localhost; do \
		if command -v xdg-open >/dev/null 2>&1; then \
			xdg-open "$$url" 2>/dev/null || true; \
		elif command -v open >/dev/null 2>&1; then \
			open "$$url" 2>/dev/null || true; \
		else \
			echo "Open manually: $$url"; \
		fi; \
	done

restart: down up ## Stop, then start (no --wait)

reset: down up-wait ## Stop, then up with --wait

ps: ## docker compose ps
	$(DC) ps

health: ## GET HEALTH_URL; require HTTP 200 and JSON status ok
	@tmp=$$(mktemp); \
	trap 'rm -f $$tmp' EXIT; \
	printf 'GET %s\n' '$(HEALTH_URL)'; \
	code=$$(curl -skS --connect-timeout 3 --max-time 10 -o $$tmp -w '%{http_code}' '$(HEALTH_URL)'); \
	if command -v jq >/dev/null 2>&1; then jq . <"$$tmp"; else cat "$$tmp"; printf '\n'; fi; \
	if [ "$$code" != "200" ]; then printf '\033[31mFAIL\033[0m HTTP %s\n' "$$code" >&2; exit 1; fi; \
	if command -v jq >/dev/null 2>&1; then \
		jq -e '.status == "ok"' "$$tmp" >/dev/null || { printf '\033[31mFAIL\033[0m JSON .status is not "ok"\n' >&2; exit 1; }; \
	else \
		grep -qE '"status"[[:space:]]*:[[:space:]]*"ok"' "$$tmp" || { printf '\033[31mFAIL\033[0m body has no "status":"ok"\n' >&2; exit 1; }; \
	fi; \
	printf '\033[32mOK\033[0m HTTP %s\n' "$$code"

clean: ## Stop stack and remove volumes (destructive)
	$(DC) down --remove-orphans --volumes

api-up-http: ## API + database only, HTTP on host :8000 (no PWA container)
	cd $(ROOT_DIR) && \
	HTTP_PORT=8000 SERVER_NAME=http://localhost:8000 \
	DEFAULT_URI=http://localhost:8000 CADDY_MERCURE_PUBLIC_URL=http://localhost:8000/.well-known/mercure \
	docker compose $(COMPOSE_DEV) up --wait --detach php database messenger_worker

dev-local: api-up-http ## api-up-http then Next dev (Turbopack); use pwa/.env.local for API URL :8000
	$(call pwa_cmd,npm run dev -- --turbo)

# =============================================================================
# Docker images & shells
# =============================================================================

## —— Docker build & shells ——

build: ## Build images (--pull --no-cache)
	$(DC) build --pull --no-cache

up: ## Start stack in detached mode (no --wait)
	$(DC) up --detach

start: build up ## Build then up (no --wait)

sh: ## Shell in php container (sh)
	@$(PHP_CONT) sh

bash: ## Shell in php container (bash)
	@$(PHP_CONT) bash

# =============================================================================
# Composer & Symfony (inside php container)
# =============================================================================

## —— Composer & Symfony ——

composer: ## Run composer; pass c='…', e.g. make composer c='req vendor/pkg'
	@$(eval c ?=)
	@$(COMPOSER) $(c)

vendor: ## composer install (prod-ish flags)
vendor: c=install --prefer-dist --no-dev --no-progress --no-scripts --no-interaction
vendor: composer

sf: ## Symfony console; pass c=…, e.g. make sf c=about
	@$(eval c ?=)
	@$(SYMFONY) $(c)

cc: c=c:c ## cache:clear
cc: sf

routes: ## debug:router (pass f= to filter, e.g. make routes f=api)
	@$(eval f ?=)
	@$(SYMFONY) debug:router $(if $(f),--show-controllers | grep $(f),)

# =============================================================================
# Database
# =============================================================================

## —— Database ——

db.migrate: ## Run pending Doctrine migrations
	@$(SYMFONY) doctrine:migrations:migrate --no-interaction --all-or-nothing

db.diff: ## Generate migration from entity/schema diff
	@$(SYMFONY) doctrine:migrations:diff

db.status: ## Migration status
	@$(SYMFONY) doctrine:migrations:status

db.validate: ## Validate ORM mapping vs database
	@$(SYMFONY) doctrine:schema:validate

db.fixtures: ## Load Doctrine fixtures (purge first)
	@$(SYMFONY) doctrine:fixtures:load --no-interaction --purge-with-truncate

db.alice: ## Load Hautelook Alice fixtures
	@$(SYMFONY) hautelook:fixtures:load --no-interaction

db.reset: ## Drop DB → migrate → fixtures
	@$(SYMFONY) doctrine:schema:drop --force --full-database --no-interaction
	@$(SYMFONY) doctrine:migrations:migrate --no-interaction --all-or-nothing
	@$(SYMFONY) doctrine:fixtures:load --no-interaction --purge-with-truncate

db.shell: ## Interactive psql in database container
	$(DOCKER_COMP) exec database \
		psql --username=$${POSTGRES_USER:-erpify_user} $${POSTGRES_DB:-erpify_db}

# =============================================================================
# Tests (API)
# =============================================================================

## —— Tests (API) ——

php.unit: ## PHPUnit in container; pass c= for extra args
	@$(eval c ?=)
	@$(PHP_TEST) bin/phpunit $(c)

php.unit.install: ## Install PHPUnit tooling (api/tools/phpunit)
	@$(COMPOSER) phpunit-tools-install

php.behat: ## Behat in container; pass c= for extra args
	@$(eval c ?=)
	@$(PHP_TEST_BEHAT) php bin/behat --format=pretty $(c)

php.behat.install: ## Install Behat tooling (api/tools/behat)
	@$(COMPOSER) behat-tools-install

test: php.behat ## Default “full” API test suite (Behat)

# =============================================================================
# PWA (Next.js)
# =============================================================================

## —— PWA ——

pwa.install: ## npm ci in $(PWA_DIR)
	$(call pwa_cmd,npm ci)

pwa.dev: ## Next dev (Turbopack)
	$(call pwa_cmd,npm run dev -- --turbo)

pwa.build: ## next build
	$(call pwa_cmd,npm run build)

pwa.test: ## Vitest; pass c= for extra args
	@$(eval c ?=)
	$(call pwa_cmd,npm test -- $(c))

pwa.e2e: ## Playwright
	$(call pwa_cmd,npm run e2e)

pwa.lint: ## ESLint + next lint
	$(call pwa_cmd,npm run lint)

pwa.lint.fix: ## ESLint --fix
	$(call pwa_cmd,npm run lint:fix)

pwa.format: ## Prettier
	$(call pwa_cmd,npm run format)

# =============================================================================
# Xdebug
# =============================================================================

## —— Xdebug ——

xdebug.enable: ## XDEBUG_MODE=develop,debug in api/.env and recreate php
	@if ! grep -q '^XDEBUG_MODE=' "$(API_DIR)/.env" 2>/dev/null; then \
		printf '\n###> docker/xdebug ###\nXDEBUG_MODE=$(XDEBUG_MODE_OFF)\n###< docker/xdebug ###\n' >> "$(API_DIR)/.env"; \
	fi
	@sed -i 's/^XDEBUG_MODE=.*/XDEBUG_MODE=$(XDEBUG_MODE_DEBUG)/' "$(API_DIR)/.env"
	@echo "Set XDEBUG_MODE=$(XDEBUG_MODE_DEBUG) in $(API_DIR)/.env. Recreating php…"
	$(DC) up --detach --force-recreate --no-deps php

xdebug.disable: ## XDEBUG_MODE=off in api/.env (if present) and recreate php
	@if grep -q '^XDEBUG_MODE=' "$(API_DIR)/.env" 2>/dev/null; then \
		sed -i 's/^XDEBUG_MODE=.*/XDEBUG_MODE=$(XDEBUG_MODE_OFF)/' "$(API_DIR)/.env"; \
		echo "Set XDEBUG_MODE=$(XDEBUG_MODE_OFF) in $(API_DIR)/.env."; \
	else \
		echo "No XDEBUG_MODE= line in $(API_DIR)/.env."; \
	fi
	@echo "Recreating php…"
	$(DC) up --detach --force-recreate --no-deps php

xdebug.status: ## Show PHP / Xdebug versions and XDEBUG_MODE in php container
	@echo "=== php -v ==="
	@$(PHP_CONT) php -v
	@echo ""
	@$(PHP_CONT) php -r "if (!extension_loaded('xdebug')) { fwrite(STDERR, 'ERROR: Xdebug extension is not loaded.'.PHP_EOL); exit(1);}"
	@$(PHP_CONT) php -r "echo 'PHP version:     ', PHP_VERSION, PHP_EOL;"
	@$(PHP_CONT) php -r "echo 'Xdebug version:  ', phpversion('xdebug'), PHP_EOL;"
	@$(PHP_CONT) php -r "echo 'XDEBUG_MODE:     ', (getenv('XDEBUG_MODE') !== false ? getenv('XDEBUG_MODE') : '(unset)'), PHP_EOL;"
	@$(PHP_CONT) php -r "echo 'PHP_IDE_CONFIG:  ', (getenv('PHP_IDE_CONFIG') !== false ? getenv('PHP_IDE_CONFIG') : '(unset)'), PHP_EOL;"
	@echo ""
	@$(PHP_CONT) php -r '$$m = getenv("XDEBUG_MODE") ?: ""; echo str_contains($$m, "debug") ? "OK: Step debugging is ON (IDE listens on host port 9003)." : "OK: Step debugging is OFF (default). Run make xdebug.enable to debug.", PHP_EOL;'

# =============================================================================
# CI helper
# =============================================================================

## —— CI ——

ci: pwa.lint pwa.test pwa.build ## PWA lint + unit tests + build (no E2E)
