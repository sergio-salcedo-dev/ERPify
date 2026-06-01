# Gate phpcs in CI — Design Spec

**Date:** 2026-06-01
**Scope:** `api/` only (PHP lint sweep + a handful of `src/` lines, mostly tests)
**Branch:** `ci/api-gate-phpcs` (base `main`)
**Status:** Approved design, pending implementation plan
**Issue:** #95 (phpcs portion) — split out of #94

## Context

PR #94 (merged into `main`, HEAD `63be094`) introduced `php.quality.dry-run`:
the read-only, parallel-safe subset of `make php.quality` that CI runs as the
"PHP lint (check, parallel)" step (`.github/workflows/ci.yml:111`). #94
**deliberately excluded psalm and phpcs** because both were masked by apply-mode
fixers (`psalm --alter`, `phpcbf` with `[ $? -le 2 ]`) and hid a pre-existing
backlog — adding them as-is would make CI permanently red. Gating them is
tracked in issue #95.

This spec covers **only the phpcs half**. The psalm baseline is deferred to a
**separate follow-up issue** (see "Issue bookkeeping"); #95 is closed when this
PR merges.

### What the phpcs backlog actually is

Running the real gate (`make php.cs.dry-run`, i.e. plain
`phpcs --standard=tools/phpcs/phpcs.xml src tests`) on `origin/main` reports:

```
Generic Files  Line length too long      161   (WARNING, lineLimit=120)
Generic Files  Line length max exceeded   21   (ERROR,   absoluteLineLimit=160)
A TOTAL OF 182 SNIFF VIOLATIONS WERE FOUND IN 29 FILES
```

Two facts that reshape the issue's "fix the handful of >160 errors" framing:

1. **Every violation is pure `Generic.Files.LineLength`** — no other PSR-12
   issues exist. phpcbf/cs-fixer **cannot auto-fix line length**; wrapping is
   manual.
2. **Plain `phpcs` exits non-zero on warnings too**, not just errors. So the
   161 `>120` warnings would fail the gate exactly like the 21 `>160` errors.
   The "handful" is really **182 lines**.

The 21 hard errors live in 7 files; **only 2 are production code**
(`src/Shared/Application/Problem/ProblemDetailsFactory.php:523,604`). The rest
are tests, concentrated in the Problem / error-contract cluster
(`tests/Unit/Shared/Application/Problem/ProblemDetailsFactoryTest.php` alone has
13 errors + 73 warnings ≈ 86 lines).

## Decision

**Approach B — enforce 120 fully.** Wrap **all 182** over-length lines to
≤120 characters, leave `tools/phpcs/phpcs.xml` strict (no warning suppression),
and add `php.cs.dry-run` to the `php.quality.dry-run` gate. End state: phpcs
reports **0 errors / 0 warnings** and any future line over 120 fails CI.

Rejected alternatives:

- **A (advisory warnings):** fix only the 21 errors + `ignore_warnings_on_exit=1`.
  Smaller diff but leaves a 161-line backlog and a softer 120 signal. Not chosen
  — user wants the clean end state.
- **C (hide warnings):** `--warning-severity=0`. Discards the repo's intentional
  120-char standard. Not chosen.

## Goals

- `make php.cs.dry-run` is green (0/0) on the branch.
- `php.cs.dry-run` is a prerequisite of `php.quality.dry-run`, so CI gates phpcs.
- A new line over 120 chars fails CI (no longer silently tolerated by phpcbf).
- No behavior change: every wrapped line is semantically identical, especially
  expected JSON / problem-detail string literals in tests.

## Non-Goals (YAGNI)

- **No psalm work** — deferred to a follow-up issue.
- **No phpcs.xml rule changes** — `lineLimit=120` / `absoluteLineLimit=160`
  stay as-is; we conform the code to them, not the rules to the code.
- **No third-party phpcs baseline tool** — PHP_CodeSniffer has no native
  baseline and Approach B makes one unnecessary.
- **No refactoring** beyond line wrapping. We don't restructure logic.

## Plan of work

### 1. Line-length cleanup (the bulk)

Wrap all 182 lines across 29 files to ≤120. Wrapping idioms by construct:

