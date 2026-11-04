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
# and acts on a developer machine, so the control is what it CANNOT do, never
# the wording of the prompt:
#
#   1. It has NO shell. The only tools it holds are Read, Glob, Grep and, in the
#      edit profile, Edit and Write. Every read-only git verb was measured able
#      to write an arbitrary file (`--output=<path>`) and `git blame --contents`
#      reads one, so no Bash rule short of "none" is a boundary. It edits files in
#      a throwaway worktree; THIS script commits, pushes and opens the PR.
#   2. The diff is refused when it touches a path that executes (CI, the
#      Makefiles, package scripts, hooks, the instruction files, env files, the
#      agent's own guardrails), carries a symlink, a submodule or a newly
#      executable file, is larger than the budget, or carries a token-shaped
#      string. Paths are read NUL-delimited: git quotes a name containing a
#      non-ASCII byte or a quote, and a quoted name matches no prefix glob, which
#      is how `.github/workflows/é.yml` walked past this very check.
#   3. Nothing here merges. There is no `gh pr merge` in this directory, and
#      merging stays a per-PR human decision (root CLAUDE.md, "Protected main").
#   4. Issue-driven runs need the issue AUTHOR and the label ACTOR in
#      scripts/agent/allowed-users. The repository is public: a label on a
#      stranger's issue must never become a prompt.
#   5. One run at a time (flock, closed in the engine's descriptors so a leaked
#      child cannot hold it), a scrubbed environment, and a wall-clock bound.
#      The project's own hooks are switched off for these runs: the secret-scan
#      hook needs credentials the scrubbed environment (rightly) does not carry,
#      and no control here depends on a hook.
#
# The agent's exit code is NOT trusted as the outcome. Antigravity is documented
# to soft-deny an unapproved tool and still exit 0, so success is decided from
# the engine's own status field and from git state.
#
# WHAT THIS DOES NOT CLOSE (also in docs/runbooks/agent-automation.md): `Read`
# is a deny-list over the credential directories, not a jail, so a path nobody
# listed is readable; and CI runs whatever a pushed branch contains before any
# human has looked, exactly as it does for a human contributor.
# =============================================================================

umask 077
export LC_ALL=C.UTF-8

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
AGENT_MAX_DIFF_BYTES="${AGENT_MAX_DIFF_BYTES:-2000000}"
# Opt-out knob for the self-test: commit locally, skip push and PR.
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

# git for every call this script makes inside an agent-written tree: no hooks,
# no fsmonitor (both are executable configuration a tree can carry), and names
# printed unquoted.
agit() {
	git -c core.hooksPath=/dev/null -c core.fsmonitor=false -c core.quotePath=false "$@"
}

# acquire_lock <quiet|wait|fail>
#   quiet  the poller: a busy lock is the normal case, exit 0
#   wait   the weekly run: queue behind a long poll instead of skipping the week
#   fail   interactive entry points: say so and exit 75 (EX_TEMPFAIL)
acquire_lock() {
	local mode=${1:-quiet}
	exec 9>"$STATE_DIR/lock"
	case $mode in
	wait) flock -w 7200 9 || {
		log "could not get the agent lock within 2h"
		exit 75
	} ;;
	fail) flock -n 9 || {
		log "another agent run holds the lock; try again later"
		exit 75
	} ;;
	*) flock -n 9 || {
		log "another agent run holds the lock; exiting"
		exit 0
	} ;;
	esac
}

# An empty login must never match: a blank line in the file would authorize "".
is_allowed_user() {
	[[ -n ${1:-} ]] || return 1
	awk -v u="$1" '$1 !~ /^#/ && $1 == u { found = 1 } END { exit !found }' "$AGENT_DIR/allowed-users"
}

