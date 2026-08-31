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
| Test double that is a test-double pattern, not a port implementation | `Spy*` / `Stub*` / `Dummy*` | `StubDriverException`, `SpyMailer`, `StubClock` |

- An in-memory test implementation of a port is `InMemory<Port>`, never `Fake<Port>`: it stays symmetric with the `Doctrine<Port>` adapter and states *how* it works rather than the uninformative "fake".
- An in-memory double that also records the calls it received still uses `InMemory<Port>` — the implementation nature dominates the incidental spying.
- Reserve `Spy*` / `Stub*` / `Dummy*` for doubles that embody a test-double pattern instead of an alternative implementation of a domain port (a stubbed framework exception, a spy mailer, a stub clock).

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

## Artifact gates: where they live

An **artifact gate** is a kernel-free test whose subject is a repository artifact — the source tree, a registry at the api root, a compose file, a doc, the migrations directory — read as data and asserted over. The category is the **mechanism**, never the subject: a behavioural test exercises code and credits the class it covers, while a gate exercises nothing and credits no *production* coverage, because `api/tools/phpunit/phpunit.dist.xml` scopes coverage to `src` and there is no production line for it to claim. A unit test of a gate's own rule engine belongs to the category too — the engines are in `tests/`, so it credits no production line either, and its placement is the same question.

- **Home** — `api/tests/Unit/Gate/`. The leaf names the **category**, which is the point of it: the folder was `Unit/Shared/Architecture` until the contents outgrew the name twice over. `Architecture` was merely imprecise — roughly 6 of 52 gates are architecture proper. `Shared/` was a false statement: it is a bounded-context name meaning the shared kernel, while these gates sweep `Backoffice/`, `Iam/`, `Organization/`, `pwa/src`, `compose*.yaml`, `docs/` and `migrations/`. It also mirrored nothing — `api/src/Shared/Architecture/` does not exist, and `api/tests/Unit/` otherwise mirrors `api/src/`. `Gate` is this repo's own vocabulary rather than an imported term, which is a real cost: it trades a weakly-established name for one with no authoritative source. It is paid because the alternative was a name that asserted false ownership.
- **The one exception is a gate mirrored on a module.** Decide by *whose rule it is*, not by what the sweep reads: a rule that is one module's own contract is filed on that module however wide the sweep must be — [`ErrorContractGateTest`](../../api/tests/Unit/Shared/ErrorContract/Application/ErrorContractGateTest.php) mirrors `src/Shared/ErrorContract` and walks every `.php` under `api/src` — while a rule that belongs to no single module goes in the home. When a sweep spans more than one module, the owner is the module whose contract the rule protects, and the line names it. `tests/` is the second mirror axis, for a gate whose subject is test infrastructure.
- **A file already in the home whose membership in the category is open is `undecided`**, and its line states why. The registry header carries the line grammar and the third field each placement takes.
- **Rule engines live in `api/tests/Support/`** — the derivation, separate from the assertions over it, so a second class can falsify the rule against synthetic input ([`ApiSourceFiles`](../../api/tests/Support/ApiSourceFiles.php) is the shape). Two were filed under `api/tests/Unit/Gate/Support/` after that home already existed and still sit there; nothing new joins them, and the gate ratchets that one directory downward. One of the two is already imported from outside it, which is the argument: an engine filed under a gate's folder can only be reached by naming that folder.
- **The gates a `php.lint.*` target names are selected by `--filter` on class name** (`make/php-quality.mk`), so for those the class name is wiring and renaming one is a change to its target. `failOnEmptyTestSuite` makes the omission loud rather than silent — a filter matching nothing exits 1 (measured) the next time that target runs. It says nothing about a filter selecting a strict **subset**, which is why each class gets its own run: see *Assert the seed before asserting the absence* above. Most gates are named by no target and run only in the whole suite.
- **A gate that has to boot a kernel is outside this category**, which is kernel-free by definition; it is a functional test and is filed like one.

Every artifact gate is classified in [`api/.artifact-gate-placement`](../../api/.artifact-gate-placement) and the classification is recomputed from the tree by `make php.lint.gate-placement`: a gate with no line, a line no gate backs, a placement the file's own path contradicts, and a file added to `api/tests/Unit/Gate/Support/` each fail the build. What the gate cannot see is enumerated in the registry header — starting with the fact that it never judges a classification.

## Behat step vocabulary

A step definition is a shared asset. Never delete one for being unused, and search the vocabulary before writing a new one — `make php.behat c='-dl'` lists it, `make php.behat c="-d '<text>'"` searches it. When you touch a feature, spend the idle steps that fit it: an assertion that exists and is never made proves nothing. The inventory is [`api/.behat-step-vocabulary`](../../api/.behat-step-vocabulary) — every declared pattern classified `used` / `idle` / `manual` / `refused`, with the classifications recomputed by `make php.lint.step-vocabulary` so a stale one fails the build. Full rule and the debugging-only exception: [`api/CLAUDE.md`](../../api/CLAUDE.md).

## Error Handling in Tests
- Use exceptions for error handling, not return codes
- Create specific exception types for different error scenarios
- Fail fast and fail clearly with meaningful error messages
- Log errors appropriately with context
- Never expose internal implementation details in error messages
