# =============================================================================
# CodeQL static analysis (local)
# =============================================================================

# Local counterpart to the .github/workflows/codeql.yml CI job, which covers the
# PWA (JS/TS) + GitHub Actions. These targets run the same analysis on your
# machine without GitHub Advanced Security. The CodeQL CLI is free under the
# GitHub CodeQL Terms & Conditions for non-automated, local use against your own
# code.
#
# Scope is limited to CodeQL-supported languages: PHP has no CodeQL analyzer, so
# api/ cannot be analysed by these targets at any CODEQL_SOURCE_ROOT.
#
# Usage:
#   make codeql.run        # install → build db → analyze → report
#   make codeql.report     # re-print summary from the last SARIF
#   make codeql.clean      # nuke .codeql/

CODEQL_DIR             ?= $(PROJECT_ROOT)/.codeql
CODEQL_BUNDLE_TAG      ?= latest
CODEQL_PLATFORM        := $(shell uname -s | tr '[:upper:]' '[:lower:]')
CODEQL_ARCH            := $(shell uname -m)

ifeq ($(CODEQL_PLATFORM),darwin)
  CODEQL_BUNDLE_FILE   := codeql-bundle-osx64.tar.gz
else ifeq ($(CODEQL_ARCH),aarch64)
  CODEQL_BUNDLE_FILE   := codeql-bundle-linux-arm64.tar.gz
else ifeq ($(CODEQL_ARCH),arm64)
  CODEQL_BUNDLE_FILE   := codeql-bundle-linux-arm64.tar.gz
else
  CODEQL_BUNDLE_FILE   := codeql-bundle-linux64.tar.gz
endif

ifeq ($(CODEQL_BUNDLE_TAG),latest)
  CODEQL_BUNDLE_URL    := https://github.com/github/codeql-action/releases/latest/download/$(CODEQL_BUNDLE_FILE)
else
  CODEQL_BUNDLE_URL    := https://github.com/github/codeql-action/releases/download/$(CODEQL_BUNDLE_TAG)/$(CODEQL_BUNDLE_FILE)
endif

CODEQL_HOME             = $(CODEQL_DIR)/codeql
CODEQL                  = $(CODEQL_HOME)/codeql
CODEQL_DB               = $(CODEQL_DIR)/db-pwa
CODEQL_SARIF            = $(CODEQL_DIR)/results.sarif
# Mirrors the CI job's suite so local and CI runs report the same findings.
CODEQL_QUERIES         ?= javascript-security-extended.qls
CODEQL_SOURCE_ROOT     ?= $(PWA_ROOT)
CODEQL_LANGUAGE        ?= javascript-typescript
CODEQL_PATHS_IGNORE    ?= **/node_modules,**/.next,**/dist,**/build,**/tests

codeql.install: ## Download CodeQL CLI bundle (CLI + standard packs) into .codeql/
	@mkdir -p $(CODEQL_DIR)
	@if [ -x "$(CODEQL)" ]; then \
		printf '✓ CodeQL already installed → '; "$(CODEQL)" version --format=terse; \
	else \
		echo "→ downloading $(CODEQL_BUNDLE_URL)"; \
		curl -fsSL --retry 3 -o "$(CODEQL_DIR)/bundle.tar.gz" "$(CODEQL_BUNDLE_URL)"; \
		tar -xzf "$(CODEQL_DIR)/bundle.tar.gz" -C "$(CODEQL_DIR)"; \
		rm -f "$(CODEQL_DIR)/bundle.tar.gz"; \
		printf '✓ installed → '; "$(CODEQL)" version --format=terse; \
	fi

codeql.db: codeql.install ## Build the CodeQL database from $(CODEQL_SOURCE_ROOT)
	@rm -rf "$(CODEQL_DB)"
	"$(CODEQL)" database create "$(CODEQL_DB)" \
		--language=$(CODEQL_LANGUAGE) \
		--source-root="$(CODEQL_SOURCE_ROOT)" \
		--overwrite

codeql.analyze: codeql.db ## Run security-extended queries → .codeql/results.sarif
	"$(CODEQL)" database analyze "$(CODEQL_DB)" $(CODEQL_QUERIES) \
		--format=sarifv2.1.0 \
		--output="$(CODEQL_SARIF)" \
		--sarif-category="/language:$(CODEQL_LANGUAGE)"

codeql.report: ## Print human-readable summary of the latest SARIF
	@if [ ! -f "$(CODEQL_SARIF)" ]; then \
		echo "✗ no $(CODEQL_SARIF) — run: make codeql.analyze" >&2; exit 1; \
	fi
	@if ! command -v jq >/dev/null 2>&1; then \
		echo "(install jq for a readable summary; raw SARIF at $(CODEQL_SARIF))"; \
		exit 0; \
	fi
	@total=$$(jq '[.runs[].results[]] | length' "$(CODEQL_SARIF)"); \
	if [ "$$total" = "0" ]; then \
		printf '\n✓ no findings\n\nSARIF: %s\n' "$(CODEQL_SARIF)"; exit 0; \
	fi; \
	printf '\n── %s findings ──\n' "$$total"; \
	jq -r '.runs[].results[] | [(.properties."problem.severity" // .level // "note"), .ruleId, "\(.locations[0].physicalLocation.artifactLocation.uri):\(.locations[0].physicalLocation.region.startLine)", .message.text] | @tsv' "$(CODEQL_SARIF)" \
		| awk -F'\t' '{ printf "  [%s] %s\n    %s\n    %s\n", $$1, $$2, $$3, $$4 }'; \
	printf '\n── totals by rule ──\n'; \
	jq -r '.runs[].results[].ruleId' "$(CODEQL_SARIF)" | sort | uniq -c | sort -rn; \
	printf '\nSARIF: %s\n' "$(CODEQL_SARIF)"

codeql.run: codeql.analyze codeql.report ## Full local run: install → db → analyze → report

codeql.clean: ## Remove .codeql/ entirely (CLI bundle + database + SARIF)
	rm -rf "$(CODEQL_DIR)"

.PHONY: codeql.install codeql.db codeql.analyze codeql.report codeql.run codeql.clean
