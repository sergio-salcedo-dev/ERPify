---
title: 'doc-2: Documents bounded context skeleton and storage port'
type: 'feature'
created: '2026-11-05'
status: 'done'
baseline_commit: '29d7ded50050bad6429300e37709e2f49428087d'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/specs/spec-documents-context/SPEC.md'
  - '{project-root}/_bmad-output/specs/spec-documents-context/evidence-lifecycle.md'
  - '{project-root}/docs/adr/images-vs-documents-conservation-contract.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The Documents context (epic #268) has no code: no aggregate, no custody log, no storage port, and no seam through which an identity erasure can reach a context other than Iam/Organization/Shared. Every later story (doc-3..doc-9) builds on tables and ports that do not exist.

**Approach:** Create `api/src/Documents` with a state-oriented `Document` snapshot and an append-only `document_custody` log written in one transaction, Documents' own stream-writing storage port with a first filesystem adapter, and the `Shared/Privacy` erasure seam both identity-erasure consumers call. Gate the custody log's append-only rule with a new source gate.

## Boundaries & Constraints

**Always:** State-oriented persistence (decided in SPEC; do not reopen). Each version is its own aggregate; `series_id` = the first version's own id, `predecessor_id` written NULL, no slot column/index/walk-up. `scope_id` random (never derived from the document id) with a UNIQUE. `DocumentCustody` is `#[ORM\Entity(readOnly: true)]`; its only write after insert is one DBAL statement, the actor anonymisation, which rewrites the actor to one constant nil-UUID marker (declared once as a constant) and takes documents then custody rows in ascending id inside the caller's transaction. `seq` is computed under the aggregate lock; `UNIQUE (document_id, seq)` is the second defence, and a violation of that constraint (matched by constraint name, never by driver text) and `deadlock_detected` both map to a retry; the idempotency-key constraint is a conflict, never retried. Every person-id column is registered (policy line, `#[PersonSubjectReference]`, detective `PersonReferenceSource` excluding the marker) in this PR; every other `Types::GUID` column is classified `non-person`. Hexagonal layering and bounded-context isolation hold (`php.deptrac`, `php.lint.bounded-context`). Commits, PRs, code, docs in English.

**Owner decisions (2026-11-05):** (1) The storage port carries `stage` (stream to a staging key under the scope), `promote` (same-mount rename), `open` (read stream) and `delete`; the boot probe, the reaper and the fsync policy stay with doc-3. (2) `document_custody` has an `event` string column backed by a PHP enum holding only what doc-2 writes (`Received`), with no database CHECK on it, so later stories add cases without a migration. (3) The spec stays whole: no split of the gate or the erasure seam into another story.

**Contracts (external spec review, 2026-11-05; each checked against SPEC.md):**
- *Versions.* doc-2 writes only first versions: `series_id` = the row's own `id`, `predecessor_id` NULL. Both are immutable (no setter, no UPDATE path). A later version's `series_id` is copied from its locked predecessor (doc-9); doc-2 adds no cycle check, no series aggregate and no walk-up.
- *Append.* One transaction, owned by the Documents append use case: lock the `document` row (`FOR UPDATE`), compute `MAX(seq)+1`, insert the custody row, commit. "No gaps" means committed rows only; no PostgreSQL sequence. A `(document_id, seq)` violation raises a retryable conflict and the use case re-runs the WHOLE `transactional()` closure, bounded (3 attempts), never only the INSERT; `deadlock_detected` is left to `DoctrineTransactionManager`, which already answers `TransientTransactionFailure`. The idempotency constraint is a conflict and is not retried. Constraints have explicit names so the mapping is by name.
- *Idempotency.* The hash is the actor-scoped key hash (SPEC); the nullable UNIQUE is global over that hash, so NULLs never collide, and it is nulled by the same purge that rewrites the actor.
- *Erasure statements.* Two DBAL statements in the caller's transaction: documents where uploader = subject (`ORDER BY id FOR UPDATE`, then rewritten), then custody rows where actor = subject (same shape, precedent `DbalAuditActorAnonymiser`). Nothing in the Documents erasure path loads `Document` or `DocumentCustody` into the unit of work, and tests read the result through DBAL, never through a managed instance; the ORM identity-map limit is stated in the ADR.
- *Foreign key.* `document_custody.document_id` → `document.id` with no cascade (NO ACTION) and no ORM cascade/orphanRemoval, asserted by a schema test; documents are never physically deleted.
- *Custody event.* `event` names a custody fact, not the document's current state; a stored value never changes meaning.
- *Scope id.* An opaque, immutable, random, globally unique identifier, never exposed in a path and never reused (documents are not deleted); crypto semantics stay with doc-1/doc-7.
- *Storage port.* `stage` refuses an existing key; `promote` creates the destination exclusively (never overwrites; typed failure when the destination exists or the source is absent; not idempotent); `open` returns a read stream or a typed absence; `delete` is idempotent on an absent object. Atomicity is claimed only for a staging key and a final root on one filesystem and is probed by the adapter's own test, not assumed.
- *Gate.* Besides SQL strings, the engine reads DQL and QueryBuilder surfaces that name the entity class (`DocumentCustody::class` in `createQuery`/`update`/`delete`), `remove()` and the DBAL table API; the sanctioned set names one statement in one file (the custody anonymiser) and each supported surface has a negative test plus a declared blind-spot test.

