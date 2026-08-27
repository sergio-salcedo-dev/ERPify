---
title: Deferred-work batch — GDPR erasure exit code, session-double fidelity, clock isolation, docs drift
status: in-review
---

# Deferred-work batch (2026-08-27)

Resolves a set of items from `deferred-work.md` in one branch. Scope was chosen for
items that are self-contained and need no architectural decision; the registry is
pending-only, so every resolved item loses its bullet rather than gaining a note.

## What landed

1. **`identity:gdpr:erase-subject` no longer reports success for an erasure it never
   performed.** Without `--force`, a confirmation the run could not put (`--no-interaction`,
   a closed stdin, a pipe, `--quiet`/`--silent`) returned `SymfonyStyle::confirm()`'s `false`
   default and the command exited `0` — indistinguishable, to a compliance job reading `$?`,
   from a completed erasure. Three outcomes now carry distinct codes; the two unanswerable
   paths are separate mechanisms and both are guarded.
2. **`InMemorySessionRepository`'s bulk revocations mirror the adapter's directed UPDATE**
   instead of recording the call and mutating nothing. Closes two registry bullets written
   five weeks apart about one defect.
3. **`ResetSystemClockExtension` resets the ambient clock before each test**, not only after.
4. **Documentation drift**: the audit ADR's `metadata.operation` key, the dead
   `api/phpunit.xml.dist` path, and `docs/architecture-pwa.md`'s PascalCase module names.
5. **Registry sweep**: 20 bullets removed from 117 — 8 resolved by this branch, 12 already
   closed in the tree with nobody having removed the bullet. Four rotted references repaired,
   one false premise restated.

## Verification, and its limits

Stated plainly because the gap is large. This container has **no docker**, and `api/vendor`
**cannot be installed**: the egress policy answers **403** for `api.github.com/.../zipball`
and `codeload.github.com`, which is where Composer fetches every package payload. Five
install strategies were tried and all fail there. Consequently:

- **Not run, no result claimed**: PHPStan, PHPUnit, Behat, deptrac, PHPMD, php-cs-fixer,
  and every `make php.*` target. **The new tests in this branch are unexecuted.**
- **Run, with exit codes**: `php -l` on every changed PHP file (0 — but under PHP 8.4 where
  the project targets 8.5); a 120-column check over the diff (0 findings); a scan of added
  lines for change-relative comments and story/ticket ids (0 hits); `npx tsc --noEmit` in
  `pwa/` (0); `npx vitest run` (247 files, 1548 tests, 0).
- Premises that could not be read from an installed vendor tree were verified by cloning the
  dependency's source directly — `git` to github.com is permitted even though the zipball
  endpoints are not. `symfony/console` v8.1.1 and `phpunit` 13.3.1 were read this way.

## Adversarial pass

A hostile read was performed in a fresh context by a reviewer other than the authors, over
the committed diff, before this branch's pull request was opened. It cloned the real
`symfony/console` v8.1.1 and `phpunit` 13.3.1 sources rather than reasoning from memory, and
built the full invocation matrix for the erasure command.

**Verdict: no GRAVE finding.** No combination of (flags x stdin state x identity state)
erases-and-reports-non-zero or fails-to-erase-and-reports-zero, other than the three that are
deliberate. The `QuestionHelper` mechanism the fix relies on was confirmed at source:
`ask()` catches `MissingInputException`, calls `setInteractive(false)`, and rethrows only
when the default is `null` — which it is not for a `ConfirmationQuestion`. `SymfonyStyle::confirm()`
routes through that same helper on the same input object.

### Findings acted on in this branch

- **A-1 (moderate) — the exit-code contract was documented nowhere an operator reads.** The
  whole justification for the change is what a job learns from `$?`, and that contract sat
  only in a `private` method's docblock; `setHelp()` did not even show `--force`, the one flag
  the refusal tells you to pass. The sibling reconciler states its codes in its class docblock
  *and* in `docs/architecture-api.md`; the more consequential command had not been brought into
  that convention. Fixed in all three places.
- **A-2 (minor) — `--quiet`/`--silent` imply `--no-interaction` and also suppress the refusal's
  own message**, so those invocations exit 2 in complete silence. An improvement on the prior
  silent 0, but the refusal's parenthetical was incomplete. Documented.
- **A-3 (minor) — a third copy of a corrected sentence.** The fix updated two statements of
  "a non-interactive run declines silently" and missed the one in
  `ReconcileErasedSubjectReferencesCommandTest`. Fixed.
- **A-4 (nit) — the cross-reference overreached.** `INVALID` was justified as "nothing about the
  system is broken", but the cited command also answers `INVALID` for a failed probe, which is a
  fault. The shared reading is "no verdict was reached"; corrected to say that.
- **B-1 (moderate) — the session double's docblock argued away a real cost.** It rejected the
  side-set alternative partly because "any test holding the preset object would read `ACTIVE`",
  presenting that as a defect — but Doctrine does not refresh its identity map from a bulk
  UPDATE, so reading `ACTIVE` off a held aggregate is exactly what production does. The double is
  therefore *more* observable than the adapter on that axis, and three of the six new cases assert
  on held entities under a docblock inviting the pattern to be copied. The mutation is still the
  right implementation; the argument was wrong. Both docblocks now state the cost and tell a
  use-case test to assert through the port's reads.
- **C-1 (nit) — the clock claim was over-broad.** PHPUnit forces `wasPrepared` before reporting a
  failed *assertion* in `setUp()`, so that branch does emit `Finished`; a skip and an unexpected
  throwable do not. Corrected.
- **D-1 (nit) — the ADR's key count is path-dependent.** `GDPR_ERASURE_EXECUTED` has two writers,
  with eight keys and two. The sentence now names the path.

### Findings recorded rather than fixed

Five went to `deferred-work.md` under this pass's own heading, the first two deliberately:

- **P-1 — the same silent-zero defect is live in both sibling GDPR commands**
  (`bank-account:gdpr:erase-subject`, which destroys a DEK, and `audit:gdpr:erase`). The
  registry bullet this branch removed named only the identity command, so closing it would
  otherwise have deleted the only written record of the class. Recording it is the minimum;
  whether to fix both here is a scope decision for the repository owner, not one to take
  unilaterally on a GDPR path.
- **P-2 — `audit:gdpr:erase` has a genuine erases-but-reports-non-zero path.** Deliberate and
  commented, but a live instance of the hazard this branch's own docblock warns about.
- **P-3** an API-first fourth `operation` value hard-fails the PWA detail read; **B-2** the
  spared session id is compared with `===` where Postgres compares as `uuid`; **A-5** a stream
  already at `feof()` defeats both new guards, with no reachable instance today.

### What this pass could not do

Nothing was executed — no `vendor/`, no docker — so every behavioural conclusion above is
derived from reading source, including the invocation matrix. A reviewer with a working stack
should run the unexecuted tests before merging.
