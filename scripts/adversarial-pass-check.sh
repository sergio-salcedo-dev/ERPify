#!/usr/bin/env bash
# =============================================================================
# Adversarial-pass record check
# =============================================================================
#
# Answers one question, at the moment a pull request is about to be opened:
# does this branch already carry the record of its adversarial pass?
#
# CLAUDE.md -> "Security review on every change" -> Process requires the pass to
# run AND its findings to be written down before the PR exists. Nothing but
# prose enforced that, and it failed three times: #616 and #620 landed their
# pass in a SEPARATE follow-up PR, and #770 landed it in the same PR nine
# minutes late while its body claimed the opposite. That last shape is the one
# worth naming -- a reader gets a compliant-looking claim, and the seam between
# "the pass ran" and "its findings were written" is invisible to every other
# gate in this repo.
#
# WHY THIS RUNS AT CREATION TIME RATHER THAN AFTER
#
# The obvious instrument is post-hoc: compare the artifact's commit date against
# the PR's createdAt. It does not work, and #799 measured why. `%cI` is the
# COMMITTER date, which every rebase rewrites -- five commits on #789's branch
# share one committer timestamp while their author dates span 71 minutes, so
# `%cI` records when the branch was last rewritten, not when the pass was
# recorded. A branch rebased after the PR opened reports a timestamp LATER than
# createdAt while having done everything in the right order: a false red. And
# `%aI` is settable and survives a reordering rebase, so it is no better.
#
# Running AT the moment of creation dissolves the problem instead of solving it:
# "now" is the creation time, so the check reads content that exists rather than
# inferring an order from dates that lie. No timestamp is consulted anywhere in
# this script, and none should be added.
#
# WHAT COUNTS AS THE RECORD
#
# Either witness, and both carry a content floor -- an asymmetry here was a
# measured hole, because the witness without a floor is always the cheaper one
# to fake:
#
#   1. A COMMIT on this branch carrying an `Adversarial-pass:` trailer whose
#      value clears MIN_TRAILER_CHARS. The value is read with git's own trailer
#      parser, so a message that merely quotes the key mid-body is not a
#      trailer, and an empty `Adversarial-pass:` is not a record.
#   2. An ARTIFACT under the artifacts directory, COMMITTED on this branch,
#      carrying a `## Adversarial pass` section that clears MIN_RECORD_LINES and
#      MIN_RECORD_CHARS -- and that clears them AGAIN on what it adds over any
#      pass already on the base. Measuring identity alone was not enough: the
#      section-level floors are satisfied by the pre-existing text, so appending
#      one word to somebody else's pass turned the gate green (#816). Putting
#      the floors on the delta refuses a rename, a copy, a whitespace nudge and
#      a token extension by the same arithmetic.
#
# Committed, not merely written: a file sitting in the working tree is exactly
# the state #770 shipped -- the head the PR opens from does not contain it. An
# earlier cut of this script accepted it, on the reasoning that the human could
# still commit "before the PR". That reasoning is self-refuting at the one
# moment this runs, which is AT creation.
#
# WHAT A GREEN PROVES, AND WHAT IT DOES NOT
#
# It proves a record of the required SHAPE exists on the branch before the PR
# does. It cannot judge whether the findings are real, whether the pass was
# adversarial, or whether a hostile reader was actually involved -- it gates the
# form, not the substance, and review remains the only control on that
# direction.
#
# Its recognition of "a pull request is being opened" is a NAMED LIST of
# spellings, not a decision procedure, and everything outside that list passes:
# a PR opened outside this session (the GitHub web UI, another machine, CI) is
# never seen at all, because a PreToolUse hook only observes tool calls made
# here; and an invocation this splitter cannot see in command position -- inside
# a quoted string, behind an alias, through a variable, or via an HTTP client
# hitting the REST API directly -- is not matched. The enumeration is a floor on
# accidents, never a ceiling on intent.
#
# A CALL ABOUT ANOTHER REPOSITORY IS NOT THIS CHECKOUT'S TO JUDGE
#
# Both surfaces read the repository the call names and stand down when it is not
# this one -- a verdict about a branch this checkout cannot see is a false red
# when there is no record here and a false green when there is. The MCP surface
# always did; the CLI surface did not, so `gh pr create --repo other/thing` was
# answered out of THIS branch's state (#816). And the name of "this one" comes
# from the remote rather than from the checkout's own basename: CLAUDE.md
# requires every feature branch to live in a linked worktree, whose basename is
# the worktree slug, so reading that made every call compare unequal and go
# silent in the one place all feature work happens.
#
# FAILING OPEN IS DELIBERATE
#
# A gate on opening a pull request is a new way for the tool to be wrong at the
# worst moment, so every inability to DETERMINE an answer -- not a git repo, no
# base ref, no artifacts directory, no jq -- exits 0 and lets the human proceed.
# Only a determinate "the record is missing" denies, and even that has an escape
# hatch that PROCEEDS while recording that it was unchecked.
# =============================================================================

set -uo pipefail

# CDPATH= on both: `cd` prints the resolved directory when it resolves through
# CDPATH, which makes this a two-line string and REPO_ROOT empty -- measured,
# every verdict then collapsed to "undetermined" and the gate was silently dead.
# Both wired call sites use a bare relative path, which is the vulnerable form.
SCRIPT_DIR="$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(CDPATH='' cd -- "${SCRIPT_DIR}/.." && pwd)"
readonly SCRIPT_DIR REPO_ROOT

# `git -C` is NOT authoritative against these: a hook inherits its parent's
# environment, and anything run from a git hook or `git rebase --exec` carries
# them. Measured: with GIT_DIR pointing elsewhere this reported a verdict about
# a different repository, in both directions.
unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE GIT_COMMON_DIR GIT_OBJECT_DIRECTORY

readonly ARTIFACT_DIR="_bmad-output/implementation-artifacts"
readonly TRAILER_KEY="Adversarial-pass"

# The hatch's own placeholders, quoted verbatim in usage() and deny_reason().
# Pasting either of those unfenced into a PR body reproduces this text after
# "ADVERSARIAL_PASS_ACK=", which is not a reason anyone wrote -- it is this
# script's own instructions. Rejected by identity rather than by fencing, so
# it stays refused whether or not the paste happens to be fenced.
readonly -a ACK_PLACEHOLDERS=("<why this PR needs no pass>" "<reason>")

is_ack_placeholder() {
	local candidate="$1" p
	for p in "${ACK_PLACEHOLDERS[@]}"; do
		[[ "${candidate}" == "${p}" ]] && return 0
	done
	return 1
}

# A heading alone is not a record, and neither is a colon with nothing after it.
# The live example (#789) runs to ~40 lines; these floors reject a stub without
# demanding a particular length.
readonly MIN_RECORD_LINES=3
readonly MIN_RECORD_CHARS=200
readonly MIN_TRAILER_CHARS=40

# A live registry is never the pass's record -- it is a pending-only list. Only
# `.md` candidates reach this, so `sprint-status.yaml` needs no entry and having
# one only suggested the list did more than it does.
readonly -a NON_RECORD_FILES=("deferred-work.md")

MODE="report"
BASE_REF=""
# Declared before the option loop reads --surface; the hook overwrites it from
# the payload.
SURFACE=""

usage() {
	cat <<'EOF'
usage: adversarial-pass-check.sh [options]

  --strict       exit 1 when the branch carries no adversarial-pass record
  --hook         PreToolUse mode: read the hook payload on stdin and emit a
                 permission decision as JSON (always exits 0)
  --self-test    run the fixtures and prove the gate fails in both directions
  --surface S    name the escape hatch of surface S (cli|mcp) in a denial;
                 the hook infers this and does not need it
  --ack-from-body
                 read a pull request body on stdin and print the acknowledgement
                 it carries, if any (empty otherwise); always exits 0
  --base-ref R   git ref the branch is measured against
                 (default: the base named by the gated command, else
                 origin/main, falling back to main)
  -h, --help     show this help

Escape hatch:
  ADVERSARIAL_PASS_ACK="<reason>" proceeds without a record and records the
  reason. On the CLI surface write it as an assignment prefix on the gated
  command; on the GitHub MCP surface write it on its own line in the PR body;
  it is also honoured from the real environment when one is set.
EOF
}