**Never:** No route, no upload flow, no `UploadIntent`, no scan columns, no `promoted_at`/`next_scan_at`, no digest computation, no filename sealing, no `MAX_ORIGINAL_BYTES`, no erasure by key destruction (doc-3..doc-9 own these). No reuse of `ImageStorage` or any generic storage port; no WORM claim on the port; no database role, GRANT or trigger on `document_custody`; no `#[AuditedEntity]` on `Document`; no filename key in `AuditMetadataKey`; no `Document` audit resource type (the first story that writes it classifies it). Do not touch `Shared/Crypto` (doc-1). Never edit `api/config/reference.php` by hand.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Create document | new `Document` + seq 1 custody row | both rows in one transaction | rollback leaves neither |
| Append custody | document exists, concurrent appenders | each gets a distinct `seq`, no gaps | `(document_id, seq)` violation or deadlock → retried |
| Duplicate idempotency hash | second document, same non-null hash | conflict, not retried | maps to the existing 409 conflict marker |
| Subject erasure | uploader/actor id on snapshot + custody rows, any state incl. `Erased` | uploader and actor become the marker, idempotency hash NULL, count returned | failure inside the transaction aborts the whole erasure |
| Detective read after erasure | only marker rows remain | `PersonReferenceSource` reports no id | N/A |
| Concurrent seq collision | two appenders, `(document_id, seq)` violated | whole transaction re-run, distinct `seq`, no gap in committed rows | gives up after 3 attempts with a transient failure |
| Promote onto existing key | destination already present | destination untouched | typed failure, never an overwrite |
| Custody mutation in `src` | any UPDATE/DELETE/`remove()`/DQL/QueryBuilder write on custody except the sanctioned statement | gate red naming file and verb | N/A |

</frozen-after-approval>

## Code Map

