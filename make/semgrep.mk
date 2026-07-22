# =============================================================================
# Semgrep — repo-specific taint rules (Docker)
# =============================================================================

# Enforces the api/ bans from the root CLAUDE.md security checklist that no
# vendor ruleset covers. api/src reads user input exclusively through Symfony's
# Request; community and Pro PHP rulesets model only superglobals as taint
# sources, so they see an unreachable source and report nothing on this
# codebase. Rules live in tools/semgrep/rules/, fixtures in tools/semgrep/tests/.
#
# The ruleset is local, so a run needs neither the Semgrep registry nor an
# account — the scan is hermetic and works on a network-isolated job.
#
# Usage:
#   make semgrep.test      # rule fixtures — run this after editing a rule
#   make semgrep.scan      # scan api/src, non-zero exit on any finding
#   make semgrep.sarif     # same scan, SARIF for GitHub code scanning

# --no-git-ignore is load-bearing: without it the scanner skips everything not in
# `git ls-files`, so a new file would pass unscanned until first commit. Semgrep
# still applies its built-in ignores, which drop any `tests/` directory — scoping
# SEMGREP_TARGET at a test tree silently scans nothing.
SEMGREP_IMAGE  ?= semgrep/semgrep:1.169.0
SEMGREP_TARGET ?= api/src
SEMGREP_DIR    := $(PROJECT_ROOT)/.semgrep
SEMGREP_SARIF  := $(SEMGREP_DIR)/erpify-rules.sarif

SEMGREP_SCAN = docker run --rm -v "$(PROJECT_ROOT):/src:ro" -w /src $(SEMGREP_IMAGE) \
	semgrep scan --metrics=off --no-git-ignore --config=tools/semgrep/rules

## —— Semgrep ——

semgrep.test: ## Run the Semgrep rule fixtures (tools/semgrep/tests)
	@cd $(PROJECT_ROOT) && docker run --rm -v "$(PROJECT_ROOT)/tools/semgrep:/s:ro" -w /s \
		$(SEMGREP_IMAGE) semgrep --test --config=rules tests $(c)

semgrep.scan: ## Scan api/src with the ERPify taint rules, fails on any finding; scope with `SEMGREP_TARGET=…`
	@cd $(PROJECT_ROOT) && $(SEMGREP_SCAN) --error $(SEMGREP_TARGET) $(c)

semgrep.sarif: ## Scan and write SARIF to .semgrep/ for GitHub code scanning
	@mkdir -p $(SEMGREP_DIR)
	@cd $(PROJECT_ROOT) && docker run --rm \
		-v "$(PROJECT_ROOT):/src:ro" -v "$(SEMGREP_DIR):/out" -w /src $(SEMGREP_IMAGE) \
		semgrep scan --metrics=off --no-git-ignore --error --config=tools/semgrep/rules \
		--sarif -o /out/$(notdir $(SEMGREP_SARIF)) $(SEMGREP_TARGET) $(c)

.PHONY: semgrep.test semgrep.scan semgrep.sarif