while (( $# > 0 )); do
	case "$1" in
		--strict) MODE="strict"; shift ;;
		--hook) MODE="hook"; shift ;;
		--self-test) MODE="self-test"; shift ;;
		--ack-from-body) MODE="ack-from-body"; shift ;;
		--surface)
			# Which escape hatch a denial should name. The hook infers it from
			# the payload; a caller outside the hook has to say, or the denial
			# tells the reader to write an assignment prefix on a command they
			# are not running.
			if (( $# < 2 )) || [[ "$2" == -* ]]; then
				echo "--surface needs cli or mcp" >&2; exit 2
			fi
			case "$2" in
				cli|mcp) SURFACE="$2" ;;
				*) echo "--surface takes cli or mcp, not $2" >&2; exit 2 ;;
			esac
			shift 2 ;;
		--base-ref)
			# `shift 2` with one argument left shifts nothing and returns
			# non-zero, which `set -uo pipefail` (no -e) ignores -- measured
			# spinning at 100% CPU for ever. And a bare `--strict` swallowed as
			# the ref silently disabled strictness.
			if (( $# < 2 )) || [[ "$2" == -* ]]; then
				echo "--base-ref needs a ref argument" >&2; exit 2
			fi
			BASE_REF="$2"; shift 2 ;;
		-h|--help) usage; exit 0 ;;
		*) echo "unknown option: $1" >&2; usage >&2; exit 2 ;;
	esac
done

git_c() { git -C "${REPO_ROOT}" "$@"; }

