---
description: Squash-merge one PR once CI and the Sonar quality gate are green, pinned to the verified head; optionally remove its worktree
argument-hint: <pr-number> [--remove-worktree]
---

Squash-merge a single pull request **only if** every CI check and the SonarCloud quality gate are green,
pinned to the head that was verified. Invoking this command is the user's explicit permission to merge
**this PR and no other** (`CLAUDE.md` → *Protected `main`*); it never carries over to a second PR, and the
condition is part of the permission — a merge over a pending or red check is not authorized.

Arguments: `$ARGUMENTS` → the first token is the PR number; `--remove-worktree` anywhere also removes the
PR's local worktree after the merge. A missing or non-numeric PR number → ask, do not guess.

Steps:

1. **Read the PR.**

   ```bash
   gh pr view <n> --json number,title,state,headRefName,headRefOid,mergeable,mergeStateStatus
   ```

   Stop unless `state` is `OPEN`. Record `headRefOid` as `<head>` — every later check is against it.

2. **Wait for CI without polling.** Run `gh pr checks <n> --watch --interval 30` **in the background** and
   wait for its completion notification. Then read the arbiter, `statusCheckRollup`:

   ```bash
   gh pr view <n> --json headRefOid,mergeStateStatus,statusCheckRollup \
     --jq '{headRefOid,mergeStateStatus,checks:[.statusCheckRollup[]|{n:(.name//.context),c:(.conclusion//.state)}]}'
   ```

   Green means: every check `SUCCESS`, except a `SKIPPED` whose workflow `if:` explains it (`PWA Burn-In`
   runs only on `schedule`); `mergeStateStatus` is `CLEAN`; `headRefOid` still equals `<head>`. Any
   failure, a still-pending check, or a moved head → **stop and report**, never merge.

3. **Ask Sonar directly.** The GitHub check is not enough — a passing gate can still carry new issues:

   ```bash
   curl -s "https://sonarcloud.io/api/qualitygates/project_status?projectKey=sergio-salcedo-dev_ERPify&pullRequest=<n>"
   ```

   Require `projectStatus.status == "OK"`. Also report any open code smell the PR analysis carries
   (`sonar api GET "/api/issues/search?componentKeys=sergio-salcedo-dev_ERPify&pullRequest=<n>&resolved=false"`
   when the CLI is available); a new open issue → stop and report rather than merge.

4. **Merge, pinned.**

   ```bash
   gh pr merge <n> --squash --match-head-commit <head>
   gh pr view <n> --json state,mergeCommit,mergedAt
   ```

   Empty output does not mean success — confirm `state: MERGED` from the second command. If the auto-mode
   classifier denies the merge, do not route around it: stop and hand the user
   `! gh pr merge <n> --squash --match-head-commit <head>`.

5. **`--remove-worktree` only.** Find the worktree whose branch is `headRefName` in
   `git worktree list --porcelain` (a branch pushed under another name than the local one is matched by
   its stem). Audit **before** removing — a failed removal destroys the evidence:

   ```bash
   git -C <wt> status --porcelain                                     # must be empty
   git fetch -q origin refs/pull/<n>/head:refs/tmp/pr<n>
   git merge-base --is-ancestor $(git -C <wt> rev-parse HEAD) refs/tmp/pr<n>   # must succeed
   ```

   Either check failing → stop and report what is unshipped. Otherwise, from the primary checkout:

   ```bash
   make worktree.remove NAME=<dir> FORCE=true
   ```

   On `Permission denied` (root-owned bind-mount residue): confirm `git worktree list` no longer lists the
   path, remove that **exact** directory with a container — never a glob, never the parent:
   `docker run --rm -v "$PWD/.claude/worktrees:/w" alpine rm -rf "/w/<dir>"` — then run
   `make worktree.remove NAME=<branch> FORCE=true` again, which deletes the leftover branch. Finally
   `git update-ref -d refs/tmp/pr<n>`, and check that `git worktree list`, `ls .claude/worktrees/` and
   `git branch --list <branch>` are all clear of it.

6. **Report** the merge commit, `mergedAt`, the checks and Sonar status it was gated on, and — when asked —
   what was removed. Say which worktrees were left alone because another session owns them.

Never: merge a second PR, merge with `--admin`, re-run or skip a failing check to get green, or remove a
worktree that is not this PR's.
