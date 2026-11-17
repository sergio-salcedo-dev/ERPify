# ERPify — agent guide

This repo's authoritative instructions for AI coding agents live in [`CLAUDE.md`](CLAUDE.md).
It is tool-agnostic guidance despite the filename — read it first.

## Hard rules for interactive sessions

The rules an agent breaks silently when it never opens `CLAUDE.md`. Each names the section that owns it, and `CLAUDE.md` wins on any conflict. Unattended runs (`make agent.poll`) follow [`scripts/agent/preamble.md`](scripts/agent/preamble.md) instead.

- **Worktree first.** Every feature or multi-edit task starts in a linked worktree, never in the primary `main` checkout: `make worktree.create BRANCH=<type>/<scope>-<slug> BASE=origin/main`, then run `make` from inside it. A worktree isolates files, not the branch — never point two sessions (Claude Code, Codex, a human) at the same branch. (*Worktrees*)
- **Claude Code's session-start checks do not run for you.** Three `SessionStart` hooks guard the repo and only Claude Code fires them, so run them by hand before editing: `scripts/worktree-collision-check.sh` (flags that you are in the primary checkout, that the tree was already dirty, or that the branch has a commit minutes old; `--strict` exits non-zero), `make bmad.status.audit` and `make bmad.skills.sync.dry-run`. (*Worktrees*)
- **`main` is protected.** No force-push, ever. No merge — local or PR — without explicit permission for that one PR. (*Protected `main`*)
- **Checks are part of done.** PHP edit → `make php.stan` on every changed file, `make php.quality` at the end, `make php.prepush` before pushing. PWA edit → `make pwa.quality`. Shell edit → `make shell.lint`. Read the exit code of a fresh run. (*Required checks*)
- **Finishing means committed and pushed.** Push the feature branch once its checks are green and open the PR (never `--draft`). A review finding is a commit on the open PR, not a new PR. (*Finishing substantial work…*, *Pull requests*)
- **Slash commands are procedures you can read.** `/feature`, `/deps-update`, `/merge-when-green`, `/local-generate-handoff`, `/local-resume-handoff` and `/local-generate-md` live in `.claude/commands/*.md`; Codex has no slash commands, so run one only when the user names it, by reading its file and following it. Merging stays the user's call per PR: only an explicit request naming that PR authorises it.
- **Commits** are Conventional Commits with a lower-case, imperative subject; a commit that ships a story names its tag in the subject. (*Commit messages*)
- **Never hand-edit generated or merged artefacts:** `api/config/reference.php` (`make sf.config.reference`), `api/.route-manifest.json` (`make sf.routes.manifest`), a migration already on `main` (add a new one with `make db.diff`), `vendor/`, `node_modules/`. (*Do not touch*)
- **Architecture.** Dependencies point inward; no framework imports in `Domain/`; no import across bounded contexts (reference ids, react to events). The persistence strategy of a new aggregate and aggregate boundaries are the user's call — lay out the options, don't pick. (*Architecture*)
- **Security.** HTTP errors go through the RFC 9457 pipeline, never a hand-built `JsonResponse`. Walk the security checklist for every diffed file, and say in the PR body which attack classes apply or why none does — a silent skip is the failure. State there too which checks you ran and which you could not. (*Security review on every change*)
- **Review debt.** Every story, and any change touching security, GDPR or audit surface even outside a story, owes three independent read-only review layers (Blind Hunter, Edge Case Hunter, Acceptance Auditor) before it is done, and every `patch` finding is applied. State in the PR body where each layer ran; a layer you could not run is stated as not run, never skipped silently. (*Code review*)
- **Comments** explain the non-obvious why of the current code: no "previously…", no story or ticket IDs. (*Code comments*)
- **Scratch files** go in `tmp/` and are deleted when the task ends — never loose in the tree or in `/tmp`. (*Temporary / scratch files*)

## Where things live

- **Monorepo baseline:** [`CLAUDE.md`](CLAUDE.md)
- **Symfony API (`api/`):** [`api/CLAUDE.md`](api/CLAUDE.md)
- **Next.js PWA (`pwa/`):** [`pwa/CLAUDE.md`](pwa/CLAUDE.md)
- **Coding rules:** `docs/rules/*.md`
- **Stack versions & project facts:** [`docs/project-context.md`](docs/project-context.md)
- **Command catalog & layout:** [`docs/claude-code-quickref.md`](docs/claude-code-quickref.md)
- **Triaging a Sentry issue:** [`docs/runbooks/sentry-issue-triage.md`](docs/runbooks/sentry-issue-triage.md)

Always start the stack from the repo root with `make app.dev` (or `make docker.up`).