# --- The record, as content --------------------------------------------------
#
# ONE program, used for both "is there a record" and "is it the same record as
# the base". They used to be two near-identical awk programs, and the divergence
# that mattered was not where the section began -- 4000 randomised documents
# found them agreeing exactly -- but WHAT each counted: one compared raw lines
# while the other counted stripped content, so a single trailing space inside
# the section read as a change. Emitting the normalised lines once removes the
# question rather than keeping the two in step.
#
# The section runs from its heading to the next heading of the same or a higher
# level, so a `###` sub-heading inside the pass is part of it while the next
# `##` ends it. Headings and blank lines are not content: a section of nothing
# but sub-headings is a stub, not a record.
#
# Fence-aware, because showing the required shape must not perform it -- an
# artifact that merely documents this gate inside a ``` block is a template, and
# it was measured passing as a record.
section_lines() {
	awk '
		NR == 1 { sub(/^\xef\xbb\xbf/, "") }          # a BOM hid the first heading
		{
			line = $0
			sub(/\r$/, "", line)                      # CRLF

			fence = line
			gsub(/[ \t]/, "", fence)
			if (fence ~ /^(```|~~~)/) { in_fence = !in_fence; next }
			if (in_fence) { if (inside) print normalise(line); next }

			level = 0
			if (match(line, /^#+[ \t]/)) { level = RLENGTH - 1 }

			if (level >= 2 && level <= 3) {
				title = tolower(line)
				sub(/^#+[ \t]+/, "", title)
				gsub(/[^a-z]+/, " ", title)
				gsub(/^ | $/, "", title)
				if (title == "adversarial pass" || title == "pasada adversarial") {
					inside = 1
					next
				}
			}

			if (inside && level > 0 && level <= 2) { inside = 0 }
			if (!inside || level > 0) { next }

			out = normalise(line)
			if (out != "") { print out }
		}
		function normalise(s) {
			gsub(/[ \t]+/, " ", s)
			gsub(/^ | $/, "", s)
			return s
		}
	'
}

has_record() {
	section_lines | awk '
		{ lines++; chars += length($0) }
		END { exit (lines >= '"${MIN_RECORD_LINES}"' && chars >= '"${MIN_RECORD_CHARS}"') ? 0 : 1 }
	'
}

# How much of a candidate's section is NEW relative to one already on the base.
#
# Section identity used to be the whole test: a candidate whose normalised
# section matched one on the base was refused, anything else accepted. That
# bounds WHETHER the section changed and never HOW MUCH, and the section-level
# floors are already satisfied by the pre-existing text -- so appending a single
# word to somebody else's pass turned the gate green. Measured, on the shape
# #816 records:
#
#     printf 'ok\n' >> _bmad-output/implementation-artifacts/br-1-old-story.md
#     git commit -qam nudge
#     ./scripts/adversarial-pass-check.sh --base-ref main --strict
#     -> the row went green
#
# The floors therefore move onto the DELTA: the normalised lines this branch's
# section has and a base section does not must clear MIN_RECORD_LINES and
# MIN_RECORD_CHARS on their own. Equality is the delta-of-zero case, so this
# SUBSUMES the identity test rather than sitting beside it -- a rename, a copy
# and a whitespace nudge are all still refused, by the same arithmetic.
#
# Set membership, not a line diff, and that is deliberate: a line moved within
# the section is not new evidence, and neither is one deleted and restored.
delta_clears_floor() {
	awk -v lines_floor="${MIN_RECORD_LINES}" -v chars_floor="${MIN_RECORD_CHARS}" '
		NR == FNR { if ($0 != "") { on_base[$0] = 1 } ; next }
		$0 != "" && !($0 in on_base) { lines++; chars += length($0) }
		END { exit (lines >= lines_floor && chars >= chars_floor) ? 0 : 1 }
	' <(printf '%s\n' "$1") <(printf '%s\n' "$2")
}

# --- Command text ------------------------------------------------------------
#
# Segments of a command line, NUL-delimited, split on separators that are not
# inside quotes. Quote awareness is the whole point: splitting blindly made
# `git commit -m "a; <invocation>"` a denial, which refuses the very act by
# which the record gets written, and splitting on every newline made every
# multi-paragraph commit message a denial too. A quoted separator is text.
#
# Braces and parentheses are separators because `( <invocation> )` and
# `{ <invocation>; }` reach command position through them.
split_segments() {
	printf '%s' "$1" | awk '
		function flush(  ) { printf "%s%c", seg, 0; seg = "" }
		{
			line = $0
			if (in_heredoc) {
				body = line
				gsub(/^[ \t]+|[ \t]+$/, "", body)
				if (body == delim) { in_heredoc = 0 }
				next
			}
			n = length(line)
			for (i = 1; i <= n; i++) {
				c = substr(line, i, 1)
				if (c == "\\" && !sq) {
					seg = seg c
					if (i < n) { i++; seg = seg substr(line, i, 1) }
					continue
				}
				if (c == SQ && !dq) { sq = !sq; seg = seg c; continue }
				if (c == DQ && !sq) { dq = !dq; seg = seg c; continue }
				if (!sq && !dq && c == "<" && substr(line, i + 1, 1) == "<" && substr(line, i + 2, 1) != "<") {
					# A heredoc: its body is data, not commands. Without this,
					# every line of a document written through one becomes a
					# command position — which denied the very commands that
					# write this gate down.
					rest = substr(line, i + 2)
					sub(/^-/, "", rest)
					sub(/^[ \t]+/, "", rest)
					if (match(rest, /^["'"'"']?[A-Za-z_][A-Za-z0-9_]*["'"'"']?/)) {
						delim = substr(rest, RSTART, RLENGTH)
						gsub(/["'"'"']/, "", delim)
						in_heredoc = 1
					}
					seg = seg "<<"
					i++
					continue
				}
				if (!sq && !dq && index(SEPS, c) > 0) { flush(); continue }
				seg = seg c
			}
			# An unquoted end of line ends the command; a quoted one is text.
			if (sq || dq) { seg = seg "\n" } else { flush() }
		}
		END { flush() }
	' SQ="'" DQ='"' SEPS=';&|(){}'
}

# Strips what may legitimately precede the program on a segment: an `env`, a run
# of VAR=value assignments (the value may be quoted and hold spaces), a shell
# keyword that opens a compound command, and the wrappers that run a program
# under another name. Each of these was measured reaching an unquoted
# invocation while the gate stayed silent.
strip_prefixes() {
	local seg="$1" previous=""
	seg="${seg#"${seg%%[![:space:]]*}"}"
	while [[ "${seg}" != "${previous}" ]]; do
		previous="${seg}"
		case "${seg}" in
			\\*) seg="${seg#\\}" ;;
			env\ *|sudo\ *|command\ *|exec\ *|nohup\ *|time\ *|then\ *|do\ *|else\ *|elif\ *)
				seg="${seg#* }" ;;
		esac
		while [[ "${seg}" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]]; do
			seg="${seg#*=}"
			case "${seg}" in
				'"'*) seg="${seg#\"}"; seg="${seg#*\"}" ;;
				"'"*) seg="${seg#\'}"; seg="${seg#*\'}" ;;
				*)    seg="${seg#"${seg%%[[:space:]]*}"}" ;;
			esac
			seg="${seg#"${seg%%[![:space:]]*}"}"
		done
		seg="${seg#"${seg%%[![:space:]]*}"}"
	done
	printf '%s' "${seg}"
}

# The spellings recognised as opening a pull request. `gh api ... pulls` is the
# documented REST route and is the obvious next thing to reach for after reading
# a denial that names only the porcelain one; an absolute or relative path to
# the binary is the same program.
segment_opens_pr() {
	local program
	program="$(strip_prefixes "$1")"
	[[ "${program}" =~ ^([^[:space:]]*/)?gh[[:space:]]+pr[[:space:]]+create([^[:alnum:]_-]|$) ]] && return 0
	[[ "${program}" =~ ^([^[:space:]]*/)?gh[[:space:]]+api([[:space:]]|$) ]] && [[ "${program}" == *pulls* ]] && return 0
	return 1
}

# The repository THIS checkout belongs to, as a bare name.
#
# `basename "${REPO_ROOT}"` was the whole answer, and it is wrong in exactly the
# place CLAUDE.md requires the work to happen: a linked worktree's root is
# `.claude/worktrees/<slug>`, so the name read back is the slug. Measured, from
# a worktree, an MCP create_pull_request naming this very repository compared
# unequal and the call was declared not-applicable -- the gate silently dead
# across every worktree, which is where every feature branch lives. The remote
# is the authority; the SHARED git directory is the fallback, because that is
# the one path a linked worktree and its primary agree on.
#
# A non-zero return means "cannot determine", never a guess: `basename
# REPO_ROOT` used to be the last resort, and it is the exact defect this
# function exists to fix -- a linked worktree's root is its slug, and a
# `--separate-git-dir`/submodule/bare-mirror layout with no origin remote
# would fall through to the same wrong guess. Every caller must treat "cannot
# determine" as "apply the check", not as a name to compare against.
this_repo_name() {
	local url name common
	url="$(git_c remote get-url origin 2>/dev/null || true)"
	if [[ -n "${url}" ]]; then
		name="${url%/}"; name="${name%.git}"; name="${name##*[:/]}"
		[[ -n "${name}" ]] && { printf '%s' "${name}"; return 0; }
	fi
	common="$(git_c rev-parse --git-common-dir 2>/dev/null || true)"
	if [[ -n "${common}" ]]; then
		common="$(CDPATH='' cd -- "${common}" 2>/dev/null && pwd)"
		if [[ "${common}" == */.git ]]; then
			name="$(basename -- "${common%/.git}")"
			[[ -n "${name}" ]] && { printf '%s' "${name}"; return 0; }
		fi
	fi
	return 1
}

# One segment, split into its ARGUMENTS the way the shell would: NUL-delimited,
# quotes honoured and stripped, backslash escapes consumed.
#
# Reading a flag out of the raw segment text is the defect this file already
# records for the escape hatch -- matching a name anywhere let a `--body` that
# merely QUOTED the denial message acknowledge itself. Measured, the same shape
# on the flags below is worse, because it fails toward silence:
#
#     gh pr create --title "chore: pass --repo other/thing to gh"
#
# named no repository, and a text match read `other/thing` out of the title and
# declared the whole call not-applicable. A green over an unrecorded pull
# request, from a string the author controls. Arguments, not text.
segment_args() {
	printf '%s' "$1" | awk '
		function flush(  ) { if (started) { printf "%s%c", word, 0; word = ""; started = 0 } }
		{
			n = length($0)
			for (i = 1; i <= n; i++) {
				c = substr($0, i, 1)
				if (c == "\\" && !sq) {
					if (i < n) { i++; word = word substr($0, i, 1); started = 1 }
					continue
				}
				if (c == SQ && !dq) { sq = !sq; started = 1; continue }
				if (c == DQ && !sq) { dq = !dq; started = 1; continue }
				if (!sq && !dq && (c == " " || c == "\t")) { flush(); continue }
				word = word c; started = 1
			}
			# A newline inside quotes is part of the word; outside, it ends it.
			if (sq || dq) { word = word "\n"; started = 1 } else { flush() }
		}
		END { flush() }
	' SQ="'" DQ='"'
}

# The repository a matched segment names, as a bare name. A non-zero return
# means it names none, which is the ordinary case and means this checkout.
#
# The MCP surface has judged this since it was written: a create_pull_request
# for another repository is not-applicable, because a verdict about a branch
# this checkout cannot see is a false red when there is no record here and a
# false green when there is. The CLI path had no equivalent, so
# `gh pr create --repo somebody-else/thing` was answered out of THIS branch's
# state. gh spells it `--repo`/`-R` and takes [HOST/]OWNER/REPO; the REST route
# carries the same name in its path. Only the last path segment is compared, so
# a fork of this repository still reads as this repository -- the same
# approximation the MCP path makes, since `tool_input.repo` is the bare name.
#
# The REST-route pattern is tested only on a `gh api` segment's own arguments,
# never on `gh pr create`'s -- matching it against ANY argument answered
# `gh pr create --title x --body "docs: also see /repos/o/other/pulls"` out of
# a repository the body merely MENTIONS, a false not-applicable and a silent,
# unrecorded pull request from a string the author controls. `gh pr create`
# already has `--repo`/`-R` for this; it never needs the route fallback.
#
# Repeated flags keep the LAST value, matching gh's own flag semantics (each
# occurrence overwrites the one before it) rather than the first: a call
# correcting an earlier `--repo` with a second one naming THIS repository was
# read from the first and judged not-applicable. A trailing `.git` is stripped
# the same way `this_repo_name` strips it from the origin URL, so a value
# copied from a remote URL still compares equal.
segment_target_repo() {
	local -a args=()
	local arg value="" i count
	local program is_api=0
	program="$(strip_prefixes "$1")"
	[[ "${program}" =~ ^([^[:space:]]*/)?gh[[:space:]]+api([[:space:]]|$) ]] && is_api=1
	while IFS= read -r -d '' arg; do args+=("${arg}"); done < <(segment_args "$1")
	count=${#args[@]}
	for (( i = 0; i < count; i++ )); do
		arg="${args[i]}"
		case "${arg}" in
			--repo=*) value="${arg#--repo=}" ;;
			--repo|-R) (( i + 1 < count )) && { value="${args[i + 1]}"; (( i++ )); } ;;
			*)
				if (( is_api )) && [[ "${arg}" =~ (^|/)repos/[A-Za-z0-9._-]+/([A-Za-z0-9._-]+)/pulls ]]; then
					value="${BASH_REMATCH[2]}"
				fi ;;
		esac
	done
	value="${value%/}"
	value="${value%.git}"
	[[ -n "${value}" ]] || return 1
	printf '%s' "${value##*/}"
}

# The base branch a matched segment names, read as an ARGUMENT for the same
# reason: a `--base` lifted out of a quoted title picks the wrong merge base,
# and a wrong merge base is a wrong verdict in whichever direction it lands.
# Keeps the LAST occurrence, for the same reason segment_target_repo does.
segment_base_ref() {
	local -a args=()
	local arg i count value="" found=1
	while IFS= read -r -d '' arg; do args+=("${arg}"); done < <(segment_args "$1")
	count=${#args[@]}
	for (( i = 0; i < count; i++ )); do
		arg="${args[i]}"
		case "${arg}" in
			--base=*) value="${arg#--base=}"; found=0 ;;
			--base|-B) (( i + 1 < count )) && { value="${args[i + 1]}"; found=0; (( i++ )); } ;;
		esac
	done
	(( found == 0 )) || return 1
	printf '%s' "${value}"
}

# The acknowledgement, read only as an assignment PREFIX on the segment that is
# opening the pull request. Matching the name anywhere was measured letting a
# `--body` that merely quotes this script's own denial message acknowledge
# itself, recording `"<reason>"` as the reason -- so the audit trail the design
# leans on recorded nothing. An empty or whitespace-only value is not an
# acknowledgement.
extract_ack_prefix() {
	local seg="$1" rest value
	seg="${seg#"${seg%%[![:space:]]*}"}"
	while :; do
		case "${seg}" in
			env\ *|sudo\ *|command\ *|exec\ *|nohup\ *|time\ *) seg="${seg#* }"; continue ;;
		esac
		[[ "${seg}" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]] || return 1
		if [[ "${seg}" == ADVERSARIAL_PASS_ACK=* ]]; then
			rest="${seg#ADVERSARIAL_PASS_ACK=}"
			case "${rest}" in
				'"'*) rest="${rest#\"}"; value="${rest%%\"*}" ;;
				"'"*) rest="${rest#\'}"; value="${rest%%\'*}" ;;
				*)    value="${rest%%[[:space:]]*}" ;;
			esac
			value="${value#"${value%%[![:space:]]*}"}"
			value="${value%"${value##*[![:space:]]}"}"
			[[ -n "${value}" ]] && ! is_ack_placeholder "${value}" && { printf '%s' "${value}"; return 0; }
			return 1
		fi
		seg="${seg#*=}"
		case "${seg}" in
			'"'*) seg="${seg#\"}"; seg="${seg#*\"}" ;;
			"'"*) seg="${seg#\'}"; seg="${seg#*\'}" ;;
			*)    seg="${seg#"${seg%%[[:space:]]*}"}" ;;
		esac
		seg="${seg#"${seg%%[![:space:]]*}"}"
	done
}

