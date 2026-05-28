# =============================================================================
# ERPify — monorepo Make entry point.
# =============================================================================

# Canonical interface for all dev/CI work. See `make help` for the full
# target list grouped by section. Always invoke from repo root.
#
# Environments   : ENV=dev|staging|prod     (default: dev)
#                  Selects the compose overlay. CI runs with the default.
# Passthrough    : c='...'                  — extra args for composer/sf/phpunit/…
# Container mode : IN_CONTAINER=true|false  (default: true; CI-safe either way)

ENV          ?= dev
IN_CONTAINER ?= true

# Module order matters: config first (vars), help last (lists them).
#include make/config.mk
#include make/docker.mk
#include make/xdebug.mk
#include make/composer.mk
#include make/symfony.mk
#include make/db.mk
#include make/git.mk
#include make/php.mk
#include make/php-test.mk
#include make/php-quality.mk
#include make/pwa.mk
#include make/ci.mk
#include make/super-lint.mk
#include make/help.mk
#include make/codeql.mk
include make/*.mk

.DEFAULT_GOAL := help

## —— Aggregates ——

app.quality: php.quality pwa.quality ## Run all linters (PHP + PWA)

app.test: php.test pwa.test ## Run all tests (PHP + PWA)

app.update: composer.update pwa.update ## Safe update of API + PWA deps (within composer.json / package.json ranges)

app.upgrade: composer.upgrade pwa.upgrade ## Force upgrade API + PWA deps to the latest (bumps constraints across majors)

app.clean.soft: sf.clear.var pwa.clean.soft ## Clean build artifacts (Symfony var + Next .next/.next-e2e)

app.clean.all: sf.clear pwa.clean.all ## Clean all build artifacts (Symfony var + vendor + Next .next/.next-e2e + node_modules)

app.clean.sudo: sf.clear.sudo pwa.clean.sudo ## Host-side sudo wipe of all build artifacts (api/var + api/vendor + Next .next/.next-e2e + node_modules + lockfile) (requires sudo; run with stack down)

app.dev: docker.down pwa.install.if-missing docker.up.wait php.fix.ownership ## Full dev stack with --wait

app.dev.clean: docker.down app.clean.sudo app.dev ## Full dev stack with --wait (destructive)

.PHONY: app.quality app.test app.update app.upgrade \
		app.dev app.dev.clean \
		app.clean.soft app.clean.all app.clean.sudo
