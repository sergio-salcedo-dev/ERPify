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
# worth naming — a reader gets a compliant-looking claim, and the seam between
# "the pass ran" and "its findings were written" is invisible to every other
# gate in this repo.
#
# WHY THIS RUNS AT CREATION TIME RATHER THAN AFTER
#
# The obvious instrument is post-hoc: compare the artifact's commit date against
# the PR's createdAt. It does not work, and #799 measured why. `%cI` is the
# COMMITTER date, which every rebase rewrites — five commits on #789's branch
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
# A spec or story artifact touched by this branch carrying a non-empty
# `## Adversarial pass` section, or a commit on this branch carrying an
# `Adversarial-pass:` trailer. Either is durable: `spec-*.md` is a retained
# artifact (it is no longer pruned on `status: done` — that rule and this one
# used to contradict each other, #800), and a commit message survives a rebase
# even though its hash does not.
#
# WHAT A GREEN PROVES, AND WHAT IT DOES NOT
#
# It proves a record of the required SHAPE exists on the branch before the PR
# does. It cannot judge whether the findings are real, whether the pass was
# adversarial, or whether a hostile reader was actually involved — it gates the
# form, not the substance, and review remains the only control on that
# direction. It is also blind to a PR opened outside this session (the GitHub
# web UI, another machine, a CI job), because a PreToolUse hook only sees tool
# calls made here.
#
# FAILING OPEN IS DELIBERATE
#
# A gate on `gh pr create` is a new way for the tool to be wrong at the worst
# moment, so every inability to DETERMINE an answer — not a git repo, no base
# ref, no artifacts directory, no jq — exits 0 and lets the human proceed.
# Only a determinate "the record is missing" denies, and even that has an
# escape hatch: ADVERSARIAL_PASS_ACK="<reason>" proceeds and surfaces the
# acknowledgement, so an unchecked PR is recorded as unchecked rather than
# leaving a wedged branch.
# =============================================================================

set -uo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
readonly SCRIPT_DIR REPO_ROOT
readonly ARTIFACT_DIR="_bmad-output/implementation-artifacts"

# A heading alone is not a record. The live example (#789) runs to ~40 lines;
# these floors reject a stub without demanding a particular length.
readonly MIN_RECORD_LINES=3
readonly MIN_RECORD_CHARS=200

# Live registries are never the pass's record — they are pending-only lists.
readonly -a NON_RECORD_FILES=("sprint-status.yaml" "deferred-work.md")

MODE="report"
BASE_REF=""

usage() {
	cat <<'EOF'
usage: adversarial-pass-check.sh [options]

  --strict       exit 1 when the branch carries no adversarial-pass record
  --hook         PreToolUse mode: read the hook payload on stdin and emit a
                 permission decision as JSON (always exits 0)
  --self-test    run the record classifier over synthetic fixtures and prove
                 it fails in both directions
  --base-ref R   git ref the branch is measured against
                 (default: origin/main, falling back to main)
  -h, --help     show this help

Environment:
  ADVERSARIAL_PASS_ACK   non-empty value proceeds without a record and
                         surfaces the value as the acknowledged reason
EOF
}

while (( $# > 0 )); do
	case "$1" in
		--strict) MODE="strict"; shift ;;
		--hook) MODE="hook"; shift ;;
		--self-test) MODE="self-test"; shift ;;
		--base-ref) BASE_REF="${2:-}"; shift 2 ;;
		-h|--help) usage; exit 0 ;;
		*) echo "unknown option: $1" >&2; usage >&2; exit 2 ;;
	esac
done

# --- The classifier ----------------------------------------------------------
#
# Reads a document on stdin, exits 0 when it holds a non-empty adversarial-pass
# section. The section runs from its heading to the next heading of the same or
# a higher level, so a `###` sub-heading inside the pass counts as its content
# while the next `##` ends it.
#
# Both spellings are accepted because the specs in this tree are written in
# Spanish while their headings are not consistently either language.

has_record() {
	awk '
		BEGIN { inside = 0; lines = 0; chars = 0 }
		{
			line = $0
			# Heading level, or 0 when the line is not a heading.
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

			if (inside) {
				stripped = line
				gsub(/[ \t]/, "", stripped)
				if (stripped != "" && line !~ /^#+[ \t]/) {
					lines++
					chars += length(line)
				}
			}
		}
		END {
			exit (lines >= '"${MIN_RECORD_LINES}"' && chars >= '"${MIN_RECORD_CHARS}"') ? 0 : 1
		}
	'
}

