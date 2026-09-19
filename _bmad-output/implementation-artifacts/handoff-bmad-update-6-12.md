# Handoff — BMAD update to 6.12.0 (PR #945)

> Written at a session close on 2026-09-19 and refreshed the same day, after PR #945
> merged as `3d3ef122` and CI ran. Its branch and worktree are gone; read it against
> `main`. The three items that blocked it are **closed** — what survives below is
> decisions nobody has dated, not work in flight.

## Done and pushed

- The install is at **6.12.0** (`_bmad/_config/manifest.yaml`). core + bmm 6.10.0 → 6.12.0,
  tea v1.19.0 → v1.27.1, cis v0.2.1 → v0.3.2, automator main @f332173 → @0b94fd7.
  bmb stayed **pinned** at v1.8.1 and wds was already v0.4.3.
- Commit `ed7c7731` carries the two tracked IDE skill trees (`.agent/skills`, `.qwen/skills`,
  1792 files, written as mirrors of each other). PR **#945** merged as `3d3ef122`.
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

## Closed by CI on the merge commit

The three blocking items were "never run locally", and CI answered all three on `3d3ef122`.
Both gates this session could not execute are **green**, so the doubt they recorded is spent:

- **`php.lint.project-context`** — green, inside `API Tests (PHPUnit + Lint)`. It is a member of
  `php.quality.dry-run`, which is the sweep CI runs; the local run was impossible only because
  the worktree had no php container. The measurement that predicted this still holds:
  `docs/project-context.md` claims no BMAD version (one incidental `bmad-agent-dev` mention at
  line 212 is not a version claim), so the update could not stale it.
- **`shell.lint`** — green, as the `Shell (ShellCheck)` job. `shellcheck` is not installed on
  the machine that did the update, which is why it went unrun; its subject had not moved, the
  two tracked files with a sh/bash shebang under these trees being byte-identical afterwards.
- **CI overall** — API Behat, API Build (Docker), API Security (Semgrep), CodeQL (both), PWA
  (Node) and both PWA E2E shards all green; PWA Burn-In skipped. That run covers **four**
  merges (#943/#947, #944, #946, #945), so a red there would not have been attributable to this
  change without checking — this one touches no PHP and no PWA source.

**`document_output_language` is decided: it stays `Spanish`.** 6.11 made it enforced on file
writes rather than advisory, and the question was whether that collides with the repo writing
its docs in English. It does not, deliberately: `_bmad-output/` is the working layer and
several of its artifacts are already in Spanish, while what lands under `docs/` is English.
The value is pinned in `_bmad/custom/config.toml`.

## Still open — decisions nobody has dated

These are not unfinished work; each is a choice someone should make on purpose.

1. **`bmb` is pinned at v1.8.1 while v2.2.2 is the stable release** (two majors back). The pin
   was respected deliberately during the update, and nobody in that session knew what motivated
   it. Not a defect — a decision with no date on it.
2. **wds is deprecated upstream in 6.12**, hidden from the module picker for new installs and
   firing deprecation warnings on every flow. It still installs here at v0.4.3.
3. **The four IDE skill roots do not agree with each other**, and this accreted rather than
   being chosen: the installer writes 92 skills to each of `.claude/skills`, `.agents/skills`,
   `.agent/skills` and `.qwen/skills`, but `.gitignore:94-96` ignores `/.claude/skills/bmad-*/`
   and `/.agents/skills/bmad-*/` (**with an s**) while `.agent/skills` (**no s**) and
   `.qwen/skills` are tracked in full — which is why #945 was 1792 files of two identical
   mirrors. `make/worktree.mk:176` seeds new worktrees from `.agent/skills` specifically.
   Whether `.qwen` should be tracked at all is the open question.
4. **The `_bmad/custom/` guardrail is per-machine.** `/_bmad/` is gitignored, so the pinned
   `project_name`, languages and `user_name` protect one checkout. A fresh clone or another
   developer gets the installer's defaults, and the next `quick-update` there resets them the
   same way it did here. If that matters, the fix has to live somewhere tracked.

## Not owed, stated so it does not read as an omission

The three review layers were **not** run. This is a `chore/` touching no `api/` or `pwa/`
source, no route, handler, query, DTO, header or migration — vendored agent-skill content plus
a version manifest — so it is neither a story nor a change to security, GDPR or audit surface,
and the per-PR obligation in `CLAUDE.md` does not attach. If a reviewer disagrees, the branch
is the place to run them.
