# ERPify — Docker & Symfony commands for ./api (symfony-docker)

API_DIR := api
DOCKER  := cd $(API_DIR) && docker compose

.DEFAULT_GOAL := help
.PHONY: help build up up-wait start down restart logs ps sh bash composer vendor sf cc test clean

## —— Help —————————————————————————————————————————————————————————————————————
help: ## Show available commands
	@grep -E '^[a-zA-Z0-9_.-]+:.*?##' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

## —— Docker (API) —————————————————————————————————————————————————————————————
build: ## Build images (--pull --no-cache)
	@$(DOCKER) build --pull --no-cache

up: ## Start stack in the background
	@$(DOCKER) up --detach

up-wait: ## Start stack and wait until healthy (handy for first Symfony bootstrap)
	@$(DOCKER) up --wait --detach

start: build up ## Build then start in the background

down: ## Stop stack and remove orphans
	@$(DOCKER) down --remove-orphans

restart: down up ## Restart the stack

logs: ## Follow container logs (last 50 lines, then stream)
	@$(DOCKER) logs --tail=50 --follow

ps: ## List API containers
	@$(DOCKER) ps

sh: ## Open sh in the php (FrankenPHP) container
	@$(DOCKER) exec php sh

bash: ## Open bash in the php container
	@$(DOCKER) exec php bash

clean: ## Stop stack and remove volumes (wipes DB & Caddy persistent data)
	@$(DOCKER) down --remove-orphans --volumes

## —— Composer ———————————————————————————————————————————————————————————————
composer: ## Run composer in php; pass c="..." e.g. make composer c="req symfony/orm-pack"
	@$(eval c ?=)
	@$(DOCKER) exec php composer $(c)

vendor: c=install --prefer-dist --no-dev --no-progress --no-scripts --no-interaction
vendor: composer ## Install vendors from composer.lock (no dev)

## —— Symfony ——————————————————————————————————————————————————————————————————
sf: ## Run bin/console; pass c="about" or c="debug:router"
	@$(eval c ?=)
	@$(DOCKER) exec php php bin/console $(c)

cc: c=cache:clear
cc: sf ## Clear Symfony cache

## —— Tests ————————————————————————————————————————————————————————————————————
test: ## Run PHPUnit in php; pass c="..." e.g. make test c="--stop-on-failure"
	@$(eval c ?=)
	@$(DOCKER) exec -e APP_ENV=test php bin/phpunit $(c)
