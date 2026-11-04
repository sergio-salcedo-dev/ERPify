#!/usr/bin/env bash
# shellcheck shell=bash
# shellcheck disable=SC2034 # TASK_STATUS and TASK_DETAIL are read by the scripts that source this file
# =============================================================================
# Unattended-agent library — shared by poll-issues.sh, maintenance.sh and
# review-pr.sh. Source it; never execute it.
# =============================================================================
#
# THE CONTAINMENT MODEL (read this before widening anything)
#
# An unattended agent reads text a third party can write (an issue body, a diff)
# and holds a shell. The control is therefore not "a good prompt" but what the
# agent CANNOT do:
#
#   1. It has no git write access and no `gh`. It edits files in a throwaway
#      worktree; THIS script commits, pushes and opens the PR. A prompt-injected
#      agent can at worst produce a bad diff that a human reviews.
#   2. The diff is refused when it touches a protected path (CI workflows, the
#      agent's own guardrails, the instruction files, env files) or exceeds a
#      file budget. Those are the paths where a hostile diff buys privilege.
#   3. Nothing here merges. There is no `gh pr merge` in this directory, and
#      merging stays a per-PR human decision (root CLAUDE.md, "Protected main").
#   4. Issue-driven runs require the issue AUTHOR and the label ACTOR to be in
#      scripts/agent/allowed-users. The repository is public: a label on a
#      stranger's issue must never become a prompt.
#   5. One run at a time (flock), and every engine call is wall-clock bounded.
#
# The agent's exit code is NOT trusted as the outcome. Antigravity soft-denies
# an unapproved tool and still exits 0, so success is decided from git state.
# =============================================================================

AGENT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# The PRIMARY checkout, even when this file is read from a linked worktree:
# worktrees are created from it and the timers point at it.
REPO_ROOT="$(dirname "$(git -C "$AGENT_DIR" rev-parse --path-format=absolute --git-common-dir)")"
STATE_DIR="${XDG_STATE_HOME:-$HOME/.local/state}/erpify-agent"
LOG_DIR="$STATE_DIR/logs"
REPORT_DIR="$STATE_DIR/reports"
WORK_DIR="$STATE_DIR/work"
AGENT_TIMEOUT="${AGENT_TIMEOUT:-45m}"
AGENT_MAX_CHANGED_FILES="${AGENT_MAX_CHANGED_FILES:-60}"
# Opt-out knob for tests: commit locally, skip push and PR.
AGENT_NO_PUSH="${AGENT_NO_PUSH:-0}"

mkdir -p "$LOG_DIR" "$REPORT_DIR" "$WORK_DIR"
LOG_FILE="${LOG_FILE:-$LOG_DIR/$(date +%Y%m%d).log}"

TASK_STATUS=""
TASK_DETAIL=""

log() {
	printf '%s %s\n' "$(date -Is)" "$*" | tee -a "$LOG_FILE" >&2
}

die() {
	log "ERROR: $*"
	exit 1
}

need() {
	local tool
	for tool in "$@"; do
		command -v "$tool" >/dev/null 2>&1 || die "missing tool: $tool"
	done
}

# Non-blocking: a second timer tick while a long run is active must exit, not queue.
acquire_lock() {
	exec 9>"$STATE_DIR/lock"
	flock -n 9 || {
		log "another agent run holds the lock; exiting"
		exit 0
	}
}

is_allowed_user() {
	awk -v u="$1" '$1 == u { found = 1 } END { exit !found }' "$AGENT_DIR/allowed-users"
}

# A glob on purpose: these are the paths where an unreviewed hostile diff buys
# privilege (CI, the guardrails themselves, instruction files, secrets).
is_protected() {
	case "$1" in
	.github/* | .claude/* | scripts/agent/* | CLAUDE.md | */CLAUDE.md | AGENTS.md | GEMINI.md | .env* | */.env*) return 0 ;;
	*) return 1 ;;
	esac
}

engine_footer() {
	case "$1" in
	claude) printf '%s' '🤖 Generated with [Claude Code](https://claude.com/claude-code)' ;;
	*) printf '%s' "Generated with the Antigravity CLI (\`agy\`)" ;;
	esac
}

