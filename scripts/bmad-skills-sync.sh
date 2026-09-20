#!/usr/bin/env bash
# =============================================================================
# BMAD skill-tree sync: .agent/skills -> .claude/skills
# =============================================================================
#
# The installer writes its ~92 skills to one root per target IDE, and the roots
# do not agree about who owns them. `.agent/skills` is TRACKED; `.gitignore`
# ignores `/.claude/skills/bmad-*/` and `/.agents/skills/bmad-*/`. So an update
# run from a worktree reaches the primary checkout through git for `.agent/`
# only — the primary's `.claude/skills/bmad-*` is never rewritten by anything.
#
# Measured on 2026-09-20: the 6.12.0 update (#945) ran from a worktree on
# 2026-09-19 and the primary was still serving the 6.10 tree of 2026-07-14 —
# 73 skill directories against 77, 793 lines of `diff -rq`, missing the eight
# skills 6.12 added and still carrying four it retired, three `__pycache__`
# directories and eighteen `validation-report-2026-01-27-*.md` files. Nothing
# went red, because nothing was looking: `make/worktree.mk` seeds every NEW
# worktree from `.agent/`, so the only checkout left wrong is the one that gets
# used the most.
#
# REPLACE, never merge. `cp -a` over the top leaves the retired skills and every
# file the new version stopped shipping, so the tree ends up a union of two
# releases rather than either one.
#
# The guardrail is the reason this is a script and not four lines of recipe.
# A directory under `.claude/skills/bmad-*` that `.agent/` does not have is one
# of two unlike things, and deleting the second is unrecoverable:
#
#   - a skill the installer RETIRED (6.12 dropped `bmad-index-docs`,
#     `bmad-shard-doc`, `bmad-check-implementation-readiness` and
#     `bmad-agent-tech-writer`) — regenerable, safe to drop;
#   - a skill somebody WROTE and dropped in by hand, which exists nowhere else
#     and which no reinstall brings back.
#
# Git tells them apart, so this does not guess: `.agent/skills` is tracked, so a
# retired skill HAS history at that path and a hand-written one has none. That
# distinction is not hypothetical — `git-worktree-code-review` lived only in the
# `.qwen` tree and was found by a human reading a diff, one step from being
# deleted with it.
#
# Usage:  scripts/bmad-skills-sync.sh [--dry-run] [--force]
#           --dry-run  report drift and change nothing
#           --force    sync even when a hand-written skill would be removed
# Exit:   0 already in sync, or synced
#         1 drift found (--dry-run), or a hand-written skill blocks the sync
#         2 could not run (no source tree, or the result did not verify)
# =============================================================================

set -euo pipefail
shopt -s nullglob

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
SOURCE="${ROOT}/.agent/skills"
TARGET="${ROOT}/.claude/skills"
BACKUP_DIR="${ROOT}/tmp"

dry_run=false
force=false

while [ $# -gt 0 ]; do
	case "$1" in
	--dry-run) dry_run=true ;;
	--force) force=true ;;
	-h | --help)
		sed -n '/^# Usage:/,/^# Exit:/p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
		exit 0
		;;
	*)
		printf '✗ unknown argument: %s (try --help)\n' "$1" >&2
		exit 2
		;;
	esac
	shift
done

# ---------------------------------------------------------------------------
# Inventory
# ---------------------------------------------------------------------------

source_names=()
for d in "${SOURCE}"/bmad-*/; do
	source_names+=("$(basename "$d")")
done

if [ ${#source_names[@]} -eq 0 ]; then
	printf '✗ no bmad-* skills under %s\n' "${SOURCE}" >&2
	printf '  .agent/skills is tracked — a checkout without it is broken, not stale.\n' >&2
	exit 2
fi

target_names=()
for d in "${TARGET}"/bmad-*/; do
	target_names+=("$(basename "$d")")
done

# ---------------------------------------------------------------------------
# Guardrail: what would be removed, and was it ever the installer's?
# ---------------------------------------------------------------------------

retired=()
foreign=()
for name in "${target_names[@]}"; do
	[ -d "${SOURCE}/${name}" ] && continue
	if [ -n "$(git -C "${ROOT}" log --oneline -1 -- ".agent/skills/${name}" 2>/dev/null)" ]; then
		retired+=("${name}")
	else
		foreign+=("${name}")
	fi
done

if [ ${#foreign[@]} -gt 0 ] && [ "${force}" = false ]; then
	printf '✗ %d skill(s) under .claude/skills were never tracked in .agent/skills:\n' "${#foreign[@]}" >&2
	printf '    • %s\n' "${foreign[@]}" >&2
	printf '  No reinstall brings these back. Move them somewhere tracked, or pass --force\n' >&2
	printf '  to archive them into the backup and remove them.\n' >&2
	exit 1
fi

# ---------------------------------------------------------------------------
# Drift
# ---------------------------------------------------------------------------

added=()
changed=()
for name in "${source_names[@]}"; do
	if [ ! -d "${TARGET}/${name}" ]; then
		added+=("${name}")
	elif ! diff -rq "${SOURCE}/${name}" "${TARGET}/${name}" >/dev/null 2>&1; then
		changed+=("${name}")
	fi
done

total=$((${#added[@]} + ${#changed[@]} + ${#retired[@]} + ${#foreign[@]}))

if [ "${total}" -eq 0 ]; then
	printf '✓ bmad-skills-sync: .claude/skills matches .agent/skills (%d skills)\n' "${#source_names[@]}"
	exit 0
fi

printf 'drift against .agent/skills — %d added, %d changed, %d retired, %d hand-written\n' \
	"${#added[@]}" "${#changed[@]}" "${#retired[@]}" "${#foreign[@]}"
[ ${#added[@]} -gt 0 ] && printf '  + %s\n' "${added[@]}"
[ ${#retired[@]} -gt 0 ] && printf '  - %s\n' "${retired[@]}"
[ ${#foreign[@]} -gt 0 ] && printf '  ! %s (hand-written, removed under --force)\n' "${foreign[@]}"
[ ${#changed[@]} -gt 0 ] && printf '  ~ %d skill(s) differ in content\n' "${#changed[@]}"

if [ "${dry_run}" = true ]; then
	printf '→ run: make bmad.skills.sync — replaces the tree\n'
	exit 1
fi

# ---------------------------------------------------------------------------
# Replace
# ---------------------------------------------------------------------------

if [ ${#target_names[@]} -gt 0 ]; then
	mkdir -p "${BACKUP_DIR}"
	backup="${BACKUP_DIR}/bmad-skills-backup-$(date +%Y%m%d-%H%M%S).tar.gz"
	tar czf "${backup}" -C "${ROOT}" ".claude/skills"
	printf '→ backed up the existing tree to %s\n' "${backup#"${ROOT}"/}"
	for name in "${target_names[@]}"; do
		rm -rf -- "${TARGET:?}/${name}"
	done
fi

mkdir -p "${TARGET}"
cp -a "${SOURCE}"/bmad-*/ "${TARGET}/"

# ---------------------------------------------------------------------------
# Verify: the run proves its own result, it does not assume it
# ---------------------------------------------------------------------------

for name in "${source_names[@]}"; do
	if ! diff -rq "${SOURCE}/${name}" "${TARGET}/${name}" >/dev/null 2>&1; then
		printf '✗ %s still differs after the copy — the tree is half-written\n' "${name}" >&2
		exit 2
	fi
done

printf '✓ bmad-skills-sync: %d skills replaced from .agent/skills\n' "${#source_names[@]}"
