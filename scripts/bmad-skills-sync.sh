#!/usr/bin/env bash
# =============================================================================
# BMAD skill-tree sync: .agent/skills -> .claude/skills, in the PRIMARY checkout
# =============================================================================
#
# The installer writes its skills to one root per target IDE, and the roots do
# not agree about ownership. `.agent/skills` is TRACKED; `.gitignore` ignores
# `/.claude/skills/bmad-*/`. So an update run from a worktree reaches every other
# checkout through git for `.agent/` only — the primary's `.claude/skills/bmad-*`
# is never rewritten by anything, and `make/worktree.mk` seeds each NEW worktree
# at creation, so the one checkout left wrong is the one most used.
#
# Measured 2026-09-20, the day after the 6.12.0 update (#945, `3d3ef122`,
# 2026-09-19): the primary was still serving a tree roughly two months old —
# 73 skill directories against 77, 790+ lines of `diff -rq`, eight skills
# missing and four retired ones still answering. (The last bulk `.agent/skills`
# commit before the update is `4c5bb241`, 2026-07-09.)
#
# IT ACTS ON THE PRIMARY CHECKOUT, whichever checkout invoked it. That is not a
# convenience: `CLAUDE.md` requires every multi-edit task to happen in a
# worktree, worktrees are seeded correctly at creation, so a run scoped to its
# own checkout answers "already in sync" from the one place that never drifts
# while the primary stays stale — measured, and it is the defect this exists to
# end. `--root <path>` overrides for the case where you mean another checkout.
#
# REPLACE, never merge. `cp -a` over the top leaves the retired skills and every
# file the new version stopped shipping, so the tree ends up the union of two
# releases rather than either one.
#
# WHAT THE GUARDRAIL DOES AND DOES NOT SEPARATE
#
# A `bmad-*` directory under `.claude/skills` that `.agent/` does not have is
# either a skill the installer RETIRED (regenerable) or one somebody WROTE by
# hand, which exists nowhere else. Git is asked, but asking it whether the PATH
# has history is not enough and the first version of this script got that wrong:
# hand-written content sitting at a path the installer once occupied classified
# as `retired` and was deleted with exit 0, no refusal — measured. Path history
# separates PATHS, not AUTHORS. So the test is content: a directory absent from
# the source is `retired` only when its bytes still match what git last recorded
# at that path. Anything else is `foreign` and stops the run.
#
# Its remaining bound, stated rather than discovered: the universe is
# `bmad-*/`, so a hand-written skill under any other name is invisible here —
# it is protected by the glob, not by this check. `git-worktree-code-review`,
# cited in earlier versions of this comment as the precedent, is exactly that
# case and could never have reached this guardrail.
#
# Usage:  scripts/bmad-skills-sync.sh [--dry-run] [--force] [--quiet-when-clean]
#                                     [--root <path>]
#           --dry-run            report drift and change nothing
#           --force              sync even when a hand-written skill would go
#           --quiet-when-clean   print nothing when already in sync
#           --root <path>        act on this checkout instead of the primary
#
# Exit:   0  already in sync, or synced
#         1  drift found (--dry-run), or a hand-written skill blocks the sync
#         2  could not run, or could not finish — see the message
#
# A green proves the `bmad-*` directories match by content, ignoring the runtime
# residue listed in PRUNE below. It proves nothing about the other skills in
# either root, and nothing about `.agents/skills`, which holds no `bmad-*` at all.
# =============================================================================

set -euo pipefail
shopt -s nullglob

PRUNE=(-x '__pycache__' -x '*.pyc')
KEEP_BACKUPS=5

dry_run=false
force=false
quiet_when_clean=false
root_override=""

while [ $# -gt 0 ]; do
	case "$1" in
	--dry-run) dry_run=true ;;
	--force) force=true ;;
	--quiet-when-clean) quiet_when_clean=true ;;
	--root)
		[ $# -ge 2 ] || { printf '✗ --root needs a path\n' >&2; exit 2; }
		root_override="$2"
		shift
		;;
	-h | --help)
		sed -n '/^# Usage:/,/^# ====/p' "${BASH_SOURCE[0]}" | sed '$d; s/^# \{0,1\}//'
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
# Which checkout
# ---------------------------------------------------------------------------

HERE="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"

if [ -n "${root_override}" ]; then
	ROOT="$(cd -- "${root_override}" && pwd)"
else
	# `git worktree list --porcelain` always lists the main worktree first.
	ROOT="$(git -C "${HERE}" worktree list --porcelain 2>/dev/null | awk '/^worktree /{print substr($0,10); exit}')"
	[ -n "${ROOT}" ] || ROOT="${HERE}"