# Paths where an unreviewed hostile diff buys privilege: whatever CI, a hook, a
# make target or an agent session executes, the instruction files and secrets.
# Lower-cased first so a case variant cannot slip past on a case-folding mount.
is_protected() {
	case "${1,,}" in
	.github/* | .claude/* | .agent/* | .agents/* | .gemini/* | .githooks/* | .husky/* | .vscode/* | .devcontainer/* | .bmad-loop/*) return 0 ;;
	scripts/* | binaries/* | makefile | make/* | */makefile | docker/*) return 0 ;;
	compose*.yaml | compose*.yml | */compose*.yaml | */compose*.yml | dockerfile* | */dockerfile*) return 0 ;;
	composer.json | */composer.json | composer.lock | */composer.lock) return 0 ;;
	package.json | */package.json | package-lock.json | */package-lock.json) return 0 ;;
	api/tools/* | api/bin/* | */bootstrap.php) return 0 ;;
	sonar-project.properties | .gitattributes | .gitmodules | .gitignore | .mcp.json | */.mcp.json) return 0 ;;
	claude.md | */claude.md | agents.md | */agents.md | gemini.md | */gemini.md | .env* | */.env*) return 0 ;;
	*) return 1 ;;
	esac
}

# Token-shaped strings. A heuristic: it catches the common credential formats,
# not every secret, and a miss is not evidence of absence.
scan_secrets() {
	grep -aE '(gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{30,}|AKIA[0-9A-Z]{16}|sk-ant-[A-Za-z0-9_-]{20,}|AIza[0-9A-Za-z_-]{35}|-----BEGIN [A-Z ]*PRIVATE KEY-----|xox[baprs]-[A-Za-z0-9-]{10,}|eyJ[A-Za-z0-9_-]{15,}\.eyJ[A-Za-z0-9_-]{15,}\.[A-Za-z0-9_-]{10,})' >/dev/null
}

has_text() { # <file>: a regular file (never a symlink) with a non-blank character
	[[ -f $1 && ! -L $1 && -n "$(tr -d '[:space:]' <"$1")" ]]
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
# The prompt goes to claude through stdin: `--allowedTools` is variadic and
# would swallow a positional prompt as one more tool name.
run_engine() {
	local engine=$1 profile=$2 wt=$3 prompt=$4 out=$5 rc=0 var
	local -a envv=(HOME="$HOME" PATH="$PATH" LANG=C.UTF-8 LC_ALL=C.UTF-8 TERM=dumb)
	for var in ANTHROPIC_API_KEY GEMINI_API_KEY; do
		[[ -z ${!var:-} ]] || envv+=("$var=${!var}")
	done
	local -a allow=(Read Glob Grep)
	local -a deny=(Bash WebFetch WebSearch Task NotebookEdit
		'Read(~/.ssh/**)' 'Read(~/.config/gh/**)' 'Read(~/.aws/**)' 'Read(~/.gnupg/**)'
		'Read(~/.claude/**)' 'Read(~/.gemini/**)' 'Read(~/.docker/**)' 'Read(~/.netrc)'
		'Read(~/.npmrc)' 'Read(~/.git-credentials)' 'Read(~/.local/state/erpify-agent/**)' 'Read(//proc/**)')

	# 9>&- keeps the run lock out of the engine and everything it spawns.
	case "$engine" in
	claude)
		# Edit and Write are scoped to the working directory. Bare `Write` plus
		# acceptEdits was measured writing an absolute path outside it.
		if [[ $profile == edit ]]; then
			allow+=('Edit(./**)' 'Write(./**)')
		else
			deny+=(Edit Write)
		fi
		(cd "$wt" && env -i "${envv[@]}" timeout --kill-after=30s "$AGENT_TIMEOUT" claude -p \
			--output-format json --permission-mode default \
			--setting-sources project --strict-mcp-config --settings '{"disableAllHooks":true}' \
			--allowedTools "${allow[@]}" --disallowedTools "${deny[@]}" <"$prompt" 9>&-) >"$out" 2>>"$LOG_FILE" || rc=$?
		;;
	agy)
		local mode=accept-edits
		[[ $profile == edit ]] || mode=plan
		(cd "$wt" && env -i "${envv[@]}" timeout --kill-after=30s "$AGENT_TIMEOUT" agy -p "$(<"$prompt")" \
			--output-format json --mode "$mode" --sandbox \
			--settings "$AGENT_DIR/agy-settings.$profile.json" \
			--print-timeout "$AGENT_TIMEOUT" 9>&-) >"$out" 2>>"$LOG_FILE" || rc=$?
		;;
	*) die "unknown engine: $engine" ;;
	esac
	if ((rc == 0)) && ! engine_ok "$out"; then
		log "engine=$engine exited 0 but reported an error or produced no JSON object"
		rc=1
	fi
	log "engine=$engine profile=$profile exit=$rc"
	return "$rc"
}

