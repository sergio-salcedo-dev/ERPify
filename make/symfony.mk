# =============================================================================
# Symfony console, Messenger
# =============================================================================

.PHONY: sf sf.cc sf.cache.warmup sf.routes sf.about \
        sf.messenger.stop-workers \
        sf.clear.vendor sf.clear.var sf.clear.var.log sf.clear.var.cache \
        sf.chown.var sf.clear sf.clear.sudo

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
	@sudo rm -R $(API_ROOT)/var/* $(API_ROOT)/vendor/* .php-cs-fixer.cache
