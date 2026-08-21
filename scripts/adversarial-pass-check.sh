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
#      MIN_RECORD_CHARS -- and whose normalised section does not already exist
#      on the base. That last clause is what stops a rename, a copy, or a
#      whitespace nudge of somebody else's pass from counting as this branch's.
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

usage() {
	cat <<'EOF'
usage: adversarial-pass-check.sh [options]

  --strict       exit 1 when the branch carries no adversarial-pass record
  --hook         PreToolUse mode: read the hook payload on stdin and emit a
                 permission decision as JSON (always exits 0)
  --self-test    run the fixtures and prove the gate fails in both directions
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

section_fingerprint() {
	section_lines | cksum
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
			[[ -n "${value}" ]] && { printf '%s' "${value}"; return 0; }
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
# body that DESCRIBES the hatch in a sentence does not trigger it.
extract_ack_body() {
	printf '%s' "$1" | sed -n 's/^ADVERSARIAL_PASS_ACK=["'"'"']\{0,1\}\([^"'"'"']*\).*/\1/p' | head -1
}

# --- The payload -------------------------------------------------------------

SURFACE=""
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
			this_repo="$(basename -- "${REPO_ROOT}")"
			# A call targeting a different repository is not this checkout's to
			# judge: judging it would be a verdict about the wrong branch.
			if [[ -n "${target_repo}" ]] && [[ "${target_repo,,}" != "${this_repo,,}" ]]; then
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

	local segment matched=1
	while IFS= read -r -d '' segment; do
		if segment_opens_pr "${segment}"; then
			matched=0
			ACK_FROM_PAYLOAD="$(extract_ack_prefix "${segment}" || true)"
			BASE_FROM_PAYLOAD="$(sed -n 's/.*--base[= ]\{1,\}\([^[:space:]"'"'"']\{1,\}\).*/\1/p' <<<"${segment}" | head -1)"
			break
		fi
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

	# Every normalised section already present on the base. A candidate matching
	# one of these is a rename, a copy or a nudge of somebody else's pass, not
	# this branch's evidence.
	local -A base_fingerprints=()
	local base_path fingerprint
	while IFS= read -r -d '' base_path; do
		[[ "${base_path}" == *.md ]] || continue
		fingerprint="$(git_c show "${merge_base}:${base_path}" 2>/dev/null | section_fingerprint)"
		[[ -n "${fingerprint}" ]] && base_fingerprints["${fingerprint}"]=1
	done < <(git_c ls-tree -r -z --name-only "${merge_base}" -- "${ARTIFACT_DIR}" 2>/dev/null)

	local found="" reason="" content
	for path in "${candidates[@]}"; do
		content="$(git_c show "HEAD:${path}" 2>/dev/null || true)"
		[[ -n "${content}" ]] || continue
		printf '%s' "${content}" | has_record || continue
		fingerprint="$(printf '%s' "${content}" | section_fingerprint)"
		if [[ -n "${base_fingerprints["${fingerprint}"]:-}" ]]; then
			reason="${reason}
    ${path} (its adversarial-pass section already exists on ${resolved})"
			continue
		fi
		found="${path}"; break
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
    committed on this branch and not a copy of a section already on the base, or
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

fingerprint_is() {
	local name="$1" expect="$2" a="$3" b="$4" fa fb
	fa="$(printf '%s' "${a}" | section_fingerprint)"
	fb="$(printf '%s' "${b}" | section_fingerprint)"
	if [[ "${fa}" == "${fb}" ]]; then
		if [[ "${expect}" == same ]]; then pass_row "${name}"; else fail_row "${name}: same, expected different"; fi
	else
		if [[ "${expect}" == different ]]; then pass_row "${name}"; else fail_row "${name}: different, expected same"; fi
	fi
}

echo
echo "section fingerprint (what counts as a CHANGED section)"
fingerprint_is "a trailing space is not a change"  same      "## Adversarial pass"$'\n\n'"${LONG_BODY}" "## Adversarial pass"$'\n\n'"${LONG_BODY} "
fingerprint_is "a blank line is not a change"      same      "## Adversarial pass"$'\n\n'"${LONG_BODY}" "## Adversarial pass"$'\n\n\n'"${LONG_BODY}"$'\n\n'
fingerprint_is "an empty sub-heading is not a change" same   "## Adversarial pass"$'\n\n'"${LONG_BODY}" "## Adversarial pass"$'\n\n'"${LONG_BODY}"$'\n'"### Findings"
fingerprint_is "new prose IS a change"             different "## Adversarial pass"$'\n\n'"${LONG_BODY}" "## Adversarial pass"$'\n\n'"${LONG_BODY}"$'\n'"An eleventh patch, measured."
fingerprint_is "text outside the section is irrelevant" same "# A"$'\n'"## Adversarial pass"$'\n\n'"${LONG_BODY}" "# B (typo fixed)"$'\n'"## Adversarial pass"$'\n\n'"${LONG_BODY}"

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
applies "the REST route"             yes "$(bash_payload 'gh api --method POST /repos/o/r/pulls -f title=x')"
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
applies "an unparseable payload"         no 'not json'

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
ack_is "MCP body, own line"            "release train"   '{"tool_name":"mcp__github__create_pull_request","tool_input":{"body":"## What\nstuff\nADVERSARIAL_PASS_ACK=release train\n"}}'
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

		fixture_git commit -q --allow-empty -m "$(printf 'chore: x\n\nAdversarial-pass:')" >/dev/null 2>&1
		verdict_is "an empty trailer" missing
		fixture_git reset -q --hard HEAD~1 >/dev/null 2>&1

		printf '\n## Adversarial pass\n\n%s\nAn eleventh patch, independently measured and applied.\n' "${LONG_BODY}" \
			>> "${FIXTURE}/${AD}/spec-renamed.md"
		fixture_git add -A >/dev/null 2>&1; fixture_git commit -qm extend >/dev/null 2>&1
		verdict_is "that artifact, section genuinely extended" record
		fixture_git reset -q --hard HEAD~1 >/dev/null 2>&1

		printf '# New spec\n\n## Adversarial pass\n\n%s\nA distinct pass, with its own seven findings and three refutations.\n' "${LONG_BODY}" > "${FIXTURE}/${AD}/spec-new.md"
		fixture_git add -A >/dev/null 2>&1; fixture_git commit -qm record >/dev/null 2>&1
		verdict_is "an artifact this branch creates" record
		fixture_git reset -q --hard HEAD~1 >/dev/null 2>&1

		fixture_git commit -q --allow-empty -m "$(printf 'fix: x\n\nAdversarial-pass: two layers ran, ten patches applied, two rejected with measurements.')" >/dev/null 2>&1
		verdict_is "a populated trailer" record
	fi

	rm -rf -- "${FIXTURE}"
fi

echo
if (( FAILURES > 0 )); then
	echo "✗ ${FAILURES} self-test failure(s)"
	exit 1
fi
if [[ "${VERDICT_BLOCK_RAN}" == true ]]; then
	echo "✓ classifier, fingerprint, applicability, escape hatch and verdict each fail in both directions"
else
	echo "✓ classifier, fingerprint, applicability and escape hatch pass — the VERDICT block did not run"
fi
exit 0
