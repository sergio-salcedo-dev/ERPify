---
name: git-worktree-code-review
description: Collect the complete diff of a branch that lives in an isolated git worktree, so a code review sees committed and uncommitted work alike. Use when a branch under review does not appear in the primary checkout.
allowed-tools: Bash(git:*)
---

# Reviewing a branch that lives in a worktree

Feature work here happens in a linked worktree under `.claude/worktrees/`, so a branch
under review is often absent from the primary checkout's `git branch -a`. This is how to
assemble its diff.

**Read-only, without exception.** Collecting a diff changes nothing: no edit, no `git`
state change, no mutating `make` target. `CLAUDE.md` → *Code review* states it for every
review layer, and the reason is that the object store, the index and the reflog are
shared with whatever other session is working in that tree — a subagent here once emptied
the dev database.

## 1. Resolve the worktree exactly

Never match a branch or directory name loosely. Worktree slugs differ only by a random
4-char suffix, so a near-match is a different session's live checkout — loose matching is
what twice let `worktree.remove` delete one (`CLAUDE.md` → *Worktrees*).

```sh
git worktree list --porcelain | grep -A2 "^worktree .*<slug>"
```

Require exactly one match and take its `worktree` line verbatim. Zero matches or more
than one is a stop, not a guess.

Two further checks before reading anything:

- `[ -e "<wt>/.git" ]` — without it you are not in a worktree at all, and `git -C` then
  answers from the **parent repository on `main`** rather than failing, so a residue of a
  half-finished removal reads exactly like a live checkout. The registry above is the
  authority; treat `.git` as corroboration only, because a failed `worktree remove` can
  leave it either way (`make/worktree.mk`).
- `git -C "<wt>" status --porcelain | grep -qE '^(UU|AA|DD|AU|UA|DU|UD)'` — a tree mid
  merge or rebase is not reviewable; its conflict markers are not authored code.

**Pass the absolute path** to anyone you delegate to. A subagent defaults to the primary
checkout, which is on `main` and does not contain the branch.

## 2. Diff from the merge base, never from `HEAD`

```sh
base=$(git -C "<wt>" merge-base origin/main HEAD)
git -C "<wt>" diff "$base"
```

`git diff HEAD` shows only what is **uncommitted**, so on a branch that has committed its
work it reports an empty or partial review and looks like success. Use `origin/main`, not
`main`: the local ref is routinely behind here, which is why `make worktree.create` takes
`BASE=origin/main`.

An empty `origin/main..HEAD` does not mean "the changes are uncommitted" — it also means
the branch is already merged, or was based on something other than `main`.

## 3. Include untracked files without writing to the tree

`git diff` cannot see an untracked file, and the usual fix — `git add -N` — writes the
index of the checkout you are reviewing and is never undone, so the owner's next
`commit -a` silently carries it. Use a throwaway index instead:

```sh
export GIT_INDEX_FILE=$(mktemp -u)          # -u: a PATH, not an empty file
git -C "<wt>" read-tree HEAD                 # git cannot use an index it did not write
git -C "<wt>" add -N -- $(git -C "<wt>" ls-files -o --exclude-standard)
git -C "<wt>" diff HEAD                      # now includes the untracked files
```

`mktemp` without `-u` creates an empty file, and git rejects it with
`index file smaller than expected` — measured, and the failure is silent enough to be
read as "no untracked files".

or, per file, `git -C "<wt>" diff --no-index /dev/null <path>`.

Read the file list with `-z` and split on NUL: a porcelain path containing a space or
non-ASCII comes back C-quoted, and a rename arrives as `R old -> new`, so a line-split
list feeds the arrow or a quoted string to the next command and drops files silently.

Two things this still will not show, so say so rather than claiming a complete diff:
a file under an ignored path (`/_bmad/`, `.claude/skills/bmad-*/`, `api/var/`, …), which
needs `--ignored` to even list, and the contents of a binary.

## 4. Hand the diff to the review

The surviving control is the parallel review layers in `CLAUDE.md` → *Code review*, run
read-only against this same absolute path. The single adversarial pass this repo used to
require was retired in #903; do not route the diff to it.
