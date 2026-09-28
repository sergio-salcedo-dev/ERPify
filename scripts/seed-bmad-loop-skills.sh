#!/usr/bin/env bash
# =============================================================================
# Seed the bmad-loop-* skills of the primary checkout into a new worktree
# =============================================================================
#
# Usage: scripts/seed-bmad-loop-skills.sh <primary-checkout> <worktree>
#
# `bmad-loop init` installs its skills into `.claude/skills` only, and the BMad
# installer never writes them to `.agent/skills`, so the `bmad-*` seed in
# `make/worktree.mk` — which copies from one root — never carries them. Without
# them a sweep started from a worktree answers `Unknown command:
# /bmad-loop-sweep` and idles to its session timeout while `bmad-loop validate`
# reports ok.
#
# Each skill is seeded on its own: one the worktree already holds is left
# alone, so a worktree holding some of them still receives the rest. A copy
# that fails is reported on stderr and its partial directory removed, so the
# next run copies it again instead of skipping a half-written skill.
#
# Exit codes: 0 every missing skill was seeded (or there was nothing to seed);
#             1 at least one skill could not be seeded.
#
# Pinned by api/tests/Unit/Gate/BmadLoopSkillSeedGateTest.php, which runs this
# script over throwaway directories.

set -u

if [ "$#" -ne 2 ]; then
	echo "usage: $0 <primary-checkout> <worktree>" >&2
	exit 1
fi

main=$1
path=$2
status=0

for skill in "$main/.claude/skills"/bmad-loop-*/; do
	[ -d "$skill" ] || continue
	name=$(basename "$skill")
	target="$path/.claude/skills/$name"
	[ -e "$target" ] && continue

	if mkdir -p "$path/.claude/skills" 2>/dev/null && cp -a "$skill" "$path/.claude/skills/" 2>/dev/null; then
		echo "→ seeded .claude/skills/$name from the main checkout (bmad-loop init installs it there, never in .agent/skills)"
	else
		rm -rf "$target" 2>/dev/null
		echo "! could not seed .claude/skills/$name — /bmad-loop-* will be Unknown command in this worktree" >&2
		status=1
	fi
done

exit "$status"
