# make/help.mk — self-documenting `make help` grouped by section.
#
# Rules:
#   * Section headers are lines shaped like `## —— Title ——` inside modules.
#   * Every public target has a trailing `## description` comment.
#   * Private/internal targets should omit the `##` comment (they stay hidden).

help: ## Show quick-start and all targets grouped by section
	@printf '\n\033[1mERPify\033[0m  %s    ENV=\033[36m%s\033[0m  IN_CONTAINER=\033[36m%s\033[0m\n' '$(PROJECT_ROOT)' '$(ENV)' '$(IN_CONTAINER)'
	@printf '\n\033[1mQuick start\033[0m\n'
	@printf '  %-22s %s\n' 'make dev'            'Full stack (wait) + open browser'
	@printf '  %-22s %s\n' 'make docker.up'      'Stack up --build (detached, ENV-aware)'
	@printf '  %-22s %s\n' 'make docker.down'    'Stop stack'
	@printf '  %-22s %s\n' 'make docker.health'  'Probe $(HEALTH_URL)'
	@printf '  %-22s %s\n' 'make dev.local'      'API+DB on :8000 + Next dev on host'
	@printf '  %-22s %s\n' 'make test'           'All tests (PHP + PWA)'
	@printf '  %-22s %s\n' 'make lint'           'All linters (PHP + PWA)'
	@printf '  %-22s %s\n' 'make ci'             'Full CI: lint + test'
	@printf '\n\033[1mKnobs\033[0m\n'
	@printf '  %-22s %s\n' 'ENV=dev|staging|prod' 'Compose overlay (CI uses default)'
	@printf '  %-22s %s\n' "c='…'"                  "Passthrough args (composer/sf/phpunit/vitest)"
	@printf '  %-22s %s\n' 'IN_CONTAINER=false'     'Run PHP on host instead of exec in php container'
	@printf '  %-22s %s\n' 'OPEN_BROWSER=0'         'Skip xdg-open/open in dev/prod-up'
	@printf '  %-22s %s\n' 'CI_SHARD CI_TOTAL_SHARDS' 'Playwright sharding for pwa.test.e2e'
	@$(MAKE) --no-print-directory help-targets

help-targets:
	@awk ' \
	/^## ——/ { \
		line = $$0; \
		sub(/^## ——[[:space:]]*/, "", line); \
		sub(/[[:space:]]—+.*$$/, "", line); \
		printf "\n\033[33m%s\033[0m\n", line; \
		next \
	} \
	/^help:/ || /^help-targets:/ { next } \
	/^\.PHONY:/ { next } \
	$$0 ~ /^[[:alpha:]][^#]*:.*##/ { \
		n = index($$0, "##"); \
		if (n == 0) next; \
		left = substr($$0, 1, n - 1); \
		desc = substr($$0, n + 2); \
		gsub(/^[[:space:]]+|[[:space:]]+$$/, "", desc); \
		c = index(left, ":"); \
		if (c == 0) next; \
		targets = substr(left, 1, c - 1); \
		gsub(/^[[:space:]]+|[[:space:]]+$$/, "", targets); \
		if (targets == "") next; \
		printf "  \033[32m%-28s\033[0m %s\n", targets, desc; \
		next \
	} \
	' $(MAKEFILE_LIST)

.PHONY: help help-targets
