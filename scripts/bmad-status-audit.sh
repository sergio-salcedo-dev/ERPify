#!/usr/bin/env bash
# =============================================================================
# BMAD sprint-status drift audit
# =============================================================================
#
# Answers one question: does sprint-status.yaml still describe reality?
#
# A story ships when its PR is squash-merged on GitHub. Nothing in that path
# moves the marker in sprint-status.yaml, so the file drifts silently — the
# rbac epic read `in-progress` for 11 days after its last story merged, and
# rm-3/rm-4/rm-6 each sat at `review` while already in main.
#
# Two checks, both offline (no network, no gh, no YAML parser):
#
#   A. Epic consistency  — an epic marked `in-progress` whose stories are all
#      `done`. Pure bookkeeping, needs nothing but the file itself.
#   B. Shipped story     — a story at `review`/`in-progress` whose tag (RM-6,
#      U-4, II-5, AF-1.1…) appears in a commit subject on the base branch.
#
# Check B only sees stories whose key carries a letter prefix, because that is
# what the commit convention puts in the subject: `feat(iam): … (U-4) (#508)`.
# Purely numeric keys (E1/E2 style `1-7`, `2-3`) have no such tag — and E2
# shipped as a single PR with no per-story tag at all. Those are reported as
# unchecked rather than silently passed.
#
# Exit codes: 0 always, unless --strict (then 1 on drift). Advisory by design:
# it runs from a SessionStart hook and must never block a session.

set -uo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
readonly SCRIPT_DIR REPO_ROOT
readonly STATUS_FILE="${REPO_ROOT}/_bmad-output/implementation-artifacts/sprint-status.yaml"

STRICT=false
QUIET_WHEN_CLEAN=false
BASE_REF=""

usage() {
	cat <<'EOF'
Usage: bmad-status-audit.sh [options]

  --strict             exit 1 when drift is found (default: always exit 0)
  --quiet-when-clean   print nothing when there is no drift
  --base-ref <ref>     git ref to scan for shipped stories
                       (default: origin/main, falling back to main)
  -h, --help           show this help
EOF
}

while [[ $# -gt 0 ]]; do
	case "$1" in
		--strict) STRICT=true; shift ;;
		--quiet-when-clean) QUIET_WHEN_CLEAN=true; shift ;;
		--base-ref) BASE_REF="${2:-}"; shift 2 ;;
		-h|--help) usage; exit 0 ;;
		*) echo "unknown option: $1" >&2; usage >&2; exit 2 ;;
	esac
done

if [[ ! -f "$STATUS_FILE" ]]; then
	echo "bmad-status-audit: no sprint-status.yaml at ${STATUS_FILE} — nothing to audit" >&2
	exit 0
fi

# Resolve the ref to scan. A worktree may have no remote-tracking ref yet.
if [[ -z "$BASE_REF" ]]; then
	for candidate in origin/main main; do
		if git -C "$REPO_ROOT" rev-parse --verify --quiet "$candidate" >/dev/null; then
			BASE_REF="$candidate"
			break
		fi
	done
fi

# --- Read development_status into ordered parallel arrays ---------------------
# File order is load-bearing: stories are grouped under the epic key that
# precedes them, which is the only link between an epic and its stories (the
# keys share no common substring — `epic-rbac-authorization-model` owns `rm-*`).

keys=()
statuses=()

