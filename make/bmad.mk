# =============================================================================
# BMAD WORKING ARTIFACTS
# =============================================================================

# Passthrough: c='--strict' / c='--base-ref origin/develop'
BMAD_STATUS_AUDIT := scripts/bmad-status-audit.sh
ADVERSARIAL_PASS_CHECK := scripts/adversarial-pass-check.sh

.PHONY: bmad.status.audit bmad.adversarial.check bmad.adversarial.self-test

## —— BMad ——

bmad.status.audit: ## Report stale markers across every sprint-status board (canonical + scoped)
	@$(BMAD_STATUS_AUDIT) $(c)

# The same instrument the PreToolUse hook runs when a pull request is about to
# be opened. Runnable by hand so the verdict is inspectable before the hook ever
# fires. Passthrough: c='--strict' / c='--base-ref origin/develop'
bmad.adversarial.check: ## Report whether this branch carries its adversarial-pass record
	@$(ADVERSARIAL_PASS_CHECK) $(c)

bmad.adversarial.self-test: ## Prove the adversarial-pass gate fails in both directions
	@$(ADVERSARIAL_PASS_CHECK) --self-test
