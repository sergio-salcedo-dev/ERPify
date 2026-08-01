#!/usr/bin/env bash
# =============================================================================
# Worktree / branch collision check
# =============================================================================
#
# Answers one question at session start: is this checkout mine alone?
#
# The worktree rule in CLAUDE.md isolates FILES. It does not reserve a BRANCH.
# Two sessions pointed at the same branch name share the object store, the
# reflog and the remote, so the second one commits into the first one's work
# and neither is told. That is exactly how a G-1b session found three commits
# it had not authored sitting on its own branch, mid-task.
#
# Three checks, all offline and read-only:
#
#   A. Wrong checkout — the session is in the primary clone, not a linked
#      worktree. CLAUDE.md makes a worktree mandatory for any multi-edit task,
#      and the primary is the shared surface where concurrent git operations
#      destroy uncommitted work.
#   B. Not clean on arrival — a worktree handed to a fresh session should have
#      nothing staged or modified. Anything here means somebody else is
#      already working in this directory, or a previous session left it dirty.
#   C. Fresh commits — the branch HEAD is younger than the threshold, so
#      another writer is committing to this branch right now.
#
# B is the one that catches a live collision soonest: a second writer shows up
# as working-tree churn long before it commits.
#
# Advisory by design — it runs from a SessionStart hook and must never block a
# session. Exit code is always 0 unless --strict is passed.
# =============================================================================

set -uo pipefail

FRESH_COMMIT_MINUTES="${WORKTREE_COLLISION_FRESH_MINUTES:-60}"
QUIET_WHEN_CLEAN=false
STRICT=false

usage() {
	cat <<'EOF'
usage: worktree-collision-check.sh [options]

  --quiet-when-clean   print nothing when there is no collision signal
  --strict             exit 1 when a signal is found (default: always 0)
  --fresh-minutes N    treat a branch HEAD younger than N minutes as fresh
                       (default 60, or $WORKTREE_COLLISION_FRESH_MINUTES)
EOF
}

while (( $# > 0 )); do
	case "$1" in
		--quiet-when-clean) QUIET_WHEN_CLEAN=true; shift ;;
		--strict) STRICT=true; shift ;;
		--fresh-minutes) FRESH_COMMIT_MINUTES="${2:-60}"; shift 2 ;;
		-h|--help) usage; exit 0 ;;
		*) echo "unknown option: $1" >&2; usage >&2; exit 2 ;;
	esac
done

git rev-parse --is-inside-work-tree >/dev/null 2>&1 || {
	echo "worktree-collision-check: not inside a git work tree — nothing to check" >&2
	exit 0
}

# SessionStart delivers {"source": "startup"|"resume"|"clear"} on stdin. Check B asks whether the
# checkout was clean when this session ARRIVED, which is only meaningful on a real startup: on a
# resume the dirt is your own work from a minute ago, and an alarm that fires every time is an alarm
# nobody reads. The field is absent when the script is run by hand or by a harness that omits it —
# default to running the check, so a missing field never silently disables it.
SESSION_SOURCE=""
if [[ ! -t 0 ]]; then
	stdin_payload="$(timeout 2 cat 2>/dev/null || true)"
	if [[ -n "${stdin_payload}" ]] && command -v jq >/dev/null 2>&1; then
		SESSION_SOURCE="$(jq -r '.source // empty' <<<"${stdin_payload}" 2>/dev/null || true)"
	fi
fi

TOPLEVEL="$(git rev-parse --show-toplevel)"
BRANCH="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo '<detached>')"
GIT_DIR="$(git rev-parse --absolute-git-dir)"
COMMON_DIR="$(cd "$(git rev-parse --git-common-dir)" && pwd)"

# A linked worktree keeps its own .git dir under <common>/worktrees/<name>; the
# primary clone is the one where the two paths coincide.
IN_LINKED_WORKTREE=true
[[ "${GIT_DIR}" == "${COMMON_DIR}" ]] && IN_LINKED_WORKTREE=false

signals=()

if [[ "${IN_LINKED_WORKTREE}" == false ]]; then
	signals+=("primary|You are in the PRIMARY checkout (${TOPLEVEL}), not a worktree.
        CLAUDE.md requires a worktree for any feature or multi-edit task.
        Create one:  make worktree.create BRANCH=<type>/<scope>-<slug>")
fi

dirty_count=0
[[ "${SESSION_SOURCE}" != "resume" ]] && dirty_count="$(git status --porcelain 2>/dev/null | wc -l | tr -d ' ')"
if (( dirty_count > 0 )); then
	sample="$(git status --porcelain 2>/dev/null | head -5 | sed 's/^/          /')"
	more=""
	(( dirty_count > 5 )) && more="
          … and $(( dirty_count - 5 )) more"
	signals+=("dirty|This checkout is NOT clean on arrival — ${dirty_count} changed path(s).
        A worktree handed to a fresh session should be empty. Someone else may
        be working here; look before you edit or commit.
${sample}${more}")
fi

if head_epoch="$(git log -1 --format=%ct 2>/dev/null)" && [[ -n "${head_epoch}" ]]; then
	age_minutes=$(( ( $(date +%s) - head_epoch ) / 60 ))
	if (( age_minutes < FRESH_COMMIT_MINUTES )); then
		head_line="$(git log -1 --format='%h %an — %s' 2>/dev/null)"
		signals+=("fresh|Branch '${BRANCH}' has a commit from ${age_minutes} minute(s) ago:
          ${head_line}
        If that commit is not yours, another session is writing to this branch.
        A worktree isolates files, never the branch.")
	fi
fi

if (( ${#signals[@]} == 0 )); then
	if [[ "${QUIET_WHEN_CLEAN}" == false ]]; then
		echo "✓ worktree-collision-check: ${TOPLEVEL} on '${BRANCH}' — linked worktree, clean, no fresh commits"
	fi
	exit 0
fi

echo "⚠ worktree-collision-check: ${#signals[@]} collision signal(s)"
echo
echo "  cwd    ${TOPLEVEL}"
echo "  branch ${BRANCH}"
echo
for signal in "${signals[@]}"; do
	echo "  ${signal#*|}"
	echo
done
echo "  Registered worktrees:"
git worktree list | sed 's/^/    /'
echo

[[ "${STRICT}" == true ]] && exit 1
exit 0
