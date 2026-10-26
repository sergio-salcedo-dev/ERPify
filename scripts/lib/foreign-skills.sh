# shellcheck shell=bash
# =============================================================================
# Skills that live in .claude/skills but that the BMad installer does not write
# =============================================================================
#
# Sourced by scripts/bmad-skills-sync.sh and scripts/seed-foreign-skills.sh, so
# both read the same set: the sync must never inventory these (its replace path
# rm -rf's every inventoried name, and no reinstall of BMad brings them back),
# and the worktree seed must copy them from the primary's .claude/skills, since
# the bmad-* seed in make/worktree.mk copies from .agent/skills, which never
# holds them.
#
#   bmad-loop-*          `bmad-loop init` installs them.
#   bmad-tea, bmad-tea-* TEA 3 (bmad-method-test-architecture-enterprise >= 2),
#   bmod-tea             a v7 "bmod" the 6.12 installer cannot install; added
#                        with `npx skills add`, recorded in skills-lock.json.
#
# Named rather than derived: `customize.toml` is absent from 18 of the 75 BMad
# skills, so "no customize.toml => foreign" would exempt real ones. Every other
# unrecognised bmad-* still aborts the sync, so a product arriving here unnamed
# fails closed and visibly. `bmad-tea-*` needs its dash: TEA 1's
# `bmad-teach-me-testing` is installer output and must stay in the sync.

foreign_skill() {
	case "$1" in
	bmad-loop-* | bmad-tea | bmad-tea-* | bmod-tea) return 0 ;;
	*) return 1 ;;
	esac
}
