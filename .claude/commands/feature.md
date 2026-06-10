---
description: Start a new feature in an isolated git worktree (branch + stack up)
argument-hint: <scope> <slug>   e.g. backoffice search-filters
---

Start new feature work in an isolated worktree. **Never** start a feature in the
primary `main` checkout — see the `main-checkout-concurrent-use` memory.

Arguments: `$ARGUMENTS` → first token is `<scope>`, the rest is the `<slug>`.

- `<scope>` ∈ `api | pwa | backoffice | frontoffice | shared` (a bounded context
  or deployable, per the branch-naming table in `CLAUDE.md`).
- `<slug>` is a short kebab-case description of the feature.

Steps:

1. Parse `$ARGUMENTS`. If scope or slug is missing or scope isn't one of the
   allowed values, ask the user for the missing piece before proceeding — do not
   guess.
2. Build the branch name `feat/<scope>-<slug>` (lower-case, kebab-case; no
   trailing period). The make target appends a random 4-char suffix to both the
   branch and the dir slug, so collisions are impossible and re-running is safe.
3. From the repo root, run:

   ```bash
   make worktree.create BRANCH=feat/<scope>-<slug> START=true
   ```

   `START=true` also brings the worktree's isolated stack up via `make app.dev`.
   The recipe prints the new worktree path under `.claude/worktrees/`.
4. `cd` into that printed worktree path for all subsequent commands this session,
   so checks/tests/edits run against the worktree's code and its own Compose
   stack (`erpify-<slug>`), never `main`'s.
5. Briefly confirm to the user: branch created, worktree path, and that the stack
   is up. Then proceed with the actual feature work there.

Notes:
- For a fix/chore/hotfix/etc. instead of a feature, use the matching prefix
  (`fix/…`, `chore/…`) per the branch-naming table — this command defaults to
  `feat/`.
- Tear down later with `make worktree.remove NAME=<dir>` (local only).
