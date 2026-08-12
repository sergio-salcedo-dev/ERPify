---
title: 'Redact ip/user_agent on anonymous-actor audit rows naming an erased subject'
type: 'bugfix'
created: '2026-08-12'
status: 'done'
baseline_commit: '781c75a2e66548ef8a434017dda8fe5c965fad09'
review_loop_iteration: 0
context:
  - '{project-root}/docs/adr/audit-activity-log.md'
  - '{project-root}/api/.audit-resource-types'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** An `audit_log` row whose actor is `anonymous` and whose `resource_id` names a person
survives that person's GDPR erasure still carrying `ip` and `user_agent`. Neither pass reaches it: the
actor pass matches `actor_id`, which is NULL on these rows, and the resource pass deliberately leaves
the actor columns alone. The rows are live, not hypothetical — `USER_LOCKED` and
`PASSWORD_RECOVERY_THROTTLED` are written from self-service paths where the acting party and the named
subject are the same person, so the surviving IP may be the subject's own. No detective control can
ever report it: `DbalPersonResourceReferences` reads only `resource_erased = FALSE` rows, so the pass
that pseudonymises `resource_id` is precisely what removes the row from the reconciler's sight.

**Approach:** Widen the single resource-axis `UPDATE` so it also redacts `ip`/`user_agent` to the
shared `[REDACTED]` sentinel, guarded per column by `actor_type = 'anonymous' AND <col> IS NOT NULL`
inside the `SET`. One statement, one round trip, no new mutation policy on the table.

## Boundaries & Constraints

**Always:**
- The predicate lives in `CASE WHEN` inside the `SET`, never as a `WHERE` conjunct — a `WHERE` filter
  would stop pseudonymising `resource_id` on admin-written rows, deleting behaviour correct today.
- `actor_type = :anonymous`, never `actor_id IS NULL` — the latter also matches `system` rows.
- `AND <col> IS NOT NULL` per column: a never-captured value stays NULL, so "redacted" remains
  distinguishable from "never captured". That distinction is the sentinel's entire reason to exist.
- `actor_erased` is not written. D4.1 defines it as "identified and then erased"; raising it on a
  never-identified row corrupts the flag and its cross-check with `GDPR_ERASURE_EXECUTED`.
- One `UPDATE`, one round trip — `erase.feature` asserts a 20-query budget that a second statement breaks.
- The sentinel becomes one shared literal both anonymisers read; it is compliance-critical and must stay
  byte-identical (the D4.1 invariant is asserted over the literal `'[REDACTED]'`).

**Ask First:**
- Any backfill of subjects erased before this ships.
- Any fourth mutation policy on `audit_log`, or a new column/flag.
- Any change to what `actor_erased` or `resource_erased` mean to a reader.

**Never:**
- Backfill, blanket `UPDATE`, or any statement not narrowed by `(resource_type, resource_id)`.
- Redact on `user` / `api_key` / `system` rows — that destroys a third party's evidence.
- Touch `metadata`, `actor_id`, or `actor_erased` on this pass.
- Introduce a pseudonym→subject crosswalk (D4 bans it).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Anonymous row names the subject | `actor_type=anonymous`, `actor_id=NULL`, `ip`/`user_agent` set, `resource=(User, subject)` | `resource_id`→pseudonym, `resource_erased=TRUE`, `ip`=`user_agent`=`[REDACTED]`, `actor_erased` stays FALSE | N/A |
| Admin wrote the row | `actor_type=user`, admin `actor_id`+`ip` | `resource_id`→pseudonym, `resource_erased=TRUE`; all four actor columns untouched | N/A |
| Anonymous, no header captured | `actor_type=anonymous`, `ip IS NULL` | `ip` stays NULL — never the sentinel | N/A |
| System actor names the subject | `actor_type=system`, `ip IS NULL` | `ip` stays NULL | N/A |
| Anonymous row, non-person resource | `actor_type=anonymous`, `resource=(Bank, …)` | Row untouched on every column | N/A |
| Malformed pseudonym | `'not-a-uuid'` | `InvalidUuidException` before the driver | Existing `Uuid::ensure()` guard |

</frozen-after-approval>

## Code Map

