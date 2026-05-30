# =============================================================================
# Production deploy helpers (ENV=prod profile).
# =============================================================================

# `prod.env.check` validates the secret file before any compose command runs;
# `deploy.local` stands the prod profile up on this host at https://erpify.local
# via scripts/deploy/deploy-local.sh. Both are host-side (no container needed).

## —— Deploy ———————————————————————————————————————————————————————————————

# Keys that MUST be set (non-empty, no CHANGE_ME placeholder) before the prod
# stack can start. Kept in sync with .env.prod.example.
PROD_REQUIRED_KEYS := APP_SECRET POSTGRES_PASSWORD CADDY_MERCURE_JWT_SECRET SERVER_NAME NEXT_PUBLIC_SYMFONY_API_BASE_URL

prod.env.check: ## Validate .env.prod.local holds every required prod secret (fails on missing file / unset / placeholder)
	@file="$(PROJECT_ROOT)/$(PROD_ENV_FILE)"; \
	if [ ! -f "$$file" ]; then \
		echo "✗ $(PROD_ENV_FILE) not found at repo root."; \
		echo "  Copy the template and fill in every CHANGE_ME value:"; \
		echo "    cp .env.prod.example $(PROD_ENV_FILE)"; \
		exit 1; \
	fi; \
	missing=""; \
	for key in $(PROD_REQUIRED_KEYS); do \
		val=$$(grep -E "^$$key=" "$$file" | head -n1 | cut -d= -f2-); \
		if [ -z "$$val" ] || printf '%s' "$$val" | grep -q 'CHANGE_ME'; then \
			missing="$$missing $$key"; \
		fi; \
	done; \
	if [ -n "$$missing" ]; then \
		echo "✗ $(PROD_ENV_FILE) has unset or placeholder required keys:"; \
		for k in $$missing; do echo "    - $$k"; done; \
		echo "  Edit $(PROD_ENV_FILE) and replace every CHANGE_ME value (see .env.prod.example)."; \
		exit 1; \
	fi; \
	echo "✓ $(PROD_ENV_FILE) is complete — all required keys set."

deploy.local: ## Stand up the prod profile on this host at https://erpify.local (preflight → up → migrate → smoke)
	@bash $(PROJECT_ROOT)/scripts/deploy/deploy-local.sh

.PHONY: prod.env.check deploy.local