# Both CLIs print one JSON object; claude flags failure with is_error and agy
# with a status other than SUCCESS, each while still exiting 0 in some cases.
# `num_turns == 0` means the model never ran: a hook that blocks the prompt makes
# claude exit 0 with is_error false and the hook's message as the "result", which
# once passed for a finished report.
engine_ok() {
	jq -e 'type == "object" and ((.is_error // false) == false) and ((.status // "SUCCESS") == "SUCCESS") and ((.num_turns // 1) >= 1)' "$1" >/dev/null 2>&1
}

# The model's final text, whichever engine produced it. Empty when unreadable.
engine_text() {
	jq -r '(.result // .response // "")' "$1" 2>/dev/null || true
}

remove_worktree() {
	make -C "$REPO_ROOT" --no-print-directory worktree.remove NAME="$(basename "$1")" FORCE=true >>"$LOG_FILE" 2>&1 ||
		log "WARN: could not remove worktree $1 (remove it with make worktree.remove)"
}

# Commit message from the agent's file: no trailers it could forge, bounded, with
# a final newline so the trailer appended later starts its own paragraph.
sanitize_message() { # <in> <out>
	tr -d '\r' <"$1" | { grep -viE '^(co-authored-by|signed-off-by|reviewed-by|acked-by|tested-by|closes|fixes|resolves|refs?)[: ]' || true; } |
		head -c 4000 | awk 1 >"$2"
}

# PR body from the agent's file: no auto-close keywords, no live @mentions.
sanitize_body() { # <in> <out>
	tr -d '\r' <"$1" | { grep -viE '^[[:space:]]*(closes|fixes|resolves)[[:space:]]' || true; } |
		cut -c1-20000 | sed -E 's/@([A-Za-z0-9_-])/@\xe2\x80\x8b\1/g' >"$2"
}