fi

SOURCE="${ROOT}/.agent/skills"
TARGET="${ROOT}/.claude/skills"
BACKUP_DIR="${ROOT}/tmp"

git -C "${ROOT}" rev-parse --git-dir >/dev/null 2>&1 || {
	printf '✗ %s is not a git checkout — retired and hand-written cannot be told apart\n' "${ROOT}" >&2
	exit 2
}

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

# A non-directory here is never inventoried by the glob above, so it would
# survive the delete loop and then abort `cp` halfway. Refuse it up front.
strays=()
for p in "${TARGET}"/bmad-*; do
	[ -d "$p" ] || strays+=("$(basename "$p")")
done
if [ ${#strays[@]} -gt 0 ]; then
	printf '✗ %d non-directory entr(y|ies) named bmad-* under .claude/skills:\n' "${#strays[@]}" >&2
	printf '    • %s\n' "${strays[@]}" >&2
	printf '  Remove or rename them first; the sync only reasons about directories.\n' >&2
	exit 2
fi

target_names=()
for d in "${TARGET}"/bmad-*/; do
	target_names+=("$(basename "$d")")
done

# ---------------------------------------------------------------------------
# Classify what would be removed: retired (regenerable) or hand-written (not)
# ---------------------------------------------------------------------------

# Extract the last version git recorded at .agent/skills/<name> into $2.
# Returns 1 when git has no record of that path at all.
extract_last_recorded() {
	local name="$1" dest="$2" commit
	commit="$(git -C "${ROOT}" log -1 --format=%H -- ".agent/skills/${name}")" || return 1
	[ -n "${commit}" ] || return 1
	for ref in "${commit}^" "${commit}"; do
		if git -C "${ROOT}" archive "${ref}" -- ".agent/skills/${name}" 2>/dev/null |
			tar xf - -C "${dest}" --strip-components=3 2>/dev/null; then
			return 0
		fi
	done
	return 1
}

retired=()
foreign=()
for name in "${target_names[@]}"; do
	[ -d "${SOURCE}/${name}" ] && continue
	recorded="$(mktemp -d)"
	if extract_last_recorded "${name}" "${recorded}" &&
		diff -rq "${PRUNE[@]}" "${recorded}" "${TARGET}/${name}" >/dev/null 2>&1; then
		retired+=("${name}")
	else
		foreign+=("${name}")
	fi
	rm -rf "${recorded}"
done

if [ ${#foreign[@]} -gt 0 ] && [ "${force}" = false ] && [ "${dry_run}" = false ]; then
	printf '✗ %d bmad-* skill(s) under .claude/skills are not what git recorded at that path:\n' "${#foreign[@]}" >&2
	printf '    • %s\n' "${foreign[@]}" >&2
	printf '  No reinstall brings hand-written content back. Move it to .agent/skills/<name>,\n' >&2
	printf '  which is tracked and survives every future sync, or pass --force to archive it\n' >&2
	printf '  into the backup and remove it.\n' >&2
	exit 1
fi

# ---------------------------------------------------------------------------
# Drift
# ---------------------------------------------------------------------------

# Same residue the content comparison prunes, or the mode check re-admits it.
modes_of() {
	(cd -- "$1" && find . -name '__pycache__' -prune -o -name '*.pyc' -prune -o \
		-type f -printf '%m %P\n' 2>/dev/null | sort)
}

added=()
changed=()
for name in "${source_names[@]}"; do
	if [ ! -d "${TARGET}/${name}" ]; then
		added+=("${name}")
	elif ! diff -rq "${PRUNE[@]}" "${SOURCE}/${name}" "${TARGET}/${name}" >/dev/null 2>&1 ||
		! diff -q <(modes_of "${SOURCE}/${name}") <(modes_of "${TARGET}/${name}") >/dev/null 2>&1; then
		changed+=("${name}")
	fi
done

total=$((${#added[@]} + ${#changed[@]} + ${#retired[@]} + ${#foreign[@]}))

if [ "${total}" -eq 0 ]; then
	[ "${quiet_when_clean}" = true ] || printf '✓ bmad-skills-sync: %s — %d bmad-* skills match .agent/skills\n' "${ROOT}" "${#source_names[@]}"
	exit 0
fi

printf 'drift in %s — %d added, %d changed, %d retired, %d hand-written\n' \
	"${ROOT}" "${#added[@]}" "${#changed[@]}" "${#retired[@]}" "${#foreign[@]}"
[ ${#added[@]} -gt 0 ] && printf '  + %s\n' "${added[@]}"
[ ${#retired[@]} -gt 0 ] && printf '  - %s\n' "${retired[@]}"
[ ${#changed[@]} -gt 0 ] && printf '  ~ %s\n' "${changed[@]}"
[ ${#foreign[@]} -gt 0 ] && printf '  ! %s (not what git recorded; removed only under --force)\n' "${foreign[@]}"

if [ "${dry_run}" = true ]; then
	printf '→ run: make bmad.skills.sync — replaces the tree\n'
	exit 1
fi

# ---------------------------------------------------------------------------
# Replace, under a trap: past this point a failure must restore, never abort
# ---------------------------------------------------------------------------

mkdir -p "${BACKUP_DIR}"

# The lock file must exist before flock can take it, and a failure to CREATE it
# is not a held lock — reporting one for the other is the shape of diagnosis
# this script exists to stop making.
if exec 9>"${BACKUP_DIR}/.bmad-skills-sync.lock"; then
	flock -n 9 || {
		printf '✗ another sync holds the lock on %s\n' "${ROOT}" >&2
		exit 2
	}
else
	printf '✗ cannot create %s/.bmad-skills-sync.lock — not locking, not syncing\n' "${BACKUP_DIR}" >&2
	exit 2
fi

backup=""

# The restore VERIFIES itself. Announcing a restore you did not check is the
# defect this whole file is a response to, and it is easy to hit here: the
# failed copy can leave a directory whose mode blocks the extraction, so tar
# returns non-zero over a tree that is still short of files.
restore() {
	trap - ERR
	if [ -z "${backup}" ] || [ ! -f "${backup}" ]; then
		printf '✗ sync failed before a backup existed; nothing was removed\n' >&2
		exit 2
	fi
	# Clear whatever the failed copy left, so no leftover mode blocks the untar.
	for n in "${source_names[@]}" "${target_names[@]}"; do
		rm -rf -- "${TARGET:?}/${n}" 2>/dev/null || true
	done
	local rel="${backup#"${ROOT}"/}"
	if ! tar xzf "${backup}" -C "${ROOT}"; then
		printf '✗ sync failed AND the restore failed — recover by hand: tar xzf %s -C %s\n' "${rel}" "${ROOT}" >&2
		exit 2
	fi
	local missing=0
	for n in "${target_names[@]}"; do
		[ -d "${TARGET}/${n}" ] || missing=$((missing + 1))
	done
	if [ "${missing}" -gt 0 ]; then
		printf '✗ sync failed; restore left %d skill(s) missing — recover by hand: tar xzf %s -C %s\n' "${missing}" "${rel}" "${ROOT}" >&2
		exit 2
	fi
	printf '✗ sync failed — restored %d skill(s) from %s\n' "${#target_names[@]}" "${rel}" >&2
	exit 2
}
trap 'restore' INT TERM HUP ERR

if [ ${#target_names[@]} -gt 0 ]; then
	backup="${BACKUP_DIR}/bmad-skills-backup-$(date +%Y%m%d-%H%M%S)-$$.tar.gz"
	paths=()
	for name in "${target_names[@]}"; do
		paths+=(".claude/skills/${name}")
	done
	tar czf "${backup}" -C "${ROOT}" "${paths[@]}"
	printf '→ backed up %d skill(s) to %s (restore: tar xzf %s -C %s)\n' \
		"${#target_names[@]}" "${backup#"${ROOT}"/}" "${backup#"${ROOT}"/}" "."
	for name in "${target_names[@]}"; do
		rm -rf -- "${TARGET:?}/${name}"
	done
fi

mkdir -p "${TARGET}"
cp -a "${SOURCE}"/bmad-*/ "${TARGET}/"

for name in "${source_names[@]}"; do
	diff -rq "${PRUNE[@]}" "${SOURCE}/${name}" "${TARGET}/${name}" >/dev/null 2>&1 || restore
done

trap - INT TERM HUP ERR

# Keep the backup directory bounded: this archives ~2 MB a run and tmp/ is
# gitignored, so nothing else would ever notice them accumulating.
mapfile -t old < <(
	find "${BACKUP_DIR}" -maxdepth 1 -name 'bmad-skills-backup-*.tar.gz' -printf '%T@ %p\n' 2>/dev/null |
		sort -rn | tail -n "+$((KEEP_BACKUPS + 1))" | cut -d' ' -f2-
)
[ ${#old[@]} -gt 0 ] && rm -f -- "${old[@]}"

printf '✓ bmad-skills-sync: %d bmad-* skills replaced in %s\n' "${#source_names[@]}" "${ROOT}"
