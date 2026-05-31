# =============================================================================
# Config module for Makefile. Variables only, no targets.
# =============================================================================

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

# —— Per-worktree stack isolation ————————————————————————————————————————
# Each git worktree runs its own Compose project so multiple checkouts can keep
# isolated stacks up at the same time — containers, networks, and volumes are
# all namespaced by the project name. The primary checkout keeps the bare
# `erpify` name (so its existing stack/volumes are untouched); a linked worktree
# under .claude/worktrees/ derives `erpify-<dir-slug>`.
#
# Linked-worktree stacks also publish their host ports ephemerally (port 0 → a
# random free port) so they never collide on 80/443/15432/8025 with the primary
# stack or with each other. They are meant to be driven through
# `docker compose exec` and the internal Docker network (e.g. MINK_BASE_URL,
# pwa→php), not fixed host ports — so the random ports don't matter for the
# checks/tests a worktree runs. Browse the UI from the primary checkout, or set
# the *_PORT vars explicitly to opt back into fixed ports.
#
# Everything below is `?=`/overridable: exporting COMPOSE_PROJECT_NAME or any
# *_PORT in your environment or on the command line wins.

# True only inside a linked worktree (git-common-dir's parent != this checkout).
# Resolves a possibly-relative --git-common-dir against PROJECT_ROOT.
IS_LINKED_WORKTREE := $(shell cd "$(PROJECT_ROOT)" 2>/dev/null && \
	_c="$$(git rev-parse --git-common-dir 2>/dev/null)" && [ -n "$$_c" ] && \
	[ "$$(cd "$$_c/.." 2>/dev/null && pwd -P)" != "$$(pwd -P)" ] && echo true)

ifeq ($(IS_LINKED_WORKTREE),true)
  # Lower-cased dir name, non-alphanumerics → '-', trimmed (e.g. chore+x → chore-x).
  WORKTREE_SLUG := $(shell printf '%s' "$$(basename '$(PROJECT_ROOT)')" \
	| tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9' '-' | sed -E 's/^-+|-+$$//g')
  COMPOSE_PROJECT_NAME ?= erpify-$(WORKTREE_SLUG)
  # Ephemeral host ports for dev-overlay worktree stacks only; never touch the
  # fixed ports a real staging/prod deploy needs.
  ifeq ($(filter $(ENV),prod staging),)
    HTTP_PORT       ?= 0
    HTTPS_PORT      ?= 0
    HTTP3_PORT      ?= 0
    POSTGRES_PORT   ?= 0
    MAILPIT_UI_PORT ?= 0
    export HTTP_PORT HTTPS_PORT HTTP3_PORT POSTGRES_PORT MAILPIT_UI_PORT
  endif
else
  COMPOSE_PROJECT_NAME ?= erpify
endif
export COMPOSE_PROJECT_NAME

# —— Compose overlay by ENV ————————————————————————————————————————————————
# dev      : compose.yaml + compose.dev.yaml
# staging  : compose.yaml + compose.prod.yaml
# prod     : compose.yaml + compose.prod.yaml
#
# CI runs with ENV unset (= dev overlay). If CI-specific Compose behavior is
# ever needed, introduce compose.ci.yaml and re-add ENV=ci deliberately.
#
# Prod/staging additionally load secrets through `--env-file $(PROD_ENV_FILE)`.
# Compose interpolation (`${VAR}` in a compose file) only reads the shell env
# or `--env-file`/default `.env` — NOT a service's `env_file:` — so prod
# secrets MUST flow through this wiring, not an `env_file:` on a service.
PROD_ENV_FILE ?= .env.prod.local
ifeq ($(ENV),prod)
  COMPOSE_FILES := -f compose.yaml -f compose.prod.yaml
  ENV_FILE_ARGS := --env-file $(PROD_ENV_FILE)
else ifeq ($(ENV),staging)
  COMPOSE_FILES := -f compose.yaml -f compose.prod.yaml
  ENV_FILE_ARGS := --env-file $(PROD_ENV_FILE)
else
  COMPOSE_FILES := -f compose.yaml -f compose.dev.yaml
  ENV_FILE_ARGS :=
endif

DOCKER_COMPOSE := cd $(PROJECT_ROOT) && docker compose $(ENV_FILE_ARGS) $(COMPOSE_FILES)
DOCKER_COMPOSE_EXEC := $(DOCKER_COMPOSE) exec

# —— PHP exec helpers ——————————————————————————————————————————————————————
# IN_CONTAINER=true  → exec into the running php container (default)
# IN_CONTAINER=false → run on host in $(API_ROOT) (needs PHP + composer on PATH)
ifeq ($(IN_CONTAINER),false)
  PHP_CONT  := cd $(API_ROOT) &&
  PHP_TEST  := cd $(API_ROOT) && APP_ENV=test
  PHP_BEHAT := cd $(API_ROOT) && APP_ENV=test MINK_BASE_URL=$(MINK_BASE_URL)
else
  PHP_CONT  := $(DOCKER_COMPOSE_EXEC) $(PHP_SERVICE)
  PHP_TEST  := $(DOCKER_COMPOSE_EXEC) -e APP_ENV=test $(PHP_SERVICE)
  PHP_BEHAT := $(DOCKER_COMPOSE_EXEC) -e APP_ENV=test -e MINK_BASE_URL=$(MINK_BASE_URL) $(PHP_SERVICE)
endif

PHP       := $(PHP_CONT) php
COMPOSER  := $(PHP_CONT) composer
SYMFONY   := $(PHP) bin/console

# —— Overrides —————————————————————————————————————————————————————————————
MINK_BASE_URL      ?= http://php
XDEBUG_MODE_OFF    ?= off
XDEBUG_MODE_DEBUG  ?= develop,debug

# —— PWA host exec wrapper ————————————————————————————————————————————————
# IDEs (PHPStorm External Tool) launch sh with a minimal PATH. Prefer a
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

# —— Guards ————————————————————————————————————————————————————————————————
# Refuse to run destructive var/ operations outside dev/test. Usage:
#   @$(call guard_var_writable,target.name)
# Prints an error and exits non-zero when ENV is prod or staging.
define guard_var_writable
	@if [ "$(ENV)" = "prod" ] || [ "$(ENV)" = "staging" ]; then \
		echo "✗ refusing to run '$(1)' with ENV=$(ENV). Dev/test only."; \
		exit 1; \
	fi
endef