- `api/src/Shared/Audit/Infrastructure/Persistence/DbalAuditResourceAnonymiser.php` -- the one statement to widen; its docblock argues at length for leaving all four actor columns and must be rewritten, not appended to.
- `api/src/Shared/Audit/Infrastructure/Persistence/DbalAuditActorAnonymiser.php` -- holds `[REDACTED]` as a private const today; the sibling statement, unchanged.
- `api/src/Shared/Audit/Domain/ActorType.php` -- backed enum; `ANONYMOUS = 'anonymous'` is the token bound into the statement.
- `api/tests/Functional/Shared/Audit/AuditResourceAnonymiserFunctionalTest.php` -- already guards the over-reach direction (`"a third party's ip is not collateral"`); needs the under-reach assertions.
- `api/features/backoffice/users/erase.feature` -- acceptance witness for the `User` person type; line 117 carries the 20-query budget canary.
- `docs/adr/audit-activity-log.md` -- D4 "Asimetría de columnas" (the `no toca` sentence this change contradicts), D4.1:336-341 (the "único *tell*" sentence, which reads as a biconditional and becomes false) and D4.1:395-396 (the already-discarded `ip = '[REDACTED]'` derivation, which is why nothing depends on the reverse implication).
- `PRODUCTION_SECURITY_CHECKLIST.md:227-232`, `docs/rules/security.md:145`, `docs/rules/database.md:56` -- all three name `audit:gdpr:erase` as the sentinel's writer; each becomes incomplete once a second statement writes it.
- `_bmad-output/implementation-artifacts/deferred-work.md` -- the bullet this closes (delete it, do not annotate).

## Tasks & Acceptance

**Execution:**
- [x] `api/src/Shared/Audit/Domain/AuditRedaction.php` -- new: `public const string SENTINEL = '[REDACTED]'`. Its docblock states the "sentinel, not NULL" rationale AND that this centralises the **literal only** — never a licence to write it. Which rows may receive it stays each statement's own decision, because the two writers do different things: one anonymises the actor axis, the other anonymises the resource axis and redacts two context columns as a consequence.
- [x] `api/src/Shared/Audit/Infrastructure/Persistence/DbalAuditActorAnonymiser.php` -- drop the private const, read `AuditRedaction::SENTINEL`; correct the docblock sentence that lists `anonymous` among the never-captured cases -- it is already false today (`SealedAuditEntryFactory` captures the client IP on any in-flight request whatever the actor type) and this change would make it doubly so.
- [x] `api/src/Shared/Audit/Infrastructure/Persistence/DbalAuditResourceAnonymiser.php` -- add the two `CASE WHEN` clauses to the `SET`; bind `:anonymous` from `ActorType::ANONYMOUS->value` and `:redacted`; rewrite the docblock to carry the split argument (the `actor_erased` half survives, the third-party half does not on an anonymous row).
- [x] `api/tests/Functional/Shared/Audit/AuditResourceAnonymiserFunctionalTest.php` -- extend the seed with an anonymous row, an anonymous row with NULL `ip`/`user_agent`, and a system row; assert each I/O-matrix line, including that `actor_erased` stays FALSE on the redacted row.
- [x] `api/features/backoffice/users/erase.feature` -- new scenario seeding its own subject plus an anonymous row: assert the seed inserted 1 row, then pair the NEGATIVE (no row holds the seeded ip) with the POSITIVE (that row id holds the sentinel, `actor_erased = FALSE`).
- [x] `docs/adr/audit-activity-log.md` -- restate D4's asymmetry as ONE rule with its exception, not as a rule plus a patch: *the resource pass does not write the actor columns, except `ip`/`user_agent` when the actor is `anonymous`, because there is then no identified third party to protect and those values may be the erased subject's own*. In D4.1, make the "único *tell*" inference **unidirectional** — after this change `ip = '[REDACTED]'` may equally mean `resource_erased = TRUE ∧ actor_type = anonymous ∧ actor_erased = FALSE`. Amend in the file's existing language (Spanish), not English: a bilingual ADR is worse than either.
- [x] `PRODUCTION_SECURITY_CHECKLIST.md`, `docs/rules/security.md`, `docs/rules/database.md` -- each names `audit:gdpr:erase` as the sentinel's sole writer; record the second writer in all three so none reads as exhaustive. Word it as **two mutation paths sharing one normative sentinel**, never as "two redaction policies" — D4 declares the mutation-policy set closed at three, and the looser phrasing opens a fourth by wording alone.
- [x] `_bmad-output/implementation-artifacts/deferred-work.md` -- delete the resolved bullet.