# On the MCP surface there is no command line, so the hatch has to live in the
# only free-text field the call carries. Anchored to the start of a line, so a
# body that DESCRIBES the hatch in a sentence does not trigger it. A body that
# QUOTES this script's own placeholder unfenced (a paste of usage() or
# deny_reason()'s hatch text) is the same shape and is refused the same way.
extract_ack_body() {
	local value
	value="$(printf '%s' "$1" | sed -n 's/^ADVERSARIAL_PASS_ACK=["'"'"']\{0,1\}\([^"'"'"']*\).*/\1/p' | head -1)"
	is_ack_placeholder "${value}" && return
	printf '%s' "${value}"
}

# --- The payload -------------------------------------------------------------

ACK_FROM_PAYLOAD=""
BASE_FROM_PAYLOAD=""

# PreToolUse delivers {"tool_name": ..., "tool_input": {...}} on stdin. The
# matcher in settings.json is deliberately wider than the thing being gated,
# because two different surfaces open pull requests here and a matcher naming
# only one of them would be dead on the other: the CLI arrives as a Bash
# command, while a remote session has no CLI installed at all and goes through
# the GitHub MCP server. Both are matched; this narrows Bash to command
# position.
#
# Anything unparseable is not applicable -- a hook that denies because it could
# not read its own input is the wedged branch this design refuses.
hook_applies() {
	local payload="$1"

	command -v jq >/dev/null 2>&1 || return 1
	[[ -n "${payload}" ]] || return 1

	local tool_name
	tool_name="$(jq -r '.tool_name // empty' <<<"${payload}" 2>/dev/null || true)"
	[[ -n "${tool_name}" ]] || return 1

	case "${tool_name}" in
		*create_pull_request*)
			SURFACE="mcp"
			local this_repo target_repo body
			target_repo="$(jq -r '.tool_input.repo // empty' <<<"${payload}" 2>/dev/null || true)"
			this_repo="$(this_repo_name || true)"
			# A call targeting a different repository is not this checkout's to
			# judge: judging it would be a verdict about the wrong branch. But
			# that verdict needs a known name to compare against -- when this
			# checkout's own name cannot be determined, guessing one wrong is
			# the exact defect `this_repo_name` refuses; apply the check
			# instead of silently standing down.
			if [[ -n "${target_repo}" ]] && [[ -n "${this_repo}" ]] && [[ "${target_repo,,}" != "${this_repo,,}" ]]; then
				return 1
			fi
			BASE_FROM_PAYLOAD="$(jq -r '.tool_input.base // empty' <<<"${payload}" 2>/dev/null || true)"
			body="$(jq -r '.tool_input.body // empty' <<<"${payload}" 2>/dev/null || true)"
			ACK_FROM_PAYLOAD="$(extract_ack_body "${body}")"
			return 0 ;;
		Bash) SURFACE="cli" ;;
		*) return 1 ;;
	esac

	local command_text
	command_text="$(jq -r '.tool_input.command // empty' <<<"${payload}" 2>/dev/null || true)"
	[[ -n "${command_text}" ]] || return 1

	# Cheap prefilter: the splitter walks the text character by character, and a
	# command can be megabytes. Nothing without one of these substrings can
	# match, so the common case never reaches the walk.
	[[ "${command_text}" == *"pr create"* || "${command_text}" == *pulls* ]] || return 1

	local segment matched=1 target_repo this_repo
	this_repo="$(this_repo_name || true)"
	while IFS= read -r -d '' segment; do
		segment_opens_pr "${segment}" || continue
		# A segment naming another repository is not this checkout's to judge,
		# mirroring what the MCP path already does. `continue`, not `break`: a
		# later segment may still open one here. Same rule as the MCP branch on
		# an unknown `this_repo`: apply the check rather than guess a name.
		target_repo="$(segment_target_repo "${segment}" || true)"
		if [[ -n "${target_repo}" ]] && [[ -n "${this_repo}" ]] && [[ "${target_repo,,}" != "${this_repo,,}" ]]; then
			continue
		fi
		matched=0
		ACK_FROM_PAYLOAD="$(extract_ack_prefix "${segment}" || true)"
		BASE_FROM_PAYLOAD="$(segment_base_ref "${segment}" || true)"
		break
	done < <(split_segments "${command_text}")
	return "${matched}"
}

# --- Verdict -----------------------------------------------------------------
#
# VERDICT is one of: record | missing | undetermined | acknowledged.

VERDICT=""
DETAIL=""
UNCOMMITTED_HINT=""

acknowledgement() {
	[[ -n "${ADVERSARIAL_PASS_ACK:-}" ]] && { printf '%s' "${ADVERSARIAL_PASS_ACK}"; return 0; }
	[[ -n "${ACK_FROM_PAYLOAD}" ]] && { printf '%s' "${ACK_FROM_PAYLOAD}"; return 0; }
	return 1
}

is_non_record_file() {
	local base skip
	base="$(basename -- "$1")"
	for skip in "${NON_RECORD_FILES[@]}"; do
		[[ "${base}" == "${skip}" ]] && return 0
	done
	return 1
}

