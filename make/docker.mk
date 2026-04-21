# make/docker.mk — Stack lifecycle (ENV-aware).
#
# All targets drive `docker compose` from the repo root with the overlay
# chosen by $(COMPOSE_FILES) in config.mk.

## —— Stack ————————————————————————————————————————————————————————————————

docker.up: ## Start stack detached, rebuild images (ENV-aware)
	$(DC) up --build --detach

docker.up.wait: ## Start stack detached with --wait health gate (no --build)
	$(DC) up --wait --detach

docker.down: ## Stop stack and remove orphans
	$(DC) down --remove-orphans

docker.build: ## Rebuild images (--pull --no-cache)
	$(DC) build --pull --no-cache

docker.reset: docker.down docker.up.wait ## Reset all services

docker.restart: ## Restart all services
	$(DC) restart

docker.logs: ## Follow compose logs (all services)
	$(DC) logs --tail=0 --follow

docker.ps: ## Compose ps
	$(DC) ps

docker.health: ## GET $(HEALTH_URL) and require HTTP 200 + JSON status ok
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

docker.sh: ## Shell (sh) in the php container
	@$(DC) exec $(PHP_SERVICE) sh

docker.bash: ## Shell (bash) in the php container
	@$(DC) exec $(PHP_SERVICE) bash

docker.exec: ## Run arbitrary cmd in php container; pass cmd='...'
	@$(eval cmd ?=)
	@$(DC) exec $(PHP_SERVICE) $(cmd)

docker.clean: ## Stop stack and REMOVE volumes (destructive)
	$(DC) down --remove-orphans --volumes

.PHONY: docker.up docker.up.wait docker.down docker.build docker.restart \
        docker.logs docker.ps docker.health docker.sh docker.bash \
        docker.exec docker.clean
