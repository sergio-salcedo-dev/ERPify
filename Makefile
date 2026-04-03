# ERPify — Makefile aligned with symfony-docker docs/makefile.md
# https://github.com/dunglas/symfony-docker/blob/main/docs/makefile.md
# Compose project lives in ./api (monorepo); upstream assumes project root.

API_DIR := api
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

# Executables
PHP      = $(PHP_CONT) php
COMPOSER = $(PHP_CONT) composer
SYMFONY  = $(PHP) bin/console

# Misc
.DEFAULT_GOAL = help
.PHONY        : help build up start down logs sh bash composer vendor sf cc test up-wait restart ps clean xdebug.enable xdebug.disable xdebug-verify xdebug-check

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

test: ## Start tests with phpunit, pass the parameter "c=" to add options to phpunit, example: make test c="--group e2e --stop-on-failure"
	@$(eval c ?=)
	@$(PHP_TEST) bin/phpunit $(c)


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

ps: ## docker compose ps for ./api
	$(DC) ps

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
