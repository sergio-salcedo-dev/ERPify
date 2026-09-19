# Handoff — weekly dependabot batch, 2026-09-18

Session stopped here because the machine was being shut down. Everything below was
verified against the tree, the remote and the running stack at the moment of writing,
not recalled.

**Delete this file when the batch closes.** It is the last commit on the branch so it
drops cleanly: `git rebase --onto HEAD~1 HEAD~1 <branch>` on the tip, or simply
`git rm` it and amend. It describes work that stops being pending the moment #944
merges, so it must not reach `main`.

## State at stop

| | |
|---|---|
| Branch | `chore/deps-weekly-batch-2026-09-18-3o32` |
| Worktree | `.claude/worktrees/deps-weekly-batch-2026-09-18-3o32` |
| Head | `d9b42d84`, local and `origin` in agreement |
| PR | **#944**, `OPEN`, `mergeStateStatus=CLEAN`, no review submitted |
| CI on that head | 20 `SUCCESS`, 1 `SKIPPED` (`PWA Burn-In`, conditional), 0 failures |
| Superseded bot PRs | #931–#941, all 11 deliberately left open — dependabot closes them itself once it sees the targets |
| Docker | 5 containers of this worktree's stack still up |

## What is done

Eleven dependabot PRs consolidated across npm, composer and docker, plus two source
changes the bumps demanded and two advisory fixes the review forced. Full account,
including every version read back out of the lockfiles, lives in the PR body — read
that rather than reconstructing it.

All three code-review layers ran as parallel read-only subagents against this worktree
(Blind Hunter, Edge Case Hunter, Acceptance Auditor). **No GRAVE.** Every `patch` they
returned is applied and re-verified; the commit was amended and force-pushed once, to
`d9b42d84`. The findings and where each layer ran are recorded in the PR body's
*Code review* section.

Gates re-run against the final tree, each a fresh run with its exit code read:
`pwa.quality.dry-run`, `pwa.quality`, `pwa.test.unit`, `php.quality`,
`php.quality.dry-run`, `ci.api`, `composer.check.all`, `docker build --target prod`,
`npm audit` — all 0 / no vulnerabilities.

## Pending — needs Sergio, do not decide for him

1. **Merging #944.** It is ready and CI is green, but merging needs his explicit
   per-PR permission (`CLAUDE.md` → *Protected `main`*). Approval was never given, so
   an agent picking this up must ask, not merge. Note `gh pr merge` is blocked by the
   auto-mode classifier here — measure the conditions and hand him the command to run
   with `!`, rather than working around it.

2. **Strix no longer reviews this repository.** Its bot comment on #944 says the
   workspace trial has ended, so no PR gets an automated security review any more.
   Combined with the adversarial-pass gate having been retired in #903, the three
   manual review layers are now the only control. Whether to renew, replace or
   consciously accept that is his call and nothing tracks it yet — it is not in
   `PRODUCTION_SECURITY_CHECKLIST.md` §7 and has no issue.

## Pending — mechanical, no decision needed

3. **This worktree and its stack are still up** (5 containers), left running
   deliberately in case he wanted to inspect something. Clean up with
   `make worktree.remove NAME=deps-weekly-batch-2026-09-18-3o32` once #944 is merged —
   not before, the branch is only local-plus-origin and removal deletes it.

4. **Delete this file**, per the top of the note.

## Things learned here that are already saved

Two memory entries were written so the next session does not re-derive them:
a dependabot `groups:` without `applies-to` does not cover security updates
(all three groups in this repo had the gap; fixed in this PR), and npm's third
silence in a batch — a freshly bumped parent can require more than a root caret
already satisfies, leaving the lock contradicting itself with nothing red. That
second one is what nearly shipped `next` 16.3.5 with AVIF re-enabled over
`sharp` 0.35.3.