# ---------------------------------------------------------------------------
# run_task <engine> <branch-type> <slug> <prompt-file> <pr|report> [issue-number]
# ---------------------------------------------------------------------------
# Sets TASK_STATUS (pr-opened | committed | no-change | reported | rejected |
# failed) and TASK_DETAIL, which names local paths: it is for the log, never for
# a public comment. Returns 0 once the outcome is classified, so a caller under
# `set -e` reports it instead of dying half-way.
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
	# worktree.create links the primary's bmad-loop policy into the tree; a Write
	# through that link would change the primary and no diff would show it.
	rm -f "$wt/.bmad-loop/policy.toml"
	mkdir -p "$wt/tmp"
	log "task=$slug engine=$engine mode=$mode worktree=$wt"

	if ! run_engine "$engine" "$profile" "$wt" "$prompt" "$out"; then
		TASK_DETAIL="the $engine run failed or timed out (worktree kept: $wt)"
		return 0
	fi
	log "agent said: $(engine_text "$out" | head -c 600 | tr '\n' ' ')"

	if [[ $mode == report ]]; then
		local report="$REPORT_DIR/$slug-$stamp.md"
		engine_text "$out" >"$report"
		if ! has_text "$report"; then
			rm -f "$report"
			TASK_DETAIL="the $engine run returned an empty report (worktree kept: $wt)"
			return 0
		fi
		TASK_STATUS=reported
		TASK_DETAIL="$report"
		remove_worktree "$wt"
		return 0
	fi

	if [[ -z "$(agit -C "$wt" status --porcelain)" ]]; then
		TASK_STATUS=no-change
		TASK_DETAIL="the agent finished without changing any file"
		remove_worktree "$wt"
		return 0
	fi

	agit -C "$wt" add -A
	reject() { # <reason>: unstage and keep the tree for a human to look at
		agit -C "$wt" reset --quiet
		TASK_STATUS=rejected
		TASK_DETAIL="$1 (worktree kept: $wt)"
	}

	local -a changed
	mapfile -d '' -t changed < <(agit -C "$wt" diff --cached --name-only -z --no-renames)
	local path
	for path in "${changed[@]}"; do
		if is_protected "$path"; then
			reject "the diff touches a protected path ($path)"
			return 0
		fi
	done
	if ((${#changed[@]} > AGENT_MAX_CHANGED_FILES)); then
		reject "the diff touches ${#changed[@]} files (limit $AGENT_MAX_CHANGED_FILES)"
		return 0
	fi
	if [[ -n "$(agit -C "$wt" diff --cached --raw --no-abbrev --no-renames |
		awk '$2 == "120000" || $2 == "160000" || ($2 == "100755" && $1 != ":100755") { print $2 }')" ]]; then
		reject "the diff adds a symlink, a submodule or a newly executable file"
		return 0
	fi
	if (($(agit -C "$wt" diff --cached --binary | wc -c) > AGENT_MAX_DIFF_BYTES)); then
		reject "the diff is larger than $AGENT_MAX_DIFF_BYTES bytes"
		return 0
	fi
	if agit -C "$wt" diff --cached --binary | scan_secrets; then
		reject "the diff contains a token-shaped string"
		return 0
	fi

	# Commit message: from the agent's file when it is a conventional commit,
	# otherwise a fallback subject, plus our own trailer.
	local subject msg="$wt/tmp/commit.final.txt" re
	re='^(feat|fix|docs|style|refactor|perf|test|build|ci|chore|revert)(\([a-z0-9._/-]+\))?!?: [^[:space:]].{0,100}$'
	if has_text "$wt/tmp/agent-commit-message.txt"; then
		sanitize_message "$wt/tmp/agent-commit-message.txt" "$msg"
	else
		: >"$msg"
	fi
	subject="$(head -n1 "$msg")"
	if [[ ! $subject =~ $re ]]; then
		if [[ -n $issue ]]; then
			subject="$type(agent): resolve issue #$issue"
		else
			subject="$type(agent): apply $slug"
		fi
		printf '%s\n' "$subject" >"$msg"
	fi
	if [[ $engine == claude ]]; then
		printf '\nCo-Authored-By: Claude <noreply@anthropic.com>\n' >>"$msg"
	fi
	if scan_secrets <"$msg"; then
		reject "the commit message contains a token-shaped string"
		return 0
	fi
	agit -C "$wt" commit --quiet -F "$msg" || {
		TASK_DETAIL="git commit failed (worktree kept: $wt)"
		return 0
	}

	if [[ $AGENT_NO_PUSH == 1 ]]; then
		TASK_STATUS=committed
		TASK_DETAIL="AGENT_NO_PUSH=1: committed '$subject' on $branch, not pushed (worktree: $wt)"
		return 0
	fi

	local body="$wt/tmp/agent-pr-body.final.md" clean="$wt/tmp/agent-pr-body.clean.md" url
	if has_text "$wt/tmp/agent-pr-body.md"; then
		sanitize_body "$wt/tmp/agent-pr-body.md" "$clean"
	else
		printf 'Automated change; the agent supplied no description.\n' >"$clean"
	fi
	{
		cat "$clean"
		[[ -z $issue ]] || printf '\nCloses #%s\n' "$issue"
		# shellcheck disable=SC2016 # the backticks are literal Markdown, not command substitution
		printf '\n---\nOpened unattended by `scripts/agent` (engine: `%s`). The agent had no shell, git or `gh`: the script committed and pushed. Quality gates were **not** run locally (no stack), so CI is the gate. A human must review before merging.\n\n%s\n' \
			"$engine" "$(engine_footer "$engine")"
	} >"$body"
	if scan_secrets <"$body"; then
		reject "the PR description contains a token-shaped string"
		return 0
	fi

	agit -C "$wt" push --quiet -u origin "$branch" >>"$LOG_FILE" 2>&1 || {
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
