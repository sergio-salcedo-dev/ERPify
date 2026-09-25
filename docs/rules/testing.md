# Testing

- Add tests whenever relevant and appropriate
- Use PHPUnit for unit tests and integration tests in PHP
- Use Behat for behavior-driven development (BDD) and acceptance tests
- Use Jest for JavaScript/TypeScript testing
- Write tests before or alongside code (TDD/BDD when applicable)
- Maintain high test coverage for critical business logic
- Tests should be fast, independent, and repeatable

## Testing Best Practices
- Write tests for critical business logic
- Aim for high test coverage on important code paths
- Write unit tests that are fast and isolated
- Use integration tests for testing component interactions
- Write tests that are easy to understand and maintain
- Follow AAA pattern (Arrange, Act, Assert)
- Use test doubles appropriately, named per the convention below

## Test double naming convention

Name **ports by capability** and **implementations by technology/strategy** — the same naming axis holds in `src/` and `tests/`, so a port's production and test adapters read as siblings.

| Role | Convention | Example |
| --- | --- | --- |
| Port (capability) | `<Capability>` | `BankAccountCounter`, `BankRepository`, `BankExistenceChecker` |
| Production adapter | `<Technology><Port>` | `DoctrineBankAccountCounter`, `DoctrineBankRepository` |
| Test double that is an in-memory implementation of the port | `InMemory<Port>` | `InMemoryBankAccountCounter`, `InMemoryBankRepository` |
| Test double that is a test-double pattern, not a port implementation | `Spy*` / `Stub*` / `Dummy*` | `StubDriverException`, `StubPersistenceFailure` |

- An in-memory test implementation of a port is `InMemory<Port>`, never `Fake<Port>`: it stays symmetric with the `Doctrine<Port>` adapter and states *how* it works rather than the uninformative "fake".
- An in-memory double that also records the calls it received still uses `InMemory<Port>` — the implementation nature dominates the incidental spying.
- Reserve `Spy*` / `Stub*` / `Dummy*` for a **solitary** double whose only notable property is its pattern — a stubbed framework exception, a stubbed persistence failure. The prefix earns its place by distinguishing that double from nothing else.
- **When a port has several doubles, the pattern prefix stops discriminating and the name states the behaviour instead**, on the same axis as the port's production adapters: `FixedClock` / `AdvancingClock` / `MovableClock`, siblings of `NativeClock` / `SymfonyClock` (and of the vendor's own `NativeClock` / `MonotonicClock` / `MockClock`). All three of those clocks are stubs, so `Stub` would name the category beside two names that name members, and the question a reader actually has — does the clock move, and who moves it — would go unanswered. This is not a carve-out for clocks: it is the rule the first row already states, applied where the pattern prefix carries no information.
- The two rules above describe what the tree does; the row's own predicate does not. `StubImageProcessor` and `SpyInvitationEmailSender` both implement a domain port, which "not a port implementation" excludes. They keep their names — renaming them buys nothing — but they are the reason the predicate is a guide rather than a gate.

## A double with no expectations is `createStub()`

`createMock()` declares that the interaction itself is under test; `createStub()` declares that the double only has to answer. Reach for the mock **only** when the test configures `expects()` — configuring nothing and calling `createMock()` claims a verification that never happens, and PHPUnit says so: *"No expectations were configured for the mock object … Consider refactoring your test code to use a test stub instead."*

That arrives as a **PHPUnit** notice rather than a PHP one, and the two answer to different switches — `failOnNotice` has no authority over it. `api/tools/phpunit/phpunit.dist.xml` therefore also sets `failOnPhpunitNotice` and `displayDetailsOnPhpunitNotices`: without the first the build stays green, and without the second the run prints an aggregate count carrying no class, method or message, which reads as noise.

Do **not** silence it with `#[AllowMockObjectsWithoutExpectations]`. The attribute is for a double that genuinely needs mock semantics without expectations; over an expectation-less `createMock()` it preserves the wrong claim instead of correcting it. It targets a class **or** a method, and the class form — the one `AllowMockObjectsForDataProviderRector` emits, which is why that rule is skipped in `api/tools/rector/rector.php` — also covers every double added to that class later.

## Assert the seed before asserting the absence

A test that asserts *"no row survives"* passes perfectly when the setup inserted nothing. The assertion is true, the test is green, and it proves nothing — so **every test whose subject is an absence must first assert that its own seed happened**: that the `INSERT` affected N rows, that the fixture exists, that the query it is about to negate would have found something a moment ago.

