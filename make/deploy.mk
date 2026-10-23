# =============================================================================
# Production deploy helpers (ENV=prod profile).
# =============================================================================

# `prod.env.check` validates the secret file before any compose command runs;
# `deploy.local` stands the prod profile up on this host at https://erpify.local
# via scripts/deploy/deploy-local.sh. Both are host-side (no container needed).

## —— Deploy ———————————————————————————————————————————————————————————————

# Every check on a prod env file lives in scripts/deploy/lib/prod-env.sh, the
# reader the deploy scripts share and the ProdEnv*GateTest family executes:
# required keys (derived from the compose files' `${VAR:?}`/`${VAR?}`),
# placeholders, duplicates, AUDIT_KEK length, the security sender, the Postgres
# password, the SMTP timeout and the memory knobs — read as Compose reads them.
PROD_ENV_LIB := $(PROJECT_ROOT)/scripts/deploy/lib/prod-env.sh

prod.env.check: ## Validate .env.prod.local as Compose will read it (required keys, placeholders, duplicates, AUDIT_KEK, sender, password, timeout, memory knobs)
	@file="$(PROD_ENV_PATH)"; \
	if [ ! -f "$$file" ]; then \
		echo "✗ $$file not found."; \
		echo "  Copy the template and fill in every CHANGE_ME value:"; \
		echo "    cp .env.prod.example $$file"; \
		exit 1; \
	fi; \
	bash "$(PROD_ENV_LIB)" check "$$file" || exit 1; \
	echo "✓ $$file passes every check."

# The project — erpify-prodlocal for the tls-internal rehearsal, the checkout's own
# otherwise — is resolved from the env file by make/config.mk via scripts/deploy/lib/prod-env.sh.
deploy.local: ## Stand up the prod profile on this host at https://erpify.local (preflight → up → migrate → smoke → CA export + trust guidance)
	@bash $(PROJECT_ROOT)/scripts/deploy/deploy-local.sh

deploy.local.down: ## Stop the prod stack deploy.local stood up (volumes kept); on the rehearsal, required before `make app.dev`, which needs the same ports
	@$(MAKE) --no-print-directory ENV=prod docker.down

deploy.local.trust: ## Run the PRIVILEGED client-trust steps (hosts + system CA + Chromium NSS). Run with sudo: `sudo make deploy.local.trust`
	@bash $(PROJECT_ROOT)/scripts/deploy/trust-local.sh

backup.prod: ## Prod backup: verified pg_dump of the database (knobs: BACKUP_DIR, RETENTION_DAYS, BACKUP_SYNC_CMD)
	@bash $(PROJECT_ROOT)/scripts/deploy/backup-prod.sh

restore.prod: ## Restore a backup (STAMP=<stamp>, omit to list) — DESTRUCTIVE, for the restore drill / pre-prod check (knobs: BACKUP_DIR, RESTORE_YES, ALLOW_PROD_RESTORE)
	@STAMP="$(STAMP)" bash $(PROJECT_ROOT)/scripts/deploy/restore-prod.sh

.PHONY: prod.env.check deploy.local deploy.local.down deploy.local.trust backup.prod restore.prod