- `api/src/Organization/Membership/**`, `api/src/Backoffice/Bank/**` -- layout/aggregate/repository precedents (`extends AggregateRoot`, `#[AsAlias]` Doctrine repository, `save()` maps `UniqueConstraintViolationException`).
- `api/src/Shared/Images/Domain/Entity/Image.php` -- shape for `DocumentCustody` (`final readonly`, `DATETIMETZ_IMMUTABLE` createdAt, no AggregateRoot).
- `api/src/Shared/Images/Infrastructure/FlysystemImageStorage.php`, `api/config/packages/flysystem.yaml`, `compose.yaml` (storage volume), `api/frankenphp/docker-entrypoint.sh` -- adapter/config/provisioning to MIRROR, not reuse.
- `api/src/Shared/Persistence/Infrastructure/{DoctrineTransactionManager,InjectedForeignKeySchemaListener,KeysetDistinctIds}.php` -- `RetryableException` → `TransientTransactionFailure`; FK listener for `document_custody.document_id`; distinct-id reader with scope predicate.
- `api/src/Shared/Audit/Infrastructure/Persistence/{DbalAuditActorAnonymiser,DbalAuditSubjectRowLock}.php` -- anonymisation/lock precedent (single statement, `ORDER BY id FOR UPDATE`).
- `api/src/Iam/Identity/Application/{FulfilIdentityErasure,ResweepErasedSubjects,FulfilIdentityErasureResult}.php` -- both consumers; Documents step goes after membership/event-store, before the result; 15 test files construct them.
- `api/src/Shared/Privacy/Application/PersonReferenceSource.php`, `api/.person-reference-policy`, `api/tests/Support/PersonReferences.php` -- registry and the wiring check (collaborator typed with the entity name + `anonymise*` call).
- `api/tests/Unit/Iam/Identity/Application/ErasureLockOrderTest.php`, `api/tests/Unit/Shared/Persistence/Double/LockOrderJournal.php`, `api/tests/Functional/Iam/Identity/ErasureLockOrderFunctionalTest.php` -- add the Documents position.
- `api/tests/Unit/Gate/SanctionedLogMutation*GateTest.php`, `api/tests/Support/{SanctionedLogMutations,PhpStringExpressions,DbalTableApiCalls,ApiSourceFiles}.php` -- gate precedent; `TABLES` is hard-coded, so the new gate gets its own engine.
- `api/.artifact-gate-placement`, `make/php-quality.mk` (prerequisite lines of `php.quality` and `php.quality.dry-run`, `.PHONY`) -- gate registration and wiring.
- `api/config/packages/doctrine.yaml` (mapping per tree), `api/tools/deptrac/deptrac.yaml`, `api/migrations/2026/Version20261105123104.php` (doc-1 migration style, hand-written CHECKs).
- Docs: `docs/architecture-api.md`, `docs/source-tree-analysis.md`, `docs/claude-code-quickref.md`, `api/docs/make-targets.md`, `docs/rules/database.md`, `docs/index.md`, `CLAUDE.md` (Required-checks bullet), `PRODUCTION_SECURITY_CHECKLIST.md` §7.

## Tasks & Acceptance

**Execution:**
- [x] `api/src/Documents/Document/Domain/**` -- `Document` aggregate (state enum: Received, Scanning, Ready, Quarantined, ScanFailed, Erased), `DocumentCustody`, `DocumentRepository`, nil-UUID marker constant, retry/conflict exceptions -- the contract.
- [x] `api/src/Documents/Document/Infrastructure/Persistence/**` -- Doctrine repository (lock, `seq`, constraint-name mapping), anonymisation statement, two `PersonReferenceSource`s, FK schema listener -- persistence.
- [x] `api/src/Documents/Document/{Domain,Infrastructure}/Storage/**`, `flysystem.yaml`, compose/entrypoint -- storage port + filesystem adapter (scope per Open Question).
- [x] `api/migrations/2026/Version2026110*.php` -- both tables, named UNIQUEs, CHECKs, actor index.
- [x] `api/src/Shared/Privacy/Application/PersonReferenceErasure.php` + `api/src/Documents/Document/Application/PurgeUserDocumentReferences.php` -- the seam and its owner; wire into both consumers and their result/log metadata; update the 15 constructors.
- [x] `api/tests/**` -- unit, functional (concurrent seq, constraint refusal, planted-uploader scenario, lock order), Behat/functional erasure scenario proving no id survives.
- [x] new custody gate + engine + rules/blind-spot tests, `.artifact-gate-placement`, `php.lint.document-custody` wired in `php-quality.mk`.
- [x] registries/docs: `doctrine.yaml`, `deptrac.yaml`, `.person-reference-policy`, ADR `docs/adr/documents-custody-log.md` + `docs/index.md`, `docs/rules/database.md`, checklist §7, architecture/tree/quickref docs, `CLAUDE.md` bullet.

