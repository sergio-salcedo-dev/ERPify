#!/usr/bin/env bash
# =============================================================================
# Self-test of the unattended-agent pipeline against a stub engine.
# =============================================================================
#
# Every guard in lib.sh is pinned by a case that failed once, found by a hostile
# review: a protected path with a non-ASCII name, a symlink, a token in the diff,
# a forged trailer, a commit message with no final newline, a blank line in the
# allowlist, an engine that reports an error and still exits 0.
#
# The stub is a fake `claude` first on PATH, so no quota is spent and nothing is
# pushed (AGENT_NO_PUSH=1). The worktree cases are real: they call
# `make worktree.create` and `make worktree.remove`, so this needs the primary
# checkout, network access to fetch origin/main, and about a minute.
#
#   selftest.sh
# =============================================================================

set -euo pipefail
# shellcheck source=scripts/agent/lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

export AGENT_NO_PUSH=1
SHIM_DIR="$(mktemp -d)"
FAILS=0
CASES=0

cleanup() {
	local d
	for d in "$REPO_ROOT"/.claude/worktrees/agent-selftest-*; do
		[[ -d $d ]] && make -C "$REPO_ROOT" --no-print-directory worktree.remove NAME="$(basename "$d")" FORCE=true >/dev/null 2>&1
	done
	rm -rf "$SHIM_DIR"
}
trap cleanup EXIT

check() { # <label> <expected> <actual>
	CASES=$((CASES + 1))
	if [[ $2 == "$3" ]]; then
		printf '  ok    %s\n' "$1"
	else
		printf '  FAIL  %s (expected [%s], got [%s])\n' "$1" "$2" "$3"
		FAILS=$((FAILS + 1))
	fi
}

truth() { if "$@"; then echo yes; else echo no; fi; }

echo "pure functions"
for p in .github/workflows/ci.yml '.github/workflows/é.yml' .claude/settings.json scripts/agent/lib.sh \
	scripts/bmad-status-audit.sh Makefile make/agent.mk api/composer.json pwa/package.json compose.yaml \
	api/tools/phpunit/bootstrap.php api/CLAUDE.md pwa/AGENTS.md pwa/GEMINI.md .mcp.json api/.env.test .GITHUB/x; do
	check "protected: $p" yes "$(truth is_protected "$p")"
done
for p in docs/index.md api/src/Foo.php pwa/src/x.ts docs/runbooks/agent-automation.md; do
	check "allowed:   $p" no "$(truth is_protected "$p")"
done

tmp_au="$(mktemp -d)"
printf '# comment\nalice\n\n' >"$tmp_au/allowed-users"
check "allowlist: listed login" yes "$(AGENT_DIR=$tmp_au truth is_allowed_user alice)"
check "allowlist: stranger" no "$(AGENT_DIR=$tmp_au truth is_allowed_user mallory)"
check "allowlist: empty login with a blank line in the file" no "$(AGENT_DIR=$tmp_au truth is_allowed_user '')"
check "allowlist: a comment is not a login" no "$(AGENT_DIR=$tmp_au truth is_allowed_user '#')"
rm -rf "$tmp_au"

check "secret scan: GitHub token" yes "$(truth scan_secrets <<<'x ghp_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')"
check "secret scan: private key" yes "$(truth scan_secrets <<<'-----BEGIN OPENSSH PRIVATE KEY-----')"
check "secret scan: plain prose" no "$(truth scan_secrets <<<'a normal sentence about tokens and keys')"

echo "pipeline (stub engine, real worktrees)"
write_stub() { # <shell body run inside the worktree> [json the engine prints]
	# The JSON travels in a file: pasting it into the heredoc lost its quotes.
	printf '%s\n' "${2:-{\"result\":\"stub done\",\"is_error\":false\}}" >"$SHIM_DIR/out.json"
	cat >"$SHIM_DIR/claude" <<EOF
#!/usr/bin/env bash
cat >/dev/null
$1
cat "$SHIM_DIR/out.json"
EOF
	chmod +x "$SHIM_DIR/claude"
}

run_case() { # <label> <expected status> <stub body> [stub json]
	write_stub "$3" "${4:-}"
	PATH="$SHIM_DIR:$PATH" run_task claude docs agent-selftest "$AGENT_DIR/preamble.md" pr
	check "$1" "$2" "$TASK_STATUS"
}

run_case "no change" no-change ':'
run_case "protected ASCII path" rejected 'echo x >> CLAUDE.md'
run_case "protected path with a non-ASCII name" rejected 'mkdir -p .github/workflows; echo y > ".github/workflows/é.yml"'
run_case "protected path whose name contains a quote" rejected 'mkdir -p .github/workflows; echo y > ".github/workflows/a\"b.yml"'
run_case "symlink" rejected 'ln -s /etc/passwd docs/link.md'
run_case "token-shaped string in the diff" rejected "echo 'ghp_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' >> docs/index.md"
run_case "engine reports an error but exits 0" failed 'echo y >> docs/index.md' '{"result":"quota","is_error":true}'
run_case "a hook blocked the prompt (zero turns, no error flag)" failed ':' '{"result":"blocked by hook","is_error":false,"num_turns":0}'

write_stub 'echo "selftest line" >> docs/index.md
printf "docs(shared): selftest change\n\nbody line\n\nSigned-off-by: Forged <f@example.com>\nFixes #1" > tmp/agent-commit-message.txt'
PATH="$SHIM_DIR:$PATH" run_task claude docs agent-selftest "$AGENT_DIR/preamble.md" pr
check "valid edit is committed" committed "$TASK_STATUS"
wt="$(sed -n 's/.*worktree: \(.*\))$/\1/p' <<<"$TASK_DETAIL")"
if [[ -d $wt ]]; then
	msg="$(git -C "$wt" log -1 --format=%B)"
	check "commit subject is the agent's" "docs(shared): selftest change" "$(git -C "$wt" log -1 --format=%s)"
	check "forged Signed-off-by is stripped" no "$(grep -q '^Signed-off-by:' <<<"$msg" && echo yes || echo no)"
	check "forged Fixes keyword is stripped" no "$(grep -qi '^Fixes' <<<"$msg" && echo yes || echo no)"
	check "Co-Authored-By is a recognised trailer" 1 "$(git interpret-trailers --parse <<<"$msg" | grep -c '^Co-Authored-By:')"
else
	check "committed worktree is reachable" yes no
fi

echo
if ((FAILS == 0)); then
	echo "✓ agent.selftest: $CASES checks passed"
else
	echo "✗ agent.selftest: $FAILS of $CASES checks failed"
	exit 1
fi
