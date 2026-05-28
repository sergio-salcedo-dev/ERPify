# =============================================================================
# Help targets and self-documenting `make help` grouped by section.
# =============================================================================

# Rules:
#   * Section headers are lines shaped like `## —— Title ——` inside modules.
#   * Every public target has a trailing `## description` comment.
#   * Private/internal targets omit the `##` comment (they stay hidden).
#
# Grouping (see make/CONVENTIONS.md §2):
#   * Targets are grouped under the most recent `## —— Title ——` header.
#   * Section state resets at every file boundary, so a module that forgets
#     its header can't bleed its targets into the previous module's section.
#   * A module with targets but no header yet falls back to its own namespace
#     (the filename: `git.mk` → `git`). Add an explicit header to override.
#   * Headings print lazily — only when a target appears under them — so empty
#     sections never emit a lonely heading.

help: ## Show quick-start and all targets grouped by section
	@printf '\n\033[1mERPify\033[0m  %s    ENV=\033[36m%s\033[0m  IN_CONTAINER=\033[36m%s\033[0m\n' '$(PROJECT_ROOT)' '$(ENV)' '$(IN_CONTAINER)'
	@printf '\n\033[1mQuick start\033[0m\n'
	@printf '  %-26s %s\n' 'make app.dev'      'Full stack (down → up --wait → fix ownership)'
	@printf '  %-26s %s\n' 'make docker.up'    'Start stack detached, rebuild (ENV-aware)'
	@printf '  %-26s %s\n' 'make docker.down'  'Stop stack and remove orphans'
	@printf '  %-26s %s\n' 'make php.bash'     'Shell into the php container'
	@printf '  %-26s %s\n' 'make app.test'     'All tests (PHP + PWA)'
	@printf '  %-26s %s\n' 'make app.quality'  'All linters (PHP + PWA)'
	@printf '  %-26s %s\n' 'make ci'           'Full CI: lint + test'
	@printf '\n\033[1mKnobs\033[0m\n'
	@printf '  %-26s %s\n' 'ENV=dev|staging|prod'     'Compose overlay (CI uses default)'
	@printf '  %-26s %s\n' "c='...'"                  'Passthrough args (composer/sf/phpunit/vitest)'
	@printf '  %-26s %s\n' 'IN_CONTAINER=false'       'Run PHP on host instead of exec in php container'
	@printf '  %-26s %s\n' "f='...'"                  "Router filter (sf.routes f='...')"
	@printf '  %-26s %s\n' "cmd='...'"                'Command for php.exec'
	@printf '  %-26s %s\n' 'CI_SHARD CI_TOTAL_SHARDS' 'Playwright sharding for pwa.test.e2e'
	@$(MAKE) --no-print-directory help-targets

help-targets:
	@awk ' \
	function emit_heading() { \
		if (pending != shown) { printf "\n\033[33m%s\033[0m\n", pending; shown = pending } \
	} \
	FNR == 1 { \
		pending = FILENAME; \
		sub(/.*\//, "", pending); \
		sub(/\.mk$$/, "", pending); \
	} \
	/^## ——/ { \
		pending = $$0; \
		sub(/^## ——[[:space:]]*/, "", pending); \
		sub(/[[:space:]]—+.*$$/, "", pending); \
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
		emit_heading(); \
		printf "  \033[32m%-28s\033[0m %s\n", targets, desc; \
		next \
	} \
	' $(MAKEFILE_LIST)

.PHONY: help help-targets
