---
title: 'Redact ip/user_agent on anonymous-actor audit rows naming an erased subject'
type: 'bugfix'
created: '2026-08-12'
status: 'in-review'
baseline_commit: '781c75a2e66548ef8a434017dda8fe5c965fad09'
review_loop_iteration: 1
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
shared `[REDACTED]` sentinel, guarded per column by `actor_type = 'anonymous'` and by the column
actually holding something, inside the `SET`. One statement, one round trip, no new mutation policy on
the table.

## Boundaries & Constraints

**Always:**
- The predicate lives in `CASE WHEN` inside the `SET`, never as a `WHERE` conjunct — a `WHERE` filter
  would stop pseudonymising `resource_id` on admin-written rows, deleting behaviour correct today.
- `actor_type = :anonymous`, never `actor_id IS NULL` — the latter also matches `system` rows.
- An emptiness guard **per column**, covering both spellings of "nothing was captured": an off-request
  or header-less capture leaves NULL, and `SealedAuditEntryFactory` only null-checks the header, so a
  bare `User-Agent:` seals `''`. A never-captured value must stay as it is, so "redacted" remains
  distinguishable from "never captured". That distinction is the sentinel's entire reason to exist.
- `actor_erased` is not written. D4.1 defines it as "identified and then erased"; raising it on a
  never-identified row corrupts the flag and its cross-check with `GDPR_ERASURE_EXECUTED`.
- One `UPDATE`, one round trip — `erase.feature` asserts a 20-query budget that a second statement breaks.
- The sentinel becomes one shared literal both anonymisers read; it is compliance-critical and must stay
  byte-identical (the D4.1 invariant is asserted over the literal `'[REDACTED]'`).

**Ask First:**
- Any backfill of subjects erased before this ships. **Answered (code review, 2026-08-12): none needed.** A
  subject erased before this merges keeps its `ip`/`user_agent` unreachably — the `WHERE` needs the real id
  the row no longer holds, and `PersonResourceReferences` reads only `resource_erased = FALSE`. The app has
  no production or staging environment, so that population does not exist; the only rows of that shape live
  in a disposable dev database. Revisit **before the first deployment that can erase anyone**, not after.
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
| System actor names the subject | `actor_type=system`, `ip` **set** | `ip` unchanged — the enum token spares it, not its nullity | N/A |
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
- Given each mutation of the shipped statement is applied **one at a time** — (1) delete the `ip` `CASE` arm; (2) delete the `user_agent` `CASE` arm; (3) drop `actor_type = :anonymous` from the `ip` arm; (4) the same from the `user_agent` arm; (5) drop the `ip` emptiness guard; (6) drop the `user_agent` emptiness guard; (7) cross-wire the two arms' guards; (8) additionally set `actor_erased = TRUE`; (9) weaken the `ip` guard to `IS NOT NULL`; (10) the same on `user_agent` — when the suite runs, then at least one named assertion fails for each, and **the failing assertion is recorded per mutation under Verification**. Everything is mutated **per arm and never both at once**: a mutation applied to both cannot distinguish a diff that wired `ip` and forgot `user_agent`. A mutation must redden on an **assertion**, not on a SQL syntax error — deleting an arm also deletes the comma it followed, or the red proves nothing.
- Given the erasure endpoint is exercised, when the query budget is measured, then `erase.feature`'s existing count is unchanged.

### Review Findings

Independent code review of PR #690 (three parallel layers: adversarial, edge-case, acceptance), triaged and
each claim re-measured against the tree before rating.

