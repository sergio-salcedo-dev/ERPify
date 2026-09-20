# =============================================================================
# BMAD WORKING ARTIFACTS
# =============================================================================

# Passthrough: c='--strict' / c='--base-ref origin/develop'
BMAD_STATUS_AUDIT := scripts/bmad-status-audit.sh

# Passthrough: c='--force'
BMAD_SKILLS_SYNC := scripts/bmad-skills-sync.sh

.PHONY: bmad.status.audit bmad.skills.sync bmad.skills.sync.dry-run

## —— BMad ——

bmad.status.audit: ## Report stale markers across every sprint-status board (canonical + scoped)
	@cd "$(PROJECT_ROOT)" && $(BMAD_STATUS_AUDIT) $(c)

bmad.skills.sync.dry-run: ## Report drift between .claude/skills/bmad-* and the tracked .agent/skills
	@cd "$(PROJECT_ROOT)" && $(BMAD_SKILLS_SYNC) --dry-run $(c)

bmad.skills.sync: ## Replace .claude/skills/bmad-* from the tracked .agent/skills; pass c='--force' (destructive)
	@cd "$(PROJECT_ROOT)" && $(BMAD_SKILLS_SYNC) $(c)
