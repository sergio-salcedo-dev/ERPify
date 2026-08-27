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
   eighteen days apart about one defect.
3. **`ResetSystemClockExtension` resets the ambient clock before each test**, not only after.
4. **Documentation drift**: the audit ADR's `metadata.operation` key, the dead
   `api/phpunit.xml.dist` path, and `docs/architecture-pwa.md`'s PascalCase module names.
5. **The same silent-zero defect in both sibling GDPR commands.** `bank-account:gdpr:erase-subject`
   (which destroys a DEK — an irreversible crypto-shred) and `audit:gdpr:erase` carried the
   identical shape. Fixed with the same guard pair, so `$?` now means one thing across all
   three commands rather than two.
6. **Registry sweep**: 20 bullets removed from 117 — 7 resolved by this branch, 13 already
   closed in the tree with nobody having removed the bullet. Four rotted references repaired,
   one false premise restated. The split was recounted during the code review; the totals
   (20 removed, 117 → 103) were exact and every one of the thirteen was verified genuinely
   closed on `main`, so no obligation was dropped.

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

Four are enumerated below, and they sit under this pass's own heading in `deferred-work.md`
alongside P-1 (later escalated into scope) and the second pass's existence-oracle nit — six
bullets in total. The first two are deliberate:

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
process exit code from `Throwable::getCode()`: **measured at 7 for a DBAL driver code and at 255
for `1213`**, never `1`. (`Application::run()` clamps anything above 255 before `exit()`, so the
byte-wrapped 189 an earlier measurement reported is what a harness with `setAutoExit(false)`
produces, not the production path.) On top of that structural defect, the docblock this branch had just
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

## Review findings — code review of PR #866 (2026-08-27)

Three parallel layers in fresh contexts (adversarial, edge-case, acceptance), read-only, against the
committed head `200dca79`. Unlike the branch's own two passes, this one had the **installed `api/vendor`
tree and a running stack**, so the conclusions below are measured rather than read. **This review is also the
adversarial pass covering `200dca79` itself** — the head commit that fixed the second pass's GRAVE and which
no pass had read, which the acceptance layer raised as a gap in its own right.

### Decisions taken

- [x] **A stream already at `feof()` defeats both guards, and it is reachable.** `QuestionHelper::doReadInput()`
  loops `while (!feof($inputStream))`, so a stream arriving already at EOF never enters the loop, returns `''`
  rather than `false`, never raises the `MissingInputException` that demotes the input — and the default is
  taken for an operator's answer. `A-5` deferred this as unreachable; measured false. The producer is the
  console's own single-alternative prompt (`Application::doRun()`), which asks a confirmation of its own for a
  mistyped name with exactly one near match and drains a pipe whose last byte is not a newline: **38 single-
  character typos of the three command names yield exactly one alternative**, and `printf 'y' | bin/console
  bank-account:gdprerase-subject <uuid>` exits `0` having erased nothing, against `2` for the same pipe with a
  trailing newline. **Resolved:** a third guard, `cannotBeAsked()`, in all three commands, resolving the stream
  exactly as `QuestionHelper::ask()` does. The `A-5` bullet is removed from the registry.
- [x] **`countFor()` ran outside every `try` and ahead of the guards — one defect wearing two hats.** Both were
  put to the architect and the developer personas, who converged on a shape neither of the two options offered:
  the row count is not *moved*, it stops being *computed* on the paths that discard it. It still feeds the
  confirmation's magnitude — the only defect an operator can catch before an irreversible `UPDATE` — and still
  answers `--dry-run`; `--force` no longer takes a preview it does not need, and a run about to be refused no
  longer takes one at all. That closes the existence oracle in every spelling, including the ones where stdout
  is suppressed (`--quiet`, `--silent`, a negative `SHELL_VERBOSITY`, a plain `>/dev/null`) and the exit code is
  the only channel. The oracle bullet is removed from the registry — it was added by this branch, so the net
  diff for it is empty.
  - **Contract change, approved deliberately:** an unattended run without `--force` against an actor with **no
    rows** now exits `2` where it exited `0`. That `0` answered for an operator who was never asked and was
    right only because the answer did not matter.
  - **A GRAVE the restructure would have opened, and did not:** with `--force` taking no preview, an already
    erased actor reaches the `UPDATE`, matches nothing, and would have minted a `GDPR_ERASURE_EXECUTED` row.
    `AuditErasureEvidence` exempts that action from the retention prune **for ever**, so every retry of a
    compliance job would mine an immortal row claiming an erasure that did not happen. Guarded on
    `affectedRows`, and pinned.

