# =============================================================================
# BMAD WORKING ARTIFACTS
# =============================================================================

# Passthrough: c='--strict' / c='--base-ref origin/develop'
BMAD_STATUS_AUDIT := scripts/bmad-status-audit.sh

# Passthrough: c='--force' / c='--root <path>' / c='--quiet-when-clean'
BMAD_SKILLS_SYNC := scripts/bmad-skills-sync.sh

## —— BMad ——

bmad.status.audit: ## Report stale markers across every sprint-status board (canonical + scoped)
	@cd "$(PROJECT_ROOT)" && $(BMAD_STATUS_AUDIT) $(c)

# The script exits 1 on drift, which is this target's NORMAL and useful outcome —
# letting it through would print `make: *** Error 1` on every run that found
# something, over a check the docs call "look first". Exit 2 still fails.
bmad.skills.sync.dry-run: ## Report drift between the primary checkout's .claude/skills/bmad-* and its tracked .agent/skills; pass c='…'
	@cd "$(PROJECT_ROOT)" && { $(BMAD_SKILLS_SYNC) --dry-run $(c) || [ $$? -eq 1 ]; }

bmad.skills.sync: ## Replace the primary checkout's .claude/skills/bmad-* from its tracked .agent/skills; pass c='--force' (destructive)
	@cd "$(PROJECT_ROOT)" && $(BMAD_SKILLS_SYNC) $(c)

.PHONY: bmad.status.audit bmad.skills.sync bmad.skills.sync.dry-run
