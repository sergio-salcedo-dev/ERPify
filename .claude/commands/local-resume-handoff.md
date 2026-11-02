---
description: Find the latest handoff written by /local-generate-handoff — on the current branch or on any branch of origin — check its branch out and resume the work it describes.
argument-hint: [<scope>[/<task>] | <branch>]   e.g. identity-invitation-lifecycle/ii-4
---

The counterpart of `/local-generate-handoff`. Handoffs are tracked under
`_bmad-output/handoffs/<scope>/<task>/<YYYYMMDD-HHMMSS>-<slug>.md` on the branch whose work they
describe, so they can be found from any clone after a fetch — a new machine or a cloud session whose
container started empty.

## Procedure

### 1. Fetch

```bash
git fetch --prune origin
```

### 2. Find the candidates

The timestamp leads the file name, so the newest handoff is the greatest path within a folder and the
greatest basename across folders.

- **No argument, and the current branch is not `main`:** the current branch's own handoffs —
  `git pull --ff-only` first, then the newest file under `_bmad-output/handoffs/`.
- **No argument on `main`, or `<scope>[/<task>]` given:** every branch of origin. List what each holds:

  ```bash
  for ref in $(git for-each-ref --format='%(refname:short)' refs/remotes/origin | grep -v '^origin/HEAD$'); do
    git ls-tree -r --name-only "$ref" -- _bmad-output/handoffs/ | sed "s|^|$ref |"
  done
  ```

  Filter by `<scope>` / `<task>` when given, then sort by basename and keep the newest few. The same
  file appears on several refs once a branch is merged into another; prefer the ref that is **not**
  `origin/main`.
- **`<branch>` given:** `git ls-tree -r --name-only origin/<branch> -- _bmad-output/handoffs/`.

Show the user the newest candidates (path, branch, timestamp) when there is more than one plausible
match, and ask which one; with exactly one, continue.

### 3. Check the branch out

The work belongs on the handoff's branch, never on `main`, and a feature resumes in a worktree (root
`CLAUDE.md` → Worktrees). If the current checkout is already on that branch, stay. Otherwise:

```bash
git worktree add ".claude/worktrees/<branch with / replaced by ->" "<branch>"
```

and continue from that directory. Refuse to resume if the branch has been deleted or its PR merged:
say so, and show the handoff for reference only.

### 4. Resume

Read the handoff file, then the artifacts its **ARTIFACTS (re-read)** section names, and verify its
claims against the tree before acting on them (a handoff is a snapshot; the branch may have moved
since — compare `git log` with its **DONE** list). Then act on the block: invoke its leading
`/bmad-…` skill with the rest of the block as context, and continue from **NEXT**.

Report which handoff was resumed, from which branch, and anything in it that no longer matched the tree.
