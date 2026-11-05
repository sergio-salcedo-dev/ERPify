---
name: prune-merged-branches
description: Delete merged local and remote branches and the worktrees holding them, with the GitHub PR as the arbiter of "merged" because squash merges hide a merged branch from git. Use when asked to delete, clean up or prune merged branches.
---

# Pruning merged branches

Branches, remote branches and worktrees pile up because each is merged by a squash, and a squash
leaves nothing git can see: `git branch --merged`, `git cherry` and `-d` all report a shipped branch
as unmerged. The PR is therefore the arbiter, and everything below is read-only until a branch has
been **proven** shipped.

Run it as one sequence from the primary checkout. Do not spawn subagents: every step reads the
result of the one before, and the work is a few dozen `git`/`gh` calls.

## 1. Inventory (read-only)

```sh
git fetch --prune origin
git for-each-ref --format='%(refname:short) %(objectname) %(upstream:track)' refs/heads refs/remotes
git worktree list
```

Ignore `main` and the bare `origin` entry (the remote's HEAD symref). Other sessions may be working
in the same repository: list again right before deleting, because a worktree and its branch can be
removed under you mid-run.

## 2. Classify each branch by its PR

`gh pr list --head <branch> --state all --json number,state,mergedAt,title` — never by git.

| PR state | Action |
|---|---|
| `MERGED` | candidate, go to 3 |
| none | **keep** — it holds work nothing else has; say how many commits it is ahead of `origin/main` |
| `OPEN` | **keep** |
| `CLOSED`, not merged | **keep and ask** — a parked branch is not a merged one, and its remote may be retained on purpose |

## 3. Prove the tip is shipped

A merged PR is not enough: commits pushed after it merged, or a branch reused for a second piece of
work, share the name and are lost by deleting it. Every commit of the branch must be accounted for.

```sh
gh pr view <n> --json commits --jq '.commits[].oid'            # what the PR carried
git rev-list "$(git merge-base origin/main <tip>)..<tip>"       # what the branch holds
```

Comparing the tip with `headRefOid` is not the test: once the remote branch is gone that commit is
not in the local object store, so the comparison can fail without meaning anything.

For a commit the PR did **not** carry, find where it went before deciding:
`git log origin/main --grep='<subject>' -F` (it often merged as a sibling PR), then
`gh api repos/{owner}/{repo}/commits/<sha>/pulls`, then compare its added lines against
`origin/main` with `git show origin/main:<file>`. A hit means shipped. **No hit means keep and report
it** — never `FORCE=true` past a commit you could not place.

## 4. Worktrees holding a candidate

For each, with its own path:

```sh
git -C <wt> status --porcelain          # must be empty
for p in /proc/[0-9]*; do readlink "$p/cwd"; done | grep -F <wt>   # must be empty: no session is in it
```

Dirty or in use means **keep the worktree and its branch**, and say whose it looks like. Otherwise
`make worktree.remove NAME=<dir>` **without** `FORCE`: git refuses a tree it can see is dirty, and the
target clears the root-owned bind-mount residue by itself, with no `sudo`. "Can see" is the limit: a
root-owned directory git cannot open is invisible to that test, which is acceptable because only a
container could have written it, but it means the status check above is not a proof of emptiness.

`NAME` is the directory, which is not always the branch's slug. A run that ends with a single `•`
saying the branch was kept is expected for a squash-merged branch (`-d` cannot see the squash); step 5
deletes it. Any other `•` or `✗` is a stop.

## 5. Delete the local branch, guarded by its tip

`git branch -D` has no expected-value guard. If another session moved the branch since step 3, it
deletes the new commits. `git update-ref -d` has the expected-value guard but **not** the checked-out
one: deleting a branch a worktree (the primary included) still has checked out leaves that HEAD
unborn. So skip any branch that appears in `git worktree list` at this point; after step 4 removed
its worktree it no longer does.

```sh
git update-ref -d refs/heads/<branch> <tip-oid-from-git>
```

Take the oid from `git rev-parse` and check it still equals the tip proven in step 3, never from
memory. If it differs, keep the branch.

## 6. Delete the remote branch, with a lease

```sh
full=$(git rev-parse refs/remotes/origin/<branch>)
git push origin --force-with-lease=refs/heads/<branch>:"$full" --delete <branch>
```

The lease value is the full oid **read from git**. A hash typed or abbreviated by hand is rejected,
and a wrong one that parsed would delete the wrong state. Only branches proven in steps 2–3.

Never: `main`, a branch with an open PR, a closed-unmerged branch nobody confirmed, or any
`--force` without the lease.

## 7. Verify and report

`git fetch --prune origin`, `git worktree prune -v`, then list branches, remotes and worktrees again.
Report, per branch: deleted (with the PR number), kept (with the reason), and anything another
session did while you worked. Say which deletions touched the remote — those are the ones that are
hard to take back.

If a directory under `.claude/worktrees/` survives the target, re-run
`make worktree.remove NAME=<branch> FORCE=true` only after the step-3 proof; never `rm` it by hand
and never `make worktree.chown`.