### Patches applied

- [x] `countFor()` gains its own failure path — `FAILURE`, never `INVALID`, because a database that cannot
  answer is exactly what a caller should retry [EraseActorAuditTrailCommand::reportMatches()]
- [x] The anonymisation failure message no longer asserts a post-condition it cannot know: a connection lost
  mid-statement can commit without acknowledging, so it now tells the operator to verify with `--dry-run`
  instead of promising the run is safe to repeat [EraseActorAuditTrailCommand::eraseAndReport()]
- [x] "Nothing to erase" is named as a `SUCCESS` cause in all three docblocks, both help texts and
  `docs/architecture-api.md`
- [x] Bind-time failures (unknown option, wrong arity, mistyped name) exit `1` and no guard can reach them;
  stated in all three contracts, so "never retry on `INVALID`" reads as a floor on retries rather than a
  partition of them
- [x] `SHELL_VERBOSITY` below zero is named beside `--quiet` and `--silent` as a third silent-refusal spelling
- [x] `aDeclinedConfirmationAbortsWithoutErasing` uses `refusingEraser()` — the test named `…WithoutErasing`
  could not detect an erasure, using the double this branch's own docblock calls "the trap"
- [x] The change-relative comment is gone from `api/src`, and the paragraph that carried it no longer asserts a
  roster: a command's reachability of the CRITICAL path is a property of each call path, not of a command, and
  `countFor()` was the counter-example [ConsoleCommandRedactionProcessor]
- [x] The `P-2` and `B-2` registry bullets: rotted line references repaired, `P-2`'s premise restated against
  the head's actual contract, and `B-2` now names the `userId` comparison as well — the sharper direction, where
  an upper-case id makes the double revoke nothing while production revokes everything
- [x] The gate bullet's invariant was mis-stated and would have been **green over this branch's own GRAVE**;
  it now asks for adjacency plus a behavioural gate, with the binding-failure blind spot stated
- [x] The `1213` figure is `255`, not `189` — `Application::run()` clamps before `exit()`; the identity command's
  two ordering pins carry the docblock its siblings got; the sweep split is 7/13, not 8/12; "five weeks" is
  eighteen days; the stray blank line splitting the new registry section is closed
- [x] `ResetSystemClockExtension` documents what its leading reset is upstream of — class-level hooks and data
  providers — and that the ordering inverts under `inIsolation`
- [x] `PRODUCTION_SECURITY_CHECKLIST.md` carries the guard pattern and its three ungated residuals
- [x] **CI was red on the head and is green now.** `php.cs-fixer.dry-run` (`assertEquals` → `assertSame`),
  `php.rector.dry-run` (`assertNull` → `assertNotInstanceOf`, plus a blank line before an assignment) and
  `php.md` (11 public methods against a limit of 10) all failed — the branch was written with no docker and no
  `api/vendor`, so no linter had ever run over it. The third was answered by splitting the bulk-revocation cases
  into `InMemorySessionRepositoryBulkRevocationContractTest`, along the seam the class docblock already drew.

### Tests added, each falsified by mutating the code and watching its row go red

- `cannotBeAsked()` removed → the drained-stream pin fails `0 is identical to 2`, in all three commands
- The `affectedRows` guard removed → the forced-run-over-an-erased-actor pin fails `actual size 1 matches
  expected size 0`, catching the immortal evidence row
- The count's `catch` removed → both counting paths error
- The anonymisation's `catch` removed → errors. **That `catch` shipped in this branch with no test at all**,
  while its bank-account sibling received one in the same commit
- The guards moved back behind the count → four failures, including the oracle reappearing
- `RecordingAuditActorAnonymiser` gained a separable `affectedRows` (additive; seven files construct it),
  because a double wiring it to the match count makes the immortal-evidence branch untestable

### Deferred

- [x] [Review][Defer] The double's bulk revocation is not undone by a rolled-back transaction, so a test can
  stay green over a rollback that production would have restored — consistent with the pre-existing
  `deleteAllForUser()`, but the new three-point docblock invites the double to be trusted as a mirror and
  never mentions transactionality
  [api/tests/Unit/Iam/Session/Application/InMemorySessionRepository.php:161, :169] — deferred, pre-existing

### What this review does not close

The guards close the exit code as an existence oracle. They do **not** close the count as information:
`--dry-run` still prints it to the same caller, and whoever can invoke the CLI can pass `--dry-run`. Sold as a
structural invariant — no query on a path already going to refuse — and never as a confidentiality gain, so
that nobody rediscovers `--dry-run` in three months and reopens it as a regression.