- **Method/fluent chains** → break before `->`, one call per line.
- **Array literals** → one element per line, trailing comma.
- **Function/method calls** → one argument per line.
- **Long string literals** → `.`-concatenation across lines, or heredoc/nowdoc
  for multi-line content. The concatenated value must be **byte-identical** to
  the original (no added/removed whitespace inside the string).
- **Long comments / docblocks** (`ignoreComments=false`, so these count) →
  reflow to ≤120.

### 2. Tool convergence (critical)

cs-fixer also runs in the sweep and **will reformat** wrapped constructs
(indentation, trailing commas, operator placement). Per file, the loop is:

```
wrap → make php.cs-fixer (apply) → make php.cs.dry-run  ⇒ must be 0/0
```

Iterate if cs-fixer reflows a construct back over 120. Run cs-fixer/phpcs inside
the real dev container via `make` (per memory `php-lint-env-parity` — cs-fixer's
`native_function_invocation` depends on loaded extensions; never a bare image).

### 3. Gate wiring

- Add `php.cs.dry-run` to the `php.quality.dry-run` prerequisite list in
  `make/php-quality.mk:136`. (`.PHONY` already lists `php.cs.dry-run`.)
- Update the explanatory comment block (`make/php-quality.mk:122-135`) and the
  CI step comment (`.github/workflows/ci.yml:106-109`): phpcs is **now gated**;
  **psalm remains excluded**, pointing at the new follow-up issue instead of #95.

### 4. Verification

- `make php.stan` on the one changed `src/` file (`ProblemDetailsFactory.php`).
- Run the affected test files to prove no behavior change — the Problem /
  error-contract unit + functional tests and the Behat contexts touched
  (e.g. `make php.unit c='--filter ProblemDetailsFactoryTest'`, etc.).
- Final full sweep: `make -j2 --output-sync=target php.quality.dry-run` green.

### 5. Parallelization

The 29 files share no state, so wrapping fans out cleanly to subagents grouped
by file/cluster (CLAUDE.md "Parallelizing work with subagents"). Convergence
(cs-fixer + phpcs + tests) happens **centrally** after the fan-in, not inside
the subagents, to avoid racing on the cs-fixer cache and shared verify runs.

### 6. Docs & memory

- Update memory `php-lint-gating-state`: phpcs is gated; psalm still pending
  (new issue number).
- Refresh lint-target wording in `CLAUDE.md` / `docs/claude-code-quickref.md`
  only if the current text describing the gate is now stale.
- No `docs/api-error-contract.md` change — line wrapping doesn't touch the
  contract (markers, status map, redaction, log-line shape all unchanged).

### 7. Issue bookkeeping

- **Open a new follow-up issue** for the psalm baseline, capturing the findings
  uncovered here so it is actionable:
  - `php.psalm.baseline` (`make/php-quality.mk:77`) writes
    `--set-baseline=api/tools/psalm/psalm-baseline.xml`, but the container cwd is
    `/app/api`, so it resolves to `/app/api/api/tools/...` (doubled `api/`).
  - `tools/psalm/psalm.xml` has **no `errorBaseline="…"`** attribute, so even a
    generated baseline is ignored by a plain `psalm` run.
  - `findUnusedBaselineEntry="true"` means a fixed-but-still-listed entry will
    itself error — the follow-up must define the regenerate-on-fix workflow.
  - Backlog is ~495 issues at `errorLevel=3` with `findUnusedCode=true` and
    `MissingOverrideAttribute`/`ClassMustBeFinal` forced to `error`.
- **Close #95** when this PR merges (PR description: "closes #95 (phpcs half);
  psalm tracked in #<new>").

### 8. Commit & PR

- **Single `ci(api)` commit** containing the line wrapping + gate wiring + doc
  updates.
- Branch `ci/api-gate-phpcs` → PR into `main`.
- Self-review against the CLAUDE.md security checklist (line wrapping is
  behavior-neutral, but confirm no string literal/secret/assertion changed
  meaning).

## Success criteria

1. `make php.cs.dry-run` → 0 errors / 0 warnings on the branch.
2. `php.cs.dry-run` is in `php.quality.dry-run`; `make -j2 php.quality.dry-run`
   green.
3. All touched test suites pass unchanged.
4. A deliberately-added 121-char line fails the gate (spot-check the mechanism).
5. #95 closed; psalm follow-up issue open with the findings above.
