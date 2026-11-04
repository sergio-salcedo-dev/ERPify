---
engine: agy
mode: report
---
Produce a read-only inventory of unfinished-work markers in `api/src`, `pwa/src` and `docs/`.

1. Find every `TODO`, `FIXME`, `HACK` and `XXX` comment.
2. Group them by area (bounded context for `api/src`, route or module for `pwa/src`, doc name for `docs/`) and give a count per group.
3. For the 10 that look most important, quote the marker with its `file:line`, and say in one sentence whether it still describes real work or reads as stale, judging from the surrounding code.
4. Name any marker that concerns a person's data, authentication, authorization or audit, since those are the ones worth a human's attention first.

Give the result as your final answer: Markdown with a short summary at the top. You cannot write files or run commands in this task.
