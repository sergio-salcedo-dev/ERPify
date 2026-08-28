# Deferred-work sweep — closure evidence

Auditable record for the sweep. It exists so that **every disappearance from the
register has a proof, every proof has a mutation, and the final register can be
audited against the base state**. It is NOT part of `deferred-work.md`: the
register stays pending-only and never gains a changelog.

Base commit: `f86b2662` (the merge of PR #866) · Branch: `chore/deferred-work-sweep-uqmh`

## A. Register integrity

Produced mechanically by `deferred-work-sweep-register-gate.py` — never by hand.
Measured on the branch head after Wave G: `OK    every register invariant holds`, exit 0.

| Property | Expected | Measured |
|---|---:|---|
| base count (`f86b2662`) | 98 | **98** |
| deleted by this branch | 45 | **45** |
| added | 0 | **0** |
| surviving head count | 53 | **53** |
| survivors with modified text | 1 (ITEM 41 only) | **1** |

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

## B. Closure rows — 36 rows covering the 37 closed bullets (`54+67` is one row for two)

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
| 86 | Verbosity resolved by last matching flag, so `-vvv --verbose` degraded to VERY_VERBOSE | `verbosityFrom()` takes the maximum | `MessengerVerbosityResolutionTest::testTheStrongestVerbosityFlagWinsOverTheLastOneDeclared` | `\max($verbosity, $level)` → `$level` | Re-measured after the class split: 86 red (1 failure), 82/84 green — recorded here as `exit 2` first, which PHPUnit reserves for an ERROR; a failed `assertSame` is a FAILURE and exits 1, so the number named an outcome the stated mutation cannot produce | CLOSED |

| 2 | Nothing pinned that the persisted `operation` equals `AuditWriteOperation::name`, nor that the three PWA literals match the enum | New `AuditWriteOperationParityTest` comparing the enum's case names against the values parsed out of the PWA object literal, plus a pin that the payload guard is still derived from it | `theWriteOperationVocabularyIsTheSameOnBothDeployables` | Renamed a case PHP-side; renamed the literal PWA-side; replaced the derived guard with a hand-written set. Negative control: a commented-out value stays green | Own file | CLOSED |
| 17 | The gate loaded the admitted `Session`, discarded it, and both controllers re-ran the identical query | The gate publishes the row on the **Request** (never a singleton or static — FrankenPHP worker mode); controllers read it, falling back to a fresh lookup | `SessionAdmissionGateTest::testItPublishesTheAdmittedSessionOnTheRequest` | Collapse the gate back to the discarded `instanceof` and restore both controllers | Reverted: 17 red, 27 and 19 green | CLOSED |
| 18 | No scenario proved an expired session leaves `GET /sessions`; covered only at adapter level | Scenario seeding an `ACTIVE` row with `expires_at` in the past, with a row-count assertion so the seed cannot be vacuous | The scenario | Behat, verified in the serial run: 470 scenarios green | Own file | CLOSED |
| 19 | No unit test admitted, so inverting the gate's condition reddened nothing | `testItAdmitsALiveSession` + the publication test | `SessionAdmissionGateTest::testItAdmitsALiveSession` | Make the gate refuse every session. **Baseline measured first: the same mutation on the original gate passed the original suite — 6 tests, exit 0** | Reverted: 19 red, 17 and 27 green | CLOSED |
| 27 | Rejecting a session left the native cookie alive, so a revoked-cookie bearer cost a DB round-trip on every request while an anonymous caller cost none | Both refusal branches route through `refuse()`, which invalidates the native session before throwing — mirroring `RevokeCurrentSessionController` | `testItDropsTheNativeSessionWhenTheSessionIsNoLongerActive` and `…WhenThereIsNoCorrelation`, one per branch — plus `session.feature`'s revoked-session scenario, which asserts the `Set-Cookie` the invalidation puts on the 401, so the falsifier now reaches the wire and not only the unit seam | Replace both `refuse()` calls with the bare throw | Reverted: 27 red (2 unit failures + 1 Behat scenario), 17 and 19 green | CLOSED |
| 44 | `MAX_FIELD_LENGTH = 100` mirrored a `VARCHAR(100)` width with nothing comparing them | Functional test asking `information_schema.columns` for both columns | `AuditLogFieldWidthContractTest::eachGuardedColumnIsAsWideAsTheEntryGuard` | `MAX_FIELD_LENGTH` 100 → 101 | Own file | CLOSED |
| 45 | The port's alias had no consumer, so the test built the writer by hand and the wiring was unpinned | A method resolving `AuditLogWriter` from the container; the stale docblock corrected | `AuditLogWriterIdempotencyTest::testThePortResolvesToTheDbalWriter` | **Removing `#[AsAlias]` is a NON-falsifier (exit 0)** — the binding comes from `registerAliasesForSinglyImplementedInterfaces()`. The working mutation adds a second implementer that redirects the port | Own file | CLOSED |
| 47 | Raw-DBAL writes raise no ORM flush, so `FixturesChangeTracker` stayed clean and rows leaked across features | `TestDebugDataHolder::addQuery()` marks the tracker when the leading keyword is INSERT/UPDATE/DELETE/TRUNCATE, before the existing filter and touching none of its accounting | `testRawWriteMarksTheFixturesTrackerEvenWhenTheQueryIsFilteredOut` and `testPlainSelectLeavesTheFixturesTrackerClean` | Delete the marking (write test reds); mark unconditionally (read test reds) | Own file | CLOSED |
| 75 | The registry re-swept all of `api/src` on every call, inside a gate CI runs per PR | Four lazily-computed **instance** memos; the class stops being `readonly` because PHPStan refuses a lazy write to a readonly property | `PersonResourceErasureRulesGateTest::theSourceCorpusIsOneSnapshotPerInstanceAndNeverOutlivesIt` | Revert the memos (1 failure); make the snapshot `static` (**6 failures across neighbouring gates — the shared-state hazard, demonstrated**) | Own file | CLOSED |
| 91 | The PWA's `[REDACTED]` copy had no parity gate, while the three PHP/Gherkin spellings are deliberate falsifiers | New sibling gate `AuditRedactionSentinelParityTest` | `theRedactionSentinelIsTheSameOnBothDeployables` | Changed the sentinel PHP-side; changed the rendered node PWA-side while leaving both docblock occurrences intact — still red, so the comment strip is load-bearing | Own file | CLOSED |
| 92 | `findByUserId()` had no DBAL-failure conversion, so a store outage on `GET /sessions` surfaced as a raw 500 against a contracted 503 | Both reads run through one `convertingStoreFailure()` owning the single `try`/`catch` | `testConvertsADbalFailureOnTheSessionListingToSessionStoreUnavailable` | Invoke the read closure directly instead of through the converter | Reverted: 92 red, 93 green | CLOSED |
| 93 | The 503 was pinned by a mock of `createQueryBuilder()`, which is `return new QueryBuilder($this)` and cannot throw | The stub returns a real `QueryBuilder` and throws from `createQuery()`, so the failure arises inside the real `try` | `testConvertsADbalFailureOnTheGatesReadToSessionStoreUnavailable` | Move the execution outside the `try`. **Decisive: the OLD test stays GREEN under it (exit 0), the new one reds** | Reverted: 93 red, 92 green | CLOSED |

| 20 | 415 fell through to the generic `http-error` bucket, so a client routing on `body.type` could not tell it apart | `unsupported-media-type` added to `HTTP_STATUS_TYPE_MAP` + a row in the error contract; the mirror test now asserts the marker-free set explicitly instead of degrading to a subset check | `ProblemDetailsFactoryTest::testUnsupportedMediaTypeHttpExceptionCarriesItsOwnTypeRatherThanTheGenericBucket` | Delete the map entry (4 failures); add an undeclared marker-free 418 (2 failures — proving the new set assertion is live) | Own file | CLOSED |
| 21 | `acceptFormat: ['json']` repeated at every call site instead of being the default | Default flipped; **all 13** declarations deleted | `StrictRequestPayloadTest::itRefusesAFormEncodedBodyWithoutBeingAskedTo` | Revert the default to `null` — the form body is then actually read | Own file | CLOSED |
| 37 | Two concurrent erasures of one account raced under READ COMMITTED and failed loudly instead of no-op | `findByIdForUpdate` (PESSIMISTIC_WRITE) added to the port and used by the use case; `findById` untouched | `BankAccountSubjectErasureRaceFunctionalTest::aCompetingErasureIsRefusedTheSubjectRowWhileThisOneHoldsIt` | `findByIdForUpdate` → `findById`: the competitor is no longer blocked and the erasure dies with `DekDestroyed` — the reported symptom exactly | Own file | CLOSED |
| 54+67 | The last-admin guard answered "does another admin exist" instead of "is this the one whose removal drains the set", so a zero-admin state refused an unrelated non-admin with a misleading 409 | The adapter checks membership before the remaining-set question; the in-memory double mirrors it; the port docblock states the question | `ChangeUserStatusTest::testSuspendingAnIdentityOutsideTheActiveAdminSetIsNotRefusedAsTheLastAdministrator` + the Doctrine counterpart | Restore both adapter and double | `ChangeUserRolesTest` green (14 tests) — it pre-filters, so both semantics agree there | CLOSED |
| 55 | The three pre-identity login answers were asserted apart, never against each other | Functional test comparing all three whole responses, key order included | `LoginPreIdentityOpacityFunctionalTest::testTheThreePreIdentityFailuresAnswerOneIndistinguishableResponse` | Let the handler pass the real exception message through, so the title diverges | Own file | CLOSED |
| 57 | One 503 branch invalidated the session, its sibling did not | `getSession()->invalidate()` before the throw, mirroring the sibling — **one statement, no new DI** | `ClearLockoutOnLoginSuccessTest::testAStoreFailureWhileClearingBecomesARetryable503AndDropsTheSession` | Delete the invalidation. The old stub-driven test would have passed either way, so it was rebuilt on real collaborators | Own file | CLOSED |
| 61 | Every accept-rejection test failed before the mutations, so nothing pinned the post-flip failure | One test using the existing `onSave` hook to throw after the flips | `AcceptInvitationTest::itPropagatesAStoreFailureRaisedAfterTheFlipsAndAnnouncesNothing` | Publish before saving; wrap the save in a catch that rethrows `InvalidToken` | Own file | CLOSED |
| 62 | The five-case dead-token enumeration never presented a REVOKED invitation | Sixth case seeded and revoked | `InvitationAcceptFunctionalTest::testAllSixDeadTokenCasesReturnOneUniformInvalidToken` | Narrow the guard to `ACCEPTED ===`, admitting REVOKED past it | Own file | CLOSED |
| 63 | The reset endpoint's four dead links were checked per case, never against each other; and the supersede ordering was unpinned | Functional opacity test + an `onSave` hook on the token double, asserting the old token is gone and the new one survives | `ResetPasswordDeadTokenOpacityFunctionalTest::testTheFourDeadLinksAnswerOneIndistinguishableResponse` and `RequestPasswordResetTest::testTheSupersedeDropsThePendingTokenBeforeWritingTheNewOne` | An extra `ProblemDetails` member with status, type and title **unchanged** — precisely the divergence the Behat scenario cannot see; and swapping save ahead of delete | Own file | CLOSED |
| 64 | The `INVALID_RESET_LINK` wall offered sign-in but never said why it might work | One sentence of English copy | `AccessWall.test.tsx::"tells the reset wall's visitor their new password may already be live"` | Revert the description | Own file | CLOSED |
| 72 | The sidebar painted "Users" for every role though `users.read` is ADMIN-only | `permission?` on the nav model + one pure filter feeding **both** render sites | `backOfficeMenuPermissions.test.tsx` | Revert the layout to the unfiltered constant (proves the render site is filtered, not just the function); delete the permission; delete the parent re-point | Own file | CLOSED |
| 76 | `resourceErased` was guarded as strictly as `id`, so its absence failed the whole envelope | Optional in the wire guard, defaulted at the mapper; the domain type stays strictly boolean | `ApiAuditTimelineRepository.test.ts` + the detail counterpart, five cases each | Widen the guard (8 red); restore the strict guard (2 red); drop the mapper default (2 red) | Own file | CLOSED |
| 79 | `failed_attempts` sat in the closed exemption list with no written argument | The argument written — and **not** the one `status` uses | none — docblock, and it says so | n/a | Own file | CLOSED |
| 53 | `SingleUseToken::verify()` answers `true` repeatedly until the TTL, so a consumer that validates the token and then fails **before** retiring the digest leaves the link replayable inside the window. The bullet does not ask for a change to the VO — it states a contract its II-4/II-5 consumers must meet: retire-then-act in one transaction, idempotent | **None.** Measured: both consumers already meet it. `AcceptInvitation:60-86` loads the invitation `findByIdForUpdate`, retires it with `accept()` (`SENT → ACCEPTED`) and saves inside one `transactional()`; `CompletePasswordReset:93-101` calls `tokens->consume()` — a conditional delete guarded by its affected-row count — **before** `resetPassword()`, in one transaction under the user's row lock. `git grep '\->verify(' -- api/src` finds exactly two callers of the two entity `verify()` methods, so the contract has no third consumer; `SendInvitation`, `ResendInvitation` and `RequestPasswordReset` mint and are not bound by it | `AcceptInvitationTest` + `InvitationAcceptFunctionalTest` (the mid-flight failure case ITEM 61 added) and `CompletePasswordResetTest` (the delete-before-save ordering ITEM 63 added) | **None of its own** — no production line changed | **N/A by construction — see the note below.** Reverting ITEM 61's or ITEM 63's mutation reddens their own falsifiers, and those same falsifiers are what pin this contract | CLOSED BY VERIFICATION |

**Four rows carry no independent mutation, and the count is stated here rather
than left for a reader to discover.** An earlier version of this paragraph named
ITEM 53 as the single exception and asserted the Definition of Done line *"cada
fila tiene mutación independiente"* held for "the 36 rows that changed code".
The adversarial pass measured that claim false against this very table, which is
the most useful thing it could have found: the document a reviewer audits *by*
was asserting a completeness property it did not have, in the paragraph
congratulating itself for honesty. The real accounting:

| ITEM | Why it carries no independent mutation | Falsifier it does have |
|---|---|---|
| 53 | Verification, not a change — both consumers already satisfied the contract | ITEMs 61 and 63's tests, which pin the two consumers |
| 79 | A written argument in a docblock; there is no behaviour to mutate | **None** |
| 80 | A recorded accepted cost in `PRODUCTION_SECURITY_CHECKLIST.md` §7 | **None** |
| 73 | The CI step IS the change, so it cannot also be its own falsifier | The mechanism, verifiable by reading `ci.yml` + `make/php-test.mk` + `config.mk`, never by a local red |

So **32 of the 36 rows** carry a mutation that reddens their own falsifier and
leaves the others green; that is what the DoD line covers, and the four above are
exempted by name rather than counted inside it. Two of them (79, 80) are prose
closures with nothing that can go red at all — legitimate, because the bullets
asked for an argument to be written down, but they are the weakest closures here
and weaker than 53, which at least names falsifiers. ITEM 73's mechanism is real
and gating (`.github/workflows/ci.yml` runs `make php.unit` in `api-test` with no
`if:` and no `continue-on-error`, `make/config.mk` pins `PHPUNIT_MEMORY_LIMIT` at
512M, `phpunit.dist.xml` sets `failOnWarning`, and `ci-success` needs `api-test`),
so the bullet is genuinely closed — just not by the standard its row claims.

**A cost that came with ITEM 73 and was not weighed:** `api-test` still declares
`timeout-minutes: 15` while the job now runs the unit suite **twice**, once
uninstrumented and once under Xdebug coverage. The budget was not raised. Left as
measured rather than adjusted, because guessing at a CI timeout is how a flaky
red gets manufactured; the number to raise it to is the one the first post-merge
run reports.

If a third consumer of `SingleUseToken::verify()` ever appears, nothing in this
branch refuses it the replay window — a gap the register no longer records, and
stated here instead.

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

**Measured, and the measurement is weaker than a single number suggests.** ITEM 47 makes more scenarios
dirty the fixtures tracker and pay a restore, so the mechanism for a slowdown is real. Four separate runs
of the full suite across the branch reported 45s, 40s, 36.22s and 45.14s — the spread between them is larger
than any effect being claimed, and the last two bracket the first, so what these runs support is "no cost
materialised", never a speed-up. A wall-clock sample on a shared dev stack is not a benchmark, and quoting one figure as
if it were is the kind of asserted performance claim this repo refuses; the honest statement is that the
suite did not get slower, and that a real measurement would need repeated timed runs against a quiet host.

**Unverified and recorded as such:** invalidating the native session (ITEM 27) regenerates the session
id, so the 401 response is expected to carry a `Set-Cookie` — the same one `RevokeCurrentSessionController`
already emits on logout. Body and status are unchanged and the full suite is green, but the header was
not observed live. It belongs in this branch's adversarial pass.

## C. ITEM 21 — every `StrictRequestPayload` site, one row each

Flipping `StrictRequestPayload`'s default to `['json']` restricts every site that
does not declare `acceptFormat`. The governing decision demanded each site be
inspected individually and a single intentional form/multipart consumer treated as
a HALT, not as something to restrict.

**Measured at the base commit `f86b2662`: thirteen sites, not eleven, and all
thirteen already declared `acceptFormat: ['json']` verbatim.** So the flip
restricted nothing at runtime; it is a DRY consolidation that closes a *latent*
hole rather than a live one. The bullet's "once sitios" was not a competing
measurement of the same thing: it was written by `08f8199b` (2026-07-23) and
counted the *repetition* — eleven was the exact site count on that day, and its
stated hazard was prospective ("un controlador nuevo que la omita"). Two sites
were added in the five weeks since — `BankAccountIbanLookupController` and
`ChangeMyPasswordController` — and both declared the list, so the hazard never
fired. That is the difference between a hole nobody fell into and a hole that is
closed.

| Endpoint | Declares `acceptFormat`? | form/multipart intentional? | Verdict |
|---|---|---|---|
| `POST /api/v1/backoffice/banks` — `api/src/Backoffice/Bank/Infrastructure/Controller/BankPostController.php:29` | YES — `acceptFormat: ['json']` | No. Maps `CreateBankCommand` (two `string` members); `BankCreateAcceptsJsonOnlyFunctionalTest` already pinned multipart and form-encoded to 415 at base | unaffected by the flip |
| `PUT /api/v1/backoffice/banks/{id}` — `BankPutController.php:36` | YES | No. `UpdateBankCommand` = two `string`s, no `UploadedFile` member | unaffected by the flip |
| `POST /api/v1/backoffice/bank-accounts/iban-lookup` — `BankAccountIbanLookupController.php:38` | YES | No. `LookupBankAccountByIbanQuery` = one `string $iban` | unaffected by the flip |
| `PATCH /api/v1/backoffice/bank-accounts/{id}/status` — `BankAccountPatchStatusController.php:36` | YES | No. `ChangeBankAccountStatusCommand` = one backed enum | unaffected by the flip |
| `POST /api/v1/backoffice/bank-accounts` — `BankAccountPostController.php:38` | YES | No. `CreateBankAccountCommand` = strings + enum | unaffected by the flip |
| `PUT /api/v1/backoffice/bank-accounts/{id}` — `BankAccountPutController.php:36` | YES | No. `UpdateBankAccountCommand` = strings + enum | unaffected by the flip |
| `POST /api/v1/me/password` — `ChangeMyPasswordController.php:76` | YES | No. `ChangeMyPasswordRequest` = two `string`s; the class docblock already states "JSON-only … so no form post can reach it" | unaffected by the flip |
| `PATCH /api/v1/backoffice/users/{id}/roles` — `UserPatchRolesController.php:57` | YES | No. `ChangeUserRolesRequest` = `array $roles` | unaffected by the flip |
| `PATCH /api/v1/backoffice/users/{id}/status` — `UserPatchStatusController.php:45` | YES | No. `ChangeUserStatusRequest` = one enum | unaffected by the flip |
| `POST /api/v1/backoffice/reset-password` — `CompletePasswordResetController.php:59` | YES | No. `ResetPasswordRequest` = two `string`s; `ResetPasswordForm.tsx` is `onSubmit`-driven with no `action=`/`method=` | unaffected by the flip |
| `POST /api/v1/backoffice/forgot-password` — `RequestPasswordResetController.php:45` | YES | No. `ForgotPasswordRequest` = one `string $email`; `ForgotPasswordForm.tsx` is `onSubmit`-driven | unaffected by the flip |
| `POST /api/v1/backoffice/invitations/accept` — `AcceptInvitationController.php:64` | YES | No. `AcceptInvitationRequest` = two `string`s | unaffected by the flip |
| `POST /api/v1/backoffice/invitations` — `CreateInvitationController.php:55` | YES | No. `InviteUserRequest` = `string $email` + `array $roles` | unaffected by the flip |

**No HALT condition anywhere**, and the evidence is wider than the DTOs: `UploadedFile`
appears in exactly two files in `api/src` (`TransportOnlyUploadedFileDenormalizer`,
which exists to refuse body-described files, and `Shared/Images/Application/UploadImage`,
which has no HTTP surface); there is no `#[MapUploadedFile]` anywhere in `api/src`;
`x-www-form-urlencoded` appears nowhere in the repository; `FormData`/`multipart`
appear nowhere in `pwa/src` or the e2e suite; and Behat's two form-capable steps are
classified `idle` in `api/.behat-step-vocabulary:163-164` — every feature posts
`with body:`.

**Cross-check of the flip's actual effect.** `git diff f86b2662..HEAD -- api/`
deletes exactly fourteen lines containing `acceptFormat`: the thirteen call-site
declarations (one per file above) plus the constructor's own former default. The
thirteen deleted declarations equal the thirteen sites found, one-to-one, no
residue in either direction; HEAD introduces no new call site. Direct
`#[MapRequestPayload]` usage in `api/src` is **zero**, so the sweep's scoping has
no unaffected parallel population — `StrictRequestPayloadGateTest` forbids the bare
attribute.

**A drift this measurement found, fixed in the same branch.**
`PRODUCTION_SECURITY_CHECKLIST.md` carried a `[x]`-checked assertion that "all
eleven `#[StrictRequestPayload]` sites declare `acceptFormat: ['json']`" and
prescribed the verification "`git grep -n '#\[StrictRequestPayload' -- api/src` —
every attribute site must carry the format list". After the flip **zero** sites
carry the list, so the checklist's own stated pass criterion failed against its own
tree — a security assertion made false by this branch's change, which is exactly
the direction the repo requires a checklist update for. The bullet now states the
guarantee where it actually lives (the type's default), and gives a recipe that
holds: the old grep could not have worked anyway, since it matches the eleven
docblock mentions of the attribute alongside the thirteen real sites. The stale
"declaring `acceptFormat: ['json']`" prose in `ProblemDetailsFactory`'s 415
docblock was corrected with it.

## C-bis. End-to-end verification, and why its first three readings were noise

The e2e suite is the one gate this branch had never run, and running it took four
readings to produce a verdict rather than a number — each earlier one refuted by
measurement, not by argument.

| Reading | Result | What it actually measured |
|---|---|---|
| `make pwa.test` | e2e half `EACCES` | `pwa/.next-e2e` is root-owned in this worktree; Playwright's own `webServer` cannot build. Not a code signal at all |
| Live stack, `PLAYWRIGHT_BASE_URL=…:35292` | 160 failed | A dead port — the `php` container had restarted and its ephemeral HTTPS port had moved to 35302 |
| Live stack, `…:35302` | 33 failed | Real port, broken stack: `DEFAULT_URI=https://localhost:0`, because the worktree stack was brought up on ephemeral ports and that variable interpolated the literal `0`, so every absolute URL the app generates is unreachable |
| `HTTPS_PORT=8443 make docker.up`, then `…:8443` | **1 failed, 173 passed** | The suite |

The one survivor is `banks-delete-preconditions.spec.ts` — a **mocked-API** spec,
failing a focus assertion after a refresh. It is **pre-existing**, established by
A/B rather than by the plausible argument that this branch does not touch banks:
with the base (`f86b2662`) versions of all six PWA source files this branch
changes installed in place, the same spec still fails 6 of 6, three runs. The
sources were restored by byte copy, not by `git checkout`, and the restore was
verified as an empty diff.

Two properties of that spec worth recording rather than smoothing over: in the
full parallel run **one** of its six cases fails, and in isolation **all six** do,
identically across three consecutive runs. So that spec is order- or state-
dependent rather than flaky, and neither shape is this branch's to fix.

**And the suite as a whole is not a reliable local signal, which is the honest
conclusion rather than a green.** A second full run against the same stack and the
same head reported **4 failed / 170 passed**, and its failing set is **disjoint**
from the first run's: `banks-real-api`, `banks` (cards-view delete),
`shared/rate-limit` (go-back) and `frontoffice/landing` (mobile sign-in CTA), with
the first run's `banks-delete-preconditions` case passing. Nothing overlaps. Two
runs of one head that disagree completely on which tests fail are measuring the
environment — a dev-mode Next server behind a proxy, eleven parallel workers, and
a shared dev database the Behat suite resets out from under it — not the code.

So what this branch can honestly claim about e2e is: it was run (it never had
been), the one failure that reproduced was proven pre-existing against the base by
A/B, and the rest do not reproduce. It cannot claim a green, and the DoD line is
marked accordingly. CI, which runs against a purpose-built stack, is the reader
that can settle it.

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