**Acceptance Criteria:**
- Given a subject named as the resource of both an anonymous row and an admin-written row, when the identity is erased, then the anonymous row's `ip`/`user_agent` read `[REDACTED]` while the admin row's read the admin's real values.
- Given the erasure ran, when any redacted row is read, then `actor_erased` is FALSE and no `GDPR_ERASURE_EXECUTED` pseudonym cross-check is invalidated.
- Given each of these FIVE mutations is applied **one at a time** — (1) delete the `ip` `CASE` clause only; (2) delete the `user_agent` `CASE` clause only; (3) drop `actor_type = :anonymous` from both; (4) drop `IS NOT NULL` from both; (5) additionally set `actor_erased = TRUE` — when the suite runs, then at least one named assertion fails for each, and the failing assertion is recorded per mutation. The two `CASE` clauses are mutated **separately and never together**: deleting both at once cannot distinguish a diff that wired `ip` and forgot `user_agent`.
- Given the erasure endpoint is exercised, when the query budget is measured, then `erase.feature`'s existing count is unchanged.

## Adversarial pass (recorded before the PR was opened)

Two independent hostile reads of the full diff, run in fresh contexts with no prior conversation:
**Blind Hunter** (`bmad-review-adversarial-general`) and **Edge Case Hunter**
(`bmad-review-edge-case-hunter`). Both were given the diff and the ten claims the author wanted broken.
Disposition of every finding below; nothing was dropped silently.

**Fixed in this PR**

| # | Finding | Fix |
|---|---------|-----|
| 1 | **The change's central justification was FALSE.** "On an anonymous row there is no third party, so the ip is the subject's own" — but `USER_LOCKED` seals the ip of whoever submitted the failing password and `PASSWORD_RECOVERY_THROTTLED` that of whoever exhausted the budget. In the canonical brute-force case that is the **attacker**, not the subject. The wrong reason had been replicated into four normative documents. | Argument rewritten in the docblock and all four docs: the row records **no discriminant** for whose address it holds, so this errs toward erasure, and the forensic cost — a stranger's address destroyed with `actor_erased = FALSE` recording nothing — is now stated, not hidden. |
| 2 | **`''` defeated the guard.** `SealedAuditEntryFactory` only null-checks the header, so a bare `User-Agent:` seals `''`; `'' IS NOT NULL` is true, so the sentinel was written over a value never captured — precisely what the guard existed to prevent. | Guard became `COALESCE(col, :blank) <> :blank`, plus a seeded row with `user_agent = ''`. |
| 3 | **`docs/rules/database.md` contradicted itself inside one bullet** — opened "closed set of **two** mutation policies", then enumerated a third. | Opening corrected to three (the ADR already said three; the file was stale). |
| 4 | **The two per-column guards were not independently pinned.** Every seeded row had both columns alike, so pasting one arm's guard into the other, or cross-wiring them, passed everything. | Added an anonymous row with `ip` set and `user_agent` NULL; added `user_agent` assertions on the admin and system rows. |
| 5 | `AuditRedaction` was an instantiable class used as a constant namespace. | Private constructor. |

**Found by the author while re-measuring, not by either reviewer**

- **`IS NOT NULL` had become a dead conjunct.** Once `<> :blank` was added, `NULL <> ''` is already unknown
  and falls to the `ELSE`, so no mutation of `IS NOT NULL` could ever redden — a predicate that reads as a
  guard while proving nothing. Collapsed into the single `COALESCE` form. This surfaced only because the
  mutation battery was re-run **after** the last edit rather than trusted from its earlier green.

**Deferred (real, not caused by this change)** — recorded in `deferred-work.md`:
no CHECK constraint ties `actor_type` to `actor_id` nullability, so illegal rows are representable by raw
SQL; `user_agent` is client-forgeable as the literal `[REDACTED]`; a row committed after the `UPDATE` but
before the erasure commits keeps its metadata.

**Claims the reviewers attacked and could NOT break:** `SET`-not-`WHERE` preserves admin-row behaviour
exactly; `actor_type = :anonymous` cannot match another type (`varchar`, byte-exact, closed enum); the
statement stays idempotent on re-run including over the sentinel; the 20-query canary is untouched; the
Behat scenario is not vacuous (seed proven to land, negative and positive on the same row id); no
concurrency or lock-ordering consequence; the concatenated SQL is valid; no assertion is tautological.

