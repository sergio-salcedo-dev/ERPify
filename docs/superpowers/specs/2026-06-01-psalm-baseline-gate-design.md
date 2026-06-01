# Gate psalm in CI — Design Spec

**Date:** 2026-06-01
**Scope:** `api/` only (psalm baseline + CI lint sweep wiring)
**Branch:** `ci/api-gate-phpcs` (base `main`)
**Status:** Implemented
**Issue:** #97 (psalm half) — follow-up split out of #95

## Context

PR #94 introduced `php.quality.dry-run`: the read-only, parallel-safe subset of
`make php.quality` that CI runs as the "PHP lint (check, parallel)" step
(`.github/workflows/ci.yml`). It **excluded psalm and phpcs** because both were
masked by apply-mode fixers (`psalm --alter`, `phpcbf` with `[ $? -le 2 ]`) and
hid a pre-existing backlog. The phpcs half landed first (see the sibling spec
`2026-06-01-phpcs-line-length-gate-design.md`, closed #95); this spec covers the
**psalm half** (#97).

In apply-mode, `php.quality` runs `psalm --alter` (`php.psalm.fix.all`), so in
the ephemeral CI container it auto-fixes-and-discards and exits 0 — gating
nothing. A plain `psalm` run surfaces **492 errors** at `errorLevel=3` with
`findUnusedCode=true` (`MissingOverrideAttribute`, `ClassMustBeFinal`,
`PossiblyUnusedMethod`, `LessSpecificReturnStatement`, …). Adding `php.psalm` to
the gate as-is would make CI permanently red against that backlog.

## Decision

**Freeze the backlog with a psalm `errorBaseline`**, then gate. Generate
`api/tools/psalm/psalm-baseline.xml` (492 issues), wire it into `psalm.xml`, and
add `php.psalm` to `php.quality.dry-run`. End state: plain `psalm` reports **0
errors** today and FAILS on any **new** regression.

Rejected alternative — **burn the backlog down first** (the `--alter` cleanup
auto-fixes ~87). Not chosen now: it tangles a 400-line refactor into a CI-wiring
PR and risks the phpstan/psalm type tug-of-war noted in `make/php-quality.mk`.
The baseline is the smaller, reversible first step; burn-down is a follow-up the
`findUnusedBaselineEntry` workflow actively encourages (see below).

## Findings (corrections to #97's hypotheses)

1. **Baseline path bug — wrong mechanism in #97.** #97 guessed the
   `--set-baseline=api/tools/psalm/psalm-baseline.xml` value doubled `api/`
   against cwd `/app/api`. The real cause: `psalm.xml` sets
   `resolveFromConfigFile="true"`, so psalm resolves `--set-baseline` relative to
   the **config-file dir** (`tools/psalm/`), not cwd. The correct value is the
   bare filename `psalm-baseline.xml` (`make/php-quality.mk`).
2. **`errorBaseline` wiring is automatic.** Running `--set-baseline` AUTO-ADDS
   `errorBaseline="psalm-baseline.xml"` to `psalm.xml` — no manual edit. (#97
   item 2 expected a manual edit.)
3. **`findUnusedBaselineEntry="true"` is kept ON, by design.** When a baselined
   issue is later fixed, the now-unused entry errors → the gate goes red until
   the baseline is regenerated. This is the chosen workflow (#97 item 3): it
   forces the baseline to only ever **shrink**, never rot. Regenerate-on-fix:
   `make php.psalm.baseline` then commit the smaller baseline.

## Plan of work (as implemented)

1. **Fix `php.psalm.baseline`** — `--set-baseline=psalm-baseline.xml` (config-dir
   relative), with a comment explaining the resolution.
2. **Generate the baseline** — `make php.psalm.baseline` writes
   `api/tools/psalm/psalm-baseline.xml` (492 entries) and auto-wires
   `errorBaseline`. The file is created root-owned in-container → run
   `make php.fix.ownership` before committing.
3. **Gate wiring** — add `php.psalm` to the `php.quality.dry-run` prerequisite
   list (grouped next to `php.stan`, the other static analyzer / heavyweight);
   update the explanatory comment block (psalm now gated; regenerate-on-fix).
4. **CI `-j` bump** — with phpstan **and** psalm now in the sweep (two CPU-heavy
   targets), raise the lint step `make -j2 → -j4` so the light / I/O-bound
   targets fill the gaps while the analyzers run. GitHub runners have ~7GB RAM,
   so 4 PHP processes fit; revisit from step timing if a run is purely CPU-bound.

## Verification (done)

- `make php.psalm` → **No errors found!**, exit 0 (backlog absorbed by baseline).
- `make -j4 --output-sync=target php.quality.dry-run` → green, no races; all 9
  prerequisites ran (phpstan `[OK] No errors`, psalm, error-contract PHPUnit
  `Tests: 5`, rector/cs-fixer dry-run, doctrine, gherkin, phpcs, md).
- **Regression proof** — a throwaway `src/PsalmRegressionProbe.php` returning a
  string from an `int` method made `make php.psalm` fail with 3 fresh errors
  (`UnusedClass`, `InvalidReturnType`, `InvalidReturnStatement`); removing it
  returned the gate to green. The baseline does not mask new code.

## Non-Goals (YAGNI)

- **No backlog burn-down** — deferred; the baseline freezes it as-is.
- **No `psalm.xml` rule changes** beyond the auto-added `errorBaseline`
  (`errorLevel`, suppressions, forced-error handlers all unchanged).
- **No `-j` value beyond a documented bet** — `-j4` is an oversubscription
  heuristic, tunable from real CI timing; not measured locally.

## Success criteria

1. `make php.psalm` → 0 errors on the branch. ✓
2. `php.psalm` is in `php.quality.dry-run`; `make -j4 php.quality.dry-run` green. ✓
3. A deliberately-added psalm error fails the gate. ✓
4. #97 closed when this PR merges; baseline + config committed.
