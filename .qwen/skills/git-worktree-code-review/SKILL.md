---
name: git-worktree-code-review
description: Procedure to locate and review uncommitted/untracked changes in an isolated git worktree for code review.
source: auto-skill
extracted_at: '2026-06-10T19:30:00.000Z'
---

# Git Worktree Code Review Procedure

When a user requests a code review for a story or branch that does not appear in the main repository's `git branch -a`, they are likely using an isolated git worktree (a common pattern in this project, e.g., via `make worktree.create`). 

Follow these steps to gather the complete diff for review:

1. **Locate the worktree**: Run `git worktree list` to find the path and branch name. Note that the user's provided branch name might have slight typos (e.g., `paginatiion` vs `pagination`); match it fuzzily or by the unique suffix (e.g., `ix9e`).
2. **Check commit history**: Run `git -C <worktree-path> log main..HEAD --oneline`. If empty, the changes are uncommitted.
3. **Inspect working tree state**: Run `git -C <worktree-path> status --porcelain` to identify modified (` M`) and untracked (`??`) files.
4. **Include untracked files in diff**: To review *all* changes (including new, untracked files) in a single unified diff, first add them with "intent-to-add":
   ```bash
   git -C <worktree-path> add -N <list-of-untracked-paths>
   ```
5. **Generate the comprehensive diff**: Run `git -C <worktree-path> diff HEAD`. This will output both the uncommitted modifications to tracked files and the full content of the newly added untracked files, providing the complete context needed for the code review.
6. **Proceed with review**: Use this comprehensive diff as the `{diff_output}` for the adversarial code review workflow.
