# =============================================================================
# Configuration — paths, environment, docker commands, helpers
# =============================================================================

# —— Paths (Make internal only, never exported) ——
PROJECT_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST))))
API_ROOT     := $(PROJECT_ROOT)/api
PWA_ROOT     := $(PROJECT_ROOT)/pwa

# —— Service names ——
PHP_SERVICE  ?= php
PWA_SERVICE  ?= pwa
DB_SERVICE   ?= database

# —— Compose file selection based on ENV ——
COMPOSE_FILES := -f compose.yaml
ifeq ($(ENV),dev)
  COMPOSE_FILES += -f compose.override.yaml
endif
ifeq ($(ENV),ci)
  COMPOSE_FILES += -f compose.ci.yaml
endif
ifneq ($(filter staging prod,$(ENV)),)
  COMPOSE_FILES += -f compose.prod.yaml
endif

# —— Docker command builders ——
DOCKER_COMPOSE := cd $(PROJECT_ROOT) && docker compose $(COMPOSE_FILES)
DOCKER_COMPOSE_ENV := --env-file .env.$(ENV)

# —— Execution context: dev=container, CI=host ——
IN_CONTAINER ?= $(if $(filter ci,$(ENV)),false,true)

ifeq ($(IN_CONTAINER),false)
  DOCKER_EXEC_PHP      := cd $(API_ROOT)
  DOCKER_EXEC_PHP_TEST := cd $(API_ROOT)
else
  DOCKER_EXEC_PHP      := $(DOCKER_COMPOSE) $(DOCKER_COMPOSE_ENV) exec $(PHP_SERVICE)
  DOCKER_EXEC_PHP_TEST := $(DOCKER_COMPOSE) $(DOCKER_COMPOSE_ENV) exec -e APP_ENV=test $(PHP_SERVICE)
endif

# —— Exported (containers need these) ——
export COMPOSE_PROJECT_NAME ?= erpify

# —— Configurable overrides (safe, non-secret) ——
HEALTH_URL        ?= https://localhost/api/v1/health
MINK_BASE_URL     ?= http://php
OPEN_BROWSER      ?= 1
XDEBUG_MODE_OFF   := off
XDEBUG_MODE_DEBUG := develop,debug
GITHUB_TOKEN      ?=
SUPERLINTER_IMAGE ?= super-linter/super-linter:latest

# —— PWA command helper (always runs on host) ——
define pwa_cmd
	_pwa_l="$$(command -v zsh 2>/dev/null || command -v bash 2>/dev/null)"; \
	if [ -n "$$_pwa_l" ]; then \
		exec "$$_pwa_l" -lc "cd \"$(PWA_ROOT)\" && $(strip $(1))"; \
	fi; \
	export PATH="$$PATH:/usr/local/bin:/opt/homebrew/bin:$$HOME/.local/bin:$$HOME/.fnm/shims:$$HOME/.local/share/fnm"; \
	[ -s "$$HOME/.nvm/nvm.sh" ] && . "$$HOME/.nvm/nvm.sh" 2>/dev/null || true; \
	cd "$(PWA_ROOT)" && exec $(strip $(1))
endef