# Prints just the adversarial-pass section's content, so two revisions of the
# same file can be compared on that section alone rather than on the whole file.
record_section() {
	awk '
		BEGIN { inside = 0 }
		{
			line = $0
			level = 0
			if (match(line, /^#+[ \t]/)) { level = RLENGTH - 1 }
			if (level >= 2 && level <= 3) {
				title = tolower(line)
				sub(/^#+[ \t]+/, "", title)
				gsub(/[^a-z]+/, " ", title)
				gsub(/^ | $/, "", title)
				if (title == "adversarial pass" || title == "pasada adversarial") { inside = 1; next }
			}
			if (inside && level > 0 && level <= 2) { inside = 0 }
			if (inside) { print line }
		}
	'
}

# --- Self-test ---------------------------------------------------------------
#
# A gate nobody falsified is a gate nobody can trust. This proves the classifier
# refuses each near-miss shape, not merely that it accepts the real one.

run_self_test() {
	local failures=0 name expect body

	check() {
		name="$1"; expect="$2"; body="$3"
		if printf '%s' "${body}" | has_record; then
			[[ "${expect}" == "accept" ]] || { echo "  ✗ ${name}: accepted, expected reject"; failures=$(( failures + 1 )); return; }
		else
			[[ "${expect}" == "reject" ]] || { echo "  ✗ ${name}: rejected, expected accept"; failures=$(( failures + 1 )); return; }
		fi
		echo "  ✓ ${name}"
	}

	local long_body
	long_body="$(printf 'Two layers ran with fresh read-only context over the branch diff; neither failed.\nApplied 10 patches, of which two demolish a claim made in the spec itself.\nRejected 2 with measurements, deferred 3 as pre-existing and unchanged here.\n')"

	echo "adversarial-pass-check --self-test"
	check "no section at all"          reject "# Spec"$'\n\n'"## Intent"$'\n\n'"Some prose that is quite long but is not the pass at all, repeated for length. ${long_body}"
	check "heading only"               reject "## Adversarial pass"$'\n\n'"## Next"
	check "heading with a stub"        reject "## Adversarial pass"$'\n\n'"TODO"$'\n'
	check "populated section"          accept "## Adversarial pass"$'\n\n'"${long_body}"
	check "spanish heading"            accept "## Pasada adversarial"$'\n\n'"${long_body}"
	check "level-3 heading"            accept "### Adversarial pass"$'\n\n'"${long_body}"
	check "content stops at next ##"   reject "## Adversarial pass"$'\n\n'"## Verification"$'\n\n'"${long_body}"
	check "sub-heading stays inside"   accept "## Adversarial pass"$'\n\n'"### Findings"$'\n\n'"${long_body}"
	check "headings are not content"   reject "## Adversarial pass"$'\n'"### a"$'\n'"### b"$'\n'"### c"$'\n'"### d"
	# The two floors are an AND, so a fixture violating one violates both and
	# neither is pinned on its own — measured, by lowering each to zero and
	# watching every row stay green. These two isolate them: many short lines
	# clears the line floor and fails the character floor, one long line does
	# the reverse.
	check "many short lines, no substance"  reject "## Adversarial pass"$'\n'"ok"$'\n'"ok"$'\n'"ok"$'\n'"ok"$'\n'"ok"
	check "one long line is not a section"  reject "## Adversarial pass"$'\n'"$(printf 'x%.0s' {1..300})"
	check "prose mention is not a heading" reject "The ## Adversarial pass section is required."$'\n'"${long_body}"

	# --- Applicability -------------------------------------------------------
	#
	# Which tool calls this gate speaks to at all. Every row here was a live
	# defect at some point in this script's own construction: the phrase matched
	# anywhere blocked the commands documenting the gate; a quoted assignment
	# containing a space made an acknowledged invocation invisible; and `while
	# read` dropped the final segment, which silently disabled the whole Bash
	# path while the MCP path kept working.

	applies() {
		name="$1"; expect="$2"; payload="$3"
		if hook_applies "${payload}"; then
			[[ "${expect}" == "yes" ]] || { echo "  ✗ ${name}: applied, expected not-applicable"; failures=$(( failures + 1 )); return; }
		else
			[[ "${expect}" == "no" ]] || { echo "  ✗ ${name}: not applicable, expected to apply"; failures=$(( failures + 1 )); return; }
		fi
		echo "  ✓ ${name}"
	}

	local P
	P="gh pr create"

	echo
	echo "applicability"
	applies "bare invocation"              yes "{\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"${P} --title x\"}}"
	applies "after &&"                     yes "{\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"git push && ${P}\"}}"
	applies "unquoted assignment prefix"   yes "{\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"FOO=1 ${P}\"}}"
	applies "quoted prefix with a space"   yes "{\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"FOO=\\\"a b\\\" ${P}\"}}"
	applies "env wrapper"                  yes "{\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"env FOO=1 ${P}\"}}"
	applies "MCP create_pull_request"      yes "{\"tool_name\":\"mcp__github__create_pull_request\",\"tool_input\":{}}"
	applies "named inside a string"        no  "{\"tool_name\":\"Bash\",\"tool_input\":{\"command\":\"echo 'run ${P} later'\"}}"
	applies "gh pr view"                   no  '{"tool_name":"Bash","tool_input":{"command":"gh pr view 1"}}'
	applies "unrelated command"            no  '{"tool_name":"Bash","tool_input":{"command":"git status"}}'
	applies "unrelated MCP tool"           no  '{"tool_name":"mcp__github__list_issues","tool_input":{}}'
	applies "unparseable payload"          no  'not json'

	# --- The escape hatch ----------------------------------------------------
	#
	# It has to be readable from where it is written. A hook process does not
	# inherit the environment of the command it gates, so the value is parsed
	# out of the command text — and an empty one is not an acknowledgement.

	# The exit code is asserted alongside the value, not instead of it: an empty
	# acknowledgement must be REFUSED, and a parser that returns success with an
	# empty string looks identical to one that refuses if only the value is
	# compared. Measured — mutating the refusal to always-succeed left every row
	# of this block green until the code was checked too.
	ack_is() {
		name="$1"; expect="$2"; command_text="$3"
		if got="$(extract_ack "${command_text}")"; then rc=0; else rc=1; got=""; fi
		want_rc=0; [[ -z "${expect}" ]] && want_rc=1
		if [[ "${got}" == "${expect}" && "${rc}" == "${want_rc}" ]]; then
			echo "  ✓ ${name}"
		else
			echo "  ✗ ${name}: got [${got}] rc=${rc}, expected [${expect}] rc=${want_rc}"
			failures=$(( failures + 1 ))
		fi
	}

	echo
	echo "escape hatch"
	ack_is "double-quoted with spaces" "deps-only batch" "ADVERSARIAL_PASS_ACK=\"deps-only batch\" ${P}"
	ack_is "single-quoted with spaces" "deps only"       "ADVERSARIAL_PASS_ACK='deps only' ${P}"
	ack_is "bare value"                "deps-only"       "ADVERSARIAL_PASS_ACK=deps-only ${P}"
	ack_is "empty value"               ""                "ADVERSARIAL_PASS_ACK=\"\" ${P}"
	ack_is "whitespace-only value"     ""                "ADVERSARIAL_PASS_ACK=\"   \" ${P}"
	ack_is "absent"                    ""                "${P}"

	# --- The verdict, over a real repository ---------------------------------
	#
	# The blocks above pin the parsers. Nothing in them reaches the part that
	# decides — which artifacts count as this branch's, and which witness is
	# accepted — and that is where the worst defect lived: every story artifact
	# in this tree carries a `## Adversarial pass` from its own story, so
	# touching one for a typo turned the gate green having reviewed nothing.
	# Only a fixture with a base and a branch can state that, so this builds one.

	local fixture
	if ! command -v git >/dev/null 2>&1; then
		echo
		echo "  · verdict block skipped — git unavailable"
	elif ! fixture="$(mktemp -d 2>/dev/null)"; then
		echo
		echo "  · verdict block skipped — no temp directory"
	else
		echo
		echo "verdict"
		verdict_is() {
			name="$1"; expect="$2"
			if ( cd "${fixture}" && ./scripts/adversarial-pass-check.sh --base-ref main --strict >/dev/null 2>&1 ); then
				got="record"
			else
				got="missing"
			fi
			if [[ "${got}" == "${expect}" ]]; then
				echo "  ✓ ${name}"
			else
				echo "  ✗ ${name}: verdict ${got}, expected ${expect}"
				failures=$(( failures + 1 ))
			fi
		}

		local artifacts="_bmad-output/implementation-artifacts"
		local body
		body="$(printf 'Two layers ran with fresh read-only context over the branch diff; neither failed.\nApplied 10 patches, two of which demolish a claim the spec itself made.\nRejected 2 with measurements, deferred 3 as pre-existing and unchanged here.\n')"

		mkdir -p "${fixture}/scripts" "${fixture}/${artifacts}"
		cp -- "${BASH_SOURCE[0]}" "${fixture}/scripts/adversarial-pass-check.sh"
		printf '# Story one\n\n## Adversarial pass\n\n%s' "${body}" > "${fixture}/${artifacts}/br-1-old-story.md"
		echo seed > "${fixture}/seed.txt"
		(
			cd "${fixture}" || exit 1
			git init -q -b main
			git config user.email gate@example.invalid
			git config user.name  gate
			git add -A && git commit -qm seed
			git checkout -qb feat/fixture
			echo change > code.txt && git add -A && git commit -qm work
		) >/dev/null 2>&1

		verdict_is "work with no record at all" missing

		( cd "${fixture}" && sed -i.bak '1s/.*/# Story one (typo)/' "${artifacts}/br-1-old-story.md" && rm -f "${artifacts}/br-1-old-story.md.bak" && git add -A && git commit -qm typo ) >/dev/null 2>&1
		verdict_is "an artifact whose pass predates the branch" missing

		( cd "${fixture}" && printf '\n## Adversarial pass\n\n%s' "${body}" >> "${artifacts}/br-1-old-story.md" && git add -A && git commit -qm extend ) >/dev/null 2>&1
		verdict_is "that same artifact, section changed" record

		( cd "${fixture}" && git reset -q --hard HEAD~1 && printf '# New spec\n\n## Adversarial pass\n\n%s' "${body}" > "${artifacts}/spec-new.md" && git add -A && git commit -qm record ) >/dev/null 2>&1
		verdict_is "an artifact this branch creates" record

		( cd "${fixture}" && git reset -q --hard HEAD~1 && git commit -q --allow-empty -m "$(printf 'fix: x\n\nAdversarial-pass: two layers, 10 patches, 2 rejected.')" ) >/dev/null 2>&1
		verdict_is "a commit trailer" record

		rm -rf -- "${fixture}"
	fi

	echo
	if (( failures > 0 )); then
		echo "✗ ${failures} self-test failure(s)"
		return 1
	fi
	echo "✓ classifier, applicability, escape hatch and verdict each fail in both directions"
	return 0
}

# --- Verdict -----------------------------------------------------------------
#
# Sets VERDICT to one of: record | missing | undetermined | acknowledged.
# DETAIL carries the human-readable half.

VERDICT=""
DETAIL=""

is_non_record_file() {
	local base
	base="$(basename -- "$1")"
	local skip
	for skip in "${NON_RECORD_FILES[@]}"; do
		[[ "${base}" == "${skip}" ]] && return 0
	done
	return 1
}

# The acknowledgement arrives by two different routes because the two gated
# surfaces are different. A hook process does NOT inherit the environment of the
# command it is gating — `ADVERSARIAL_PASS_ACK=… <command>` sets the variable for
# the command, never for the hook — so on the Bash path the hatch has to be read
# out of the command TEXT, which the payload does carry. On every other path
# (the MCP server, a hand run, a Make target) the real environment variable is
# the only thing there is. Honouring only the variable was measured leaving the
# Bash path with a hatch the deny message advertised and nothing implemented.
ACK_FROM_COMMAND=""

acknowledgement() {
	[[ -n "${ADVERSARIAL_PASS_ACK:-}" ]] && { printf '%s' "${ADVERSARIAL_PASS_ACK}"; return; }
	[[ -n "${ACK_FROM_COMMAND}" ]] && { printf '%s' "${ACK_FROM_COMMAND}"; return; }
	return 1
}

decide() {
	local ack
	if ack="$(acknowledgement)" && [[ -n "${ack}" ]]; then
		VERDICT="acknowledged"
		DETAIL="${ack}"
		return
	fi

	if ! git -C "${REPO_ROOT}" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
		VERDICT="undetermined"; DETAIL="not inside a git work tree"; return
	fi

	local resolved="${BASE_REF}"
	if [[ -z "${resolved}" ]]; then
		local candidate
		for candidate in origin/main main; do
			if git -C "${REPO_ROOT}" rev-parse --verify --quiet "${candidate}" >/dev/null 2>&1; then
				resolved="${candidate}"; break
			fi
		done
	fi
	if [[ -z "${resolved}" ]]; then
		VERDICT="undetermined"; DETAIL="no base ref resolvable (tried origin/main, main)"; return
	fi

	local merge_base
	merge_base="$(git -C "${REPO_ROOT}" merge-base "${resolved}" HEAD 2>/dev/null || true)"
	if [[ -z "${merge_base}" ]]; then
		VERDICT="undetermined"; DETAIL="no merge base with ${resolved}"; return
	fi

	local head_sha
	head_sha="$(git -C "${REPO_ROOT}" rev-parse HEAD 2>/dev/null || true)"
	if [[ "${merge_base}" == "${head_sha}" ]] && [[ -z "$(git -C "${REPO_ROOT}" status --porcelain 2>/dev/null)" ]]; then
		VERDICT="undetermined"; DETAIL="branch carries nothing over ${resolved} — nothing to review"; return
	fi

	# Witness 1: a commit on this branch carrying the trailer.
	local trailer_commit
	trailer_commit="$(git -C "${REPO_ROOT}" log --format='%H' --grep='^Adversarial-pass:' --extended-regexp "${merge_base}..HEAD" 2>/dev/null | head -1)"
	if [[ -n "${trailer_commit}" ]]; then
		VERDICT="record"
		DETAIL="Adversarial-pass: trailer on $(git -C "${REPO_ROOT}" log -1 --format='%h %s' "${trailer_commit}" 2>/dev/null)"
		return
	fi

	# Witness 2: an artifact this branch touches carrying the section. The
	# worktree copy wins when it exists, because an uncommitted record is still
	# a record the human can commit before the PR — and the working tree is
	# what the next commit will carry.
	if [[ ! -d "${REPO_ROOT}/${ARTIFACT_DIR}" ]]; then
		VERDICT="undetermined"; DETAIL="no ${ARTIFACT_DIR} directory"; return
	fi

	local -a candidates=()
	local path
	while IFS= read -r path; do
		[[ -z "${path}" ]] && continue
		[[ "${path}" == *.md ]] || continue
		is_non_record_file "${path}" && continue
		candidates+=("${path}")
	done < <(
		{
			git -C "${REPO_ROOT}" diff --name-only "${merge_base}...HEAD" -- "${ARTIFACT_DIR}" 2>/dev/null
			# `R  old -> new` names the destination after the arrow, and a path
			# holding a space or a non-ASCII byte arrives quoted. Taking the
			# first field verbatim would hand both shapes to a `-f` test that
			# can only fail, dropping the artifact silently.
			git -C "${REPO_ROOT}" status --porcelain -- "${ARTIFACT_DIR}" 2>/dev/null \
				| sed -e 's/^...//' -e 's/^.* -> //' -e 's/^"\(.*\)"$/\1/'
		} | sort -u
	)

	if (( ${#candidates[@]} == 0 )); then
		VERDICT="missing"
		DETAIL="this branch touches no artifact under ${ARTIFACT_DIR}, and no commit carries an Adversarial-pass: trailer"
		return
	fi

	# A section that was already there is not this branch's pass. Every story
	# artifact in this tree carries a `## Adversarial pass` from its own story,
	# so touching one for an unrelated reason — a typo, a link, a status line —
	# would otherwise turn this gate green having reviewed nothing. For a file
	# that already existed at the merge base, the section has to have CHANGED;
	# a file this branch creates is new evidence by construction.
	local found="" reason=""
	for path in "${candidates[@]}"; do
		local content=""
		if [[ -f "${REPO_ROOT}/${path}" ]]; then
			content="$(cat -- "${REPO_ROOT}/${path}" 2>/dev/null || true)"
		else
			content="$(git -C "${REPO_ROOT}" show "HEAD:${path}" 2>/dev/null || true)"
		fi
		[[ -z "${content}" ]] && continue
		printf '%s' "${content}" | has_record || continue

		local base_content
		if base_content="$(git -C "${REPO_ROOT}" show "${merge_base}:${path}" 2>/dev/null)"; then
			if [[ "$(printf '%s' "${base_content}" | record_section)" == "$(printf '%s' "${content}" | record_section)" ]]; then
				reason="${reason}
    ${path} (its adversarial-pass section is unchanged from ${resolved})"
				continue
			fi
		fi
		found="${path}"; break
	done

	if [[ -n "${found}" ]]; then
		VERDICT="record"; DETAIL="## Adversarial pass in ${found}"
	else
		VERDICT="missing"
		DETAIL="$(printf '%s\n' "${candidates[@]}" | sed 's/^/    /')${reason}"
	fi
}

# --- Hook applicability -------------------------------------------------------
#
# PreToolUse delivers {"tool_name": ..., "tool_input": {...}} on stdin. The
# matcher in settings.json is deliberately wider than the thing being gated,
# because two different surfaces open pull requests here and a matcher naming
# only one of them would be dead on the other. `gh pr create` arrives as a Bash
# command; a remote session has no `gh` installed at all and opens the PR
# through the GitHub MCP server instead. So both are matched, and this narrows
# Bash down to the one command that matters.
#
# Anything unparseable is not applicable: a hook that denies because it could
# not read its own input is the wedged branch this design refuses.

# Strips a leading `env` and any run of VAR=value assignments off one command
# segment, so what remains starts with the program actually being run. The value
# may be quoted and may contain spaces — the first cut of this refused exactly
# that shape, which made an acknowledged invocation invisible to the gate rather
# than acknowledged by it.
strip_assignments() {
	local seg="$1"
	seg="${seg#"${seg%%[![:space:]]*}"}"
	[[ "${seg}" =~ ^env[[:space:]]+ ]] && seg="${seg#env}" && seg="${seg#"${seg%%[![:space:]]*}"}"
	while [[ "${seg}" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]]; do
		seg="${seg#*=}"
		case "${seg}" in
			'"'*) seg="${seg#\"}"; seg="${seg#*\"}" ;;
			"'"*) seg="${seg#\'}"; seg="${seg#*\'}" ;;
			*)    seg="${seg#"${seg%%[[:space:]]*}"}" ;;
		esac
		seg="${seg#"${seg%%[![:space:]]*}"}"
	done
	printf '%s' "${seg}"
}

# Every segment of a command line, one per line: separators split it, and so do
# newlines, so a heredoc body is read as its own segments rather than as a tail
# of the command that opened it.
command_segments() {
	# The trailing newline is load-bearing: `while read` discards a final line
	# that has none, which silently skipped every single-segment command — the
	# common case, and the one the gate exists for.
	printf '%s\n' "$1" | sed 's/&&/\n/g; s/||/\n/g; s/[;&|]/\n/g'
}

# PreToolUse delivers {"tool_name": ..., "tool_input": {...}} on stdin. The
# matcher in settings.json is deliberately wider than the thing being gated,
# because two different surfaces open pull requests here and a matcher naming
# only one of them would be dead on the other: the CLI arrives as a Bash
# command, while a remote session has no CLI installed at all and goes through
# the GitHub MCP server. Both are matched; this narrows Bash to the one command
# that matters.
#
# The phrase must sit in COMMAND POSITION, not merely somewhere in the text.
# Matching it anywhere was measured blocking this script's own wiring: every
# command whose text documents the gate names the phrase, so the gate could not
# be written without reaching for its own escape hatch. Describing an invocation
# is not performing one.
#
# The cost runs the other way and is deliberate: an invocation wrapped in a
# quoted string (bash -c, an alias, a variable) never reaches command position
# here and passes. That is a bypass someone chose, not an accident someone had.
#
# Anything unparseable is not applicable — a hook that denies because it could
# not read its own input is the wedged branch this design refuses.
hook_applies() {
	local payload="$1"

	command -v jq >/dev/null 2>&1 || return 1
	[[ -n "${payload}" ]] || return 1

	local tool_name
	tool_name="$(jq -r '.tool_name // empty' <<<"${payload}" 2>/dev/null || true)"
	[[ -n "${tool_name}" ]] || return 1

	case "${tool_name}" in
		*create_pull_request*) return 0 ;;
		Bash) ;;
		*) return 1 ;;
	esac

	local command_text segment program
	command_text="$(jq -r '.tool_input.command // empty' <<<"${payload}" 2>/dev/null || true)"
	[[ -n "${command_text}" ]] || return 1

	while IFS= read -r segment; do
		program="$(strip_assignments "${segment}")"
		[[ "${program}" =~ ^gh[[:space:]]+pr[[:space:]]+create([^[:alnum:]_-]|$) ]] && return 0
	done < <(command_segments "${command_text}")
	return 1
}

