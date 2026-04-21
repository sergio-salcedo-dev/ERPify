# make/dev.mk — Developer ergonomics (browsers, local modes, Xdebug).

## —— Dev & prod shortcuts —————————————————————————————————————————————————

dev: docker.up.wait open-local ## Full dev stack with --wait, then open browser

dev.local: api-up-http ## API + DB on :8000 + Next dev on host (needs pwa/.env.local)
	$(call pwa_cmd,npm run dev -- --turbo)

api-up-http: ## Bring up API + DB only, HTTP on host :8000 (no PWA container)
	cd $(PROJECT_ROOT) && \
	HTTP_PORT=8000 SERVER_NAME=http://localhost:8000 \
	DEFAULT_URI=http://localhost:8000 CADDY_MERCURE_PUBLIC_URL=http://localhost:8000/.well-known/mercure \
	docker compose $(COMPOSE_FILES) up --wait --detach $(PHP_SERVICE) $(DB_SERVICE) messenger_worker

prod-up: ## Production overlay: up --wait --build --detach (requires secrets)
	cd $(PROJECT_ROOT) && docker compose -f compose.yaml -f compose.prod.yaml up --wait --build --detach
	@$(MAKE) --no-print-directory open-local

open-local: ## Open http://localhost and https://localhost (OPEN_BROWSER=0 to skip)
	@if [ "$(OPEN_BROWSER)" = "0" ]; then echo "OPEN_BROWSER=0, skipping browser"; exit 0; fi
	@for url in http://localhost https://localhost; do \
		if command -v xdg-open >/dev/null 2>&1; then xdg-open "$$url" 2>/dev/null || true; \
		elif command -v open >/dev/null 2>&1; then open "$$url" 2>/dev/null || true; \
		else echo "Open manually: $$url"; fi; \
	done

## —— Xdebug ——————————————————————————————————————————————————————————————

xdebug.enable: ## Set XDEBUG_MODE=$(XDEBUG_MODE_DEBUG) in api/.env and recreate php
	@if ! grep -q '^XDEBUG_MODE=' "$(API_ROOT)/.env" 2>/dev/null; then \
		printf '\n###> docker/xdebug ###\nXDEBUG_MODE=$(XDEBUG_MODE_OFF)\n###< docker/xdebug ###\n' >> "$(API_ROOT)/.env"; \
	fi
	@sed -i 's/^XDEBUG_MODE=.*/XDEBUG_MODE=$(XDEBUG_MODE_DEBUG)/' "$(API_ROOT)/.env"
	@echo "Set XDEBUG_MODE=$(XDEBUG_MODE_DEBUG) in api/.env. Recreating php…"
	$(DC) up --detach --force-recreate --no-deps $(PHP_SERVICE)

xdebug.disable: ## Set XDEBUG_MODE=off in api/.env (if present) and recreate php
	@if grep -q '^XDEBUG_MODE=' "$(API_ROOT)/.env" 2>/dev/null; then \
		sed -i 's/^XDEBUG_MODE=.*/XDEBUG_MODE=$(XDEBUG_MODE_OFF)/' "$(API_ROOT)/.env"; \
		echo "Set XDEBUG_MODE=$(XDEBUG_MODE_OFF) in api/.env."; \
	else \
		echo "No XDEBUG_MODE= line in api/.env."; \
	fi
	$(DC) up --detach --force-recreate --no-deps $(PHP_SERVICE)

xdebug.status: ## Print PHP / Xdebug versions and current XDEBUG_MODE
	@echo "=== php -v ==="
	@$(PHP_CONT) php -v
	@echo ""
	@$(PHP_CONT) php -r "if (!extension_loaded('xdebug')) { fwrite(STDERR, 'ERROR: Xdebug extension is not loaded.'.PHP_EOL); exit(1);}"
	@$(PHP_CONT) php -r "echo 'PHP version:     ', PHP_VERSION, PHP_EOL;"
	@$(PHP_CONT) php -r "echo 'Xdebug version:  ', phpversion('xdebug'), PHP_EOL;"
	@$(PHP_CONT) php -r "echo 'XDEBUG_MODE:     ', (getenv('XDEBUG_MODE') !== false ? getenv('XDEBUG_MODE') : '(unset)'), PHP_EOL;"
	@$(PHP_CONT) php -r "echo 'PHP_IDE_CONFIG:  ', (getenv('PHP_IDE_CONFIG') !== false ? getenv('PHP_IDE_CONFIG') : '(unset)'), PHP_EOL;"
	@$(PHP_CONT) php -r '$$m = getenv("XDEBUG_MODE") ?: ""; echo str_contains($$m, "debug") ? "OK: step debugging is ON (IDE listens on :9003)." : "OK: step debugging is OFF. Run make xdebug.enable.", PHP_EOL;'

.PHONY: dev dev.local api-up-http prod-up open-local \
        xdebug.enable xdebug.disable xdebug.status