**Still open, and NOT a code question** — see the note to the decision-maker in the summary: whether that
IP is legally the subject's data when the row cannot say whose it is (a DPO question), and whether the
forensic cost in finding 1 is acceptable. The ADR now records it as open rather than settling it.

## Spec Change Log

## Design Notes

`AuditRedaction` in `Domain/` rather than a shared const on either anonymiser: both callers are
Infrastructure and may depend inward, whereas hanging it off the actor port would make the resource
anonymiser import a collaborator it has no relationship with (ISP). Two callers would normally lose the
Rule-of-Three argument for extraction — what wins it here is that the ADR asserts a compliance
invariant over the literal itself, so a drifted second spelling is a silent compliance failure rather
than a cosmetic duplication.

**What the predicate deliberately does not cover, measured rather than assumed.** `actor_type = 'anonymous'`
rather than `actor_id IS NULL` is not only about `system` rows being NULL-`ip` by construction — that
coupling is *emergent*, held by two independent reads of the same `RequestStack` in two classes and
asserted by no guard and no test. `AuditLogEntry::create()` would happily persist a `system` actor with an
`ip`. The enum token is the only predicate that cannot silently widen. Separately, the throttle writer also
emits a **resource-less** anonymous row when the address resolves to no identity; a resource-axis statement
can never match it, and that is correct — the row names nobody, so there is no subject whose erasure it
could outlive. Say it in the docs rather than letting the change read as full coverage of the action.
`user_agent IS NULL` on a live anonymous `User` row is likewise reachable, not theoretical: a header-less
request to the login or forgot-password route produces exactly that.

**Three implementation decisions that depart from this spec's letter, each measured rather than chosen.**

1. *The functional test spells `'[REDACTED]'` as its own private const instead of reading
   `AuditRedaction::SENTINEL`.* Reading the constant makes the assertion tautological: it would pass
   unchanged if somebody edited the constant to hold something else, which is exactly the compliance
   failure the extraction exists to prevent. The sibling actor-axis test already pins it this way. PHPMD's
   coupling ceiling flagged the import first; the falsifiability argument is why the fix is this and not a
   suppression.
2. *The `system` row in that test carries a NON-null `ip`.* The spec's matrix said `ip IS NULL`, which
   discriminates nothing — the `IS NOT NULL` guard would leave it alone whichever predicate were used. A
   system row with a real `ip` is the only shape that separates `actor_type = anonymous` from
   `actor_id IS NULL`, and it is constructible because no guard forbids it.
3. *`DbalAuditActorAnonymiser`'s docblock gained a sentence this spec did not schedule.* Wiring the shared
   constant surfaced that the actor pass writes the sentinel **unguarded**, over a never-captured value as
   readily as a real one. That is sound there and only there — every row it matches was authored by the one
   person being forgotten — but the asymmetry between the two statements is now the kind of thing a reader
   will trip over, so both docblocks state it.

The statement shape, for reference:

```sql
SET resource_id = CAST(:pseudonym AS UUID), resource_erased = TRUE,
    ip         = CASE WHEN actor_type = :anonymous AND ip         IS NOT NULL THEN :redacted ELSE ip         END,
    user_agent = CASE WHEN actor_type = :anonymous AND user_agent IS NOT NULL THEN :redacted ELSE user_agent END
WHERE resource_type = :resource_type AND resource_id = CAST(:resource_id AS UUID)
```

## Verification

**Commands:**
- `make php.stan` -- expected: exit 0, no new errors on the four touched PHP files.
- `make php.unit c='--filter AuditResourceAnonymiserFunctionalTest'` -- expected: all tests pass; confirm with `--list-tests` that the filter actually selects the new cases rather than a subset.
- `make php.behat c='features/backoffice/users/erase.feature'` -- expected: every scenario passes and the summary line reports no undefined/pending steps. Behat resets the dev database and deletes the e2e admin — reseed after.
- `make php.quality` -- expected: exit 0, including `php.lint.audit-resource`, `php.lint.person-reference` and `php.deptrac`.

**Manual checks (if no CLI):**
- Apply each of the five mutations in turn, one at a time, and record which named assertion reddens for each; a mutation that stays green means the coverage is not there. Restore by rewriting the original bytes, never `git checkout --`.
