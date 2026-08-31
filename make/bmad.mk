# =============================================================================
# BMAD WORKING ARTIFACTS
# =============================================================================

# Passthrough: c='--strict' / c='--base-ref origin/develop'
BMAD_STATUS_AUDIT := scripts/bmad-status-audit.sh

.PHONY: bmad.status.audit

## —— BMad ——

bmad.status.audit: ## Report stale markers across every sprint-status board (canonical + scoped)
	@$(BMAD_STATUS_AUDIT) $(c)