# The acknowledgement written as an assignment prefix on the gated command. A
# hook process does not inherit the environment of the command it gates, so on
# this path the value has to be read out of the command TEXT — the environment
# variable is never set where the hook can see it. An empty value is not an
# acknowledgement: `ADVERSARIAL_PASS_ACK=""` used to bypass while recording the
# two quote characters as its reason.
extract_ack() {
	local command_text="$1" segment rest value
	while IFS= read -r segment; do
		segment="${segment#"${segment%%[![:space:]]*}"}"
		[[ "${segment}" == *ADVERSARIAL_PASS_ACK=* ]] || continue
		rest="${segment#*ADVERSARIAL_PASS_ACK=}"
		case "${rest}" in
			'"'*) rest="${rest#\"}"; value="${rest%%\"*}" ;;
			"'"*) rest="${rest#\'}"; value="${rest%%\'*}" ;;
			*)    value="${rest%%[[:space:]]*}" ;;
		esac
		value="${value#"${value%%[![:space:]]*}"}"
		value="${value%"${value##*[![:space:]]}"}"
		[[ -n "${value}" ]] && { printf '%s' "${value}"; return 0; }
	done < <(command_segments "${command_text}")
	return 1
}

json_string() {
	jq -Rs . <<<"$1"
}

if [[ "${MODE}" == "self-test" ]]; then
	run_self_test
	exit $?
