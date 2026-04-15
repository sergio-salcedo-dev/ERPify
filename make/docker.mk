# =============================================================================
# Docker — Stack Lifecycle
# =============================================================================

## —— Core commands ——

docker.up: ## Start stack; pass ENV=dev|ci|staging|prod (default: dev)
	$(DOCKER_COMPOSE) $(DOCKER_COMPOSE_ENV) up --detach --build

docker.up.wait: ## Start stack and wait for health checks
	$(DOCKER_COMPOSE) $(DOCKER_COMPOSE_ENV) up --wait --build --detach

docker.down: ## Stop stack
	$(DOCKER_COMPOSE) down --remove-orphans

docker.build: ## Build images (--pull --no-cache)
	$(DOCKER_COMPOSE) build --pull --no-cache

docker.restart: ## Restart all services
	$(DOCKER_COMPOSE) restart

## —— Inspect ——

docker.logs: ## Follow logs (all services)
	$(DOCKER_COMPOSE) logs --tail=100 --follow

docker.ps: ## Show running containers
	$(DOCKER_COMPOSE) ps

docker.health: ## Check health endpoint
	@tmp=$$(mktemp); \
	trap 'rm -f $$tmp' EXIT; \
	code=$$(curl -skS --connect-timeout 5 --max-time 10 -o $$tmp -w '%{http_code}' '$(HEALTH_URL)'); \
	if [ "$$code" != "200" ]; then printf '\033[31mFAIL\033[0m HTTP %s\n' "$$code" >&2; exit 1; fi; \
	if command -v jq >/dev/null 2>&1; then jq -e '.status == "ok"' "$$tmp" >/dev/null || { printf '\033[31mFAIL\033[0m\n' >&2; exit 1; }; fi; \
	printf '\033[32mOK\033[0m HTTP %s\n' "$$code"

docker.sh: ## Shell in PHP container (sh)
	$(DOCKER_COMPOSE) exec $(PHP_SERVICE) sh

docker.bash: ## Shell in PHP container (bash)
	$(DOCKER_COMPOSE) exec $(PHP_SERVICE) bash

docker.exec: ## Exec arbitrary command in PHP container; pass cmd=...
	@$(eval cmd ?=bash)
	$(DOCKER_COMPOSE) exec $(PHP_SERVICE) $(cmd)

## —— Cleanup ——

docker.clean: ## Stop stack and remove volumes (destructive)
	$(DOCKER_COMPOSE) down --remove-orphans --volumes

docker.reset: ## Stop and restart with --wait
	$(MAKE) docker.down
	$(MAKE) docker.up.wait

# —— Backwards-compatible aliases ——

up: docker.up
down: docker.down
restart: docker.down docker.up
build: docker.build

.PHONY: docker.up docker.up.wait docker.down docker.build docker.restart \
        docker.logs docker.ps docker.health docker.sh docker.bash docker.exec \
        docker.clean docker.reset up down restart build