This is not hypothetical hygiene. It shipped twice:

- A seed written `INSERT … SELECT … FROM organization LIMIT 1` inserted **zero rows** — the test database is migrated and never provisioned — so the phantom row under test never existed and both assertions were already true without it.
- An erasure `UPDATE` ran over **zero rows**, leaving its acceptance criteria unproven and its control unfalsifiable, while a `17 → 18` query counter was read as confirmation. **+1 is also what an `UPDATE` that matches nothing costs.**

The empty seed is one member of a family, and naming only that member let the family recur. **The general rule is that a test must fail when the mechanism it names is removed** — so falsify it by deleting the guard, not by trusting that the assertion reads well. Three shapes that passed every gate here, none of them a seed problem:

- **Asserting the exception class where the acceptance criterion promises a status.** Two domain classes existed to produce 404/503/500; all three test files that named them checked `instanceof`, so swapping their base class for `RuntimeException` left every test green while two documents kept publishing the old status.
- **Pairing the test with a different mechanism than the one under test.** An assertion about `#[MapUploadedFile]` was paired with a serializer test — disjoint Symfony resolvers — so it passed with the guard deleted, and the test that really covered it already existed elsewhere in the tree.
- **A setup that restores exactly the level the guard covers.** An existence probe was guarded only at the containing directory; the test for that branch restored the intermediate shard to `0755` before probing, exercising the one level that was already defended and never the hole.

The same trap in its other shapes: a `--filter` that selects a strict subset still exits 0 (verify with `--list-tests`, do not reason about it), and a gate whose source file is missing must **fail rather than skip**.

Corollary — **a control that has never been seen red is not a control.** Prove the red by sabotage: break the thing the test defends, watch it fail, and restore the bytes **by copy**, never with `git checkout --` (it reverts your uncommitted work along with the probe).

## The suite reads one pinned instant, and a test double's window is relative to it

The PHPUnit suite pins both of the application's time sources to `FreezeSystemClockExtension::SUITE_INSTANT` — the ambient `SystemClock` that aggregates read, and Symfony's global clock, which the container's `clock` service delegates to and which `SystemClockInitializer` copies back over the ambient one on `kernel.request`, `console.command` and every worker message. `FreezeSystemClockExtension::pin()` is called from three places, and the two subscribers are not enough on their own: `PreparationStarted` and `Finished` bracket each test, and `api/tools/phpunit/bootstrap.php` covers the windows no per-test event reaches — a data provider (resolved while the suite is *built*; three providers here construct aggregates there), `setUpBeforeClass()` of the first class, and an isolated child process, which registers no extension at all.

**Which lever overrides it depends on the lane, and reaching for the wrong one fails silently.**

- Kernel-free test: `SystemClock::set(FixedClock::at(…))` — `FixedClock` is `Erpify\Tests\Double\Clock\FixedClock`, under `api/tests/Double/` and deliberately not under `api/tests/Support/`, whose namespace `ArtifactGateSweep` reads as a signal that a kernel-free test is an artifact gate.
- Once a request, command or worker message is dispatched: `Symfony\Component\Clock\Clock::set(new MockClock(…))`. `SystemClockInitializer` runs at priority 4096 on `kernel.request` and overwrites the ambient clock with the container's, so a `SystemClock::set()` made before the request is gone by the time the controller reads it — and the failure surfaces as a wrong expiry, pointing nowhere near the listener that caused it.

Restore with `FreezeSystemClockExtension::pin()`, never `SystemClock::reset()`: `reset()` un-pins to the host wall clock, which under this harness is an escape rather than a restore. The trailing pin makes a missed restore harmless at the test boundary, not within a test.

**Why pinning and not clearing.** Clearing hands the ambient accessor back to the host wall clock, which is the failure mode itself: a test that seeds an absolute near-future expiry and reads it through an active-only predicate is green until that date arrives, then red on a commit that touched nothing. The tree shipped that twice. A pin does not make a bad seed correct — it makes its verdict the same on the first run and every run after, which is what keeps a red attributable to the change that caused it.

**Why not a clock that refuses to answer.** It was measured. `AggregateRoot::__construct()` reads the clock, so a `now()` that throws unless the test froze time reddens **720 tests across 192 classes** — every aggregate the suite builds — for a defect the pin closes outright at no such cost.

