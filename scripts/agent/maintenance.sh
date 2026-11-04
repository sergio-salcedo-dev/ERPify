#!/usr/bin/env bash
# =============================================================================
# Scheduled maintenance: run every task in scripts/agent/tasks/*.md.
# =============================================================================
#
# A task file is a header and a prompt:
#
#   ---
#   engine: claude | agy
#   mode: pr | report          # pr = edit + PR; report = read-only, writes a file
#   branch-type: docs          # pr mode only (feat|fix|docs|chore|ci …)
#   ---
#   <prompt>
#
# A task that still has an OPEN PR from an earlier run is skipped: the repo's
# rule is the fewest PRs possible, and a weekly task must not pile up
# unreviewed ones.
#
#   maintenance.sh [--dry-run] [--only <task>]
# Env: AGENT_TASKS_DIR overrides the task directory (used by tests).
# =============================================================================

set -euo pipefail
# shellcheck source=scripts/agent/lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

DRY_RUN=false
ONLY=""
while (($# > 0)); do
	case $1 in
	--dry-run) DRY_RUN=true ;;
	--only)
		ONLY="${2:?--only needs a task name}"
		shift
		;;
	*) die "unknown option: $1" ;;
	esac
	shift
done

need gh jq make git timeout flock
TASKS_DIR="${AGENT_TASKS_DIR:-$AGENT_DIR/tasks}"

header() { # <file> <key>
	awk -v k="$2" '
		/^---$/ { n++; next }
		n == 1 { sub(/#.*/, ""); if (match($0, "^" k ":[[:space:]]*")) { v = substr($0, RLENGTH + 1); gsub(/[[:space:]]+$/, "", v); print v; exit } }
	' "$1"
}

task_prompt() { # everything after the closing --- of the header
	awk '/^---$/ { n++; next } n >= 2' "$1"
}

has_open_pr() { # <branch-type>/agent-<task>
	gh pr list --state open --json headRefName --jq '.[].headRefName' | grep -q "^$1-" || return 1
}

notify() {
	command -v notify-send >/dev/null 2>&1 && notify-send "ERPify agent" "$1" || true
}

acquire_lock
shopt -s nullglob
ran=0
for file in "$TASKS_DIR"/*.md; do
	name="$(basename "$file" .md)"
	[[ -z $ONLY || $ONLY == "$name" ]] || continue
	engine="$(header "$file" engine)"
	mode="$(header "$file" mode)"
	type="$(header "$file" branch-type)"
	type="${type:-chore}"
	[[ $engine == claude || $engine == agy ]] || {
		log "task $name: bad engine '$engine'"
		continue
	}
	[[ $mode == pr || $mode == report ]] || {
		log "task $name: bad mode '$mode'"
		continue
	}
	command -v "$engine" >/dev/null 2>&1 || {
		log "task $name: engine '$engine' not installed"
		continue
	}
	if [[ $mode == pr ]] && has_open_pr "$type/agent-$name"; then
		log "task $name: an earlier PR is still open; skipping"
		continue
	fi
	if $DRY_RUN; then
		log "dry-run: would run task=$name engine=$engine mode=$mode type=$type"
		continue
	fi

	prompt="$WORK_DIR/task-$name.prompt.md"
	{
		cat "$AGENT_DIR/preamble.md"
		printf '\n## Task: %s\n\n' "$name"
		task_prompt "$file"
	} >"$prompt"
	run_task "$engine" "$type" "agent-$name" "$prompt" "$mode"
	log "task $name → $TASK_STATUS: $TASK_DETAIL"
	notify "$name: $TASK_STATUS"
	ran=$((ran + 1))
done
log "maintenance finished ($ran task(s) run)"