# ---------------------------------------------------------------------------
# run_engine <engine> <edit|readonly> <worktree> <prompt-file> <out-file>
# ---------------------------------------------------------------------------
# The prompt goes in through stdin for claude: `--allowedTools` is variadic and
# would swallow a positional prompt as one more tool name.
run_engine() {
	local engine=$1 profile=$2 wt=$3 prompt=$4 out=$5 rc=0
	local -a deny=('Bash(git push:*)' 'Bash(git commit:*)' 'Bash(git add:*)' 'Bash(git reset:*)'
		'Bash(git checkout:*)' 'Bash(gh:*)' 'Bash(curl:*)' 'Bash(wget:*)' 'Bash(sudo:*)' WebFetch WebSearch)
	local -a allow=(Read Glob Grep 'Bash(git status:*)' 'Bash(git diff:*)' 'Bash(git log:*)'
		'Bash(git show:*)' 'Bash(git blame:*)' 'Bash(ls:*)')

	case "$engine" in
	claude)
		if [[ $profile == edit ]]; then
			allow+=(Edit Write)
		else
			deny+=(Edit Write NotebookEdit)
		fi
		(cd "$wt" && timeout --kill-after=30s "$AGENT_TIMEOUT" claude -p \
			--output-format json --permission-mode acceptEdits \
			--allowedTools "${allow[@]}" --disallowedTools "${deny[@]}" <"$prompt") >"$out" 2>>"$LOG_FILE" || rc=$?
		;;
	agy)
		local mode=accept-edits
		[[ $profile == edit ]] || mode=plan
		(cd "$wt" && timeout --kill-after=30s "$AGENT_TIMEOUT" agy -p "$(<"$prompt")" \
			--output-format json --mode "$mode" --sandbox \
			--settings "$AGENT_DIR/agy-settings.$profile.json" \
			--print-timeout "$AGENT_TIMEOUT") >"$out" 2>>"$LOG_FILE" || rc=$?
		;;
	*) die "unknown engine: $engine" ;;
	esac
	log "engine=$engine profile=$profile exit=$rc"
	return "$rc"
}

# The model's final text, whichever engine produced it.
engine_text() {
	jq -r '(.result // .response // "")' "$1" 2>/dev/null || cat "$1"
}

remove_worktree() {
	make -C "$REPO_ROOT" --no-print-directory worktree.remove NAME="$(basename "$1")" FORCE=true >>"$LOG_FILE" 2>&1 ||
		log "WARN: could not remove worktree $1 (remove it with make worktree.remove)"
}

