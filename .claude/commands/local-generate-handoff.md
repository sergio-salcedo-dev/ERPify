---
description: Write a resumable BMAD handoff to _bmad-output/handoffs/, commit and push it on the current branch, so the work can continue later from any machine or cloud session (resume with /local-resume-handoff).
---

Use this when the current context window is heavy, or the session is about to end, and the work must
continue later — in a fresh window, on another machine, or in a new cloud session whose container
starts empty. It writes a self-sufficient handoff, **commits and pushes it on the branch it describes**,
and prints the block. It starts with the right `/bmad-…` invocation so the correct agent activates
immediately, and gives the next session enough to rehydrate — mostly by pointing it at artifacts to
re-read, not by inlining everything.

Result shape (the block inside the file, also printed in chat):

```
/bmad-agent-dev continue Epic 2, task 2.2 (tests). Backend was done in a prior session.

BRANCH: feat/api-foo (PR #123)
DONE: …
IN PROGRESS: …
NEXT: …
ARTIFACTS (re-read): …
KEY PATHS: …
DECISIONS/CONSTRAINTS: …
```

## Fixed conventions

- Output language: **English** (matches `.claude/commands/` and this repo's other artifacts).
- Output location: **`_bmad-output/handoffs/<scope>/<task>/<YYYYMMDD-HHMMSS>-<slug>.md`**, tracked.
  - `<scope>` — the epic's slug (`identity-invitation-lifecycle`), or for work outside an epic the
    branch name with `/` replaced by `-` (`chore-handoff-in-repo`).
  - `<task>` — the story id (`ii-4`), or `general` when there is none.
  - The timestamp means two sessions on one branch never write the same file.
- The first line of the block **must** be a valid slash invocation (e.g. `/bmad-agent-dev`,
  `/bmad-dev-story`, `/bmad-help`) so the next window activates the right agent.
- **The repository is public, and a handoff stays in its git history for ever** — deleting the file
  later does not unpublish it. Never write a secret, a credential, a token, personal data or a real
  person's identifier into one. Tracking handoffs in-repo is accepted on the basis that ERPify runs
  only locally (no live deployment), so describing an unfixed defect exposes no running system; once a
  real deployment exists, revisit that before handing off security work.
- Paths are written as inline code, never as Markdown links: the files they name move, and the
  Markdown linter rejects a dangling target.

## Procedure

### 1. Pick the resume target

- If `$ARGUMENTS` names a BMAD agent/skill or focus (e.g. `bmad-dev-story tests Epic 2`), honor it.
- Otherwise infer the active persona/skill and current focus from this session (which agent is
  active, what work is in flight). The leading command is `/<bmad-skill>`.

### 2. Assemble the state — rich and structured

Summarize compactly but completely, citing **real** paths / IDs (no invented ones):

- **BRANCH** — the branch the work lives on, and its PR number if one is open.
- **DONE** — completed work (files touched, commits — pushed or not).
- **IN PROGRESS** — current story/task (story ID, task number, AC IDs).
- **NEXT** — the immediate next steps, in order.
- **ARTIFACTS (re-read)** — story file(s), planning/implementation artifacts, docs the next session
  should open first (paths under `_bmad-output/…` and `docs/…`).
- **KEY PATHS** — relevant `api/src/…`, `pwa/src/…`, test paths.
- **DECISIONS/CONSTRAINTS** — key decisions already made and hard constraints, including anything
  the user decided in this session that is recorded nowhere else.

Prefer pointing at artifacts to re-read over pasting large content inline — the next session can
open them itself and stay lean.

### 3. Check the branch

```bash
git rev-parse --abbrev-ref HEAD
```

- On `main` (or a detached HEAD): **do not commit**. `main` is protected and a handoff never lands
  there directly. Write the file under `tmp/bmad-md/` instead (gitignored), print the block, and tell
  the user it is local only — it survives neither a cloud container nor another machine.
- On any other branch: continue.

### 4. Write, commit and push the handoff

```bash
date +%Y%m%d-%H%M%S   # <timestamp>
mkdir -p "_bmad-output/handoffs/<scope>/<task>"
```

Create the file with **one fenced block** the user could also copy wholesale, in the shape shown at the
top of this file. Then commit **only that file**, leaving anything else staged or modified untouched:

```bash
git add -- "<file>"
git commit -m "docs(bmad): hand off <scope> <task>" -- "<file>"
git push -u origin HEAD
```

If the push fails on a network error, retry up to four times with backoff (2s, 4s, 8s, 16s). If it
still fails, say so plainly — an unpushed handoff dies with the container.

### 5. Report

Print the block in chat as well (the session transcript is a second copy that outlives the
container), then the file path, the branch, whether the push succeeded, and how to resume:
`/local-resume-handoff <scope>[/<task>]` from any checkout of this repository.

## Lifecycle

A handoff is a working artifact under `_bmad-output/` and follows the root `CLAUDE.md` rule for them:
the `<scope>/` folder is deleted once the work it hands off is closed — for an epic, when the epic is
`done` and the PRs carrying it are merged; for work outside an epic, in the PR that finishes it,
before it merges. Git keeps the history.
