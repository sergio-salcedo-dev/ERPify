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
# The subject is every sprint-status file, not just the canonical one. Epics are
# tracked per-epic in `sprint-status-<slug>.yaml` by preference, and pinning this
# to `sprint-status.yaml` made those boards invisible to check A for as long as
# they lived: the Shared/Images epic sat `in-progress` with all three stories
# `done` from 2026-08-31 until the retrospective folded it into the canonical
# file, and this audit — which runs from a SessionStart hook — reported clean at
# every start. Measured: `epic-images` had zero occurrences in the canonical file
# on origin/main, and check A fires the moment it is applied to the scoped board.
#
# Discovery, not a list, for the reason every registry here is derived: a scoped
# file is created by whoever starts an epic, and a name they have to remember to
# add somewhere is a name that will be missing exactly when it matters.
#
# Two checks, both offline (no network, no gh, no YAML parser):
#
#   A. Epic consistency  — an epic marked `in-progress` whose stories are all
#      `done`. Pure bookkeeping, needs nothing but the file itself.
#   B. Shipped story     — a story at `review`/`in-progress` whose tag (RM-6,
#      U-4, II-5, AF-1.1…) appears in a commit subject on the base branch AND
#      that commit changed something other than documentation.
#
# The second half of B is not a refinement, it is what makes B mean anything. A
# tag in a subject is a REFERENCE, not evidence: a story is named by the commit
# that writes its context, by the chore that closes its siblings, and by the one
# that argues this audit is wrong about it — none of which ship it. All three
# happened, and each reported a story as shipped while its code did not exist.
# So the subject selects the candidates and the file list decides, which is the
# difference between "somebody wrote the tag" and "the work is on the branch".
#
# Documentation means `*.md` anywhere, `docs/` and `_bmad-output/`. Everything
# else counts — a registry file, a Make target and a config are how some stories
# ship, and an allow-list of source directories would quietly stop seeing them.
#
# The blind spot that buys: a story whose whole deliverable IS documentation now
# passes check B forever, because nothing distinguishes shipping it from merely
# naming it. Check A still catches its epic once every sibling is done, and the
# markers of such a story are the reviewer's to move.
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
readonly ARTIFACTS_DIR="${REPO_ROOT}/_bmad-output/implementation-artifacts"

# The canonical file first so its drift is reported first; the scoped boards in
# name order after it. `nullglob` so an absent glob contributes nothing rather
# than a literal path that no file backs.
shopt -s nullglob
STATUS_FILES=("${ARTIFACTS_DIR}/sprint-status.yaml" "${ARTIFACTS_DIR}"/sprint-status-*.yaml)
shopt -u nullglob
readonly STATUS_FILES

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

present_files=()
for candidate_file in "${STATUS_FILES[@]}"; do
	[[ -f "$candidate_file" ]] && present_files+=("$candidate_file")
done