fi

# --- Report ------------------------------------------------------------------

deny_reason() {
	cat <<EOF
No adversarial-pass record on this branch.

CLAUDE.md requires the pass to run AND its findings to be written down BEFORE
the pull request exists — the rule failed three times (#616, #620, #770) with
nothing but prose enforcing it, so it is enforced here now.

Record it one of two ways, then retry:
  - a '## Adversarial pass' section in the branch's artifact under
    ${ARTIFACT_DIR}/, or
  - an 'Adversarial-pass:' trailer on a commit of this branch.

Artifacts this branch touches, none of which carry the section:
${DETAIL}

If the pass genuinely does not apply, proceed on the record:
  ADVERSARIAL_PASS_ACK="<why this PR needs no pass>" <your command>
EOF
}

case "${MODE}" in
	hook)
		payload=""
		if [[ ! -t 0 ]]; then
			payload="$(timeout 2 cat 2>/dev/null || true)"
		fi
		hook_applies "${payload}" || exit 0

		if [[ -n "${payload}" ]] && command -v jq >/dev/null 2>&1; then
			ACK_FROM_COMMAND="$(extract_ack "$(jq -r '.tool_input.command // empty' <<<"${payload}" 2>/dev/null || true)" || true)"
		fi
		decide

		# Fail open on anything that is not a determinate "missing".
		if [[ "${VERDICT}" != "missing" ]]; then
			if [[ "${VERDICT}" == "acknowledged" ]]; then
				printf '{"systemMessage":%s}\n' \
					"$(json_string "⚠ adversarial-pass: proceeding UNCHECKED — acknowledged: ${DETAIL}")"
			fi
			exit 0
		fi
		printf '{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"deny","permissionDecisionReason":%s}}\n' \
			"$(json_string "$(deny_reason)")"
		exit 0
		;;
	*)
		decide
		case "${VERDICT}" in
			record)
				echo "✓ adversarial-pass: ${DETAIL}"
				exit 0 ;;
			acknowledged)
				echo "⚠ adversarial-pass: UNCHECKED — acknowledged: ${DETAIL}"
				exit 0 ;;
			undetermined)
				echo "· adversarial-pass: undetermined — ${DETAIL}"
				exit 0 ;;
			missing)
				echo "✗ adversarial-pass: no record on this branch"
				echo
				deny_reason | sed 's/^/  /'
				[[ "${MODE}" == "strict" ]] && exit 1
				exit 0 ;;
		esac
		;;
esac
