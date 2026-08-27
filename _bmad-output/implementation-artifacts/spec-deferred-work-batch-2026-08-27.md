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
5. **The same silent-zero defect in both sibling GDPR commands.** `bank-account:gdpr:erase-subject`
   (which destroys a DEK — an irreversible crypto-shred) and `audit:gdpr:erase` carried the
   identical shape. Fixed with the same guard pair, so `$?` now means one thing across all
   three commands rather than two.
6. **Registry sweep**: 20 bullets removed from 117 — 8 resolved by this branch, 12 already
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

- **P-2 — `audit:gdpr:erase` has a genuine erases-but-reports-non-zero path.** Deliberate and
  commented. Narrowed rather than closed: that command's class docblock now states that its
  `FAILURE` means "an erasure half-ran", so the contract is no longer ambiguous; what remains
  is the product decision of what a compliance job should do on reading it.
- **P-3** an API-first fourth `operation` value hard-fails the PWA detail read; **B-2** the
  spared session id is compared with `===` where Postgres compares as `uuid`; **A-5** a stream
  already at `feof()` defeats both new guards, with no reachable instance today.

## Second adversarial pass — the sibling commands

**P-1 was escalated into scope on the repository owner's instruction** rather than left recorded,
so `bank-account:gdpr:erase-subject` and `audit:gdpr:erase` now carry the same guard pair as the
identity command. That is new GDPR code the first pass never saw, so it received its own hostile
read in a fresh context before the pull request was opened, over the same axes: the full
invocation matrix per command, the post-`confirm()` re-read verified against real
`symfony/console` v8.1.1 source, whether each new test fails without the fix, and whether any new
output carries PII.

The three commands do **not** share a branch structure — the actor command calls `countFor()` and
returns early on zero matches before any confirmation — so the pass was aimed specifically at
whether a copied guard sits in the right place in each, which is where this kind of mirror goes
wrong. It measured **144 `CommandTester` combinations** and **20 real subprocess runs** through a
real `Application` with real stdin redirection, against a `symfony/console` install left in a
scratch directory. The exit-code/mutation matrix came back clean: no combination mutates and
reports non-zero, and none reports zero for a mutation that did not happen outside the four
intended no-ops.

### It found a GRAVE, and it was in this branch's own new prose

`EraseActorAuditTrailCommand::eraseAndReport()` ran the anonymising `UPDATE` **outside** its
`try`; only the compliance self-audit was inside it. So a failing `UPDATE` — a deadlock, a
connection drop, a statement timeout — escaped `execute()` entirely, and the console derives the
process exit code from `Throwable::getCode()`: **measured at 7 for a DBAL driver code and at 189
for `1213`**, never `1`. On top of that structural defect, the docblock this branch had just
added asserted that a non-zero there means "an erasure half-ran, never nothing happened". An
operator following it on a mid-failover Postgres would record a person as erased whose `actor_id`
is still in `audit_log` in clear — the same defect class the branch exists to close, one command
over, introduced by the documentation rather than by the code.

Fixed by giving the anonymisation its own `catch` returning `FAILURE` with a message stating that
nothing changed, and by rewriting the contract in both the docblock and the help: `FAILURE` now
means "the erasure did not complete, and the message says whether it started".

### The rest

- **Moderate — `ConsoleCommandRedactionProcessor` asserted something this command falsified.** Its
  docblock states that no `#[AsCommand]` taking person data can raise a `ConsoleErrorEvent`,
  because they all catch `Throwable` — the CRITICAL path that flushes the whole prod log buffer to
  stderr. This command did not, so the claim was false. Fixing the GRAVE closes the hole; the
  enumeration was also corrected, since it named three of eight, and the paragraph now says
  plainly that the membership is a list nothing checks — which is the shape that class's own
  "by structure, never by enumeration" argument refuses.
- **Moderate — the new bank tests could not detect a mutation.** `inertEraser()`'s stubs answer
  `findById(): null` and `destroyScope(): false`, so the use case runs to completion and reports
  "nothing to erase"; the comment claiming "an inert eraser would break if the erasure ran" was
  false, and the tests asserted only exit code and display text. Move the refusal after the
  erasure and they stay green while a real DEK is shredded. They now use a `refusingEraser()`
  whose `remove` and `destroyScope` are `expects($this->never())`.
- **Minor — three of the seven new tests pass with or without the fix.** The dry-run and
  zero-match cases short-circuit before the confirmation either way. They are legitimate ordering
  pins, and their docblocks now say so instead of implying they catch the original defect.
- **Minor — "a closed stdin" over-stated the reach**, and the re-read is load-bearing rather than
  belt-and-braces. Measured: a pipe carrying a **blank line** is an answer and accepts the default
  (exit 0), so `echo | bin/console …` still erases nothing and reports success. More importantly,
  `Application::configureIO()` in Console 8.1.1 contains **no `posix_isatty` check** — an
  unattended run that omits `--no-interaction` stays interactive, so the post-`confirm()` re-read
  is the only thing in front of it. Both facts are now in all three commands' comments, because
  the second is an assumption a Symfony upgrade could quietly change.
- **Minor — `docs/architecture-api.md` scoped the contract to one command** while three now share
  it. Generalised, including the way `audit:gdpr:erase` reads `FAILURE` differently.
- **Nit, recorded not fixed — the actor command's exit code is an existence oracle.** `countFor()`
  runs before the guards, so an unattended run answers `2` for an actor with rows and `0` for one
  without. No new exposure (`--dry-run` prints the count to the same caller) and no mutation can
  precede the refusal, but the exit code is a contract now, so it is written down.

### Duplication, argued rather than assumed

The guard pair now exists in all three commands that call `SymfonyStyle::confirm()`, which is
exactly the Rule of Three. It was **not** extracted into `Shared/`, and the pass's own reasoning
is why the answer is not a helper at all: what varies between the three is not the guard body but
its **placement** — the actor command interposes a `countFor()` and a zero-match branch before it
— so a shared helper would centralise the half that is already correct in all three while leaving
the ordering untouched at each call site. The ordering is where this pass's GRAVE and one of its
MODERATEs both lived. A gate in `api/tests/Unit/Gate/` asserting that every `#[AsCommand]`
reaching `confirm()` re-reads `isInteractive()` afterwards costs nothing at the call sites and
catches the fourth erasure command, which will be written by copying whichever of the three its
author finds first. Recorded in `deferred-work.md` rather than built here, since it is a new gate
rather than the fix that was asked for.

### What this pass could not do

Nothing was executed — no `vendor/`, no docker — so every behavioural conclusion above is
derived from reading source, including the invocation matrix. A reviewer with a working stack
should run the unexecuted tests before merging.