decide() {
	local ack
	if ack="$(acknowledgement)" && [[ -n "${ack}" ]]; then
		VERDICT="acknowledged"; DETAIL="${ack}"; return
	fi

	if ! git_c rev-parse --is-inside-work-tree >/dev/null 2>&1; then
		VERDICT="undetermined"; DETAIL="not inside a git work tree"; return
	fi

	local resolved="${BASE_REF}" candidate
	if [[ -z "${resolved}" ]]; then
		for candidate in "${BASE_FROM_PAYLOAD}" "origin/${BASE_FROM_PAYLOAD}" origin/main main; do
			[[ -n "${candidate}" ]] || continue
			if git_c rev-parse --verify --quiet "${candidate}" >/dev/null 2>&1; then
				resolved="${candidate}"; break
			fi
		done
	fi
	if [[ -z "${resolved}" ]]; then
		VERDICT="undetermined"; DETAIL="no base ref resolvable (tried origin/main, main)"; return
	fi

	local merge_base head_sha
	merge_base="$(git_c merge-base "${resolved}" HEAD 2>/dev/null || true)"
	if [[ -z "${merge_base}" ]]; then
		VERDICT="undetermined"; DETAIL="no merge base with ${resolved}"; return
	fi
	head_sha="$(git_c rev-parse HEAD 2>/dev/null || true)"
	if [[ "${merge_base}" == "${head_sha}" ]] && [[ -z "$(git_c status --porcelain 2>/dev/null)" ]]; then
		VERDICT="undetermined"; DETAIL="branch carries nothing over ${resolved} — nothing to review"; return
	fi

	# --- Witness 1: a commit trailer, read with git's own trailer parser ------
	local sha value stripped
	while IFS= read -r sha; do
		[[ -n "${sha}" ]] || continue
		value="$(git_c log -1 --format="%(trailers:key=${TRAILER_KEY},valueonly)" "${sha}" 2>/dev/null || true)"
		stripped="$(printf '%s' "${value}" | tr -d '[:space:]')"
		if (( ${#stripped} >= MIN_TRAILER_CHARS )); then
			VERDICT="record"
			DETAIL="${TRAILER_KEY}: trailer on $(git_c log -1 --format='%h %s' "${sha}" 2>/dev/null)"
			return
		fi
	done < <(git_c rev-list "${merge_base}..HEAD" 2>/dev/null)

	# --- Witness 2: an artifact COMMITTED on this branch ----------------------
	if [[ ! -d "${REPO_ROOT}/${ARTIFACT_DIR}" ]]; then
		VERDICT="undetermined"; DETAIL="no ${ARTIFACT_DIR} directory"; return
	fi

	local -a candidates=()
	local path
	while IFS= read -r -d '' path; do
		[[ -n "${path}" ]] || continue
		[[ "${path}" == *.md ]] || continue
		is_non_record_file "${path}" && continue
		candidates+=("${path}")
	done < <(git_c diff -z --name-only "${merge_base}...HEAD" -- "${ARTIFACT_DIR}" 2>/dev/null)

	# Every adversarial-pass section already present on the base, pooled into
	# ONE set of normalised lines rather than kept apart per file. Checking a
	# candidate against each base section SEPARATELY (accept if it clears the
	# floor against every one, taken one at a time) missed a candidate built by
	# concatenating two DIFFERENT prior passes: against pass A alone, all of
	# pass B's lines read as new, and against pass B alone, all of pass A's
	# lines read as new -- both checks clear the floor even though the whole
	# candidate is a copy of pre-existing content, zero lines of which this
	# branch actually wrote. Pooling first closes that: a line already on
	# EITHER prior pass no longer counts as new against the union.
	#
	# The trade running the other way is real and stays unaddressed here: a
	# genuinely new pass that happens to share a few lines of house-style
	# phrasing with an unrelated older one now has those lines subtracted too,
	# which was never true of the per-file check. At these floors (3
	# lines/200 chars against a live example of ~40 lines) that costs a false
	# `missing` only for a candidate that is mostly overlap, and the escape
	# hatch is the recorded, always-available answer to a false `missing` --
	# unlike the exploit above, which recorded nothing at all.
	local base_pool="" base_path base_section
	while IFS= read -r -d '' base_path; do
		[[ "${base_path}" == *.md ]] || continue
		base_section="$(git_c show "${merge_base}:${base_path}" 2>/dev/null | section_lines)"
		[[ -n "${base_section}" ]] && base_pool="${base_pool}${base_section}
"
	done < <(git_c ls-tree -r -z --name-only "${merge_base}" -- "${ARTIFACT_DIR}" 2>/dev/null)

	local found="" reason="" content candidate_section
	for path in "${candidates[@]}"; do
		content="$(git_c show "HEAD:${path}" 2>/dev/null || true)"
		[[ -n "${content}" ]] || continue
		printf '%s' "${content}" | has_record || continue
		candidate_section="$(printf '%s' "${content}" | section_lines)"
		if delta_clears_floor "${base_pool}" "${candidate_section}"; then
			found="${path}"; break
		fi
		reason="${reason}
    ${path} (adds too little to the passes already on ${resolved})"
	done

	if [[ -n "${found}" ]]; then
		VERDICT="record"; DETAIL="## Adversarial pass in ${found}"
		return
	fi

	# A record that exists only in the working tree is the #770 shape exactly,
	# so it is not a green -- but saying so precisely is the difference between
	# a gate and an obstacle.
	local dirty
	while IFS= read -r -d '' dirty; do
		dirty="${dirty:3}"
		[[ "${dirty}" == *.md ]] || continue
		is_non_record_file "${dirty}" && continue
		[[ -f "${REPO_ROOT}/${dirty}" ]] || continue
		if has_record < "${REPO_ROOT}/${dirty}"; then
			UNCOMMITTED_HINT="${dirty}"
			break
		fi
	done < <(git_c status -z --porcelain -uall -- "${ARTIFACT_DIR}" 2>/dev/null)

	VERDICT="missing"
	if (( ${#candidates[@]} > 0 )); then
		DETAIL="$(printf '%s\n' "${candidates[@]}" | sed 's/^/    /')${reason}"
	else
		DETAIL="    (this branch commits no artifact under ${ARTIFACT_DIR})${reason}"
	fi
}

# --- Report ------------------------------------------------------------------

deny_reason() {
	local hatch
	if [[ "${SURFACE}" == "mcp" ]]; then
		hatch="  put a line reading  ADVERSARIAL_PASS_ACK=<why this PR needs no pass>
  in the pull request body."
	else
		hatch="  ADVERSARIAL_PASS_ACK=\"<why this PR needs no pass>\" <your command>"
	fi

	cat <<EOF
No adversarial-pass record on this branch.

CLAUDE.md requires the pass to run AND its findings to be written down BEFORE
the pull request exists — the rule failed three times (#616, #620, #770) with
nothing but prose enforcing it, so it is enforced here now.

Record it one of two ways, then retry:
  - a '## Adversarial pass' section in an artifact under ${ARTIFACT_DIR}/,
    committed on this branch, adding at least ${MIN_RECORD_LINES} lines and
    ${MIN_RECORD_CHARS} characters over any pass already on the base, or
  - an '${TRAILER_KEY}:' trailer of at least ${MIN_TRAILER_CHARS} characters on a
    commit of this branch.

Artifacts this branch commits under that directory, none of which carry a new
adversarial-pass section:
${DETAIL}
$(if [[ -n "${UNCOMMITTED_HINT}" ]]; then printf '%s\n' "
A record DOES exist in your working tree, uncommitted:
    ${UNCOMMITTED_HINT}
Commit it, then retry. An uncommitted record is the exact state #770 shipped:
the head the pull request opens from does not contain it."; fi)
If the pass genuinely does not apply, proceed on the record:
${hatch}
EOF
}

json_string() { jq -Rs .; }

case "${MODE}" in
	hook)
		payload=""
		if [[ ! -t 0 ]]; then
			payload="$(timeout 5 cat 2>/dev/null || true)"
		fi
		hook_applies "${payload}" || exit 0

		decide

		if [[ "${VERDICT}" != "missing" ]]; then
			if [[ "${VERDICT}" == "acknowledged" ]]; then
				printf '{"systemMessage":%s}\n' \
					"$(printf '%s' "⚠ adversarial-pass: proceeding UNCHECKED — acknowledged: ${DETAIL}" | json_string)"
			fi
			exit 0
		fi
		printf '{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"deny","permissionDecisionReason":%s}}\n' \
			"$(deny_reason | json_string)"
		exit 0
		;;
	self-test) ;;
	ack-from-body)
		# For the server-side check, which has a PR body and no command line.
		# Exposed rather than re-implemented in YAML: two spellings of "what
		# counts as an acknowledgement" would drift, and the cheaper one always
		# wins.
		body=""
		[[ -t 0 ]] || body="$(timeout 5 cat 2>/dev/null || true)"
		extract_ack_body "${body}"
		exit 0
		;;
	*)
		decide
		case "${VERDICT}" in
			record)       echo "✓ adversarial-pass: ${DETAIL}"; exit 0 ;;
			acknowledged) echo "⚠ adversarial-pass: UNCHECKED — acknowledged: ${DETAIL}"; exit 0 ;;
			undetermined) echo "· adversarial-pass: undetermined — ${DETAIL}"; exit 0 ;;
			missing)
				echo "✗ adversarial-pass: no record on this branch"
				echo
				deny_reason | sed 's/^/  /'
				[[ "${MODE}" == "strict" ]] && exit 1
				exit 0 ;;
		esac
		;;
esac

# --- Self-test ---------------------------------------------------------------
#
# A gate nobody falsified is a gate nobody can trust. Every row here refuses a
# near-miss shape rather than merely accepting the real one, and every row was
# checked by mutating the code and watching it go red.

FAILURES=0

pass_row() { echo "  ✓ $1"; }
fail_row() { echo "  ✗ $1"; FAILURES=$(( FAILURES + 1 )); }

LONG_BODY="$(printf 'Two layers ran with fresh read-only context over the branch diff; neither failed.\nApplied 10 patches, two of which demolish a claim the spec itself made.\nRejected 2 with measurements, deferred 3 as pre-existing and unchanged here.\n')"
SECOND_BODY="$(printf 'A second read, by a fresh context that did not see the first, over the same branch diff.\nIt found three defects the first pass missed, one of them a wrong-green over an empty fixture.\nTwo were fixed in place; the third is recorded as a residual, with the measurement that bounds it.\n')"
THIRD_BODY="$(printf 'An unrelated story, reviewed on a different day against a different diff entirely.\nIts own pass found a query missing a parameter binding and one N+1 in a list endpoint.\nBoth were fixed; a migration index was added and confirmed against a fresh plan.\n')"

