# =============================================================================
# Docker Stack lifecycle (ENV-aware).
# =============================================================================

# All targets drive `docker compose` from the repo root with the overlay
# chosen by $(COMPOSE_FILES) in config.mk.

## —— Guardrails ——————————————————————————————————————————————————————————

# A memory-limit override Docker's own parser accepts without a unit suffix is silently read as
# BYTES (`DEV_PHP_MEM_LIMIT=512` -> 512 bytes), a value below Docker's own ~6 MiB floor (e.g.
# `4m`) fails inside the container with an error naming neither the variable nor the service, and
# `0g`/a negative value never mean what they look like — by which point `make app.dev` has
# already run `docker.down` (see the Makefile). Read via the shell's OWN environment (`$$$(1)`),
# never via Make's `$(VAR)` text substitution into the recipe: Make performs no shell-quoting on
# that substitution, so the raw value is pasted into the command unescaped — measured,
# `DEV_PHP_MEM_LIMIT='8g"; touch pwned; echo "'` executed the `touch` under the old `$($(1))` form.
# Checked only when the caller actually sets the variable — unset means compose's own default,
# already valid.
define check_mem_limit
	@val="$$$(1)"; \
	if [ -n "$$val" ]; then \
		if ! printf '%s' "$$val" | grep -qE '^[0-9]{1,9}[bBkKmMgG]$$'; then \
			echo "✗ $(1)='$$val' is not a valid Docker memory limit."; \
			echo "  Expected a positive integer (max 9 digits) with a unit suffix (b, k, m or g), e.g. '8g' or '512m'."; \
			echo "  A bare number like '512' is read as 512 BYTES, not 512 megabytes — Docker accepts it silently."; \
			exit 1; \
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
			echo "✗ $(1)='$$val' ($$bytes bytes) is below Docker's own ~6 MiB minimum."; \
			echo "  The container fails to start with 'Minimum memory limit allowed is 6MB', naming neither"; \
			echo "  this variable nor the service — exactly the opaque failure this check exists to prevent."; \
			exit 1; \
		fi; \
	fi
endef

docker.mem.check: ## Validate DEV_*_MEM_LIMIT overrides before they reach docker compose (dev overlay only)
ifeq ($(filter $(ENV),prod staging),)
	$(call check_mem_limit,DEV_PHP_MEM_LIMIT)
	$(call check_mem_limit,DEV_PWA_MEM_LIMIT)
	$(call check_mem_limit,DEV_DB_MEM_LIMIT)
	$(call check_mem_limit,DEV_WORKER_MEM_LIMIT)
	$(call check_mem_limit,DEV_MAILPIT_MEM_LIMIT)
endif

# The dev caps are fixed sizes measured on one 30 GB host (compose.dev.yaml header) — nothing
# scales them, so on a smaller host they can exceed what's actually free, and the HOST's own OOM
# killer fires before any per-container cgroup cap ever binds: the exact failure the caps exist to
# prevent, silently inert on the machines that need them most. Compares against MemAvailable
# (not MemTotal) because the real ceiling already accounts for whatever this host's OTHER
# worktree stacks are holding. Warns only — never blocks `make app.dev`: a host where RAM can't be
# read (no /proc/meminfo — e.g. macOS) skips silently rather than guessing. The fallback numbers
# below (8g/4g/1g/2g/256m) mirror compose.dev.yaml's own `:-` defaults — keep both in sync if
# either changes; there is no single source both a Makefile and a compose file can read today.
# Defaults via shell `${VAR:-...}`, never Make's `$(VAR)` text substitution, for the same reason
# as `check_mem_limit` above: an unescaped value would be pasted into the shell script raw.
# Digit/unit split is manual rather than `numfmt --from=iec`, which does not accept a bare `b`
# suffix (`numfmt --from=iec 500000000B` exits 2) — that silently dropped a `b`-suffixed override
# (valid Docker syntax, accepted by `check_mem_limit`'s own regex) out of the sum entirely.
docker.mem.hostcheck: ## Warn (non-blocking) if the dev caps exceed this host's available RAM
ifeq ($(filter $(ENV),prod staging),)
	@avail_kb=$$(awk '/^MemAvailable:/ {print $$2}' /proc/meminfo 2>/dev/null); \
	[ -n "$$avail_kb" ] || avail_kb=$$(awk '/^MemTotal:/ {print $$2}' /proc/meminfo 2>/dev/null); \
	if [ -n "$$avail_kb" ] && command -v numfmt >/dev/null 2>&1; then \
		avail_bytes=$$(( avail_kb * 1024 )); \
		sum_bytes=0; \
		for val in "$${DEV_PHP_MEM_LIMIT:-8g}" "$${DEV_PWA_MEM_LIMIT:-4g}" "$${DEV_DB_MEM_LIMIT:-1g}" "$${DEV_WORKER_MEM_LIMIT:-2g}" "$${DEV_MAILPIT_MEM_LIMIT:-256m}"; do \
			unit=$$(printf '%s' "$$val" | sed -E 's/^[0-9]+//' | tr '[:upper:]' '[:lower:]'); \
			digits=$$(printf '%s' "$$val" | sed -E 's/[bBkKmMgG]$$//'); \
			case "$$digits" in ''|*[!0-9]*) continue ;; esac; \
			case "$$unit" in \
				b) mult=1 ;; \
				k) mult=1024 ;; \
				m) mult=1048576 ;; \
				g) mult=1073741824 ;; \
				*) continue ;; \
			esac; \
			sum_bytes=$$(( sum_bytes + digits * mult )); \
		done; \
		if [ "$$sum_bytes" -gt "$$avail_bytes" ]; then \
			echo "⚠ dev stack memory caps ($$(numfmt --to=iec $$sum_bytes)) exceed this host's available RAM ($$(numfmt --to=iec $$avail_bytes))."; \
			echo "  The host's own OOM killer can fire before any per-container cap binds. Override DEV_PHP_MEM_LIMIT"; \
			echo "  and friends down for this machine, e.g.: make app.dev DEV_PHP_MEM_LIMIT=4g DEV_PWA_MEM_LIMIT=2g"; \
		fi; \
	fi
