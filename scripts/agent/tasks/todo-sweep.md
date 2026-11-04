---
engine: agy
mode: report
---
Produce a read-only inventory of unfinished-work markers in `api/src`, `pwa/src` and `docs/`.

1. Find every `TODO`, `FIXME`, `HACK` and `XXX` comment (use `grep -rnE`).
2. Group them by area (bounded context for `api/src`, route or module for `pwa/src`, doc name for `docs/`) and give a count per group.
3. For the 10 oldest, run `git blame` on the line and report its age and author date, and say in one sentence whether the marker still describes real work or looks stale.
4. Name any marker that references a person's data, authentication, authorization or audit, since those are the ones worth a human's attention first.

Give the result as your final answer: Markdown with a short summary at the top. You cannot write files in this task.