check() {
	local name="$1" expect="$2" doc="$3"
	if printf '%s' "${doc}" | has_record; then
		if [[ "${expect}" == accept ]]; then pass_row "${name}"; else fail_row "${name}: accepted, expected reject"; fi
	else
		if [[ "${expect}" == reject ]]; then pass_row "${name}"; else fail_row "${name}: rejected, expected accept"; fi
	fi
}

echo "adversarial-pass-check --self-test"
echo
echo "record classifier"
check "no section at all"              reject "# Spec"$'\n\n'"## Intent"$'\n\n'"${LONG_BODY}"
check "heading only"                   reject "## Adversarial pass"$'\n\n'"## Next"
check "heading with a stub"            reject "## Adversarial pass"$'\n\n'"TODO"
check "populated section"              accept "## Adversarial pass"$'\n\n'"${LONG_BODY}"
check "spanish heading"                accept "## Pasada adversarial"$'\n\n'"${LONG_BODY}"
check "level-3 heading"                accept "### Adversarial pass"$'\n\n'"${LONG_BODY}"
check "content stops at next ##"       reject "## Adversarial pass"$'\n\n'"## Verification"$'\n\n'"${LONG_BODY}"
check "sub-heading stays inside"       accept "## Adversarial pass"$'\n\n'"### Findings"$'\n\n'"${LONG_BODY}"
check "headings are not content"       reject "## Adversarial pass"$'\n'"### a"$'\n'"### b"$'\n'"### c"$'\n'"### d"
check "many short lines, no substance" reject "## Adversarial pass"$'\n'"ok"$'\n'"ok"$'\n'"ok"$'\n'"ok"$'\n'"ok"
check "one long line is not a section" reject "## Adversarial pass"$'\n'"$(printf 'x%.0s' {1..300})"
check "prose mention is not a heading" reject "The ## Adversarial pass section is required."$'\n'"${LONG_BODY}"
check "fenced example is a template"   reject "# Template"$'\n\n'"Write this:"$'\n\n''```'$'\n'"## Adversarial pass"$'\n\n'"${LONG_BODY}"$'\n''```'
check "fence inside a real section"    accept "## Adversarial pass"$'\n\n'"${LONG_BODY}"$'\n''```'$'\n'"## not a heading"$'\n''```'
check "a BOM does not hide the heading" accept $'\xef\xbb\xbf'"## Adversarial pass"$'\n\n'"${LONG_BODY}"
check "CRLF line endings"              accept "$(printf '## Adversarial pass\r\n\r\n%s' "${LONG_BODY}" | sed 's/$/\r/')"

# `base` is a pass the base branch already carries; `candidate` is what this
# branch offers as its own. `derivative` is the verdict that refuses it.
delta_is() {
	local name="$1" expect="$2" base="$3" candidate="$4" got
	if delta_clears_floor "$(printf '%s' "${base}" | section_lines)" "$(printf '%s' "${candidate}" | section_lines)"; then
		got=new
	else
		got=derivative
	fi
	if [[ "${got}" == "${expect}" ]]; then pass_row "${name}"; else fail_row "${name}: ${got}, expected ${expect}"; fi
}

BASE_PASS="## Adversarial pass"$'\n\n'"${LONG_BODY}"

echo
echo "section delta (what counts as THIS branch's pass rather than a copy of one)"
delta_is "an identical section"                 derivative "${BASE_PASS}" "${BASE_PASS}"
delta_is "a trailing space"                     derivative "${BASE_PASS}" "## Adversarial pass"$'\n\n'"${LONG_BODY} "
delta_is "a blank line"                         derivative "${BASE_PASS}" "## Adversarial pass"$'\n\n\n'"${LONG_BODY}"$'\n\n'
delta_is "an empty sub-heading"                 derivative "${BASE_PASS}" "${BASE_PASS}"$'\n'"### Findings"
delta_is "one word appended"                    derivative "${BASE_PASS}" "${BASE_PASS}"$'\n'"ok"
delta_is "one sentence appended"                derivative "${BASE_PASS}" "${BASE_PASS}"$'\n'"An eleventh patch, measured."
delta_is "the lines reordered"                  derivative "${BASE_PASS}" "## Adversarial pass"$'\n\n'"$(printf '%s\n' "${LONG_BODY}" | tac)"
delta_is "a whole second pass appended"         new        "${BASE_PASS}" "${BASE_PASS}"$'\n'"${SECOND_BODY}"
delta_is "a distinct pass under a new heading"  new        "${BASE_PASS}" "## Adversarial pass"$'\n\n'"${SECOND_BODY}"
delta_is "nothing on the base at all"           new        ""             "${BASE_PASS}"
delta_is "text outside the section"             derivative "# A"$'\n'"${BASE_PASS}" "# B (typo fixed)"$'\n'"${BASE_PASS}"

applies() {
	local name="$1" expect="$2" payload="$3"
	SURFACE=""; ACK_FROM_PAYLOAD=""; BASE_FROM_PAYLOAD=""
	if hook_applies "${payload}"; then
		if [[ "${expect}" == yes ]]; then pass_row "${name}"; else fail_row "${name}: applied, expected not-applicable"; fi
	else
		if [[ "${expect}" == no ]]; then pass_row "${name}"; else fail_row "${name}: not applicable, expected to apply"; fi
	fi
}

bash_payload() { printf '{"tool_name":"Bash","tool_input":{"command":%s}}' "$(printf '%s' "$1" | jq -Rs .)"; }

P="gh pr create"
# The repository this checkout is, resolved the same way the gate resolves it.
# Rows below that name a repository have to name THIS one, or they would be
# asserting the very not-applicable path the rows after them pin.
THIS_REPO="$(this_repo_name)"

echo
echo "applicability — it fires"
applies "bare invocation"            yes "$(bash_payload "${P} --title x")"
applies "after &&"                   yes "$(bash_payload "git push && ${P}")"
applies "after ;"                    yes "$(bash_payload "git push ; ${P}")"
applies "unquoted assignment prefix" yes "$(bash_payload "FOO=1 ${P}")"
applies "quoted prefix with a space" yes "$(bash_payload "FOO=\"a b\" ${P}")"
applies "env wrapper"                yes "$(bash_payload "env FOO=1 ${P}")"
applies "sudo wrapper"               yes "$(bash_payload "sudo ${P}")"
applies "command wrapper"            yes "$(bash_payload "command ${P}")"
applies "exec wrapper"               yes "$(bash_payload "exec ${P}")"
applies "nohup wrapper"              yes "$(bash_payload "nohup ${P}")"
applies "time wrapper"               yes "$(bash_payload "time ${P}")"
applies "backslash-escaped name"     yes "$(bash_payload "\\\\${P}")"
applies "absolute path"              yes "$(bash_payload "/usr/bin/${P}")"
applies "inside a subshell"          yes "$(bash_payload "( ${P} )")"
applies "inside a brace group"       yes "$(bash_payload "{ ${P}; }")"
applies "after then"                 yes "$(bash_payload "if true; then ${P}; fi")"
applies "after do"                   yes "$(bash_payload "for i in 1; do ${P}; done")"
applies "the REST route"             yes "$(bash_payload "gh api --method POST /repos/o/${THIS_REPO}/pulls -f title=x")"
applies "--repo naming this repo"    yes "$(bash_payload "${P} --repo o/${THIS_REPO}")"
applies "-R naming this repo"        yes "$(bash_payload "${P} -R o/${THIS_REPO}")"
applies "--repo quoted in a title"   yes "$(bash_payload "${P} --title \"chore: pass --repo other/thing to gh\"")"
applies "--repo quoted in a body"    yes "$(bash_payload "${P} --body \"see --repo somebody-else/thing\"")"
applies "-R quoted in a title"       yes "$(bash_payload "${P} --title \"use -R other/thing\"")"
applies "REST-route text in a title" yes "$(bash_payload "${P} --title \"see /repos/o/other-repo/pulls for reference\"")"
applies "REST-route text in a body"  yes "$(bash_payload "${P} --body \"docs: also see /repos/o/other-repo/pulls for reference\"")"
applies "--repo repeated, last names this repo" yes "$(bash_payload "${P} --repo somebody-else/thing --repo o/${THIS_REPO}")"
applies "--repo with a trailing .git, this repo" yes "$(bash_payload "${P} --repo o/${THIS_REPO}.git")"
applies "MCP create_pull_request"    yes '{"tool_name":"mcp__github__create_pull_request","tool_input":{"title":"x"}}'

