---
title: The aggregate receives the instant, not the clock
status: ready
branch: fix/shared-aggregate-receives-the-instant-isvf
---

# The aggregate receives the instant, not the clock

## Problem

The application reads the current time from **two** sources that can disagree, and a third that neither
reaches.

`AggregateRoot::__construct()` stamps `createdAt`/`updatedAt` from a **static ambient clock**
(`api/src/Shared/Kernel/Domain/Aggregate/AggregateRoot.php:27`), while application use cases compute their
own instants from an **injected `Clock` port**. One object therefore carries timestamps minted by two
different clocks.

Until #929 the divergence measured microseconds. That PR pinned the suite's ambient clock to
`SUITE_INSTANT = '2050-06-15T12:00:00+00:00'` — deliberately, choosing a year the tree does not use — while
individual unit tests keep injecting their own double. Measured:

- `api/tests/Unit/Iam/Session/Application/StartSessionTest.php:26,35` seeds `2026-07-10` and injects
  `new FixedClock($now)`.
- The `Session` it builds therefore has `createdAt = 2050-06-15` and `expiresAt = 2026-07-17` — **an expiry
  roughly 24 years before its own creation stamp**, in a passing test.

`FreezeSystemClockExtension`'s own docblock already records that the pin makes this divergence *larger*
rather than smaller. Nothing compares the two, so nothing goes red.

**Production is not affected, and that is measured, not assumed.**
`api/src/Shared/Clock/Infrastructure/SystemClockInitializer.php:39-44` copies the container's `clock` over
the ambient one at `kernel.request`, `console.command` and every worker message — the three entry points
this application has. The defect is confined to tests that inject a clock double.

**It is not cosmetic for that reason.** In this repository correctness lives in the suite, and the suite can
currently construct an internally impossible object with no assertion being wrong. 36 of the 54 test files
that build a `FixedClock` run with two sources 24 years apart.

## The three readings, and where they disagree

Three independent readers were given the same measured facts and none was shown the others' answers: the
architect persona, the developer persona, and an external general-purpose model.

|  | Recommends | Core argument |
|---|---|---|
| Architect | **C** | Completes a pattern the tree already holds in 15 domain signatures that take `$now`; ends with *less* code |
| External model | **C** | DIP: the static is a service locator with one global slot; the domain needs the *instant*, not the *service* |
| Developer | The 5-file containment | No live assertion changes sign today — an armed trap, not a defect; C is epic-sized |

**They do not disagree about direction.** All three reject option B (inject `Clock` into the aggregate), and
the developer's objection to C is cost, not correctness.

### Why B is wrong, and it is the reading a reviewer will reach for first

`Clock` is **not** a forbidden framework dependency — `docs/adr/external-dependencies-in-domain.md:36`
blesses it by name as a valid own port. So the objection to B is not governance, it is reach and safety:

- **It fixes 1 of 16 reads.** `SystemClock::now()` appears 16 times in `api/src` across 7 files: one in
  `AggregateRoot::__construct`, **14 in mutators** on already-hydrated entities, and one in
  `Image::__construct` (which does not extend `AggregateRoot`). Doctrine hydration does not re-run the
  constructor — `api/tests/Functional/Shared/Images/ImagePersistenceTest.php:32` pins exactly that — so a
  constructor parameter cannot reach the fourteen.
- **The field variant breaks production with PHPStan green.** A `private Clock $clock` property is assigned
  in the constructor, so `checkUninitializedProperties` is satisfied; Doctrine instantiates without the
  constructor, and the first mutator on a loaded entity raises *Typed property must not be accessed before
  initialization*. The red arrives at runtime, on the write path.

### Why the external model's immediate guard is deferred to the end

It proposes adding, as containment before the migration, a guard that fails when the two clocks disagree.
Measured, that reds **36 of the 54** injecting files on day one — a migration blocker, not containment — and
it contradicts what `FreezeSystemClockExtension` documents having bought: that the instant stops mattering
(3707 tests green at six different instants). The guard is correct as the **last** step, once one source
exists.

### Why the developer's blocker is smaller than stated

The objection was that 65 `__factory` blocks in Alice YAML call factories **positionally**, and YAML cannot
be handed a service. True — but C passes a **value**, and the fixtures already have a PHP layer that can
supply one:

| YAML calls | Files | Blocks |
|---|---|---|
| A PHP fixture factory (absorbs the instant, YAML untouched) | `User`, `Membership`, `Session`, `Invitation` | **28** |
| A domain factory directly | `Bank`, `BankAccount`, `Organization` | **37** |

`api/tests/DataFixtures/SessionFixtureFactory.php:33` already constructs `new DateTimeImmutable('+1 day')`
inside PHP. The 37 need three thin factories mirroring the four that already exist in that directory — not a
format migration, and `SeededProjectionRebuildGateTest` is not dragged in.

## Decision

**The aggregate receives the instant. The ambient clock is removed.**

The application layer reads its injected `Clock` **once per operation** and passes the resulting
`DateTimeImmutable` inward. The domain receives a value, never a service: reading a clock is I/O and a
decision about *when*, which belongs at the boundary that already owns it.

Stated as the repo requires:

1. **Principle** — DIP, in its plainest form: a domain base class depends on a static concrete accessor
   rather than on an abstraction handed to it. Secondarily SRP (`AggregateRoot` both provides aggregate
   infrastructure and decides what time it is) and a mixed level of abstraction inside one class — `Session`
   *receives* `$now` to answer (`isExpired`, `isActive`) and *reaches a global* to record.
2. **Objective** — maintainability and a trustworthy oracle: one time source instead of three, and an
   aggregate whose output is a function of its inputs. Concretely `−3` production classes, `−1` listener per
   request/command/message, `−2` public audit setters from the shared kernel and the 23 test calls that
   exist only to dodge them.
3. **Cost, and the discarded alternatives** — a wide, mechanical diff (below). Discarded: **A** (patch the
   tests) because it is already applied by hand at 19 of 54 sites with no gate and the next test reopens the
   hole; **B** for the two reasons above; **a Doctrine `prePersist`/Timestampable listener** because
   `createdAt` travels in published event payloads (`BankSnapshot.createdAt`, and `SessionStarted`'s
   `occurredOn` is `getCreatedAt()` at `Session.php:85`) and events are recorded *before* flush, so the
   listener would stamp after the payload had already copied the value — and both columns are keyset ordering columns, not
   decoration.

## Scope, measured

| Piece | Count |
|---|---|
| `AggregateRoot::__construct` takes `DateTimeImmutable $now` | 1 |
| Mutators reading the static, across 5 entities | **14** |
| `Image::__construct` (not an `AggregateRoot`) | 1 |
| `DomainEvent::$occurredOn` loses its `new DateTimeImmutable()` default (`DomainEvent.php:38`) | 1 — **the third source** |
| Use cases that mint aggregates | 10 (4 already inject `Clock`; 6 grow the dependency) |
| New fixture factories | 3 — `Bank`, `BankAccount`, `Organization` |
| Aggregates extending `AggregateRoot` | 9 |
| Test files touching an aggregate / injecting a double / mothers | ~190 / 54 / 6 |
| Deleted | `SystemClock`, `NativeClock`, `SystemClockInitializer` (+ its listener), `Timestamped::setCreatedAt`/`setUpdatedAt` |

`setCreatedAt`/`setUpdatedAt` have **0 callers in `api/src` and 23 in `api/tests`**: they exist only to dodge
the ambient stamp, and they are the shape the security checklist forbids (entity setters exposing audit
fields). They go with it.

**The change is atomic and cannot be split across pull requests**: the static cannot be deleted until every
reader takes the parameter, and the parameter does not exist until `AggregateRoot` declares it. PHPStan reds
every caller that has not been converted, so the path is mechanical with no degrees of freedom.

## Order of work

1. `AggregateRoot::__construct` and the 14 mutators take `DateTimeImmutable $now`; `Image` likewise.
2. `DomainEvent::$occurredOn` loses its default; every recorder passes the operation's instant.
3. The 10 use cases read their injected `Clock` **once** and pass the value down; the 6 without one grow it.
4. Three fixture factories for `Bank`, `BankAccount`, `Organization`; mothers and test call sites follow.
5. Delete `SystemClock`, `NativeClock`, `SystemClockInitializer` and the two `Timestamped` setters.
6. The guard: refuse a second time source. Only meaningful now that there is one.
7. An ADR (`docs/adr/` holds **none** about the clock today), plus `docs/rules/testing.md` § *Reading the
   clock in a test* and the matching `CLAUDE.md` bullet, which would otherwise describe a world that no
   longer exists.

## Acceptance criteria

- **AC1** — `git grep -n 'SystemClock' api/src` returns nothing; the three classes are gone.
- **AC2** — `git grep -n 'new DateTimeImmutable()' api/src` returns nothing in `Domain/`; `DomainEvent`
  requires its `occurredOn`.
- **AC3** — `StartSessionTest` asserts `createdAt` and `expiresAt` are both derived from the instant the test
  supplied, and reverting step 3 reds it. This is the falsifier for the whole change.
- **AC4** — `Timestamped` exposes no public setter; the 23 test call sites pass the instant at construction.
- **AC5** — the guard reds when a second time source is introduced, and that red is demonstrated, not
  asserted.
- **AC6** — `make php.stan`, `php.unit`, `php.behat`, `php.quality`, `php.quality.dry-run` each green from a
  fresh run with its exit code printed; `pwa.quality` only if `pwa/` is touched (it should not be).
- **AC7** — the ADR states the decision, the three discarded alternatives with their measurements, and the
  one cost kept: every aggregate a test builds no longer shares one `createdAt`, so orderings that fell to
  the id tie-break under the pin may now order by time again.

## What this does not claim

- Nothing about Postgres's own clock, the Behat lane's bootstrap, or a bare `time()` — none of which the
  ambient pin covered either.
- The guard proves one source exists in `api/src`; it says nothing about a test that fabricates a timestamp
  literal, which stays a review matter.
- Production behaviour does not change. The initializer that made both sources agree is deleted because
  there is no longer a second source to reconcile, not because it was wrong.

## Provenance

Surfaced by the `deferred-work.md` sweep of 2026-09-20 (#965), which rewrote the bullet rather than closing
it and recorded the decision as the product owner's. The three consultations are in
`tmp/bmad-md/consult-aggregate-clock-divergence-20260920-125402.md` (the external prompt) and in the session
record; their substance is distilled above, so the `tmp/` copies are not load-bearing.