endif

# `docker.up`/`docker.up.wait`/the two `.no-build*` variants are the documented way to bring the
# PROD overlay up too — `docs/vps-deployment.md` names `ENV=prod make docker.up.wait` (and plain
# `docker.up`) as the actual VPS commands, not only `make deploy.local`. A guard that only ever
# wires the dev checks would leave `prod.env.check`'s new `*_MEM_LIMIT` validation (make/deploy.mk)
# unreachable on that path — switch by $(ENV) so the right one runs either way.
ifeq ($(filter $(ENV),prod staging),)
DOCKER_UP_GUARDS := docker.mem.check docker.mem.hostcheck
else
DOCKER_UP_GUARDS := prod.env.check
endif

## —— Stack ————————————————————————————————————————————————————————————————

docker.up: $(DOCKER_UP_GUARDS) ## Start stack detached, rebuild images (ENV-aware)
	$(DOCKER_COMPOSE) up --build --detach

docker.up.wait: $(DOCKER_UP_GUARDS) ## Start stack detached with --wait health gate
	$(DOCKER_COMPOSE) up --wait --build --detach

# `up --wait` aborts if a container restarts once during the wait window. The php
# service can flap during cold boot (composer install + migrations + dev worker),
# so on failure we settle briefly and re-run --wait — already-up services just get
# re-evaluated and pass once php is healthy. A second failure is a real fault.
docker.up.wait.no-build: $(DOCKER_UP_GUARDS) ## Start stack detached with --wait, skip rebuild (CI: images already loaded)
	$(DOCKER_COMPOSE) up --wait --no-build --detach \
		|| { echo '[docker.up.wait.no-build] first --wait aborted (cold-boot restart); retrying once after settle...'; sleep 10; $(DOCKER_COMPOSE) up --wait --no-build --detach; }

# Backend-only variant for API-only CI jobs (PHPUnit/Behat hit /api/* and need the
# async worker for audit/email; they never touch the pwa container). Naming the php
# + messenger_worker services pulls database + mailpit in as depends_on but excludes
# pwa entirely — skips its build, `npm ci`, and ~180s cold `next dev` boot from the
# critical path. Mirrors the cold-boot retry-once logic of docker.up.wait.no-build.
docker.up.wait.no-build.api: $(DOCKER_UP_GUARDS) ## Start backend only (php + worker + deps), --wait, skip rebuild — excludes pwa (CI: API-only jobs)
	$(DOCKER_COMPOSE) up --wait --no-build --detach php messenger_worker \
		|| { echo '[docker.up.wait.no-build.api] first --wait aborted (cold-boot restart); retrying once after settle...'; sleep 10; $(DOCKER_COMPOSE) up --wait --no-build --detach php messenger_worker; }

docker.build: ## Rebuild images (--pull --no-cache)
	$(DOCKER_COMPOSE) build --pull --no-cache

docker.reset: $(DOCKER_UP_GUARDS) docker.down docker.up.wait ## Reset all services

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

.PHONY: docker.mem.check docker.mem.hostcheck \
        docker.up docker.up.wait docker.up.wait.no-build docker.up.wait.no-build.api docker.build \
		docker.restart \
        docker.logs \
        docker.ps docker.info \
        docker.down docker.down.clean-volumes docker.worker.cache.reset docker.reset \
        docker.prune