if (( ${#present_files[@]} == 0 )); then
	echo "bmad-status-audit: no sprint-status file under ${ARTIFACTS_DIR#"${REPO_ROOT}/"} — nothing to audit" >&2
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

# --- Drift accumulates across every sprint-status file ------------------------
# Check B's scan of the base branch is file-independent, so it runs once and is
# shared: the single cheap pass this needs to stay is a property of the scan, not
# of how many boards read it.

epic_drift=()
story_drift=()
unchecked=()

commits=""
if [[ -n "$BASE_REF" ]]; then
	commits="$(git -C "$REPO_ROOT" log --format=$'%H\t%s' "$BASE_REF" 2>/dev/null)"
fi

for STATUS_FILE in "${present_files[@]}"; do
status_rel="${STATUS_FILE#"${REPO_ROOT}/"}"

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
	echo "bmad-status-audit: development_status block of ${status_rel} is empty or unparseable" >&2
	continue
fi

# --- Check A: epic marked in-progress while every one of its stories is done --

current_epic=""
current_epic_status=""
group_total=0
group_done=0

flush_group() {
	[[ -z "$current_epic" ]] && return
	(( group_total == 0 )) && return
	if [[ "$current_epic_status" != "done" ]] && (( group_done == group_total )); then
		epic_drift+=("${status_rel}|${current_epic}|${current_epic_status}|${group_total}")
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

# Whether a path is documentation rather than something that ships.
is_documentation_path() {
	case "$1" in
		*.md|docs/*|_bmad-output/*) return 0 ;;
		*) return 1 ;;
	esac
}

# Whether the commit changed anything that is not documentation. One `git log`
# per candidate, and candidates are the handful of commits whose subject already
# named the tag — the scan over every subject stays the single cheap pass it was,
# which matters because this runs from a SessionStart hook.
#
# `--diff-merges=first-parent` so a merge commit reports the change it brought in
# rather than nothing. Squash merges are the norm here and need no such handling;
# this is what keeps a history that also carries real merges from reading as an
# empty file list, which would silently count as "no code".
commit_touches_code() {
	local sha="$1" path
	while IFS= read -r path; do
		[[ -z "$path" ]] && continue
		is_documentation_path "$path" || return 0
	done < <(git -C "$REPO_ROOT" log -1 --format= --name-only --diff-merges=first-parent "$sha" 2>/dev/null)

	return 1
}

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
		unchecked+=("${status_rel}|${key}|${status}")
		continue
	fi

	[[ -z "$commits" ]] && continue

	# Matched against the whole `<sha>\t<subject>` line, which is safe because
	# every tag this derives carries a `-` and a sha is hex: the sha field cannot
	# produce a hit on its own.
	candidates="$(grep -iE "(^|[^A-Za-z0-9])${tag}([^A-Za-z0-9]|$)" <<<"$commits")"
	[[ -z "$candidates" ]] && continue

	while IFS=$'\t' read -r sha subject; do
		commit_touches_code "$sha" || continue
		story_drift+=("${status_rel}|${key}|${status}|${tag}|${subject}")
		break
	done <<<"$candidates"
done

done

# --- Report -------------------------------------------------------------------

drift_count=$(( ${#epic_drift[@]} + ${#story_drift[@]} ))

# The clean line says what was read and what was checked. It used to read
# "sprint-status.yaml matches origin/main", which claims a comparison this never
# performs: nothing here diffs a file against the base ref, the two checks look
# for drift and the ref is only where check B scans for shipped tags. A line that
# overstates its own subject is how a green gets cited as a guarantee nobody wrote.
if (( drift_count == 0 )); then
	if [[ "$QUIET_WHEN_CLEAN" == false ]]; then
		echo "✓ bmad-status-audit: no stale markers in ${#present_files[@]} sprint-status file(s), checked against ${BASE_REF:-<no base ref>}"
		(( ${#unchecked[@]} > 0 )) && echo "  (${#unchecked[@]} story key(s) carry no commit tag and were not checked)"
	fi
	exit 0
fi

echo "⚠ bmad-status-audit: ${drift_count} stale marker(s)"
echo

drift_files=()
note_drift_file() {
	local candidate="$1" seen
	for seen in ${drift_files[@]+"${drift_files[@]}"}; do
		[[ "$seen" == "$candidate" ]] && return
	done
	drift_files+=("$candidate")
}

for entry in "${epic_drift[@]}"; do
	IFS='|' read -r file epic status total <<<"$entry"
	note_drift_file "$file"
	echo "  epic  ${epic}: ${status} — all ${total} stories are done"
	echo "        ${file}"
done

for entry in "${story_drift[@]}"; do
	IFS='|' read -r file key status tag subject <<<"$entry"
	note_drift_file "$file"
	echo "  story ${key}: ${status} — ${tag} already shipped on ${BASE_REF}"
	echo "        ${subject}"
	echo "        ${file}"
done

echo
echo "  Fix: set the affected keys to 'done' in:"
for file in "${drift_files[@]}"; do
	echo "    ${file}"
done

if (( ${#unchecked[@]} > 0 )); then
	echo
	echo "  Not checked (no commit tag derivable from the key):"
	for entry in "${unchecked[@]}"; do
		IFS='|' read -r file key status <<<"$entry"
		echo "    ${key}: ${status}  (${file})"
	done
fi

[[ "$STRICT" == true ]] && exit 1
exit 0
