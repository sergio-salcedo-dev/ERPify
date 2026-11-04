---
engine: claude
mode: pr
branch-type: docs
---
Find and fix drift between the documented `make` surface and the real one.

1. List every `make <target>` named in the fenced "Top-hits commands" block of `CLAUDE.md` and everywhere in `docs/claude-code-quickref.md`.
2. For each, confirm the target is defined in `make/*.mk` or the root `Makefile` (a line beginning `<target>:`). Targets with a `$(...)` or pattern rule count as defined when the pattern matches.
3. List every concrete Markdown link target in `docs/claude-code-quickref.md` and `docs/index.md` and confirm the file exists.

Fix only what is wrong in `docs/claude-code-quickref.md` and `docs/index.md`: correct a renamed target, drop a removed one, repair a dead link. `CLAUDE.md` is protected and you must not edit it: if it has drift, describe it in `tmp/agent-pr-body.md` under a "Needs a human" heading.

If everything agrees, change nothing and write no files.
