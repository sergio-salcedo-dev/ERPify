# make/config.mk — variables only, no targets.
#
# Defines paths, compose wiring, and the PHP/PWA exec helpers consumed
# by every other module.

# —— Paths ————————————————————————————————————————————————————————————————
PROJECT_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/..)
API_ROOT     := $(PROJECT_ROOT)/api
PWA_ROOT     := $(PROJECT_ROOT)/pwa
ROOT_DIR     := $(PROJECT_ROOT)

# —— Compose services ——————————————————————————————————————————————————————
PHP_SERVICE  ?= php
PWA_SERVICE  ?= pwa
DB_SERVICE   ?= database

COMPOSE_PROJECT_NAME ?= erpify
export COMPOSE_PROJECT_NAME

# —— Compose overlay by ENV ————————————————————————————————————————————————
# dev      : compose.yaml + compose.dev.yaml
# staging  : compose.yaml + compose.prod.yaml
# prod     : compose.yaml + compose.prod.yaml
#
# CI runs with ENV unset (= dev overlay). If CI-specific Compose behavior is
# ever needed, introduce compose.ci.yaml and re-add ENV=ci deliberately.
ifeq ($(ENV),prod)
  COMPOSE_FILES := -f compose.yaml -f compose.prod.yaml
else ifeq ($(ENV),staging)
  COMPOSE_FILES := -f compose.yaml -f compose.prod.yaml
else
  COMPOSE_FILES := -f compose.yaml -f compose.dev.yaml
endif

DOCKER_COMPOSE := cd $(PROJECT_ROOT) && docker compose $(COMPOSE_FILES)
DC             := $(DOCKER_COMPOSE)

# —— PHP exec helpers ——————————————————————————————————————————————————————
# IN_CONTAINER=true  → exec into the running php container (default)
# IN_CONTAINER=false → run on host in $(API_ROOT) (needs PHP + composer on PATH)
ifeq ($(IN_CONTAINER),false)
  PHP_CONT  := cd $(API_ROOT) &&
  PHP_TEST  := cd $(API_ROOT) && APP_ENV=test
  PHP_BEHAT := cd $(API_ROOT) && APP_ENV=test MINK_BASE_URL=$(MINK_BASE_URL)
else
  PHP_CONT  := $(DC) exec $(PHP_SERVICE)
  PHP_TEST  := $(DC) exec -e APP_ENV=test $(PHP_SERVICE)
  PHP_BEHAT := $(DC) exec -e APP_ENV=test -e MINK_BASE_URL=$(MINK_BASE_URL) $(PHP_SERVICE)
endif

PHP       := $(PHP_CONT) php
COMPOSER  := $(PHP_CONT) composer
SYMFONY   := $(PHP) bin/console

# —— Overrides —————————————————————————————————————————————————————————————
HEALTH_URL         ?= https://localhost/api/v1/health
MINK_BASE_URL      ?= http://php
OPEN_BROWSER       ?= 1
XDEBUG_MODE_OFF    ?= off
XDEBUG_MODE_DEBUG  ?= develop,debug

# —— SuperLinter ———————————————————————————————————————————————————————————
GITHUB_TOKEN                      ?=
SUPERLINTER_IMAGE                 ?= ghcr.io/super-linter/super-linter:latest
SUPERLINTER_SLIM_IMAGE            ?= ghcr.io/super-linter/super-linter:slim-latest
SUPERLINTER_VALIDATE_ALL_CODEBASE ?= true
SUPERLINTER_EXCLUDES              ?= (^|/)(vendor|node_modules|var|public/bundles|\.next)/

# —— PWA host exec wrapper ————————————————————————————————————————————————
# IDEs (PHPStorm External Tools) launch sh with a minimal PATH. Prefer a
# login shell so nvm/fnm/brew/npm are visible; fall back to an inline
# PATH + nvm sourcing.
define pwa_cmd
	_pwa_l="$$(command -v zsh 2>/dev/null || command -v bash 2>/dev/null)"; \
	if [ -n "$$_pwa_l" ]; then \
		exec "$$_pwa_l" -lc "cd \"$(PWA_ROOT)\" && $(strip $(1))"; \
	fi; \
	export PATH="$$PATH:/usr/local/bin:/opt/homebrew/bin:$$HOME/.local/bin:$$HOME/.fnm/shims:$$HOME/.local/share/fnm"; \
	[ -s "$$HOME/.nvm/nvm.sh" ] && . "$$HOME/.nvm/nvm.sh" 2>/dev/null || true; \
	cd "$(PWA_ROOT)" && exec $(strip $(1))
endef
