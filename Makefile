# ERPify — monorepo Make entry point.
#
# Canonical interface for all dev/CI work. See `make help` for the full
# target list grouped by section. Always invoke from repo root.
#
# Environments   : ENV=dev|staging|prod     (default: dev)
#                  Selects the compose overlay. CI runs with the default.
# Passthrough    : c='...'                  — extra args for composer/sf/phpunit/…
# Container mode : IN_CONTAINER=true|false  (default: true; CI-safe either way)
#
# Quick start:
#   make dev                 Full stack with health wait, open browser
#   make docker.up           Stack up (detached, ENV-aware)
#   make test                All tests  (PHP + PWA)
#   make lint                All linters (PHP + PWA)
#   make ci                  Full CI (lint + test)

ENV          ?= dev
IN_CONTAINER ?= true

# Module order matters: config first (vars), help last (lists them).
#include make/config.mk
#include make/docker.mk
#include make/dev.mk
#include make/api.mk
#include make/db.mk
#include make/php-test.mk
#include make/php-lint.mk
#include make/pwa.mk
#include make/ci.mk
#include make/super-lint.mk
#include make/help.mk
include make/*.mk

.DEFAULT_GOAL := help

## —— Aggregates ——

app.lint: php.lint pwa.lint ## Run all linters (PHP + PWA)

app.test: php.test pwa.test ## Run all tests (PHP + PWA)

.PHONY: app.lint app.test