echo
echo "applicability — it stays silent"
applies "named inside a string"          no "$(bash_payload "echo 'run ${P} later'")"
applies "a separator inside a string"    no "$(bash_payload "git commit -m \"a; ${P}\"")"
applies "a separator in single quotes"   no "$(bash_payload "echo 'a; ${P}'")"
applies "a brace in single quotes"       no "$(bash_payload "echo '{ ${P}; }'")"
applies "a multi-paragraph commit body"  no "$(bash_payload "git commit -m \"chore: gate

${P} is now gated by a PreToolUse hook.\"")"
applies "a heredoc that documents it"    no "$(bash_payload "cat > d.md <<EOF
To open the PR run:
${P}
EOF")"
applies "gh pr view"                     no "$(bash_payload 'gh pr view 1')"
applies "gh api without pulls"           no "$(bash_payload 'gh api /repos/o/r/issues')"
applies "an unrelated command"           no "$(bash_payload 'git status')"
applies "an unrelated MCP tool"          no '{"tool_name":"mcp__github__list_issues","tool_input":{}}'
applies "an MCP call for another repo"   no '{"tool_name":"mcp__github__create_pull_request","tool_input":{"repo":"somebody-else"}}'
applies "--repo for another repo"        no "$(bash_payload "${P} --repo somebody-else/thing")"
applies "--repo= for another repo"       no "$(bash_payload "${P} --repo=somebody-else/thing")"
applies "-R for another repo"            no "$(bash_payload "${P} -R somebody-else/thing")"
applies "--repo quoted, another repo"    no "$(bash_payload "${P} --repo \"somebody-else/thing\"")"
applies "the REST route, another repo"   no "$(bash_payload 'gh api --method POST /repos/o/somebody-else/pulls')"
applies "--repo repeated, last names another repo" no "$(bash_payload "${P} --repo o/${THIS_REPO} --repo somebody-else/thing")"
applies "an unparseable payload"         no 'not json'

base_is() {
	local name="$1" expect="$2" payload="$3"
	SURFACE=""; ACK_FROM_PAYLOAD=""; BASE_FROM_PAYLOAD=""
	hook_applies "${payload}" >/dev/null 2>&1 || true
	if [[ "${BASE_FROM_PAYLOAD}" == "${expect}" ]]; then
		pass_row "${name}"
	else
		fail_row "${name}: got [${BASE_FROM_PAYLOAD}], expected [${expect}]"
	fi
}

echo
echo "base ref (it picks the merge base, so a wrong one is a wrong verdict)"
base_is "--base with a value"           "develop" "$(bash_payload "${P} --base develop")"
base_is "--base=value"                  "develop" "$(bash_payload "${P} --base=develop")"
base_is "-B with a value"               "develop" "$(bash_payload "${P} -B develop")"
base_is "quoted in a title only"        ""        "$(bash_payload "${P} --title \"see --base develop\"")"
base_is "a title mention, then the flag" "main"   "$(bash_payload "${P} --title \"see --base develop\" --base main")"
base_is "repeated, last wins"           "main"    "$(bash_payload "${P} --base develop --base main")"
base_is "absent"                        ""        "$(bash_payload "${P}")"
base_is "the MCP payload"               "main"    '{"tool_name":"mcp__github__create_pull_request","tool_input":{"base":"main"}}'

ack_is() {
	local name="$1" expect="$2" payload="$3"
	SURFACE=""; ACK_FROM_PAYLOAD=""; BASE_FROM_PAYLOAD=""
	hook_applies "${payload}" >/dev/null 2>&1 || true
	if [[ "${ACK_FROM_PAYLOAD}" == "${expect}" ]]; then
		pass_row "${name}"
	else
		fail_row "${name}: got [${ACK_FROM_PAYLOAD}], expected [${expect}]"
	fi
}

echo
echo "escape hatch"
ack_is "double-quoted with spaces"     "deps-only batch" "$(bash_payload "ADVERSARIAL_PASS_ACK=\"deps-only batch\" ${P}")"
ack_is "single-quoted with spaces"     "deps only"       "$(bash_payload "ADVERSARIAL_PASS_ACK='deps only' ${P}")"
ack_is "bare value"                    "deps-only"       "$(bash_payload "ADVERSARIAL_PASS_ACK=deps-only ${P}")"
ack_is "behind another assignment"     "why"             "$(bash_payload "FOO=1 ADVERSARIAL_PASS_ACK=why ${P}")"
ack_is "empty value"                   ""                "$(bash_payload "ADVERSARIAL_PASS_ACK=\"\" ${P}")"
ack_is "whitespace-only value"         ""                "$(bash_payload "ADVERSARIAL_PASS_ACK=\"   \" ${P}")"
ack_is "absent"                        ""                "$(bash_payload "${P}")"
ack_is "quoted in the PR title"        ""                "$(bash_payload "${P} --title \"ADVERSARIAL_PASS_ACK=see docs\"")"
ack_is "quoted in the PR body"         ""                "$(bash_payload "${P} --body \"yields to ADVERSARIAL_PASS_ACK=<reason> on the command line\"")"
ack_is "in a later segment"            ""                "$(bash_payload "${P} ; echo ADVERSARIAL_PASS_ACK=later")"
ack_is "in an earlier echo"            ""                "$(bash_payload "echo ADVERSARIAL_PASS_ACK=bogus && ${P}")"
ack_is "the tool's own placeholder, as a real prefix" "" "$(bash_payload "ADVERSARIAL_PASS_ACK=\"<why this PR needs no pass>\" ${P}")"
ack_is "MCP body, own line"            "release train"   '{"tool_name":"mcp__github__create_pull_request","tool_input":{"body":"## What\nstuff\nADVERSARIAL_PASS_ACK=release train\n"}}'
ack_is "MCP body, the tool's own placeholder" ""          '{"tool_name":"mcp__github__create_pull_request","tool_input":{"body":"ADVERSARIAL_PASS_ACK=<why this PR needs no pass>\n"}}'
ack_is "MCP body, described in prose"  ""                '{"tool_name":"mcp__github__create_pull_request","tool_input":{"body":"The gate yields to ADVERSARIAL_PASS_ACK=<reason> in the body."}}'

# --- The verdict, over a real repository -------------------------------------
#
# The blocks above pin the parsers. Nothing in them reaches the part that
# decides — which artifacts count as this branch's, which witness is accepted,
# and whether the record is actually ON the branch — and that is where the worst
# defects lived. Only a fixture with a base and a branch can state that.
#
# The fixture is built with signing and hooks forced off, and it ASSERTS that
# its commits exist: under a global `commit.gpgsign=true` every `git commit`
# here failed silently, the repository ended up with zero commits, the script
# correctly answered "undetermined" — and a verdict helper that read the exit
# code called that `record` and printed three greens over an empty repository.
# That is the "asserting an invariant over a seed that inserted zero rows"
# defect this whole gate exists because of.

echo
if ! command -v git >/dev/null 2>&1; then
	echo "verdict — SKIPPED (git unavailable); the summary below does not cover it"
	VERDICT_BLOCK_RAN=false
elif ! FIXTURE="$(mktemp -d 2>/dev/null)"; then
	echo "verdict — SKIPPED (no temp directory); the summary below does not cover it"
	VERDICT_BLOCK_RAN=false
