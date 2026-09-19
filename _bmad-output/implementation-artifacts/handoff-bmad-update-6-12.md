# Handoff — BMAD update to 6.12.0 (PR #945)

> Session closed mid-task on 2026-09-19 at the user's request. Everything below is
> measured against this branch unless it says otherwise. Branch:
> `chore/bmad-update-6-12-udzh`. Worktree:
> `/home/dev/Projects/ERPify/.claude/worktrees/bmad-update-6-12-udzh`.

## Done and pushed

- The install is at **6.12.0** (`_bmad/_config/manifest.yaml`). core + bmm 6.10.0 → 6.12.0,
  tea v1.19.0 → v1.27.1, cis v0.2.1 → v0.3.2, automator main @f332173 → @0b94fd7.
  bmb stayed **pinned** at v1.8.1 and wds was already v0.4.3.
- Commit `ed7c7731` carries the two tracked IDE skill trees (`.agent/skills`, `.qwen/skills`,
  1792 files, written as mirrors of each other). PR **#945** is open against `main`.
- Ran with `--shims`, so the retired IDs still resolve. Only `bmad-index-docs` and
  `bmad-shard-doc` are gone with no replacement.
- `AGENTS.md` now names `docs/project-context.md`, and the falsified sentence in `CLAUDE.md`
  ("60 of the 90 installed skills declare it in `persistent_facts`") is rewritten against what
  is true now. See *the breaking change* below.

## The one thing that bit, so nobody re-derives it

`bmad install --action quick-update` **reports "preserved settings" and does not preserve
them.** It reset `user_name` to `Dev`, both language keys to `English`, and derived
`project_name` from the directory it ran in — here the worktree slug,
`bmad-update-6-12-udzh` — while **discarding the explicit `--user-name`,
`--communication-language` and `--document-output-language` flags it was handed**. The values
are restored and pinned in `_bmad/custom/config.toml` and `_bmad/custom/config.user.toml`, the
one layer the installer never rewrites, verified through `resolve_config.py` rather than by
reading the files back.

Two more, same shape:

- **`bmad status` under-reports.** It said `1 update(s) available` and marked `cis v0.2.1 ✓`
  over three shipped stable releases. It reads npm, where `bmad-creative-intelligence-suite`
  publishes 0.1.9 and `bmad-builder` 1.1.0 — both *below* what was installed — while the
  installer resolves externals from release tags. Read the tags, never the ✓.
- **A worktree's `_bmad` is a symlink to the primary's install**, so the writes landed in the
  primary. This branch isolated `.agent/skills` and nothing else; there is one install and it
  is 6.12 for every session on this machine.

## The breaking change that landed on this repo

6.12 ships `persistent_facts` empty. Measured: skills loading `docs/project-context.md` went
**62 → 15**, and no `persistent_facts` key survives the config merge. In 6.10 each
`customize.toml` carried `persistent_facts = ["file:{project-root}/**/project-context.md"]`.

Resolved by adopting the route 6.12 moves to — `AGENTS.md`, already tracked here and read by
**14** of the 92 skills. Restoring the auto-load was declined: the only place to write it is
`_bmad/custom/`, which is gitignored, so it would hold on one machine and reach nobody else.

## Pending — pick up here

1. **`make php.lint.project-context` was never run.** It is a PHPUnit-filter gate and needs the
   php container, which is not up in this worktree. Low risk but unverified: the page claims no
   BMAD version (measured by grep over `docs/project-context.md` and
   `api/.project-context-versions`, one incidental `bmad-agent-dev` mention at line 212, not a
   version claim), so the update should not have staled it. **Run it and confirm.**
2. **`make shell.lint` was never run locally** — `shellcheck` is not installed on this machine.
   Its subject did not move: the only two tracked files with a sh/bash shebang under these trees
   (`bmad-story-automator/scripts/story-automator`, in `.agent` and `.qwen`) are byte-identical
   after the update. The CI `shell-lint` job covers it. **Read CI rather than assume.**
3. **CI on #945 has not been read at all.** Nothing was checked after the push.
4. **A live question that was never asked.** `document_output_language` is `"Spanish"`, and
   6.11 made it **enforced on file writes** where it used to be advisory. This repo's rule is
   conversation in Spanish, docs/commits/PRs in English — so BMAD will now write its artifacts
   in Spanish by contract. Restoring the pre-existing value was the faithful move during a
   restore, but the setting itself deserves a decision. **Ask the user.**
5. **`bmb` is pinned at v1.8.1 while v2.2.2 is the stable release** (two majors back). The pin
   was respected deliberately — the user declined unpinning when asked, and nobody in this
   session knows what motivated the pin. Not a defect; a decision someone should date.
6. **wds is deprecated upstream in 6.12**, hidden from the module picker for new installs and
   firing deprecation warnings on every flow. It still installs here (v0.4.3, unchanged). No
   action taken.
7. **The four IDE skill roots do not agree with each other**, and this is a real inconsistency
   rather than an observation: the installer writes 92 skills to each of `.claude/skills`,
   `.agents/skills`, `.agent/skills` and `.qwen/skills`, but `.gitignore:94-96` ignores
   `/.claude/skills/bmad-*/` and `/.agents/skills/bmad-*/` (**with an s**) while `.agent/skills`
   (**no s**) and `.qwen/skills` are fully tracked — which is why this PR is 1792 files of two
   identical mirrors. `make/worktree.mk:176` seeds new worktrees from `.agent/skills`
   specifically. Whether `.qwen` should be tracked at all is worth deciding; nobody chose this,
   it accreted.
8. **The `_bmad/custom/` guardrail is per-machine.** `/_bmad/` is gitignored, so the pinned
   `project_name` / languages / `user_name` protect this checkout only. A fresh clone or another
   developer gets the installer's defaults, and the next `quick-update` there will reset them
   the same way. If that matters, the fix has to live somewhere tracked.

## Not owed, stated so it does not read as an omission

The three review layers were **not** run. This is a `chore/` touching no `api/` or `pwa/`
source, no route, handler, query, DTO, header or migration — vendored agent-skill content plus
a version manifest — so it is neither a story nor a change to security, GDPR or audit surface,
and the per-PR obligation in `CLAUDE.md` does not attach. If a reviewer disagrees, the branch
is the place to run them.
