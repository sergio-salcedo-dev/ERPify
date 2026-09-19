# Session handoff — 2026-09-19

The session ended here because the machine was being shut down. Nothing is half-done: no
uncommitted work, no open branch of mine, no running subagent. What follows is the measured state
of the project and where to pick the thread up.

**How this was produced:** by asking git, `gh`, the sprint boards and `make bmad.status.audit`,
not by recalling. Re-measure before acting — this file is a snapshot and the registries below drift
on their own.

---

## Shipped in this session

| PR | What |
|---|---|
| **#928** (`ed582c04`) | Retired the back-office **Journey** view of the audit trail, and corrected the six documents the removal made false. |
| **#942** (`4cb8133c`) | Regenerated `api/config/reference.php` **and** gated it: `make php.lint.config-reference` / `make sf.config.reference`. |

Both merged with explicit per-merge permission, both with the three review layers applied and every
new guard falsified. A third PR, **#929** (`4a692aab`, another session), fixed the test-clock time
bomb this session diagnosed at its start.

## Two findings worth not re-deriving

- **A grep over `api/config/reference.php` will lie to you.** The file has **two** `lock_factory`
  lines, and an extraction matching the wrong one tells the story backwards — it did, twice, once in
  a review layer and once by hand. The arbiter is `git diff <revA> <revB> -- <file>`. The measured
  truth: a dependency **bump** is what gets this file right; a branch whose `vendor/` is **older**
  than `main`'s rewrites it backwards on any debug boot, and the regression rides along in an
  unrelated PR. That is what #942's gate now catches.
- **A residual worktree directory and a live one look identical to `git -C <dir> log`.** With no
  `.git` file git walks up to the parent repo and answers from `main`, perfectly happily. Discriminate
  with `[ -e <dir>/.git ]` plus `git worktree list --porcelain` before deleting anything.

---

## Pending, in four unlike piles

### 1 · Eleven dependabot PRs awaiting a batch — the most actionable

`#931`–`#941`: next 16.3.4, symfony framework-bundle / config / messenger 8.1.x, `@base-ui/react`
1.8.0, `phpstan/phpdoc-parser`, the `phpstan-rector` group, a node digest.

Use `/deps-update` (`--dry-run` to inventory first). Two things to expect: **#935 is
`vitest 4.1.10 → 5.0.0`**, a major; and a batch touching fixers tends to activate rules they already
shipped, turning a dependency-only PR into a source-touching one that forfeits the checklist
exemption. New since this session: the `php.lint.config-reference` gate will now catch the
`reference.php` these bumps move, instead of letting it drift.

### 2 · Sixteen open issues, not homogeneous

Five are **watches** (`audit(...)` — accepted risk with a witness, not work): #860, #864, #870, #881,
#718.

Actual work, by weight:

- **#602** — the admin lockout recovery graph has two edges and an attacker can cut both. Highest
  severity open.
- **#462** — organization member lifecycle (J5): remove/demote + membership↔user integrity.
- **#268** — Documents context epic (evidence pipeline).
- **#925, #872, #874, #879, #418** — gate/invariant gaps, roughly one PR each.
- **#305, #373, #256** — technical debt, and a backup/restore drill never run.

### 3 · `deferred-work.md` — 49 entries

A live registry of *waits*, not a queue: nearly every entry declares its own trigger ("the first
entity that…", "the first `command:` in `compose.dev.yaml`"). **It is worth a sweep for triggers that
have already fired** — the last one was #907, two weeks and many PRs ago. Resolve a bullet by
DELETING it, never by annotating it done.

### 4 · `PRODUCTION_SECURITY_CHECKLIST.md` §7 — 44 entries

*"Known weaknesses — open, must be closed or consciously accepted before the first customer."* This
is the gate to production, not a wish list. If there is a first-customer date, this is the project's
real critical path.

---

## Not on any list

**Strix no longer reviews any pull request** — the workspace trial ended (`strix:pr-review-billing-required`,
seen on #928 and #942). Its silence under a PR now means *it did not run*, not that the PR passed. Parts
of `CLAUDE.md`'s review doctrine lean on those threads; that net is currently absent, and with eleven
dependabot PRs queued the gap is felt precisely now. Restoring it is an account action, not a repo change.

## Suggested order

1. The dependabot batch — cheap, unblocking, and now protected by the new gate.
2. **#602**, on severity.
3. In parallel, the `deferred-work.md` trigger sweep, to learn how many of the 49 are no longer
   future but present.

## State at handoff

- `origin/main` = `4a692aab` (#929). Its CI run was still `in_progress` when the session ended —
  **check it before building on top**.
- Primary checkout clean and level with `main`. No worktrees registered, `.claude/worktrees/` empty.
- Both sprint boards entirely `done` (95 + 5); `make bmad.status.audit` clean against `origin/main`.