**Acceptance Criteria:**
- Given a subject with an uploaded document, when the identity erasure runs, then no table holds the subject id and the idempotency hash is NULL, in every document state.
- Given the new gate, when a second UPDATE/DELETE/`remove()` on `document_custody` is planted in `api/src`, then `make php.lint.document-custody` is red.
- Given `ErasureLockOrder*Test`, then identity, session, audit and event-store locks precede the Documents purge.
- Given two concurrent appenders on one document, then no two committed custody rows share a `seq` and a forced rollback leaves no partial custody row.
- Given a `(document_id, seq)` violation, then the whole transaction is retried; given an idempotency violation, then it is a conflict and is not retried.
- Given a failure injected in the Documents step, then the identity erasure rolls back whole.
- Given the purge ran, then a fresh DBAL read shows no subject id in `document` or `document_custody` and the hash is NULL.
- Given the schema, then the custody FK has no cascade and the mapping has no cascade/orphanRemoval.
- Given `promote` onto an existing key, then the destination is untouched and a typed failure is raised.
- Given each supported write surface planted in `api/src`, then the custody gate is red for it.
- Given `make php.quality`, `make php.unit`, `make php.behat`, then all green from fresh runs.

## Implementation Notes

## Spec Change Log

- 2026-11-05, review pass 1: the Acceptance Auditor found the first migration omitted the columns `stories.yaml` doc-2 requires (`filename_ciphertext`, `retention_reason`, `legal_basis`, `review_at`). This story file did not name them; `stories.yaml` is the owner's contract and prevails, so they were added as a patch (nullable, no CHECK, values arrive with doc-3) instead of looping the whole story back. The frozen block is unchanged. KEEP: the migration, domain, repositories, seam wiring, gate and storage adapter as built.

## Review Triage Log

Review pass 1 (Blind Hunter, Edge Case Hunter, Verification Gap, Acceptance Auditor; 16 + 14 + 5 + 9 findings; overlaps merged). Verdicts rendered after all four reported.

