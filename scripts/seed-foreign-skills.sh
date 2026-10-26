#!/usr/bin/env bash
# =============================================================================
# Seed the primary checkout's foreign skills into a new worktree
# =============================================================================
#
# Usage: scripts/seed-foreign-skills.sh <primary-checkout> <worktree>
#
# A foreign skill is one another product installs into `.claude/skills` and the
# BMad installer never writes to `.agent/skills` (the set is named in
# scripts/lib/foreign-skills.sh), so the `bmad-*` seed in `make/worktree.mk` —
# which copies from one root — never carries it. Without them a sweep started
# from a worktree answers `Unknown command: /bmad-loop-sweep` and idles to its
# session timeout while `bmad-loop validate` reports ok, and /bmad-tea-* are
# Unknown command.
#
# Each skill is seeded on its own: one the worktree already holds is left
# alone, so a worktree holding some of them still receives the rest. A copy
# that fails is reported on stderr and its partial directory removed, so the
# next run copies it again instead of skipping a half-written skill.
#
# Exit codes: 0 every missing skill was seeded (or there was nothing to seed);
#             1 at least one skill could not be seeded.
#
# Pinned by api/tests/Unit/Gate/ForeignSkillSeedGateTest.php, which runs this
# script over throwaway directories.

set -u

if [ "$#" -ne 2 ]; then
	echo "usage: $0 <primary-checkout> <worktree>" >&2
	exit 1
fi

# shellcheck source=scripts/lib/foreign-skills.sh
. "$(dirname "${BASH_SOURCE[0]}")/lib/foreign-skills.sh"

main=$1
path=$2
status=0

for skill in "$main/.claude/skills"/*/; do
	[ -d "$skill" ] || continue
	name=$(basename "$skill")
	foreign_skill "$name" || continue
	target="$path/.claude/skills/$name"
	[ -e "$target" ] && continue

	if mkdir -p "$path/.claude/skills" 2>/dev/null && cp -a "$skill" "$path/.claude/skills/" 2>/dev/null; then
		echo "→ seeded .claude/skills/$name from the main checkout (installed there by another product, never in .agent/skills)"
	else
		rm -rf "$target" 2>/dev/null
		echo "! could not seed .claude/skills/$name — /$name will be Unknown command in this worktree" >&2
		status=1
	fi
done

exit "$status"
