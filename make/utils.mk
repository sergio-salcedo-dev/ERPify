# =============================================================================
# Utils — Help System, Aliases, Misc
# =============================================================================

## —— Help ——

help: ## Show this help
	@printf '\n\033[1mERPify\033[0m  %s\n' '$(PROJECT_ROOT)'
	@printf '\n\033[1mTypical commands\033[0m\n'
	@printf '  %-28s  %s\n' 'make docker.up' 'Start stack (ENV=dev|ci|staging|prod)'
	@printf '  %-28s  %s\n' 'make docker.down' 'Stop stack'
	@printf '  %-28s  %s\n' 'make lint' 'Run all linters (PHP + JS)'
	@printf '  %-28s  %s\n' 'make test' 'Run all tests (PHP + JS)'
	@printf '  %-28s  %s\n' 'make ci' 'Full CI pipeline'
	@printf '  %-28s  %s\n' 'make docker.sh' 'Shell in PHP container'
	@printf '\n  %-28s  %s\n' 'ENV=dev|ci|staging|prod' 'Select environment (default: dev)'
	@printf '  %-28s  %s\n' 'IN_CONTAINER=false' 'Run natively instead of docker exec'
	@$(MAKE) --no-print-directory help-targets

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
	$$0 ~ /^[[:alpha:].][^#]*:.*##/ { \
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
		printf "  \033[32m%-28s\033[0m %s\n", targets, desc; \
		next \
	} \
	' $(MAKEFILE_LIST)

## —— Open Browser ——

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

## —— Dev helpers ——

dev-up: ## Dev stack up with --wait --build, then open browser
	$(MAKE) docker.up.wait
	@$(MAKE) open-local

dev-local: ## API + DB on :8000 + Next dev on host
	cd $(PROJECT_ROOT) && \
	HTTP_PORT=8000 SERVER_NAME=http://localhost:8000 \
	DEFAULT_URI=http://localhost:8000 CADDY_MERCURE_PUBLIC_URL=http://localhost:8000/.well-known/mercure \
	docker compose $(COMPOSE_FILES) up --wait --detach php database messenger_worker
	$(call pwa_cmd,npm run dev -- --turbo)

prod-up: ## Prod: up --wait --build with prod compose files
	cd $(PROJECT_ROOT) && docker compose -f compose.yaml -f compose.prod.yaml up --wait --build --detach
	@$(MAKE) open-local

.PHONY: help help-targets open-local dev-up dev-local prod-up