**What the pin buys is that the instant stops mattering.** 3707 tests are green pinned at 1999-06-15, 2026-01-01, 2035-01-01, 2100-01-01, `SUITE_INSTANT` and 2017-03-08T14:22:37 — past and future, on a boundary and off one, to the round hour and to the odd second. That is the property to preserve.

**Free to a test is not free to a reader, and the constant carries two properties because of it.** It must sit in a year the tree does not use — the value appears verbatim in failure diffs, and when it was `2026-01-01` (the tree's most-used date literal, 66 occurrences) that string led the reader to 65 files instead of to the constant, while making `assertSame('2026-01-01…', $x->createdAt)` true by two independent paths: the code copied the seed, or the code read the clock. And it must sit away from every day/month/year boundary, so a test doing `->modify('-1 second')` does not cross all three at once. `2099` and `2100` are unavailable: the tree already spells them 19 and 7 times as its idioms for "far future" and "locked for ever".

**What it asks of a test.** Seed from the clock the subject reads, never from a second one, and express a window rather than a date. Four defects had to be fixed to reach the span above, and every one had been green for as long as two clocks happened to agree:

- the shared functional login seated its session with `new DateTimeImmutable('+1 day')` while the admission gate decided by the container's clock — **57 tests across 15 classes**, all of them that one seed;
- `ImageTest` compared an aggregate's stamp against a bare `new DateTimeImmutable()`;
- a recovery secret was minted off the wall clock and redeemed against the container's;
- `SessionMother` and `PasswordResetTokenMother` defaulted to `2099-01-01` and `2030-01-01`, the same bomb with a longer fuse — what kept every consumer that does not freeze the clock green was those dates still being in the future. They now mint `SystemClock::now()->add(P7D)` and `+1 hour`, mirroring what `StartSession` and `RequestPasswordReset` issue, and each is pinned by a test that freezes the clock past the retired literal.

**Blind spots.** The pin owns those two sources and no others, and `FreezeSystemClockExtensionTest` witnesses both — but not the *leading* edge, which the trailing pin satisfies on its own; the case it defends (a test that skipped or threw in `setUp()` and so never reached `Finished`) cannot be staged in a suite that forbids ordering dependencies, and rests on argument alone. A bare `new DateTimeImmutable()` or `time()` reads past both — including `Shared\Event\Domain\DomainEvent`'s `$occurredOn ?? new DateTimeImmutable()` default. Nothing gates the rule: the four above were found by moving the pinned instant and reading what broke, which is the check to repeat rather than a green to trust. Postgres keeps a clock nothing here touches, and Behat boots from its own bootstrap, which registers none of this.

## Artifact gates: where they live

An **artifact gate** is a kernel-free test whose subject is a repository artifact — the source tree, a registry at the api root, a compose file, a doc, the migrations directory — read as data and asserted over. The category is the **mechanism**, never the subject: a behavioural test exercises code and credits the class it covers, while a gate exercises nothing and credits no *production* coverage, because `api/tools/phpunit/phpunit.dist.xml` scopes coverage to `src` and there is no production line for it to claim. A unit test of a gate's own rule engine belongs to the category too — the engines are in `tests/`, so it credits no production line either, and its placement is the same question.

- **Home** — `api/tests/Unit/Gate/`. The leaf names the **category**, which is the point of it: the folder was `Unit/Shared/Architecture` until the contents outgrew the name twice over. `Architecture` was merely imprecise — roughly 6 of 52 gates are architecture proper. `Shared/` was a false statement: it is a bounded-context name meaning the shared kernel, while these gates sweep `Backoffice/`, `Iam/`, `Organization/`, `pwa/src`, `compose*.yaml`, `docs/` and `migrations/`. It also mirrored nothing — `api/src/Shared/Architecture/` does not exist, and `api/tests/Unit/` otherwise mirrors `api/src/`. `Gate` is this repo's own vocabulary rather than an imported term, which is a real cost: it trades a weakly-established name for one with no authoritative source. It is paid because the alternative was a name that asserted false ownership.
- **The one exception is a gate mirrored on a module.** Decide by *whose rule it is*, not by what the sweep reads: a rule that is one module's own contract is filed on that module however wide the sweep must be — [`ErrorContractGateTest`](../../api/tests/Unit/Shared/ErrorContract/Application/ErrorContractGateTest.php) mirrors `src/Shared/ErrorContract` and walks every `.php` under `api/src` — while a rule that belongs to no single module goes in the home. When a sweep spans more than one module, the owner is the module whose contract the rule protects, and the line names it. `tests/` is the second mirror axis, for a gate whose subject is test infrastructure.
- **A file already in the home whose membership in the category is open is `undecided`**, and its line states why. The registry header carries the line grammar and the third field each placement takes.
- **Rule engines live in `api/tests/Support/`** — the derivation, separate from the assertions over it, so a second class can falsify the rule against synthetic input ([`ApiSourceFiles`](../../api/tests/Support/ApiSourceFiles.php) is the shape). Two were filed under `api/tests/Unit/Gate/Support/` after that home already existed and still sit there; nothing new joins them, and the gate ratchets that one directory downward. One of the two is already imported from outside it, which is the argument: an engine filed under a gate's folder can only be reached by naming that folder.
- **The gates a `php.lint.*` target names are selected by `--filter` on class name** (`make/php-quality.mk`), so for those the class name is wiring and renaming one is a change to its target. `failOnEmptyTestSuite` makes the omission loud rather than silent — a filter matching nothing exits 1 (measured) the next time that target runs. It says nothing about a filter selecting a strict **subset**, which is why each class gets its own run: see *Assert the seed before asserting the absence* above. Most gates are named by no target and run only in the whole suite.
- **A gate that has to boot a kernel is outside this category**, which is kernel-free by definition; it is a functional test and is filed like one.

Every artifact gate is classified in [`api/.artifact-gate-placement`](../../api/.artifact-gate-placement) and the classification is recomputed from the tree by `make php.lint.gate-placement`: a gate with no line, a line no gate backs, a placement the file's own path contradicts, and a file added to `api/tests/Unit/Gate/Support/` each fail the build. What the gate cannot see is enumerated in the registry header — starting with the fact that it never judges a classification.

### Artifact gates: what makes one able to fail

- **A declaration whose universe the code can derive is checked against the source in both directions**: every member of the universe is classified, and every registry line still matches a member. With both halves the content is pinned to the code from either side and cannot be a free assertion. Generating the registry is an admissible way to get that property, never the required one — [`PersistentTransportPolicyGateTest`](../../api/tests/Unit/Gate/PersistentTransportPolicyGateTest.php) has it without generating anything, a generator cannot supply the one field that carries meaning (who erases a person's id), and the obvious generator — the gate itself run with `--update` — is ruled out by the read-only, parallel-safe guarantee [`make/php-quality.mk`](../../make/php-quality.mk) gives every prerequisite of the sweep. Full derivation is often impossible anyway (measured on one registry: its types are born of a route `defaults:`, a class constant and eight YAML routing entries, none reachable by reflection over properties), so registries here are hybrid by necessity.
- **A declaration is never its own evidence.** A check whose universe or expected value is drawn from the thing it checks reads green by construction. Shipped once: a staleness check was satisfied by the literal `'User'` appearing exactly once in `api/src` — inside the very constant the registry line pointed at. So a **manual** declaration — a classification a human makes, which no source can derive — needs a second witness independent of itself, and a gate states its own universe rather than inheriting it from the walk it performs: `pwa/tests/password-input-adoption.test.ts` asserts a file floor and reach into `src/app/`, because a non-empty check drawn from the same walk stays green over a narrowed root (measured at 43 of 496 files); `make php.lint.project-context` checks that every registry line still covers a claim the extraction finds, or reformatting one table drops its claims out of the universe with the "every claim is bound" direction still green.

## Behat step vocabulary

A step definition is a shared asset. Never delete one for being unused, and search the vocabulary before writing a new one — `make php.behat c='-dl'` lists it, `make php.behat c="-d '<text>'"` searches it. When you touch a feature, spend the idle steps that fit it: an assertion that exists and is never made proves nothing. The inventory is [`api/.behat-step-vocabulary`](../../api/.behat-step-vocabulary) — every declared pattern classified `used` / `idle` / `manual` / `refused`, with the classifications recomputed by `make php.lint.step-vocabulary` so a stale one fails the build. Full rule and the debugging-only exception: [`api/CLAUDE.md`](../../api/CLAUDE.md).

## Error Handling in Tests
- Use exceptions for error handling, not return codes
- Create specific exception types for different error scenarios
- Fail fast and fail clearly with meaningful error messages
- Log errors appropriately with context
- Never expose internal implementation details in error messages
