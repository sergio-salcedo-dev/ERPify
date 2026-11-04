#!/usr/bin/env bash
# =============================================================================
# Scheduled maintenance: run every task in scripts/agent/tasks/*.md.
# =============================================================================
#
# A task file is a header and a prompt:
#
#   ---
#   engine: claude | agy
#   mode: pr | report          # pr = edit + PR; report = read-only, returns a report
#   branch-type: docs          # pr mode only (feat|fix|docs|chore|ci|refactor|test|perf|build)
#   ---
#   <prompt>
#
# Installing the timer (`make agent.install`) is the standing authorization for
# one branch per task per run; the guard below keeps that to one OPEN PR per
# task, because the repo's rule is the fewest PRs possible and a weekly task must
# not pile up unreviewed ones. A second brake, AGENT_MAX_OPEN_PRS (default 3), caps
# the agent PRs open at once across tasks and the issue poller: each one is a full
# CI run. Report tasks open no PR and are not counted.
#
#   maintenance.sh [--dry-run] [--only <task>]
# Env: AGENT_TASKS_DIR overrides the task directory (used by the self-test).
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

# CRLF is stripped first: a `---\r` line would never match and the task would be
# skipped with a misleading "bad engine".
header() { # <file> <key>
	tr -d '\r' <"$1" | awk -v k="$2" '
		/^---$/ { n++; next }
		n == 1 { sub(/#.*/, ""); if (match($0, "^" k ":[[:space:]]*")) { v = substr($0, RLENGTH + 1); gsub(/[[:space:]]+$/, "", v); print v; exit } }
	'
}

task_prompt() { # everything after the closing --- of the header
	tr -d '\r' <"$1" | awk '/^---$/ { n++; next } n >= 2'
}

delimiters() { # number of `---` lines: a task needs the opening and the closing one
	tr -d '\r' <"$1" | grep -c '^---$' || true
}

# Fails closed: if the PR list cannot be read, the task is skipped rather than
# run into a duplicate. Exact match on `<type>/agent-<task>-<4-char suffix>`.
has_open_pr() { # <branch-type>/agent-<task>
	local refs ref
	refs="$(gh pr list --state open --limit 200 --json headRefName --jq '.[].headRefName')" || return 2
	while IFS= read -r ref; do
		# shellcheck disable=SC2053 # the glob is intentional: four suffix characters
		[[ $ref == "$1"-???? ]] && return 0
	done <<<"$refs"
	return 1
}

notify() {
	command -v notify-send >/dev/null 2>&1 && notify-send "ERPify agent" "$1" || true
}

[[ -d $TASKS_DIR ]] || die "task directory not found: $TASKS_DIR"
if [[ -n $ONLY && ! -f $TASKS_DIR/$ONLY.md ]]; then
	die "no task named '$ONLY' in $TASKS_DIR"
fi

acquire_lock wait
shopt -s nullglob
ran=0
for file in "$TASKS_DIR"/*.md; do
	name="$(basename "$file" .md)"
	[[ -z $ONLY || $ONLY == "$name" ]] || continue
	engine="$(header "$file" engine)"
	mode="$(header "$file" mode)"
	type="$(header "$file" branch-type)"
	type="${type:-chore}"
	[[ $name =~ ^[a-z0-9][a-z0-9-]*$ ]] || {
		log "task $name: the file name must be lower-case kebab-case"
		continue
	}
	[[ $engine == claude || $engine == agy ]] || {
		log "task $name: bad engine '$engine'"
		continue
	}
	[[ $mode == pr || $mode == report ]] || {
		log "task $name: bad mode '$mode'"
		continue
	}
	[[ $type =~ ^(feat|fix|docs|chore|ci|refactor|test|perf|build)$ ]] || {
		log "task $name: bad branch-type '$type'"
		continue
	}
	if (($(delimiters "$file") < 2)) || [[ -z "$(task_prompt "$file" | tr -d '[:space:]')" ]]; then
		log "task $name: needs a header closed by --- and a non-empty prompt"
		continue
	fi
	command -v "$engine" >/dev/null 2>&1 || {
		log "task $name: engine '$engine' not installed"
		continue
	}
	if [[ $mode == pr ]]; then
		rc=0
		has_open_pr "$type/agent-$name" || rc=$?
		if ((rc == 0)); then
			log "task $name: an earlier PR is still open; skipping"
			continue
		elif ((rc == 2)); then
			log "task $name: could not list open PRs; skipping rather than risk a duplicate"
			continue
		fi
		pr_budget_ok || continue
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