- [x] [Review][Decision] **RESOLVED — no population exists.** The app has no production or staging environment, so no subject has been erased outside a disposable dev database. Recorded against the *Ask First* line it answers, with the revisit trigger set at the first deployment that can erase anyone rather than at some later review. Original finding: **subjects erased before this ships keep their `ip`/`user_agent`, unreachable by every shipped control** — a pre-merge erasure left `resource_id` holding a pseudonym and `resource_erased = TRUE`, so the new statement's `WHERE resource_type = :resource_type AND resource_id = …` can never match those rows again, and `DbalPersonResourceReferences` filters `resource_erased = FALSE` so the reconciler cannot report them. This is the condition the change's own docblock calls fatal ("It is fixed here or nowhere") holding over the existing population. The shape is self-identifying (`actor_type = 'anonymous' AND resource_erased = TRUE AND ip NOT IN ('[REDACTED]')`), so a backfill is cheap — but `Boundaries & Constraints` lists backfill under both **Ask First** and **Never**, and no answer was ever recorded. Decide: measure prod and backfill, or record the deferral with its reason.
- [x] [Review][Decision] **RESOLVED — renegotiated by Sergio and corrected**, along with the two other lines of the same frozen block that named the superseded `IS NOT NULL` guard; all three recorded in the Spec Change Log. Original finding: **the frozen I/O matrix asserts a state the implementation deliberately refutes** — `## I/O & Edge-Case Matrix` says "System actor names the subject | `actor_type=system`, `ip IS NULL` | `ip` stays NULL", while `AuditResourceAnonymiserFunctionalTest.php:189-195` seeds that row with a non-NULL `ip` on purpose. The deviation is right (with `ip IS NULL` the row discriminates nothing, so it could not separate `actor_type = :anonymous` from `actor_id IS NULL` — the spec's own Always rule), but the block is `frozen-after-approval reason="human-owned intent"`, so only a human may amend it.
- [x] [Review][Decision] **RESOLVED — declined on the merits, recorded in the ADR (zero code).** Referred to the architect and the implementer persona independently; both returned the same verdict for different reasons, and D4 now carries both: the number cannot separate the subject's own rows from a stranger's so it does not answer the question that asks for it, and it is already derivable from the pseudonym the compliance entry seals — where a stored integer would outlive the rows it counts, since retention prunes them first. The declination moves out of this transient artifact, which is what the finding was actually about. Original finding: **`GDPR_ERASURE_EXECUTED` records `anonymized_resource_rows` and no count of redacted metadata** — after this change one erasure can destroy an arbitrary number of *third parties'* addresses, and the compliance entry the ADR treats as independent forensic evidence cannot say how many. The declination is recorded only in this transient artifact, not in the durable ADR beside the open DPO question. Decide: add the count to the metadata, or record the declined hardening in the ADR.
- [x] [Review][Patch] **"`ip` is not client-settable" is false as written, in the three places it is asserted** [`api/src/Shared/Audit/Infrastructure/Persistence/DbalAuditResourceAnonymiser.php:36`, `PRODUCTION_SECURITY_CHECKLIST.md:243`, `docs/adr/audit-activity-log.md` D4] — `SYMFONY_TRUSTED_PROXIES` defaults to `127.0.0.0/8,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16` (`compose.yaml:25`, not overridden in `compose.prod.yaml`), so any request arriving through a private-range hop has its `X-Forwarded-For` honoured and the attacker chooses the address the trail records. `docs/rules/security.md:145` says the opposite in the same rule set ("client-controlled (tainted — never trust)"). The *conclusion* survives for a reason nobody wrote down: `Request::normalizeAndFilterClientIps()` drops anything failing `FILTER_VALIDATE_IP` (`api/vendor/symfony/http-foundation/Request.php:2210`), so the sentinel specifically cannot be forged into `ip`. State the real reason.
- [x] [Review][Patch] **"widens an existing insider capability rather than creating one" is false** [`PRODUCTION_SECURITY_CHECKLIST.md:246-249`] — the actor pass matches `WHERE actor_id = CAST(:actor_id AS UUID)`, so every column it overwrites belonged to the person being erased (as `AuditRedaction`'s own docblock states). This pass matches `(resource_type, resource_id)` and destroys `ip`/`user_agent` on rows authored by somebody who is not the subject. An admin erasing a brute-forced account destroys the attacker's address — a capability that did not exist before. That sentence is what makes the abuse framing deflate, and it does not.
- [x] [Review][Patch] **`COALESCE(col, :blank) <> :blank` is a dead wrapper — the defect class the docblock claims to have removed, reproduced one level out** [`DbalAuditResourceAnonymiser.php:87-90`, argued at `:50-57`] — inside `CASE WHEN`, `COALESCE(ip,'') <> ''` (FALSE on NULL) and `ip <> ''` (UNKNOWN on NULL) route identically to `ELSE`. The NULL half of "both empty spellings need covering" is carried by three-valued logic, not by the code the reader is pointed at, so no mutation of the `COALESCE` can ever redden — exactly the standard the same paragraph sets for the `IS NOT NULL` conjunct it replaced. Either drop it to `<col> <> :blank`, or say in the docblock that it documents intent rather than guarding.
- [x] [Review][Patch] **The `ip` arm's emptiness guard is pinned by no test** [`AuditResourceAnonymiserFunctionalTest.php:110-176`] — the four seeded anonymous rows carry `ip ∈ {value, NULL, value, value}`; no row seeds `ip = ''`. Weakening the `ip` arm to `ip IS NOT NULL` leaves the whole suite green, while the identical weakening on `user_agent` reddens `:173`. The per-column hardening the adversarial pass claims (its finding #4) holds for one arm only.
- [x] [Review][Patch] **`docs/architecture-api.md:265` still describes the resource pass as a `resource_id`-only rewrite** — "its sibling `DbalAuditResourceAnonymiser` rewrites `resource_id` (raising `resource_erased`)". It is the fifth normative document and the one the root `CLAUDE.md` makes mandatory for architecture decisions; the sweep covered four.
- [x] [Review][Patch] **AC3 is stale and its record does not exist** [`Tasks & Acceptance`] — it commits to FIVE mutations and names "drop `IS NOT NULL` from both", a predicate the shipped statement does not contain; the PR body claims eight were run; `## Spec Change Log` is empty and no mutation→reddening-assertion record exists anywhere in the tree. The clause "the failing assertion is recorded per mutation" is satisfied by nothing. Widening was substantively right — the reconstructed eight each map to a distinct assertion — but it never reached the text, and the `Design Notes` SQL fence still shows the superseded `IS NOT NULL` form.
- [x] [Review][Patch] **The deferred bullet on the concurrent-write window is wrong in both halves** [`deferred-work.md`, third new bullet] — "the concurrent writer contends on the `identity_user` row being deleted" is false: `RecordLockoutAuditBestEffort` runs post-commit with the id already in hand and `RecordRecoveryThrottleAuditBestEffort::subjectOf()` does a plain unlocked `findByEmail` on `kernel.terminate` (`:94-111`), and `DbalAuditSubjectRowLock` scopes itself to rows existing when it ran. Nothing serialises them. And "nothing redacts the metadata" overstates: the late row keeps `resource_erased = FALSE`, so the reconciler *does* surface it and a re-run of the same idempotent statement clears both columns. What is actually missing is anything that re-runs automatically.
- [x] [Review][Patch] **"No discriminant can be sealed at write time" is asserted absolutely and the schema holds a candidate** [`DbalAuditResourceAnonymiser.php:33-34`, mirrored in `PRODUCTION_SECURITY_CHECKLIST.md` and the ADR] — both writers have already resolved the subject when they seal, and `iam_session` carries an `ip` (`api/src/Iam/Session/Domain/Entity/Session.php:55,148`), so a `requester_matched_subject` boolean is constructible. It has a real cost (`Shared/Audit` would reach into `Iam/Session`, and the match is a heuristic), which is an argument for *rejecting* it — not for calling it impossible. The absolute form forecloses the alternative instead of discarding it with a reason.
- [x] [Review][Patch] **The published port still contracts a `resource_id`-only rewrite** [`api/src/Shared/Audit/Application/AuditResourceAnonymiser.php:29-30`] — the seam `Iam` consumes says nothing about redacting request metadata. Four documents now name two mutation paths; the interface that *is* the second path does not.
- [x] [Review][Patch] **AC1's conjunction is never asserted inside one `UPDATE`** — the anonymous and admin halves live in two tests with two `anonymise()` calls. In Behat the mixed set occurs for real (the erasure's own `GDPR_SUBJECT_ERASED` row names the subject with `actor_type = user` and the admin's ip before the pass runs) and nothing asserts the admin row's ip survived. One `SELECT` in the new scenario closes it.
- [x] [Review][Patch] **Change-relative comment in a merged artifact** [`api/features/backoffice/users/erase.feature:137-138`] — "Neither GDPR pass **reached** it… the resource pass **used to leave** the actor columns alone". Banned by root `CLAUDE.md` § Code comments, which covers tests.
- [x] [Review][Patch] **The adversarial record misdescribes its own finding #3** — `origin/main:docs/rules/database.md:46-58` opened "closed set of **two**" and enumerated exactly two. It was **stale** against the ADR (which already said three), not self-contradictory. The shipped edit is correct; the account of what was found is not, and that account is the PR's gate.
- [x] [Review][Patch] **Test message describes a predicate the statement does not have** [`AuditResourceAnonymiserFunctionalTest.php:153`] — `assertSame(4, $affected, 'every anonymous row naming the subject')`. `$affected` counts the whole `UPDATE`, whose `WHERE` carries no `actor_type`; it is 4 only because all four seeded rows happen to be anonymous. A reader trusting the message concludes the filter lives in the `WHERE` — the exact mistake the docblock warns against at `:59-61`.
- [x] [Review][Patch] **"the two live writers of this shape" is stated as a property, not a census** [`DbalAuditResourceAnonymiser.php:30-32`] — the generic producer is `AccessLogAuditListener`, which emits whatever `_audit_resource_type` a route declares. Only `Bank` and `BankAccount` declare one today; adding `'User'` to any route reachable without a session would seal every visitor's ip on a row naming that user, and `make php.lint.audit-resource` would not notice (it only demands the type be classified, and it is).
- [x] [Review][Patch] **Two inserted doc paragraphs are glued mid-line** [`PRODUCTION_SECURITY_CHECKLIST.md:251-252`, `docs/adr/audit-activity-log.md` D4.1] — ~200-character lines against files wrapped near 100; it reads as a paste and makes the next diff on those paragraphs unreadable.
- [x] [Review][Defer] **The PWA copy of the `[REDACTED]` literal has no parity gate** [`pwa/src/context/backoffice/audit/infrastructure/ui/RedactedValue.tsx:23`] — deferred, pre-existing (the file is on `main` and has no caller today, since `ip`/`user_agent` are not in the detail payload). The extraction docblock earns itself on "a second, drifted copy is a silent compliance failure"; the three PHP/Gherkin literals are deliberate falsifiers that redden on drift, this one is not. `RedactionVocabularyParityTest` is the existing pattern for gating a cross-deployable literal by text.

## Adversarial pass (recorded before the PR was opened)

Two independent hostile reads of the full diff, run in fresh contexts with no prior conversation:
**Blind Hunter** (`bmad-review-adversarial-general`) and **Edge Case Hunter**
(`bmad-review-edge-case-hunter`). Both were given the diff and the ten claims the author wanted broken.
Disposition of every finding below; nothing was dropped silently.

**Fixed in this PR**

| # | Finding | Fix |
|---|---------|-----|
| 1 | **The change's central justification was FALSE.** "On an anonymous row there is no third party, so the ip is the subject's own" — but `USER_LOCKED` seals the ip of whoever submitted the failing password and `PASSWORD_RECOVERY_THROTTLED` that of whoever exhausted the budget. In the canonical brute-force case that is the **attacker**, not the subject. The wrong reason had been replicated into four normative documents. | Argument rewritten in the docblock and all four docs: the row records **no discriminant** for whose address it holds — and none can be sealed at write time, since the requester is unauthenticated and supplies only a claimed identity — so this errs toward erasure, with the forensic cost stated rather than hidden. |
| 2 | **`''` defeated the guard.** `SealedAuditEntryFactory` only null-checks the header, so a bare `User-Agent:` seals `''`; `'' IS NOT NULL` is true, so the sentinel was written over a value never captured — precisely what the guard existed to prevent. | Guard became `col <> :blank` (via `COALESCE`, collapsed in code review), plus a seeded row with `user_agent = ''` — and, from the code review, its mirror with `ip = ''`. |
| 3 | **`docs/rules/database.md` was stale, not self-contradictory** — the bullet opened "closed set of **two** mutation policies" and enumerated exactly two, while the ADR it cites already declared the set closed at **three**. (Recorded here first as a self-contradiction; corrected in code review against `origin/main:docs/rules/database.md:46-58`.) | Opening corrected to three, and the resource axis enumerated as the third. |
| 4 | **The two per-column guards were not independently pinned.** Every seeded row had both columns alike, so pasting one arm's guard into the other, or cross-wiring them, passed everything. | Added an anonymous row with `ip` set and `user_agent` NULL; added `user_agent` assertions on the admin and system rows. |
| 5 | `AuditRedaction` was an instantiable class used as a constant namespace. | Private constructor. |

**Found by the author while re-measuring, not by either reviewer**

- **`IS NOT NULL` had become a dead conjunct.** Once `<> :blank` was added, `NULL <> ''` is already unknown
  and falls to the `ELSE`, so no mutation of `IS NOT NULL` could ever redden — a predicate that reads as a
  guard while proving nothing. Collapsed into `COALESCE(col, :blank) <> :blank`. This surfaced only because
  the mutation battery was re-run **after** the last edit rather than trusted from its earlier green.
  **The code review then found the same defect one level out:** inside a `CASE WHEN`, `COALESCE(col, '') <> ''`
  and `col <> ''` route identically, so the `COALESCE` was itself a wrapper no mutation could redden. The
  guard is now the bare `col <> :blank`, and the reasoning lives in the docblock where a reader needs it
  rather than in a term pretending to enforce it.

**Deferred (real, not caused by this change)** — recorded in `deferred-work.md`:
no CHECK constraint ties `actor_type` to `actor_id` nullability, so illegal rows are representable by raw
SQL; `user_agent` is client-forgeable as the literal `[REDACTED]`; a row committed after the `UPDATE` but
before the erasure commits keeps its metadata.

**Claims the reviewers attacked and could NOT break:** `SET`-not-`WHERE` preserves admin-row behaviour
exactly; `actor_type = :anonymous` cannot match another type (`varchar`, byte-exact, closed enum); the
statement stays idempotent on re-run including over the sentinel; the 20-query canary is untouched; the
Behat scenario is not vacuous (seed proven to land, negative and positive on the same row id); no
concurrency or lock-ordering consequence; the concatenated SQL is valid; no assertion is tautological.

**Weighed and decided by Sergio after the pass, before the PR was opened.** The forensic cost of finding 1
was put to him explicitly and he kept the redaction. What decided it: the leak is *systematic* (every
erasure of anyone who ever locked themselves out or requested recovery) while the loss is *rare* (only
where a stranger happened to be the requester on that row); both are bounded by the same 365-day `security`
retention window; the fact of the redaction survives even though the value does not; no discriminant can be
sealed at write time; and the abuse framing deflates because an administrator can already destroy a
non-admin's entire actor-axis attribution through the actor pass, so this widens an existing insider
capability rather than creating one. Two hardenings were offered and declined as out of scope: counting
redacted rows on `GDPR_ERASURE_EXECUTED`, and freezing the branch pending DPO input.

**Still open, and NOT a code question:** whether that IP is legally the subject's data when the row cannot
say whose it is. That is a DPO question; the ADR records it as open rather than settling it.

## Spec Change Log

**2026-08-12 — code review of PR #690. Frozen block renegotiated by Sergio.** The `frozen-after-approval`
block described a guard the shipped statement does not have, so three of its lines asserted a predicate the
implementation refutes. Renegotiated rather than deviated from:

1. **I/O matrix, system-actor row** — was `actor_type=system`, `ip IS NULL` → `ip` stays NULL. Now
   `actor_type=system`, `ip` **set** → `ip` unchanged. The original row discriminated nothing: with a NULL
   `ip` every candidate predicate (`actor_type = :anonymous`, `actor_id IS NULL`, or none at all) leaves it
   alone, so it could not pin the *Always* rule that the enum token — not the nullity — is what spares a
   system row. `AuditResourceAnonymiserFunctionalTest::itSparesASystemActorWhoseActorIdIsAlsoNull` seeds the
   corrected shape.
2. **Intent, approach sentence** and **Boundaries, third *Always*** — both spelled the guard
   `AND <col> IS NOT NULL`. That conjunct was found dead during implementation (`NULL <> ''` is already
   unknown and falls to the `ELSE`) and collapsed; leaving the frozen text naming it kept a superseded
   predicate as the contract. Restated as "the column actually holding something", with both empty spellings
   named. *(Sergio authorised item 1 explicitly; 2 and 3 are the same defect in the same block and were
   corrected with it — flag if you want them reverted.)*

**Same review — AC3 rewritten.** It committed to FIVE mutations, one of which (`drop IS NOT NULL from both`)
names a predicate the shipped code does not contain, while eight were actually run; and its "recorded per
mutation" clause was satisfied by nothing in the tree. The battery is now enumerated as run, and the record
lives under **Verification**. Widening the criterion mid-flight was substantively right — each of the eight
maps to a distinct assertion — but it had never reached the text, which left the artifact both stale and
understating the work.

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
2. *The `system` row in that test carries a NON-null `ip`.* A system row with a real `ip` is the only shape
   that separates `actor_type = anonymous` from `actor_id IS NULL`, and it is constructible because no guard
   forbids it. This began as a deviation from the matrix, which said `ip IS NULL` — a shape that
   discriminates nothing, since every candidate predicate leaves a NULL alone. The matrix has since been
   renegotiated to match (see the Spec Change Log), so this is the contract now, not a departure from it.
3. *`DbalAuditActorAnonymiser`'s docblock gained a sentence this spec did not schedule.* Wiring the shared
   constant surfaced that the actor pass writes the sentinel **unguarded**, over a never-captured value as
   readily as a real one. That is sound there and only there — every row it matches was authored by the one
   person being forgotten — but the asymmetry between the two statements is now the kind of thing a reader
   will trip over, so both docblocks state it.

The statement shape, for reference:

```sql
SET resource_id = CAST(:pseudonym AS UUID), resource_erased = TRUE,
    ip         = CASE WHEN actor_type = :anonymous AND ip         <> :blank THEN :redacted ELSE ip         END,
    user_agent = CASE WHEN actor_type = :anonymous AND user_agent <> :blank THEN :redacted ELSE user_agent END
WHERE resource_type = :resource_type AND resource_id = CAST(:resource_id AS UUID)
```

`<> :blank` carries the NULL case on its own: inside a `CASE WHEN`, `NULL <> ''` is unknown and unknown
falls to the `ELSE` exactly as false does. Any wrapper spelling that out — `col IS NOT NULL AND …`, or
`COALESCE(col, :blank) <> :blank` — is a term no mutation can redden, which is the defect the shipped
statement went through twice before landing here.

## Verification

**Commands:**
- `make php.stan` -- expected: exit 0, no new errors on the four touched PHP files.
- `make php.unit c='--filter AuditResourceAnonymiserFunctionalTest'` -- expected: all tests pass; confirm with `--list-tests` that the filter actually selects the new cases rather than a subset.
- `make php.behat c='features/backoffice/users/erase.feature'` -- expected: every scenario passes and the summary line reports no undefined/pending steps. Behat resets the dev database and deletes the e2e admin — reseed after.
- `make php.quality` -- expected: exit 0, including `php.lint.audit-resource`, `php.lint.person-reference` and `php.deptrac`.

**Falsification battery — measured, not asserted.** Each mutation applied alone to
`DbalAuditResourceAnonymiser`, suite run, original bytes rewritten (never `git checkout --`), next mutation.
Run against the final tests, after the last edit; the control run at the end returned to exit 0 with the file
byte-identical to the original.

| # | Mutation | Test that reddens | On the assertion |
|---|----------|-------------------|------------------|
| 1 | delete the `ip` `CASE` arm | `itRedactsRequestMetadataOnlyWhereTheActorWasNeverIdentified` | *the requester ip may be the subject* |
| 2 | delete the `user_agent` `CASE` arm (and the comma it followed) | same | *and so may the user agent* |
| 3 | drop `actor_type = :anonymous` from the `ip` arm | `itRewritesOnlyTheMatchingResourceAndLeavesEveryActorColumnAlone` | *a third party's ip is not collateral* |
| 4 | drop `actor_type = :anonymous` from the `user_agent` arm | same | *nor their user agent* |
| 5 | drop the `ip` emptiness guard | `itNeverReportsAValueThatWasNotCapturedAsRedacted` | *a value never captured is not rewritten into evidence* |
| 6 | drop the `user_agent` emptiness guard | same | `assertNull($bare['user_agent'])` — *'[REDACTED]' is null* fails |
| 7 | cross-wire the two arms' guards | same | *the captured half is redacted* |
| 8 | additionally set `actor_erased = TRUE` | `itRewritesOnlyTheMatchingResourceAndLeavesEveryActorColumnAlone` | *the acting admin is not an erased actor* |
| 9 | weaken the `ip` guard to `IS NOT NULL` | `itNeverReportsAValueThatWasNotCapturedAsRedacted` | *an empty ip captured nothing to redact either* |
| 10 | weaken the `user_agent` guard to `IS NOT NULL` | same | *an empty header captured nothing to redact* |

Two of these were only measured because the battery was re-run rather than trusted. **9 stayed green** until
the code review seeded a row with `ip = ''`: every seeded `ip` had been NULL or a real address, so the `ip`
arm's guard was pinned by nothing and could be silently weakened to a null check. And **2 and 6 first went
red on a `SQLSTATE[42601]` syntax error**, not on an assertion — a mechanical deletion left a dangling comma.
A mutation that cannot compile proves nothing about coverage; both were rewritten as valid SQL before they
counted.