while IFS= read -r line; do
	[[ "$line" =~ ^[[:space:]]*# ]] && continue
	[[ "$line" =~ ^[[:space:]]*$ ]] && continue
	if [[ "$line" =~ ^[[:space:]]{2}([A-Za-z0-9._-]+):[[:space:]]*([A-Za-z0-9._-]+)[[:space:]]*$ ]]; then
		keys+=("${BASH_REMATCH[1]}")
		statuses+=("${BASH_REMATCH[2]}")
	fi
done < <(sed -n '/^development_status:/,$p' "$STATUS_FILE")

if [[ ${#keys[@]} -eq 0 ]]; then
	echo "bmad-status-audit: development_status block is empty or unparseable" >&2
	exit 0
fi

# --- Check A: epic marked in-progress while every one of its stories is done --

epic_drift=()
current_epic=""
current_epic_status=""
group_total=0
group_done=0

flush_group() {
	[[ -z "$current_epic" ]] && return
	(( group_total == 0 )) && return
	if [[ "$current_epic_status" != "done" ]] && (( group_done == group_total )); then
		epic_drift+=("${current_epic}|${current_epic_status}|${group_total}")
	fi
}

for i in "${!keys[@]}"; do
	key="${keys[$i]}"
	status="${statuses[$i]}"

	if [[ "$key" == epic-* && "$key" != *-retrospective ]]; then
		flush_group
		current_epic="$key"
		current_epic_status="$status"
		group_total=0
		group_done=0
		continue
	fi

	[[ "$key" == *-retrospective ]] && continue
	[[ -z "$current_epic" ]] && continue

	(( group_total++ ))
	[[ "$status" == "done" ]] && (( group_done++ ))
done
flush_group

# --- Check B: story not done, yet its tag is already on the base branch -------

story_drift=()
unchecked=()

subjects=""
if [[ -n "$BASE_REF" ]]; then
	subjects="$(git -C "$REPO_ROOT" log --format=%s "$BASE_REF" 2>/dev/null)"
fi

# `rm-6-gate-ocp…` → RM-6 · `u-5a-cerrar…` → U-5a · `af-1-1-user…` → AF-1.1
story_tag() {
	local key="$1"
	if [[ "$key" =~ ^([a-z]{1,3})-([0-9]+)-([0-9]+)- ]]; then
		printf '%s-%s.%s' "$(tr '[:lower:]' '[:upper:]' <<<"${BASH_REMATCH[1]}")" "${BASH_REMATCH[2]}" "${BASH_REMATCH[3]}"
	elif [[ "$key" =~ ^([a-z]{1,3})-([0-9]+[a-z]?)- ]]; then
		printf '%s-%s' "$(tr '[:lower:]' '[:upper:]' <<<"${BASH_REMATCH[1]}")" "${BASH_REMATCH[2]}"
	fi
}

for i in "${!keys[@]}"; do
	key="${keys[$i]}"
	status="${statuses[$i]}"

	[[ "$key" == epic-* ]] && continue
	[[ "$key" == *-retrospective ]] && continue
	[[ "$status" == "done" || "$status" == "backlog" ]] && continue

	tag="$(story_tag "$key")"
	if [[ -z "$tag" ]]; then
		unchecked+=("${key}|${status}")
		continue
	fi

	if [[ -n "$subjects" ]] && grep -qiE "(^|[^A-Za-z0-9])${tag}([^A-Za-z0-9]|$)" <<<"$subjects"; then
		subject="$(grep -iE "(^|[^A-Za-z0-9])${tag}([^A-Za-z0-9]|$)" <<<"$subjects" | head -1)"
		story_drift+=("${key}|${status}|${tag}|${subject}")
	fi
done

# --- Report -------------------------------------------------------------------

drift_count=$(( ${#epic_drift[@]} + ${#story_drift[@]} ))

if (( drift_count == 0 )); then
	if [[ "$QUIET_WHEN_CLEAN" == false ]]; then
		echo "✓ bmad-status-audit: sprint-status.yaml matches ${BASE_REF:-<no base ref>}"
		(( ${#unchecked[@]} > 0 )) && echo "  (${#unchecked[@]} story key(s) carry no commit tag and were not checked)"
	fi
	exit 0
fi

echo "⚠ bmad-status-audit: ${drift_count} stale marker(s) in sprint-status.yaml"
echo

for entry in "${epic_drift[@]}"; do
	IFS='|' read -r epic status total <<<"$entry"
	echo "  epic  ${epic}: ${status} — all ${total} stories are done"
done

for entry in "${story_drift[@]}"; do
	IFS='|' read -r key status tag subject <<<"$entry"
	echo "  story ${key}: ${status} — ${tag} already shipped on ${BASE_REF}"
	echo "        ${subject}"
done

echo
echo "  Fix: set the affected keys to 'done' in ${STATUS_FILE#"${REPO_ROOT}/"}"

if (( ${#unchecked[@]} > 0 )); then
	echo
	echo "  Not checked (no commit tag derivable from the key):"
	for entry in "${unchecked[@]}"; do
		IFS='|' read -r key status <<<"$entry"
		echo "    ${key}: ${status}"
	done
fi

[[ "$STRICT" == true ]] && exit 1
exit 0
