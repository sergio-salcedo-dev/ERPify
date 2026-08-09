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

# `up --wait` aborts if a container restarts once during the wait window. The php
# service can flap during cold boot (composer install + migrations + dev worker),
# so on failure we settle briefly and re-run --wait — already-up services just get
# re-evaluated and pass once php is healthy. A second failure is a real fault.
docker.up.wait.no-build: ## Start stack detached with --wait, skip rebuild (CI: images already loaded)
	$(DOCKER_COMPOSE) up --wait --no-build --detach \
		|| { echo '[docker.up.wait.no-build] first --wait aborted (cold-boot restart); retrying once after settle...'; sleep 10; $(DOCKER_COMPOSE) up --wait --no-build --detach; }

# Backend-only variant for API-only CI jobs (PHPUnit/Behat hit /api/* and need the
# async worker for audit/email; they never touch the pwa container). Naming the php
# + messenger_worker services pulls database + mailpit in as depends_on but excludes
# pwa entirely — skips its build, `npm ci`, and ~180s cold `next dev` boot from the
# critical path. Mirrors the cold-boot retry-once logic of docker.up.wait.no-build.
docker.up.wait.no-build.api: ## Start backend only (php + worker + deps), --wait, skip rebuild — excludes pwa (CI: API-only jobs)
	$(DOCKER_COMPOSE) up --wait --no-build --detach php messenger_worker \
		|| { echo '[docker.up.wait.no-build.api] first --wait aborted (cold-boot restart); retrying once after settle...'; sleep 10; $(DOCKER_COMPOSE) up --wait --no-build --detach php messenger_worker; }

docker.build: ## Rebuild images (--pull --no-cache)
	$(DOCKER_COMPOSE) build --pull --no-cache

docker.reset: docker.down docker.up.wait ## Reset all services

docker.restart: ## Restart all services
	$(DOCKER_COMPOSE) restart

docker.logs: ## Follow compose logs (all services)
	$(DOCKER_COMPOSE) logs --tail=0 --follow

docker.ps: ## Compose ps
	$(DOCKER_COMPOSE) ps

docker.info: ## Show this checkout's resolved stack identity (project + host ports)
	@printf 'checkout            : %s\n' '$(PROJECT_ROOT)'
	@printf 'linked worktree     : %s\n' '$(if $(IS_LINKED_WORKTREE),yes,no (primary))'
	@printf 'COMPOSE_PROJECT_NAME: %s\n' '$(COMPOSE_PROJECT_NAME)'
	@printf 'host ports          : http=%s https=%s http3=%s postgres=%s mailpit=%s\n' \
		'$(if $(HTTP_PORT),$(HTTP_PORT),80)' '$(if $(HTTPS_PORT),$(HTTPS_PORT),443)' \
		'$(if $(HTTP3_PORT),$(HTTP3_PORT),443)' '$(if $(POSTGRES_PORT),$(POSTGRES_PORT),15432)' \
		'$(if $(MAILPIT_UI_PORT),$(MAILPIT_UI_PORT),8025)'
	@printf '                      (0 = ephemeral/random; see `make docker.ps` for the assigned port)\n'

docker.down: ## Stop stack and remove orphans
	$(DOCKER_COMPOSE) down --remove-orphans

docker.down.clean-volumes: ## Stop stack and REMOVE volumes (destructive)
	$(DOCKER_COMPOSE) down --remove-orphans --volumes

# The worker compiles its DI container into a PRIVATE volume (compose.dev.yaml) so the web container's
# recompiles cannot delete its files mid-flight. The cost is that a changed CONSTRUCTOR SIGNATURE leaves a
# compiled factory calling the old one: the worker boot-loops on `ArgumentCountError` (exit 255) while the web
# container stays healthy, and neither a restart nor `make sf.cc` reaches it — that cache clear runs in the
# `php` container, which does not share this volume. Only dropping the volume does.
docker.worker.cache.reset: ## Drop the messenger_worker's private compiled-container cache (fixes its boot loop)
	-$(DOCKER_COMPOSE) rm --force --stop messenger_worker
	-docker volume rm $(COMPOSE_PROJECT_NAME)_messenger_cache

docker.prune: docker.down ## Prune ALL Docker images, volumes and containers system-wide (destructive, prompts)
	docker system prune --all --volumes

.PHONY: docker.up docker.up.wait docker.up.wait.no-build docker.up.wait.no-build.api docker.build \
		docker.restart \
        docker.logs \
        docker.ps docker.info \
        docker.down docker.down.clean-volumes docker.worker.cache.reset docker.reset \
        docker.prune