else
	VERDICT_BLOCK_RAN=true
	echo "verdict"

	fixture_git() { git -C "${FIXTURE}" -c commit.gpgsign=false -c core.hooksPath=/dev/null "$@"; }

	verdict_is() {
		local name="$1" expect="$2" out got
		out="$( cd "${FIXTURE}" && ./scripts/adversarial-pass-check.sh --base-ref main 2>&1 | head -1 )"
		case "${out}" in
			"✓"*) got=record ;;
			"✗"*) got=missing ;;
			"·"*) got=undetermined ;;
			*)    got="unknown(${out})" ;;
		esac
		if [[ "${got}" == "${expect}" ]]; then
			pass_row "${name}"
		else
			fail_row "${name}: verdict ${got}, expected ${expect}"
		fi
	}

	AD="_bmad-output/implementation-artifacts"
	mkdir -p "${FIXTURE}/scripts" "${FIXTURE}/${AD}"
	cp -- "${BASH_SOURCE[0]}" "${FIXTURE}/scripts/adversarial-pass-check.sh"
	printf '# Story one\n\n## Adversarial pass\n\n%s' "${LONG_BODY}" > "${FIXTURE}/${AD}/br-1-old-story.md"
	# A second, unrelated base pass -- present from the seed so a later row can
	# pin the pooled-delta fix (a candidate stitched from THIS pass and
	# br-1's, below) without disturbing any row that only ever touches br-1.
	printf '# Story two\n\n## Adversarial pass\n\n%s' "${THIRD_BODY}" > "${FIXTURE}/${AD}/br-2-second-story.md"
	echo seed > "${FIXTURE}/seed.txt"
	fixture_git init -q -b main >/dev/null 2>&1
	fixture_git config user.email gate@example.invalid
	fixture_git config user.name gate
	fixture_git add -A >/dev/null 2>&1
	fixture_git commit -qm seed >/dev/null 2>&1
	fixture_git checkout -qb feat/fixture >/dev/null 2>&1
	echo change > "${FIXTURE}/code.txt"
	fixture_git add -A >/dev/null 2>&1
	fixture_git commit -qm work >/dev/null 2>&1

	seeded="$(fixture_git rev-list --count HEAD 2>/dev/null || echo 0)"
	if (( seeded < 2 )); then
		fail_row "fixture seeding (only ${seeded} commit(s) — every row below would be vacuous)"
	else
		pass_row "fixture really has ${seeded} commits"

		verdict_is "work with no record at all" missing

		printf '# S\n\n## Adversarial pass\n\n%s' "${LONG_BODY}" > "${FIXTURE}/${AD}/spec-uncommitted.md"
		verdict_is "a record written but never committed" missing
		rm -f "${FIXTURE}/${AD}/spec-uncommitted.md"

		sed -i.bak '1s/.*/# Story one (typo)/' "${FIXTURE}/${AD}/br-1-old-story.md"
		rm -f "${FIXTURE}/${AD}/br-1-old-story.md.bak"
		fixture_git add -A >/dev/null 2>&1; fixture_git commit -qm typo >/dev/null 2>&1
		verdict_is "an artifact whose pass predates the branch" missing

		sed -i.bak 's/neither failed./neither failed. /' "${FIXTURE}/${AD}/br-1-old-story.md"
		rm -f "${FIXTURE}/${AD}/br-1-old-story.md.bak"
		fixture_git add -A >/dev/null 2>&1; fixture_git commit -qm whitespace >/dev/null 2>&1
		verdict_is "whitespace nudged inside that section" missing

		fixture_git mv "${AD}/br-1-old-story.md" "${AD}/spec-renamed.md" >/dev/null 2>&1
		fixture_git commit -qm rename >/dev/null 2>&1
		verdict_is "that same section under a new filename" missing

		# #816's repro. Identity alone answered this green: the section HAD
		# changed, and the section-level floors were satisfied by text the
		# branch did not write.
		printf 'ok\n' >> "${FIXTURE}/${AD}/spec-renamed.md"
		fixture_git add -A >/dev/null 2>&1; fixture_git commit -qm nudge >/dev/null 2>&1
		verdict_is "one word appended to that section" missing
		fixture_git reset -q --hard HEAD~1 >/dev/null 2>&1

		printf '# Copy\n\n## Adversarial pass\n\n%s\nOne extra sentence bolted on.\n' "${LONG_BODY}" \
			> "${FIXTURE}/${AD}/spec-copy.md"
		fixture_git add -A >/dev/null 2>&1; fixture_git commit -qm copy >/dev/null 2>&1
		verdict_is "a copy of that pass plus a sentence" missing
		fixture_git reset -q --hard HEAD~1 >/dev/null 2>&1

		fixture_git commit -q --allow-empty -m "$(printf 'chore: x\n\nAdversarial-pass:')" >/dev/null 2>&1
		verdict_is "an empty trailer" missing
		fixture_git reset -q --hard HEAD~1 >/dev/null 2>&1

		printf '\n## Adversarial pass\n\n%s\n' "${SECOND_BODY}" \
			>> "${FIXTURE}/${AD}/spec-renamed.md"
		fixture_git add -A >/dev/null 2>&1; fixture_git commit -qm extend >/dev/null 2>&1
		verdict_is "that artifact, section genuinely extended" record
		fixture_git reset -q --hard HEAD~1 >/dev/null 2>&1

		printf '# New spec\n\n## Adversarial pass\n\n%s\n' "${SECOND_BODY}" > "${FIXTURE}/${AD}/spec-new.md"
		fixture_git add -A >/dev/null 2>&1; fixture_git commit -qm record >/dev/null 2>&1
		verdict_is "an artifact this branch creates" record
		fixture_git reset -q --hard HEAD~1 >/dev/null 2>&1

		fixture_git commit -q --allow-empty -m "$(printf 'fix: x\n\nAdversarial-pass: two layers ran, ten patches applied, two rejected with measurements.')" >/dev/null 2>&1
		verdict_is "a populated trailer" record
		fixture_git reset -q --hard HEAD~1 >/dev/null 2>&1

		# The per-file check #816 introduced closed a candidate matching ONE
		# prior pass, but missed a candidate pasted together from TWO
		# different ones: against br-1 alone every line of br-2's content
		# reads as new, and against br-2 alone every line of br-1's does --
		# a per-file check clears both. Pooled first, nothing in it is new.
		printf '# Stitched\n\n## Adversarial pass\n\n%s\n%s\n' "${LONG_BODY}" "${THIRD_BODY}" \
			> "${FIXTURE}/${AD}/spec-stitched.md"
		fixture_git add -A >/dev/null 2>&1; fixture_git commit -qm stitched >/dev/null 2>&1
		verdict_is "a pass stitched from two different base passes" missing
		fixture_git reset -q --hard HEAD~1 >/dev/null 2>&1

		# CLAUDE.md requires every feature branch to live in a linked worktree,
		# whose root basename is the worktree slug rather than the repository.
		# Reading the name from there made an MCP call about THIS repository
		# compare unequal, and a not-applicable is silent -- so the gate was dead
		# in the one place all feature work happens. The row is a hook run,
		# because applicability is what regressed: a deny proves it applied.
		if ! command -v jq >/dev/null 2>&1; then
			fail_row "worktree applicability (jq unavailable, and the row cannot be faked green)"
		elif ! fixture_git worktree add -q -b feat/from-worktree "${FIXTURE}/wt" main >/dev/null 2>&1; then
			fail_row "worktree applicability (the fixture worktree could not be created)"
		else
			wt_git() { git -C "${FIXTURE}/wt" -c commit.gpgsign=false -c core.hooksPath=/dev/null "$@"; }
			echo worktree > "${FIXTURE}/wt/code.txt"
			wt_git add -A >/dev/null 2>&1
			wt_git commit -qm work >/dev/null 2>&1
			if (( $(wt_git rev-list --count main..HEAD 2>/dev/null || echo 0) < 1 )); then
				fail_row "worktree applicability (the worktree carries nothing over main — the row would be vacuous)"
			else
				wt_out="$(
					printf '{"tool_name":"mcp__github__create_pull_request","tool_input":{"repo":%s,"base":"main"}}' \
						"$(basename -- "${FIXTURE}" | jq -Rs .)" \
					| ( cd "${FIXTURE}/wt" && ./scripts/adversarial-pass-check.sh --hook ) 2>/dev/null
				)"
				if [[ "${wt_out}" == *'"permissionDecision":"deny"'* ]]; then
					pass_row "an MCP call judged from inside a linked worktree"
				else
					fail_row "an MCP call judged from inside a linked worktree: stayed silent (the repository name read as the worktree slug?)"
				fi
			fi
			fixture_git worktree remove --force "${FIXTURE}/wt" >/dev/null 2>&1
		fi
	fi

	rm -rf -- "${FIXTURE}"
fi

echo
if (( FAILURES > 0 )); then
	echo "✗ ${FAILURES} self-test failure(s)"
	exit 1
fi
if [[ "${VERDICT_BLOCK_RAN}" == true ]]; then
	echo "✓ classifier, delta, applicability, escape hatch and verdict each fail in both directions"
else
	echo "✓ classifier, delta, applicability and escape hatch pass — the VERDICT block did not run"
fi
exit 0
