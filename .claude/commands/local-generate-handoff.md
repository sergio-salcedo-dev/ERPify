---
description: Generate a copy-paste .md (in tmp/bmad-md/) to resume BMAD work in a fresh context window — one self-sufficient block that starts with /bmad-<agent> and carries done / in-progress / next, key paths and artifacts to re-read.
---

Use this when the current context window is heavy and you want to continue in a **clean** one.
It writes a self-sufficient handoff you copy into the new window; it starts with the right
`/bmad-…` invocation so the correct agent activates immediately, and gives that fresh session
enough to rehydrate — mostly by pointing it at artifacts to re-read, not by inlining everything.

Result shape (the block the user pastes into the new window):

```
/bmad-agent-dev continue Epic 2, task 2.2 (tests). Backend was done in a prior session.

DONE: …
IN PROGRESS: …
NEXT: …
ARTIFACTS (re-read): …
KEY PATHS: …
DECISIONS/CONSTRAINTS: …
```

## Fixed conventions

- Output language: **English** (matches `.claude/commands/` and this repo's other artifacts).
- Output location: **`tmp/bmad-md/`** at the repo root (git-ignored temp dir — never `/tmp`).
- The first line of the pasteable block **must** be a valid slash invocation (e.g. `/bmad-agent-dev`,
  `/bmad-dev-story`, `/bmad-help`) so the new window activates the right agent on paste.

## Procedure

### 1. Pick the resume target

- If `$ARGUMENTS` names a BMAD agent/skill or focus (e.g. `bmad-dev-story tests Epic 2`), honor it.
- Otherwise infer the active persona/skill and current focus from this session (which agent is
  active, what work is in flight). The leading command is `/<bmad-skill>`.

### 2. Assemble the state — rich and structured

Summarize compactly but completely, citing **real** paths / IDs (no invented ones):

- **DONE** — completed work (files touched, commits if any).
- **IN PROGRESS** — current story/task (story ID, task number, AC IDs).
- **NEXT** — the immediate next steps, in order.
- **ARTIFACTS (re-read)** — story file(s), planning/implementation artifacts, docs the new window
  should open first (paths under `_bmad-output/…` and `docs/…`).
- **KEY PATHS** — relevant `src/…`, `supabase/functions/…`, test paths.
- **DECISIONS/CONSTRAINTS** — key decisions already made and hard constraints (e.g. production
  safety, patterns to preserve).

Prefer pointing at artifacts to re-read over pasting large content inline — the fresh window can
open them itself and stay lean.

### 3. Write the handoff file

```bash
mkdir -p "$(git rev-parse --show-toplevel)/tmp/bmad-md"
date +%Y%m%d-%H%M%S   # use this as <timestamp>
```

Create `tmp/bmad-md/handoff-<slug>-<timestamp>.md` (`<slug>` = short kebab-case of the focus, e.g.
`epic2-task2.2-tests`). Inside it, put **one fenced block** the user copies wholesale, in the shape
shown at the top of this file. Keep it self-sufficient: the first line resumes the agent, the rest
lets it rehydrate by re-reading the cited artifacts.

### 4. Report

Print the generated file path and the resume target (which `/bmad-…` command the new window will run).