| Finding (layers) | Verdict | Route | Evidence |
|---|---|---|---|
| Migration omits filename ciphertext and retention columns (AA) | medium | patch | `stories.yaml` doc-2 says the first migration carries them; the table has none. |
| Constraint name scraped from the English driver line (EC, BH, AA) | medium | patch | `ViolatedUniqueConstraint` regex needs `ERROR:  duplicate key value violates`; a localised server returns null, so a lost race is a 500 and the idempotency conflict a 500. |
| `stage` cleans up only on `DocumentStorageFailed` (EC, BH, VG) | medium | patch | a non-storage `Throwable` leaves the handle open and a partial file that makes the next `stage` answer `ObjectAlreadyExists`. |
| `open` demands write permission on the area (EC, BH) | medium | patch | `guardAreaIsUsable` checks `X_OK|W_OK` for every operation. |
| Empty or relative storage root accepted (EC) | low | patch | constructor takes any string; fix is one guard. |
| Untraversable-directory delete test skipped as root (VG) | high | patch | `markTestSkipped` on euid 0 and the suite runs as root; swapping `objectExists` for `file_exists` leaves everything else green. |
| `lockForUpdate` returns a stale managed instance (EC) | false | reject | measured by the implementer: in the installed ORM `find(…, PESSIMISTIC_WRITE)` on a managed entity already refreshes it through the persister, and the new functional test passes with either form; the DQL+`HINT_REFRESH` form was kept as the explicit one. |
| Nil-UUID marker accepted as uploader/actor, and `eraseReferencesTo(nil)` (EC, BH, AA) | medium | patch | both detective sources exclude the marker, so a planted marker is invisible; purge of the marker re-counts anonymised rows. |
| Custody gate misses `getRepository(...)->createQueryBuilder()->update()`, `from(...)->delete()` and SQL comments (AA, EC) | medium | patch | the engine walks only verbs before `from()` and plain SQL text; shapes are in neither the header nor the BlindSpot test. |
| `rowsRemoved` misnamed (BH) | low | patch | most counts are anonymised rows. |
| `erasedAnything` with only document references untested; metadata value only asserted as 0 (VG) | medium | patch | deleting `+ $this->documentReferencesAnonymised` leaves all tests green. |
| Re-sweep count, audit and call position unverified (VG) | medium | patch | all `ResweepErasedSubjects*Test` use a seam double returning 0. |
| No wiring test for the container-wired storage root (AA, VG) | medium | patch | the images module needed exactly this test (`ImageStorageWiringTest`). |
| `ORDER BY id FOR UPDATE` claimed load-bearing, pinned by nothing (AA) | medium | patch | erasure and re-sweep of the same subject touch the same rows; deleting the clause keeps every test green. |
| Stale class/test names in docblocks; scope-id collision undocumented (BH, AA) | low | patch | `DbalDocumentCustodyRepository` and `DocumentCustodySchemaTest` do not exist; fix is a rename and one sentence. |
| `docs/rules/database.md` retention paragraph missing; contradictory "not an ORM entity" paragraph (AA, BH) | medium | patch | `invoke_dev_with` asks for the paragraph; the older paragraph is unscoped. |
| Atomicity "probed by the adapter's own test" overclaims; lock-order AC covered by doubles (AA, BH) | low | patch | the test runs on `/tmp`; the double writes its own journal entries. Wording and a test header. |
| Deptrac infrastructure ruleset wider than the code (BH) | low | patch | copied vendor allowlist admits ApiPlatform, Guzzle, Monolog; narrowing is one edit checked by `make php.deptrac`. |
| `size_bytes INT` caps at 2 GiB (EC, BH, AA, VG) | false | reject | `MAX_ORIGINAL_BYTES` is 100 MiB (SPEC decided 2026-11-04), far below 2^31. |
| Redundant `IDX_E95EF55AC33F7837` (BH, AA) | low | reject | removing it makes `db.diff` drift against the FK listener; cost is write amplification on a small log. |
| `promote` retry after a crash between `link` and `unlink` cannot succeed (EC, BH) | low | reject | the stale staged name is removed by `delete(Staging)`, which the port exposes; the ADR names the sweep as its owner. |
| Rollback `unlink` failing after a failed staged `unlink` (EC) | low | reject | two simultaneous filesystem failures; the fix adds a branch for no demonstrated path. |
| Zero-byte `stage` (EC) | low | reject | the upload story refuses a zero-length original before any intent exists (SPEC). |
| `mkdir -p` failure aborts container boot under `set -e` (EC) | low | reject | identical shape to the images provisioning block next to it. |
| Provisioning (entrypoint/compose parity) unverified (VG, AA) | low | reject | the boot probe is the upload story's by owner decision; images has the same gap. |
| Nested `transactional()` retry (EC) | maybe-false | reject | no caller nests it; would need a nesting call site to settle. |
| Failed custody `persist` stays queued (EC) | false | reject | `DoctrineTransactionManager` reopens a closed EntityManager, dropping the queue. |
| Custody event unrelated to state; `Received` appended twice (BH) | low | reject | deliberate in the contract: state is a snapshot, custody records facts; coupling belongs to the lifecycle stories. |
| Scope-id violation unmapped (BH, EC) | low | patch | minting-flow defect, documented rather than mapped (see docblock patch). |
| Deadlock → retry has no Documents-level test (AA) | low | reject | `DoctrineTransactionManager` owns and tests that mapping; the contract leaves it there. |
| Session/audit/event-store positions only on doubles in the lock-order tests (AA) | low | patch | stated in the test header; the functional probes never covered them before this story. |

## Design Notes

Execution order (increments, each verified before the next): migration → domain → repository and append → DBAL anonymisation → custody gate (as soon as the first custody write exists, not last) → detective sources → seam → both consumers and lock-order tests → storage port and adapter → docs and registries.

Module path is `api/src/Documents/Document/` because deptrac and the bounded-context gate key on `<context>/<module>`. The erasure seam is a port in `Shared/Privacy/Application` (always importable, so no allowlist or `skip_violations` mirror); the Documents use case is its single implementer and the `erasedBy` owner. The lock-order position "upload-intent rows first" is untestable until doc-3 adds `UploadIntent`; doc-2 asserts only identity < session < audit < event-store < Documents.

## Verification

**Commands:**
- `make php.stan` -- expected: no errors on changed files
- `make php.quality` -- expected: exit 0 (includes the new `php.lint.document-custody`)
- `make php.unit` and `make php.behat` -- expected: exit 0
