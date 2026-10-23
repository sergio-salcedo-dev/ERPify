# =============================================================================
# Production deploy helpers (ENV=prod profile).
# =============================================================================

# `prod.env.check` validates the secret file before any compose command runs;
# `deploy.local` stands the prod profile up on this host at https://erpify.local
# via scripts/deploy/deploy-local.sh. Both are host-side (no container needed).

## —— Deploy ———————————————————————————————————————————————————————————————

# Keys that MUST be set (non-empty, no CHANGE_ME placeholder) before the prod
# stack can start: every required interpolation (`${VAR:?…}` or `${VAR?…}`) in
# the two files the prod stack loads, read from them rather than listed here.
# Whole comment lines are skipped (compose.prod.yaml's header quotes the form).
# ProdEnvTemplateGateTest holds .env.prod.example to the same set.
PROD_REQUIRED_KEYS := $(sort $(shell cat $(PROJECT_ROOT)/compose.yaml $(PROJECT_ROOT)/compose.prod.yaml 2>/dev/null | sed -e '/^[[:space:]]*\#/d' | grep -oE '\$$\{[A-Z0-9_]+:?\?' | tr -d '$${:?'))

# A value as Compose reads it: first occurrence, CR and outer whitespace removed,
# one pair of surrounding quotes stripped. $$1 = key, $$2 = file.
PROD_ENV_VALUE = grep -E "^$$1=" "$$2" | head -n1 | cut -d= -f2- | tr -d '\r' | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$$//' -e 's/^"\(.*\)"$$/\1/' -e "s/^'\(.*\)'$$/\1/"

prod.env.check: ## Validate .env.prod.local holds every required prod secret (fails on missing file / unset / placeholder)
	@file="$(PROJECT_ROOT)/$(PROD_ENV_FILE)"; \
	if [ ! -f "$$file" ]; then \
		echo "✗ $(PROD_ENV_FILE) not found at repo root."; \
		echo "  Copy the template and fill in every CHANGE_ME value:"; \
		echo "    cp .env.prod.example $(PROD_ENV_FILE)"; \
		exit 1; \
	fi; \
	if [ -z "$(PROD_REQUIRED_KEYS)" ]; then \
		echo "✗ No required keys derived from compose.prod.yaml — refusing to validate against an empty set."; \
		exit 1; \
	fi; \
	missing=""; \
	for key in $(PROD_REQUIRED_KEYS); do \
		val=$$(set -- "$$key" "$$file"; $(PROD_ENV_VALUE)); \
		if [ -z "$$val" ] || printf '%s' "$$val" | grep -qE '^CHANGE_ME|examplePublicKey@'; then \
			missing="$$missing $$key"; \
		fi; \
	done; \
	if [ -n "$$missing" ]; then \
		echo "✗ $(PROD_ENV_FILE) has unset or placeholder required keys:"; \
		for k in $$missing; do echo "    - $$k"; done; \
		echo "  Edit $(PROD_ENV_FILE) and replace every CHANGE_ME value (see .env.prod.example)."; \
		exit 1; \
	fi; \
	kek=$$(set -- AUDIT_KEK "$$file"; $(PROD_ENV_VALUE)); \
	if [ "$$(printf '%s' "$$kek" | wc -c)" -ne 32 ]; then \
		echo "✗ AUDIT_KEK must be exactly 32 bytes — got $$(printf '%s' "$$kek" | wc -c)."; \
		echo "  Generate with: openssl rand -base64 24   (-hex 32 and -base64 32 are the wrong length)"; \
		exit 1; \
	fi; \
	pw=$$(grep -E "^POSTGRES_PASSWORD=" "$$file" | head -n1 | cut -d= -f2- | tr -d '\r' | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$$//'); \
	if printf '%s' "$$pw" | grep -q '[/+=:@? ]'; then \
		echo "✗ POSTGRES_PASSWORD contains a URL-unsafe char (/ + = : @ ? or space) — it corrupts DATABASE_URL."; \
		echo "  Regenerate URL-safe, e.g.: openssl rand -hex 24"; \
		exit 1; \
	fi; \
	tmo=$$(grep -E "^MAILER_SMTP_TIMEOUT=" "$$file" | head -n1 | cut -d= -f2- | tr -d '\r' | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$$//'); \
	if [ -n "$$tmo" ]; then \
		if ! printf '%s' "$$tmo" | grep -qE '^[0-9]+(\.[0-9]+)?$$'; then \
			echo "✗ MAILER_SMTP_TIMEOUT must be a plain number of seconds — got '$$tmo'."; \
			echo "  A duration spelling like '10s' passes compose, the image build and the container lint,"; \
			echo "  then throws on the first email and returns false from every lockout tick, silently."; \
			exit 1; \
		fi; \
		if [ "$$(awk -v v="$$tmo" 'BEGIN { print (v >= 1 && v <= 300) ? 1 : 0 }')" != "1" ]; then \
			echo "✗ MAILER_SMTP_TIMEOUT must be between 1 and 300 seconds — got '$$tmo'."; \
			echo "  Below 1 no relay completes a round trip, so mail is discarded while looking configured;"; \
			echo "  above 300 the bound no longer protects the five-minute lockout tick it exists for."; \
			exit 1; \
		fi; \
	fi; \
	bad_mem=""; \
	for key in PHP_MEM_LIMIT PWA_MEM_LIMIT DB_MEM_LIMIT WORKER_MEM_LIMIT SCHEDULER_MEM_LIMIT; do \
		val=$$(grep -E "^$$key=" "$$file" | head -n1 | cut -d= -f2- | tr -d '\r' | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$$//'); \
		[ -n "$$val" ] || continue; \
		if ! printf '%s' "$$val" | grep -qE '^[0-9]{1,9}[bBkKmMgG]$$'; then \
			bad_mem="$$bad_mem\n    - $$key='$$val': not a valid Docker memory limit (need a positive integer, max 9 digits, with a b/k/m/g suffix — a bare '512' reads as 512 BYTES)"; \
			continue; \
		fi; \
		unit=$$(printf '%s' "$$val" | sed -E 's/^[0-9]+//' | tr '[:upper:]' '[:lower:]'); \
		digits=$$(printf '%s' "$$val" | sed -E 's/[bBkKmMgG]$$//'); \
		case "$$unit" in \
			b) mult=1 ;; \
			k) mult=1024 ;; \
			m) mult=1048576 ;; \
			g) mult=1073741824 ;; \
		esac; \
		bytes=$$(( digits * mult )); \
		if [ "$$bytes" -lt 6291456 ]; then \
			bad_mem="$$bad_mem\n    - $$key='$$val' ($$bytes bytes): below Docker's own ~6 MiB minimum — the container fails to start with 'Minimum memory limit allowed is 6MB', naming neither key nor service"; \
		fi; \
	done; \
	if [ -n "$$bad_mem" ]; then \
		echo "✗ $(PROD_ENV_FILE) has invalid memory-limit knobs:"; \
		printf '%b\n' "$$bad_mem"; \
		exit 1; \
	fi; \
	echo "✓ $(PROD_ENV_FILE) is complete — all required keys set."

# The project (erpify-prodlocal for the tls-internal rehearsal, erpify on ACME)
# is resolved from the env file by make/config.mk and scripts/deploy/lib/project.sh.
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
