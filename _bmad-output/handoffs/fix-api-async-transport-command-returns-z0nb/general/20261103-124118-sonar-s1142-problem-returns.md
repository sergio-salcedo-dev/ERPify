```
/bmad-agent-dev fix Sonar php:S1142 in AssertTransactionalTransportCommand::problem() (4 returns, max 3), then open the PR. Spanish in conversation; commits, PRs and docs in English.

BRANCH: fix/api-async-transport-command-returns-z0nb (worktree `.claude/worktrees/api-async-transport-command-returns-z0nb`, based on origin/main at bf6d6d90). No PR yet. Stack NOT started in this worktree (`make app.dev` if tests need it).

DONE (previous window):
- #872 mitigation merged to main: PR #1178, squash bf6d6d90 — command `event:transport:assert-transactional`, entrypoint call, gate `AsyncTransportBootAssertionGateTest`, docs. Three review layers ran on it; make php.quality exit 0.
- Comment posted on issue #872 (stays OPEN, residual narrowed to 3 points; ADR `@accepted-risk #872` tags and PRODUCTION_SECURITY_CHECKLIST.md §7 row kept).
- This branch was created (authorized by the user) only for the Sonar finding below. Nothing else is committed on it except this handoff.

THE FINDING: SonarCloud php:S1142 MAJOR, `api/src/Shared/Event/Infrastructure/Cli/AssertTransactionalTransportCommand.php` line ~82, `problem()`: "This method has 4 returns, which is more than the 3 allowed." It was open on PR #1178 and was merged over by mistake (the merge command was chained with the Sonar query; the command says to stop on a new open issue). It is a style defect, not behaviour.

IN PROGRESS: nothing edited yet.

NEXT:
1. Restructure `problem()` to at most 3 returns WITHOUT changing behaviour or messages. Returns today: transport cannot be built (message carries only `$throwable::class`, never the exception message, to keep DSN credentials out of logs); transport not a DoctrineTransport; Doctrine transport on another connection (`configureSchema(new Schema(), $em->getConnection(), fn () => false)` yields no tables); success (null). One option: split resolution of the transport from the checks; another: collapse the two "wrong transport" cases. Pick the simplest that reads well; do not add abstractions.
2. Keep `api/tests/Unit/Shared/Event/Infrastructure/Cli/AssertTransactionalTransportCommandTest.php` green (4 tests, including the assertions that `s3cr3t`/`broker` never appear in the output).
3. `make php.stan` on the changed file, then `make php.quality` and read the printed exit code (the "completed (exit 0)" background notice is the echo's, not make's; capture QUALITY_EXIT).
4. Commit (`fix(api): ...` conventional, lowercase subject, trailer `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`), DELETE this handoff folder `_bmad-output/handoffs/fix-api-async-transport-command-returns-z0nb/` in that same PR before it merges, push, then ask the user before `gh pr create` (they authorized the branch, not necessarily the PR).
5. PR body ends with `🤖 Generated with [Claude Code](https://claude.com/claude-code)`; state that Sonar S1142 on #1178 is the cause and no review layers are owed beyond a one-method refactor (no security surface change) — say so.
6. After the PR is up, check the Sonar PR analysis for open issues yourself and READ the result before any merge. Merging needs explicit per-PR permission from the user (`/merge-when-green <n>`); never chain the merge with its checks.

ARTIFACTS (re-read): `CLAUDE.md` (Required checks, Protected main, Code review), `docs/adr/image-deletion-signal-transport.md` (the #872 paragraph), `PRODUCTION_SECURITY_CHECKLIST.md` §7 row #872, issue #872 and its latest comment.

KEY PATHS: `api/src/Shared/Event/Infrastructure/Cli/AssertTransactionalTransportCommand.php`, `api/tests/Unit/Shared/Event/Infrastructure/Cli/AssertTransactionalTransportCommandTest.php`, `api/frankenphp/docker-entrypoint.sh`, `api/tests/Unit/Gate/AsyncTransportBootAssertionGateTest.php`, `make/php-quality.mk` (`php.lint.persistent-transport`).

DECISIONS/CONSTRAINTS:
- #872 stays OPEN by decision (Winston + external AI both recommended it; the CI gate `accepted-risk-live-state` goes red if it closes with the tag alive). Do not edit or close it.
- Optional idea NOT adopted, user's call: a gate that fails when a publisher of `ImageDeletionRequested` appears without a commit/rollback test on the real Doctrine transport (residual 3). The `@accepted-risk` tag only blocks closing the issue; it does not fire when a publisher is written.
- Old worktree `.claude/worktrees/api-async-transport-boot-assertion-oaoy` (branch `fix/api-async-transport-boot-assertion-oaoy`, stack project `erpify-api-async-transport-boot-assertion-oaoy`) is still there with its stack up. The PR was squash-merged, so removal needs `FORCE=true`; audit first: tree clean, HEAD 2a7f8136 is contained in `refs/pull/1178/head`. Ask the user first, then `make worktree.remove NAME=api-async-transport-boot-assertion-oaoy FORCE=true` from the primary.
- Every developer must rebuild the image (`make docker.up`): the entrypoint is COPY'd into it. `APP_ENV=test` exported in the host makes the containers refuse to boot (async is in-memory there by design).
- Rules to keep: subagents read-only with absolute worktree path; never `git add -A`; restore mutations by copying bytes, never `git checkout --`; temp files in the repo `tmp/` (gitignored) or the session scratchpad; no secrets or DSNs in logs.
```
