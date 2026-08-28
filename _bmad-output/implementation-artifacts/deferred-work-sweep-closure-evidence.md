# Deferred-work sweep — closure evidence

Auditable record for the sweep. It exists so that **every disappearance from the
register has a proof, every proof has a mutation, and the final register can be
audited against the base state**. It is NOT part of `deferred-work.md`: the
register stays pending-only and never gains a changelog.

Base commit: `f86b2662` (the merge of PR #866) · Branch: `chore/deferred-work-sweep-uqmh`

## A. Register integrity

Produced mechanically by `deferred-work-sweep-register-gate.py` — never by hand.

| Property | Expected | Measured |
|---|---:|---|
| base count (`f86b2662`) | 98 | _pending_ |
| deleted by this branch | 45 | _pending_ |
| added | 0 | _pending_ |
| surviving head count | 53 | _pending_ |
| survivors with modified text | 1 (ITEM 41 only) | _pending_ |

The 53 survivors are 27 `trigger-gated` + 24 `needs-decision` + 2 the product
owner deferred (ITEM 41, ITEM 56).

**Why the base moved.** This sweep was planned against `c988198b`, when PR #866
was still open, and carried a constraint to leave its 22 bullets byte-identical
plus a projected post-merge count of 51. #866 merged while this branch was still
empty; the branch was rebased onto the merge, so that constraint has no subject
any more and the projection became a measurement. The projected 51 and the
measured 53 differ because #866 **edited** four bullets as well as deleting
twenty-four; two of those edits had never been triaged and were triaged after the
rebase — both `needs-decision`, neither closable here.

## B. Closure rows — one per closed bullet (37)

Independence rule: reverting a row's mutation reddens **its** falsifier and leaves
every other row's falsifier green. Sharing a file is not sharing a mutation.

| ITEM | Original condition | Minimal change | Falsifier | Exact mutation | Independence | Result |
|---|---|---|---|---|---|---|
| 51 | No positive `editor → write → 2xx` in either access-control feature; create/update run as MANAGER, so an over-restriction of write would be caught nowhere | Four scenarios added (create + update, Bank + BankAccount), reusing six step patterns already classified `used` | The four scenarios | Require a permission stricter than `write` on create/update | Behat-only; no PHP falsifier shares a file | CLOSED |
| 71 | `BankRealtimeAuthorizeController`'s 204 path signed topics nothing asserted, unlike its BankAccount twin | New `BankRealtimeAuthorizeControllerTest` decoding the JWT | `testCookieAuthorizesExactlyTheTwoBankTopicsAndPublishesNothing` | (A) extra subscribe topic; (B) a granted publish topic — provoked separately, because `assertSame` short-circuits | Own file | CLOSED |
| 73 | CI ran only `php.unit.coverage` (1G, warnings relaxed), so the 512M ceiling and `failOnWarning` were never exercised in CI | `make php.unit` step added to the existing `api-test` job, before the coverage step | The step itself | Lower `PHPUNIT_MEMORY_LIMIT` below the suite's peak, or emit a PHPUnit warning | Own file | CLOSED |
| 80 | The site-wide `Referer` drop was described as a mechanism, never recorded as an accepted cost | One paragraph in §7 following the document's own `**The accepted cost, stated rather than hidden:**` convention | None — prose, and it says so | n/a | Own file | CLOSED |
| 81 | The negative outbox table-match step returned having asserted nothing over an empty queue | Non-empty guard before the match loop | `OutboxTableMatchTest::testTheNegativeFormRefusesAnEmptyQueueRatherThanPassingVacuously` | Remove the guard | Own file | CLOSED |
| 82 | `runWorker()` recorded exit 0 for a consume that resolved zero receivers | Assert `$receivers !== []`, naming the unusable entries | `MessengerConsumerContextTest::testAConsumeResolvingNoReceiverIsRefusedRatherThanRunToItsTimeLimit` | Delete the `assertNotSame([], $receivers, …)` block | Reverted: 82 red, 84 and 86 green | CLOSED |
| 83 | `the last run output should not contain` satisfied itself over an empty buffer | Refuse a buffer that is empty or whitespace-only | `RunOutputAbsenceTest::testAnAbsenceIsNotProvenOverARunThatPrintedNothing` | Remove the emptiness assertion | Reverted: 83 red, 85 green | CLOSED |
| 84 | `I consume N` asserted nothing about how many were consumed | Count `WorkerMessageHandled`/`Failed` on the private dispatcher; `consumeExactly()` asserts the count — the raw-CLI step deliberately does not | `MessengerConsumerContextTest::testAConsumeThatReadsFewerMessagesThanTheStepNamedIsRefused` | Revert `iConsume`/`iConsumeWithTimeLimit` to call `runWorker()` directly | Reverted: 84 red, 82 and 86 green | CLOSED |
| 85 | `LastRun::record()` overwrote an unread run with no signal | Track whether the run was read; refuse a second record over an unread one | `LastRunTest::testRecordingOverARunNothingReadIsRefused` | Drop the `$read` field and the guard | Reverted: 85 red, 83 green | CLOSED |
| 86 | Verbosity resolved by last matching flag, so `-vvv --verbose` degraded to VERY_VERBOSE | `verbosityFrom()` takes the maximum | `MessengerVerbosityResolutionTest::testTheStrongestVerbosityFlagWinsOverTheLastOneDeclared` | `\max($verbosity, $level)` → `$level` | Re-measured after the class split: 86 red (exit 2), 82/84 green (exit 0) | CLOSED |

| 2 | Nothing pinned that the persisted `operation` equals `AuditWriteOperation::name`, nor that the three PWA literals match the enum | New `AuditWriteOperationParityTest` comparing the enum's case names against the values parsed out of the PWA object literal, plus a pin that the payload guard is still derived from it | `theWriteOperationVocabularyIsTheSameOnBothDeployables` | Renamed a case PHP-side; renamed the literal PWA-side; replaced the derived guard with a hand-written set. Negative control: a commented-out value stays green | Own file | CLOSED |
| 17 | The gate loaded the admitted `Session`, discarded it, and both controllers re-ran the identical query | The gate publishes the row on the **Request** (never a singleton or static — FrankenPHP worker mode); controllers read it, falling back to a fresh lookup | `SessionAdmissionGateTest::testItPublishesTheAdmittedSessionOnTheRequest` | Collapse the gate back to the discarded `instanceof` and restore both controllers | Reverted: 17 red, 27 and 19 green | CLOSED |
| 18 | No scenario proved an expired session leaves `GET /sessions`; covered only at adapter level | Scenario seeding an `ACTIVE` row with `expires_at` in the past, with a row-count assertion so the seed cannot be vacuous | The scenario | Behat, verified in the serial run: 470 scenarios green | Own file | CLOSED |
| 19 | No unit test admitted, so inverting the gate's condition reddened nothing | `testItAdmitsALiveSession` + the publication test | `SessionAdmissionGateTest::testItAdmitsALiveSession` | Make the gate refuse every session. **Baseline measured first: the same mutation on the original gate passed the original suite — 6 tests, exit 0** | Reverted: 19 red, 17 and 27 green | CLOSED |
| 27 | Rejecting a session left the native cookie alive, so a revoked-cookie bearer cost a DB round-trip on every request while an anonymous caller cost none | Both refusal branches route through `refuse()`, which invalidates the native session before throwing — mirroring `RevokeCurrentSessionController` | `testItDropsTheNativeSessionWhenTheSessionIsNoLongerActive` and `…WhenThereIsNoCorrelation`, one per branch | Replace both `refuse()` calls with the bare throw | Reverted: 27 red (2 failures), 17 and 19 green | CLOSED |
| 44 | `MAX_FIELD_LENGTH = 100` mirrored a `VARCHAR(100)` width with nothing comparing them | Functional test asking `information_schema.columns` for both columns | `AuditLogFieldWidthContractTest::eachGuardedColumnIsAsWideAsTheEntryGuard` | `MAX_FIELD_LENGTH` 100 → 101 | Own file | CLOSED |
| 45 | The port's alias had no consumer, so the test built the writer by hand and the wiring was unpinned | A method resolving `AuditLogWriter` from the container; the stale docblock corrected | `AuditLogWriterIdempotencyTest::testThePortResolvesToTheDbalWriter` | **Removing `#[AsAlias]` is a NON-falsifier (exit 0)** — the binding comes from `registerAliasesForSinglyImplementedInterfaces()`. The working mutation adds a second implementer that redirects the port | Own file | CLOSED |
| 47 | Raw-DBAL writes raise no ORM flush, so `FixturesChangeTracker` stayed clean and rows leaked across features | `TestDebugDataHolder::addQuery()` marks the tracker when the leading keyword is INSERT/UPDATE/DELETE/TRUNCATE, before the existing filter and touching none of its accounting | `testRawWriteMarksTheFixturesTrackerEvenWhenTheQueryIsFilteredOut` and `testPlainSelectLeavesTheFixturesTrackerClean` | Delete the marking (write test reds); mark unconditionally (read test reds) | Own file | CLOSED |
| 75 | The registry re-swept all of `api/src` on every call, inside a gate CI runs per PR | Four lazily-computed **instance** memos; the class stops being `readonly` because PHPStan refuses a lazy write to a readonly property | `PersonResourceErasureRulesGateTest::theSourceCorpusIsOneSnapshotPerInstanceAndNeverOutlivesIt` | Revert the memos (1 failure); make the snapshot `static` (**6 failures across neighbouring gates — the shared-state hazard, demonstrated**) | Own file | CLOSED |
| 91 | The PWA's `[REDACTED]` copy had no parity gate, while the three PHP/Gherkin spellings are deliberate falsifiers | New sibling gate `AuditRedactionSentinelParityTest` | `theRedactionSentinelIsTheSameOnBothDeployables` | Changed the sentinel PHP-side; changed the rendered node PWA-side while leaving both docblock occurrences intact — still red, so the comment strip is load-bearing | Own file | CLOSED |
| 92 | `findByUserId()` had no DBAL-failure conversion, so a store outage on `GET /sessions` surfaced as a raw 500 against a contracted 503 | Both reads run through one `convertingStoreFailure()` owning the single `try`/`catch` | `testConvertsADbalFailureOnTheSessionListingToSessionStoreUnavailable` | Invoke the read closure directly instead of through the converter | Reverted: 92 red, 93 green | CLOSED |
| 93 | The 503 was pinned by a mock of `createQueryBuilder()`, which is `return new QueryBuilder($this)` and cannot throw | The stub returns a real `QueryBuilder` and throws from `createQuery()`, so the failure arises inside the real `try` | `testConvertsADbalFailureOnTheGatesReadToSessionStoreUnavailable` | Move the execution outside the `try`. **Decisive: the OLD test stays GREEN under it (exit 0), the new one reds** | Reverted: 93 red, 92 green | CLOSED |

### Declared shared mutations

| ITEMs | Why they share | Mutation |
|---|---|---|
| 54 + 67 | Duplicate bullets describing one defect (`keepsAnActiveAdminWithout` answers "does any admin remain" instead of "is the target the last one"). Two artificial mutations would be worse than the rule they would satisfy. | `M54/67` |

### Mandatory mutation partitions (shared file, separate mutations)

| File | ITEM | Mutation |
|---|---|---|
| `MessengerConsumerContext.php` | 82 | empty-receivers assertion |
| `MessengerConsumerContext.php` | 84 | handled-message counter |
| `MessengerConsumerContext.php` | 86 | verbosity resolved by maximum |
| `SessionAdmissionGate.php` | 17 | admitted `Session` published on the Request |
| `SessionAdmissionGate.php` | 27 | native session invalidated on rejection |

A row here whose mutation cannot be separated is **not closed**; its bullet stays.

### Findings the wave produced that were not on the list

**A Behat scenario's outbox is emptied between HTTP requests, so N writes followed by one `consume N`
only ever reaches the worker with the last event.** `InMemoryTransport implements ResetInterface`
(`vendor/symfony/messenger/Transport/InMemory/InMemoryTransport.php:28`) and Symfony's
`services_resetter` runs between requests of the test client. Surfaced by ITEM 84's assertion the moment
it stopped being vacuous: `bank/count.feature` asked for two and the queue held one. Measured, and one
hypothesis refuted along the way — stopping the `messenger_worker` container changed nothing, so the
dev worker was not consuming them.

The two scenarios now consume after each write, which is what their own prose already claimed ("two
live deliveries"). They were not switched to `consume 1`: the count would have gone green by encoding a
harness artefact as if it were behaviour, and the assertions after it (`total == 2`) pass because the
projection replays the persistent `event_store` — a green for a reason the scenario does not state.

**A falsifier for vacuous assertions contained a vacuous assertion.** `assertTrue(true, …)` in
`RunOutputAbsenceTest`, caught by PHPStan (`method.alreadyNarrowedType`), not by review. Removed: the
step performs its own two assertions, so the count is real without adding one.

**Two bullets described their own mechanism wrongly, and the mutations proved it.** ITEM 45 said
`#[AsAlias]` was "pruned at compile time"; removing the attribute leaves the alias in the compiled
container and the test green, because `FileLoader::registerAliasesForSinglyImplementedInterfaces()`
binds any interface with exactly one implementer regardless. The attribute is redundant today and
becomes load-bearing only when a second implementer appears. ITEM 47 said `audit.feature` was the sole
reader and writer of `audit_log` and ran as one scenario; there is no such file, 18 features touch that
table, six write to it, and two independent hand-rolled workarounds for this very defect already exist
in the tree (a `TRUNCATE` in a Background, and two features that rename the table away and isolate by
correlation id).

**A pre-existing suite passed a gate that refused every request.** Measured before writing ITEM 19's
test: the mutation "refuse every session" left `SessionAdmissionGateTest` at 6 tests, exit 0.

**Measured, not estimated:** ITEM 47 makes more scenarios dirty the fixtures tracker and pay a restore.
The full Behat suite went from 45s to 40s across the wave — no cost materialised.

**Unverified and recorded as such:** invalidating the native session (ITEM 27) regenerates the session
id, so the 401 response is expected to carry a `Set-Cookie` — the same one `RevokeCurrentSessionController`
already emits on logout. Body and status are unchanged and the full suite is green, but the header was
not observed live. It belongs in this branch's adversarial pass.

## C. ITEM 21 — the eleven endpoints

Flipping `StrictRequestPayload`'s default to `['json']` restricts every site that
does not declare `acceptFormat`. All eleven are inspected individually; a single
intentional form/multipart consumer is a HALT, not a restriction.

| Endpoint | Declares `acceptFormat`? | form/multipart intentional? | Verdict |
|---|---|---|---|
| _eleven rows, filled before the default is flipped_ | | | |

Fails if fewer than eleven rows appear, if any row is intentional form/multipart,
or if the default is changed before this table is complete.

## D. Deleted-as-dead (8)

The defect no longer exists; nothing is implemented. Traceable evidence per row.

| ITEM | Why it is dead | Evidence |
|---|---|---|
| 22 | Deploy-order hazard now covered generally; `BankSnapshot::fromPrimitives()` ignores extra keys | `docs/deployment-guide.md` (Deploy process step 3, Rollback) |
| 24 | The images epic exists and is under way | `epics-images.md`, `sprint-status-images.yaml`, img-1-1 in `4043b5ab` |
| 34 | `useMercureRealtime` already degrades on a refused authorize | `useMercureRealtime.test.ts` (FORBIDDEN + UNAUTHORIZED cases) |
| 40 | **The bullet is false**, not resolved: `RequestEvent::setResponse()` does call `stopPropagation()` | `vendor/symfony/http-kernel/Event/RequestEvent.php:38-45` |
| 50 | Unique-violation now translated to `UserAlreadyMember` | `DoctrineMembershipRepository::save()`, commit `b12d8e72` |
| 52 | Same shared hook and tests as ITEM 34 | `useMercureRealtime.ts` |
| 78 | The "mirror parity" claim the bullet denounces does not exist in the code | `scrubSentryEvent.ts`, `redaction.ts` (parity scoped to the denylist) |
| 90 | The swallowing try/catch was removed | `SqlQueryContext.php`, commit `66517758` |
