# =============================================================================
# Docker Stack lifecycle (ENV-aware).
# =============================================================================

# All targets drive `docker compose` from the repo root with the overlay
# chosen by $(COMPOSE_FILES) in config.mk.

## —— Stack ————————————————————————————————————————————————————————————————

docker.up: ## Start stack detached, rebuild images (ENV-aware)
	$(DOCKER_COMPOSE) up --build --detach

docker.up.wait: ## Start stack detached with --wait health gate
	$(DOCKER_COMPOSE) up --wait --build --detach

docker.up.wait.no-build: ## Start stack detached with --wait, skip rebuild (CI: images already loaded)
	$(DOCKER_COMPOSE) up --wait --no-build --detach

docker.build: ## Rebuild images (--pull --no-cache)
	$(DOCKER_COMPOSE) build --pull --no-cache

docker.reset: docker.down docker.up.wait ## Reset all services

docker.restart: ## Restart all services
	$(DOCKER_COMPOSE) restart

docker.logs: ## Follow compose logs (all services)
	$(DOCKER_COMPOSE) logs --tail=0 --follow

docker.ps: ## Compose ps
	$(DOCKER_COMPOSE) ps

docker.down: ## Stop stack and remove orphans
	$(DOCKER_COMPOSE) down --remove-orphans

docker.down.clean-volumes: ## Stop stack and REMOVE volumes (destructive)
	$(DOCKER_COMPOSE) down --remove-orphans --volumes

docker.prune: docker.down ## Prune ALL Docker images, volumes and containers system-wide (destructive, prompts)
	docker system prune --all --volumes

.PHONY: docker.up docker.up.wait docker.up.wait.no-build docker.build \
		docker.restart \
        docker.logs \
        docker.ps \
        docker.down docker.down.clean-volumes docker.reset \
        docker.prune
