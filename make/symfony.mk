# =============================================================================
# Symfony console, Messenger, Profiler
# =============================================================================

.PHONY: sf sf.cc sf.cache.warmup sf.routes sf.about \
        sf.routes.manifest \
        sf.messenger.stop-workers \
        sf.clear.vendor sf.clear.var sf.clear.var.log sf.clear.var.cache \
        sf.chown.var sf.clear sf.clear.sudo \
        profiler.open profiler.dump-server

## —— Symfony console ——————————————————————————————————————————————————————

sf: ## Run Symfony console; pass c='…' (e.g. make sf c='about')
	@$(SYMFONY) $(c)

sf.cc: ## cache:clear
	@$(SYMFONY) cache:clear

sf.cache.warmup: ## cache:warmup (use after deploy)
	@$(SYMFONY) cache:warmup

sf.routes: ## debug:router (filter with f='…')
	@$(eval f ?=)
	@$(SYMFONY) debug:router $(if $(f),--show-controllers | grep $(f),)

sf.about: ## bin/console about
	@$(SYMFONY) about

## —— Route manifest ———————————————————————————————————————————————————————

# Writes api/.route-manifest.json — every route the PROD router declares, as
# {"<name>": {"path": …, "methods": […]}} — so a client's copy of these paths has something
# to be reconciled against. It exists because a path cannot be read off a controller's
# `#[Route]`: config/routes.yaml applies a prefix per DIRECTORY and two sibling directories of
# one context differ, so moving a controller changes its public URL and touches no attribute.
#
# `php.lint.prod-container` is a prerequisite for two reasons, and only one of them is
# correctness. It compiles var/cache/prod, which booting the prod kernel here would otherwise
# do implicitly — and under the -j4 fan-out CI gives php.quality.dry-run, that gate CLEARS
# that directory while this recipe would be reading it. Sequencing them is what keeps the pair
# safe in parallel; the same edge is why php.lint.route-manifest declares it too.
#
# Byte-for-byte deterministic (no timestamps, jq's codepoint ordering rather than the shell's
# locale-dependent sort), so re-running it on an unchanged tree produces an unchanged file.
# Never hand-edit the output — php.lint.route-manifest re-derives it and fails on any drift.
#
# The dump lands in a temp file and is copied over the manifest only once it has succeeded:
# redirecting straight onto the tracked file would leave a truncated manifest behind on a failed
# run, and `cat >` rather than `mv` so the file keeps its own mode instead of mktemp's 0600.
# The braces around the dump are load-bearing — ROUTE_MANIFEST_DUMP expands to `cd … && docker …`,
# and `! cd … && docker …` parses as `(! cd …) && docker …`, which short-circuits and never runs
# the dump at all.
sf.routes.manifest: php.lint.prod-container ## Regenerate api/.route-manifest.json from the prod router
	@dump="$$(mktemp)"; \
	if ! { $(ROUTE_MANIFEST_DUMP) > "$$dump"; }; then \
		rm -f "$$dump"; \
		echo "✗ sf.routes.manifest: could not read the production router — $(ROUTE_MANIFEST) left unchanged" >&2; \
		exit 1; \
	fi; \
	cat "$$dump" > $(ROUTE_MANIFEST); \
	rm -f "$$dump"; \
	echo "✓ $(ROUTE_MANIFEST) regenerated"

## —— Symfony Messenger ————————————————————————————————————————————————————————————

sf.messenger.stop-workers: ## Stop all messenger workers (use after deploy)
	@$(SYMFONY) messenger:stop-workers

## —— Symfony var and cache (dev/test only) ——————————————————————————————————

# Container runs as root, so files it writes to bind-mounted api/var are
# root-owned on the host. These targets delegate deletion to the container
# (no sudo needed), or offer a one-shot host-side chown as an escape hatch.
#
# CAVEAT — these clear via `docker compose exec` against the LIVE stack, so
# whatever they delete is refilled almost immediately while the app runs:
#   • var/{cache,log,share} — rewritten by the FrankenPHP worker + messenger_worker.
#   • vendor/*              — emptying it crashes the app; the container restarts and
#                             docker-entrypoint.sh re-runs `composer install` (see
#                             api/frankenphp/docker-entrypoint.sh — gated on $1 in
#                             frankenphp|php|bin/console, empty vendor/ triggers install).
# They are building blocks for flows that immediately rebuild (`make app.dev.clean`
# → app.clean.all → sf.clear, then `app.dev` reinstalls + boots fresh). To actually
# END UP with empty dirs, stop the writers first: `make docker.down` then clear, or
# just run `make app.dev.clean` for a clean down → wipe → reinstall → up cycle.

sf.clear.vendor: ## Remove api/vendor (container-side, no sudo)
	@$(PHP_CONT) sh -c 'rm -rf vendor/*'

sf.clear.var: ## Remove api/var/{cache,log} contents (container-side, no sudo)
	$(call guard_var_writable,var.clear)
	@$(PHP_CONT) sh -c 'rm -rf var/*'

sf.clear.var.log: ## Remove only api/var/log contents (container-side, no sudo)
	$(call guard_var_writable,var.clear.log)
	@$(PHP_CONT) sh -c 'rm -f var/log/*.log'

sf.clear.var.cache: ## Remove only api/var/cache contents (container-side, no sudo)
	$(call guard_var_writable,var.clear.cache)
	@$(PHP_CONT) sh -c 'rm -rf var/cache/*'

sf.chown.var: ## Reclaim ownership of api/var + api/vendor on the host (requires sudo)
	$(call guard_var_writable,var.chown)
	@sudo chown -R $(shell id -u):$(shell id -g) $(API_ROOT)/var $(API_ROOT)/vendor
	@echo "✓ api/var now owned by $(shell id -un)"

sf.clear: sf.clear.vendor sf.clear.var ## Remove api/vendor + api/var (live stack refills them; use app.dev.clean for a lasting wipe) (destructive)

# Host-side sudo wipe. Unlike the container-side targets above, this works with
# the stack DOWN (no exec needed) and removes root-owned artifacts the host user
# can't otherwise delete. Run after `make docker.down` for a wipe that sticks;
# api/var + api/vendor are recreated on the next `make docker.up` / composer install.
sf.clear.sudo: ## Wipe api/var + api/vendor host-side (requires sudo; dev/test only; run with stack down)
	$(call guard_var_writable,clear.sudo)
	@sudo rm -Rf $(API_ROOT)/var/* $(API_ROOT)/vendor/* .php-cs-fixer.cache

## —— Profiler (dev/test only) ——————————————————————————————————————————————

profiler.open: ## Open the Symfony Profiler UI (/_profiler/latest) in the host browser
	@port=$$($(DOCKER_COMPOSE) port --protocol tcp $(PHP_SERVICE) 443 2>/dev/null | cut -d: -f2); \
	url="https://localhost:$${port:-443}/_profiler/latest"; \
	printf 'Opening %s\n' "$$url"; \
	xdg-open "$$url" >/dev/null 2>&1 || open "$$url" >/dev/null 2>&1 || printf 'Open it manually: %s\n' "$$url"

profiler.dump-server: ## Start the var-dumper server (collects dump() out-of-band; Ctrl-C to stop)
	@$(SYMFONY) server:dump
