# ERPify — Makefile aligned with symfony-docker docs/makefile.md
# https://github.com/dunglas/symfony-docker/blob/main/docs/makefile.md
# Compose project lives in ./api (monorepo); upstream assumes project root.

API_DIR := api
# Override if Compose publishes HTTPS on a non-default port, e.g. HEALTH_URL=https://localhost:4443/api/v1/health
HEALTH_URL ?= https://localhost/api/v1/health
# Behat Mink base URL inside the php container (Caddy serves the app as http://php per SERVER_NAME)
MINK_BASE_URL ?= http://php
# Persisted in api/.env; Compose passes it to the container (compose.override.yaml).
XDEBUG_MODE_OFF := off
XDEBUG_MODE_DEBUG := develop,debug

# Executables (local) — same as template, prefixed to run compose from $(API_DIR)
DOCKER_COMP = cd $(API_DIR) && docker compose

# Silent `docker compose` for recipes (same as @$(DOCKER_COMP))
DC := @$(DOCKER_COMP)

# Docker containers
PHP_CONT = $(DOCKER_COMP) exec php
PHP_TEST = $(DOCKER_COMP) exec -e APP_ENV=test php
PHP_TEST_BEHAT = $(DOCKER_COMP) exec -e APP_ENV=test -e MINK_BASE_URL=$(MINK_BASE_URL) php

# Executables
PHP      = $(PHP_CONT) php
COMPOSER = $(PHP_CONT) composer
SYMFONY  = $(PHP) bin/console

# Misc
.DEFAULT_GOAL = help
.PHONY        : help build up start down logs sh bash composer vendor sf cc php.unit php.unit.install php.behat php.behat.install up-wait restart ps health clean xdebug.enable xdebug.disable xdebug-verify xdebug-check

## —— 🎵 🐳 The Symfony Docker Makefile 🐳 🎵 ——————————————————————————————————
help: ## Outputs this help screen
	@grep -E '(^[a-zA-Z0-9\./_-]+:.*?##.*$$)|(^##)' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}{printf "\033[32m%-30s\033[0m %s\n", $$1, $$2}' | sed -e 's/\[32m##/[33m/'

## —— Docker 🐳 ————————————————————————————————————————————————————————————————
build: ## Builds the Docker images
	$(DC) build --pull --no-cache

up: ## Start the docker hub in detached mode (no logs)
	$(DC) up --detach

start: build up ## Build and start the containers

down: ## Stop the docker hub
	$(DC) down --remove-orphans

logs: ## Show live logs
	$(DC) logs --tail=0 --follow

sh: ## Connect to the FrankenPHP container
	@$(PHP_CONT) sh

bash: ## Connect to the FrankenPHP container via bash so up and down arrows go to previous commands
	@$(PHP_CONT) bash

php.unit: ## Run PHPUnit in the php container (api/tools/phpunit); pass c= for CLI options, e.g. make test c="--filter Foo"
	@$(eval c ?=)
	@$(PHP_TEST) bin/phpunit $(c)

php.unit.install: ## Install PHPUnit under api/tools/phpunit (composer phpunit-tools-install)
	@$(COMPOSER) phpunit-tools-install

php.behat: ## Run Behat in the php container (api/tools/behat); pass c= for CLI options; MINK_BASE_URL defaults to http://php
	@$(eval c ?=)
	@$(PHP_TEST_BEHAT) php bin/behat --format=pretty $(c)

php.behat.install: ## Install Behat dependencies under api/tools/behat (composer behat-tools-install)
	@$(COMPOSER) behat-tools-install

## —— Composer 🧙 ——————————————————————————————————————————————————————————————
composer: ## Run composer, pass the parameter "c=" to run a given command, example: make composer c='req symfony/orm-pack'
	@$(eval c ?=)
	@$(COMPOSER) $(c)

vendor: ## Install vendors according to the current composer.lock file
vendor: c=install --prefer-dist --no-dev --no-progress --no-scripts --no-interaction
vendor: composer

## —— Symfony 🎵 ———————————————————————————————————————————————————————————————
sf: ## List all Symfony commands or pass the parameter "c=" to run a given command, example: make sf c=about
	@$(eval c ?=)
	@$(SYMFONY) $(c)

cc: c=c:c ## Clear the cache
cc: sf

## —— ERPify (monorepo extras) ————————————————————————————————————————————————
up-wait: ## Start stack with --wait (e.g. first Symfony bootstrap); runs from ./api
	$(DC) up --wait --detach

restart: down up ## Stop then start the docker hub

reset: down up-wait ## Stop then start

ps: ## docker compose ps for ./api
	$(DC) ps

health: ## GET HEALTH_URL (default https://localhost/api/v1/health); fail on non-200 or status != ok
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

xdebug.enable: ## Set XDEBUG_MODE=$(XDEBUG_MODE_DEBUG) in api/.env and recreate php
	@if ! grep -q '^XDEBUG_MODE=' "$(API_DIR)/.env" 2>/dev/null; then \
		printf '\n###> docker/xdebug ###\nXDEBUG_MODE=$(XDEBUG_MODE_OFF)\n###< docker/xdebug ###\n' >> "$(API_DIR)/.env"; \
	fi
	@sed -i 's/^XDEBUG_MODE=.*/XDEBUG_MODE=$(XDEBUG_MODE_DEBUG)/' "$(API_DIR)/.env"
	@echo "Set XDEBUG_MODE=$(XDEBUG_MODE_DEBUG) in $(API_DIR)/.env. Recreating php…"
	$(DC) up --detach --force-recreate --no-deps php

xdebug.disable: ## Set XDEBUG_MODE=$(XDEBUG_MODE_OFF) in api/.env (if present) and recreate php
	@if grep -q '^XDEBUG_MODE=' "$(API_DIR)/.env" 2>/dev/null; then \
		sed -i 's/^XDEBUG_MODE=.*/XDEBUG_MODE=$(XDEBUG_MODE_OFF)/' "$(API_DIR)/.env"; \
		echo "Set XDEBUG_MODE=$(XDEBUG_MODE_OFF) in $(API_DIR)/.env."; \
	else \
		echo "No XDEBUG_MODE= line in $(API_DIR)/.env (Compose default remains $(XDEBUG_MODE_OFF))."; \
	fi
	@echo "Recreating php…"
	$(DC) up --detach --force-recreate --no-deps php

xdebug.status: ## Verify Xdebug in php container; print PHP & Xdebug versions (start stack: make up)
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