# ---------------------------------------------------------------------------
# run_task <engine> <branch-type> <slug> <prompt-file> <pr|report> [issue-number]
# ---------------------------------------------------------------------------
# Sets TASK_STATUS (pr-opened | committed | no-change | reported | rejected |
# failed) and TASK_DETAIL. Always returns 0 once it has classified the outcome,
# so a caller under `set -e` reports it rather than dying mid-way.
run_task() {
	local engine=$1 type=$2 slug=$3 prompt=$4 mode=$5 issue=${6:-}
	local stamp out created wt branch profile=edit
	TASK_STATUS=failed
	TASK_DETAIL=""
	stamp="$(date +%Y%m%d-%H%M%S)"
	out="$WORK_DIR/$slug-$stamp.json"
	if [[ $mode == report ]]; then
		profile="readonly"
	fi

	if ! git -C "$REPO_ROOT" fetch --quiet origin main; then
		TASK_DETAIL="git fetch origin main failed"
		return 0
	fi

	created="$(make -C "$REPO_ROOT" --no-print-directory worktree.create \
		BRANCH="$type/$slug" BASE=origin/main NAME="$slug" 2>&1 | tee -a "$LOG_FILE" |
		sed -n 's/^✓ created worktree \(.*\) (branch \(.*\))$/\1\t\2/p' || true)"
	IFS=$'\t' read -r wt branch <<<"$created"
	if [[ -z ${wt:-} || ! -d $wt ]]; then
		TASK_DETAIL="could not create the worktree"
		return 0
	fi
	mkdir -p "$wt/tmp"
	log "task=$slug engine=$engine mode=$mode worktree=$wt"

	if ! run_engine "$engine" "$profile" "$wt" "$prompt" "$out"; then
		TASK_DETAIL="the $engine run failed or timed out (see the local agent log)"
		return 0
	fi
	log "agent said: $(engine_text "$out" | head -c 600 | tr '\n' ' ')"

	if [[ $mode == report ]]; then
		local report="$REPORT_DIR/$slug-$stamp.md"
		if [[ -s $wt/tmp/agent-report.md ]]; then
			cp "$wt/tmp/agent-report.md" "$report"
		else
			engine_text "$out" >"$report"
		fi
		TASK_STATUS=reported
		TASK_DETAIL="$report"
		remove_worktree "$wt"
		return 0
	fi

	if [[ -z "$(git -C "$wt" status --porcelain)" ]]; then
		TASK_STATUS=no-change
		TASK_DETAIL="the agent finished without changing any file"
		remove_worktree "$wt"
		return 0
	fi

	git -C "$wt" add -A
	local -a changed
	mapfile -t changed < <(git -C "$wt" diff --cached --name-only --no-renames)
	local path
	for path in "${changed[@]}"; do
		if is_protected "$path"; then
			git -C "$wt" reset --quiet
			TASK_STATUS=rejected
			TASK_DETAIL="the diff touches a protected path ($path); nothing was committed (worktree kept: $wt)"
			return 0
		fi
	done
	if ((${#changed[@]} > AGENT_MAX_CHANGED_FILES)); then
		git -C "$wt" reset --quiet
		TASK_STATUS=rejected
		TASK_DETAIL="the diff touches ${#changed[@]} files (limit $AGENT_MAX_CHANGED_FILES); nothing was committed (worktree kept: $wt)"
		return 0
	fi

	local subject msg_file="$wt/tmp/agent-commit-message.txt"
	local re='^(feat|fix|docs|style|refactor|perf|test|build|ci|chore|revert)(\([a-z0-9._/-]+\))?!?: .+'
	subject="$(head -n1 "$msg_file" 2>/dev/null || true)"
	if [[ ! $subject =~ $re ]]; then
		if [[ -n $issue ]]; then
			subject="$type(agent): resolve issue #$issue"
		else
			subject="$type(agent): apply $slug"
		fi
		printf '%s\n' "$subject" >"$msg_file"
	fi
	if [[ $engine == claude ]] && ! grep -q '^Co-Authored-By:' "$msg_file"; then
		printf '\nCo-Authored-By: Claude <noreply@anthropic.com>\n' >>"$msg_file"
	fi
	git -C "$wt" commit --quiet -F "$msg_file" || {
		TASK_DETAIL="git commit failed (worktree kept: $wt)"
		return 0
	}

	if [[ $AGENT_NO_PUSH == 1 ]]; then
		TASK_STATUS=committed
		TASK_DETAIL="AGENT_NO_PUSH=1: committed '$subject' on $branch, not pushed (worktree: $wt)"
		return 0
	fi

	local body="$wt/tmp/agent-pr-body.final.md" url
	{
		if [[ -s $wt/tmp/agent-pr-body.md ]]; then
			cat "$wt/tmp/agent-pr-body.md"
		else
			printf 'Automated change; the agent supplied no description.\n'
		fi
		[[ -z $issue ]] || printf '\nCloses #%s\n' "$issue"
		# shellcheck disable=SC2016 # the backticks are literal Markdown, not command substitution
		printf '\n---\nOpened unattended by `scripts/agent` (engine: `%s`). The agent had no git or `gh` access: the script committed and pushed. Quality gates were **not** run locally (no stack), so CI is the gate. A human must review before merging.\n\n%s\n' \
			"$engine" "$(engine_footer "$engine")"
	} >"$body"

	git -C "$wt" push --quiet -u origin "$branch" >>"$LOG_FILE" 2>&1 || {
		TASK_DETAIL="git push failed (worktree kept: $wt)"
		return 0
	}
	url="$(cd "$wt" && gh pr create --base main --head "$branch" --title "$subject" --body-file "$body" 2>>"$LOG_FILE")" || {
		TASK_DETAIL="pushed $branch but gh pr create failed"
		return 0
	}
	TASK_STATUS=pr-opened
	TASK_DETAIL="$url"
}
