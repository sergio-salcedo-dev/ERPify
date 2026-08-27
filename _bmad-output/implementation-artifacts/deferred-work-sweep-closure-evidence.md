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
| _rows appended as each bullet closes_ | | | | | | |

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
