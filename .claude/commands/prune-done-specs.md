---
description: Delete completed quick-dev spec artifacts (status done) from _bmad-output/implementation-artifacts
argument-hint: "[--dry-run]   (default: delete + stage + commit; --dry-run only reports)"
---

Prune **completed** quick-dev spec artifacts from
`_bmad-output/implementation-artifacts/`, per the "bmad working artifacts"
convention in `CLAUDE.md` (and the `delete-done-spec-artifacts` memory): a
`spec-*.md` whose frontmatter `status:` is `done` is a transient design contract
whose job is finished — git history preserves it, so remove it from the tree.

Arguments: `$ARGUMENTS` may contain `--dry-run` to report only (change nothing).

Steps:

1. **Branch guardrail.** Run `git rev-parse --abbrev-ref HEAD`. If it is `main`,
   STOP and tell the user to run this on a `chore/…` (or feature) branch — never
   commit deletions to `main`. (Skip this check for `--dry-run`.)

2. **Find candidates.** List `_bmad-output/implementation-artifacts/spec-*.md`.
   For each, read ONLY the frontmatter/header `status:` field — the value inside
   the leading `---` block, or the first `Status:` line. Classify:
   - value is `done` (any case/quoting: `status: done`, `Status: 'done'`) →
     **delete candidate**.
   - anything else (`ready-for-dev`, `in-progress`, no `status`) → **keep**.

   Ignore `status:` mentions inside prose (e.g. a referenced ADR's
   `(status: IMPLEMENTATION LOCKED)`) — only the artifact's own header counts.
   NEVER touch non-spec files: `deferred-work.md`, `sprint-status.yaml`, and
   anything not matching `spec-*.md` are live registries / out of scope. Story &
   PRD artifacts (e.g. `N-N-*.md`) are also out of scope — this prunes only
   quick-dev `spec-*.md`.

3. **Link safety.** For each delete candidate, grep the repo for its filename
   (without extension) across `*.md`/`*.php`/`*.ts`/`*.yaml`. If anything outside
   the file itself references it, SKIP that file and warn — deleting it would
   break a Markdown link or pointer.

4. **Act.**
   - `--dry-run`: print the summary (would-delete / kept / skipped-with-reason)
     and STOP — no changes.
   - default: `git rm` each safe candidate (stages the deletion).

5. **Commit (default flow only).** If anything was removed, commit with
   `chore(docs): remove completed spec artifacts` (list the files in the body).
   Do **not** push or merge — pushing the branch / opening a PR / merging is the
   user's call (protected-main rules in `CLAUDE.md`). Report the commit hash.

6. **Summary.** Always print a short table: deleted, kept (with their status),
   and skipped (with the reason).

Rationale and exact convention: `CLAUDE.md` → Conventions → "bmad working
artifacts".
