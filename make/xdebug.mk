# =============================================================================
# PHP Xdebug
# =============================================================================

xdebug.enable: ## Set XDEBUG_MODE=$(XDEBUG_MODE_DEBUG) in api/.env and recreate php
	@if ! grep -q '^XDEBUG_MODE=' "$(API_ROOT)/.env" 2>/dev/null; then \
		printf '\n###> docker/xdebug ###\nXDEBUG_MODE=$(XDEBUG_MODE_OFF)\n###< docker/xdebug ###\n' >> "$(API_ROOT)/.env"; \
	fi
	@sed -i 's/^XDEBUG_MODE=.*/XDEBUG_MODE=$(XDEBUG_MODE_DEBUG)/' "$(API_ROOT)/.env"
	@echo "Set XDEBUG_MODE=$(XDEBUG_MODE_DEBUG) in api/.env. Recreating php…"
	$(DOCKER_COMPOSE) up --detach --force-recreate --no-deps $(PHP_SERVICE)

xdebug.disable: ## Set XDEBUG_MODE=off in api/.env (if present) and recreate php
	@if grep -q '^XDEBUG_MODE=' "$(API_ROOT)/.env" 2>/dev/null; then \
		sed -i 's/^XDEBUG_MODE=.*/XDEBUG_MODE=$(XDEBUG_MODE_OFF)/' "$(API_ROOT)/.env"; \
		echo "Set XDEBUG_MODE=$(XDEBUG_MODE_OFF) in api/.env."; \
	else \
		echo "No XDEBUG_MODE= line in api/.env."; \
	fi
	$(DOCKER_COMPOSE) up --detach --force-recreate --no-deps $(PHP_SERVICE)

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

.PHONY: xdebug.enable xdebug.disable xdebug.status
