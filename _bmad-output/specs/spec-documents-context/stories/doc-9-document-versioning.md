---
title: 'doc-9: Document versioning'
type: 'feature'
created: '2026-11-19'
status: 'done'
baseline_commit: '98d6d449989b97fbeb625dd8f40cc89374fe8e93'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/specs/spec-documents-context/SPEC.md'
  - '{project-root}/docs/adr/documents-custody-log.md'
  - '{project-root}/docs/adr/authorization-model-boundaries.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** CAP-7 does not exist. `series_id` and `predecessor_id` have been on `document` since the first migration, but nothing files a successor, nothing refuses a second live one, a failed version cannot be replaced by a sibling, and nothing lists a series or erases it.

**Approach:** A new version is the existing two-step upload with a predecessor named at the declare. Its commit takes a per-series mutex row, rechecks the predecessor under its own lock and asks a lock-free recursive read whether a live version already succeeds it; the reopen of a failed successor does the same. Every freeing transition stays as it is. A read projection lists a series in creation order, tombstones included, and the operator's erasure gains a series form.

**Contract:** `stories.yaml` entry `doc-9` is the owner's contract and prevails over any summary here, read together with the refinement recorded below (option C), which supersedes its head-pointer sentence.

## Boundaries & Constraints

**Decided by the owner, 2026-11-19 (do not reopen):**
- **Persistence is state-oriented.** Each version is its own `Document` aggregate; the series is a scalar `series_id` plus a read projection. No event sourcing.
- **Successor exclusion is option C** (the architect's refinement of design B): a `document_series` row per series used ONLY as a mutex by the two transitions that TAKE the slot — filing a new version and reopening a `ScanFailed` successor. No stored head pointer. Under the lock the take rechecks its predecessor under `FOR UPDATE` (`Ready` for a new version, not `Erased` for a reopen) and reads, without a lock, whether the predecessor has a live strict descendant (live = `Received`, `Scanning`, `Ready`). Lock order: new version = intent → new scope keystore row → series → predecessor; reopen = series → predecessor → self. Erasure, the scan compare-and-sets, the cap sweep and `ReplayErasure` are untouched; `ErasureLimits::TRANSACTION_LOCKS` stays 3; the cascade over a run of `Erased` nodes is derived, never written. Projection ordered by `(created_at, id)`. Migration backfills one series row per existing series.
- **Everything else of `invoke_dev_with` stands:** RBAC `document.write`, no row-level voter, an absent predecessor answers like a non-`Ready` one; doc-3 size bounds on this path; series erasure added to doc-7's use case as a repeatable operator action (each non-`Erased` version in its own transaction, newest first, reporting what remains); a person's erasure touches no document; route manifest, credential-proof classification, voter and `Permission` named, problem types in `docs/api-error-contract.md`; tombstone fields; `uploader_id` stays under the person purge and the projection omits it for an `Erased` node; the series-erasure residual in `PRODUCTION_SECURITY_CHECKLIST.md` §7.

**Decided here, inside those bounds (named for review — none was settled by the sources):**
- **Routes.** `POST /api/v1/documents/{id}/versions` (`documents_version_declare`, `document.write`) is the declare with the predecessor in the path; it reuses `DeclareUploadCommand` unchanged, so the declare DTO still has no predecessor field, and the PUT is the existing `PUT …/uploads/{id}/content`. `GET /api/v1/documents/series/{seriesId}` (`documents_series`, `document.custody`, paged by `afterId`/`limit`, 410 under the ledger override like the listing) and `POST /api/v1/documents/series/{seriesId}/erase` (`documents_series_erase`, `document.erase`, re-proves the password, `credential-affecting`, pinned in `CredentialProofGateTest` as the eighth route). The series id is the first version's id; a successor's id answers 404 on both series routes.
- **Two problem types on `Conflict`:** `document-predecessor-not-ready` (absent, not `Ready`, or — for a reopen — `Erased`) and `document-version-slot-taken`. The declare refuses both early, without a lock and only for a new intent (a replay answers by its intent as before); the commit decides again under the lock.
- **Foreign keys:** `document.series_id → document_series.id` (so the take always finds a row) and `document.predecessor_id → document.id` (its index serves the recursive read); neither cascades. The series row is inserted in `DoctrineDocumentRepository::add()` for a first version, through DBAL; `document_series` is an injected table, not an entity.
- **The new-version declare is behind `documents.ingest_enabled`** like the other two ingest routes.
- **A new version carries its own retention terms** from the caller, as each version is its own aggregate; nothing is inherited.
- **The series page returns `uploaderId`** for a version that is not `Erased` (the nil UUID for an uploader since erased); the statement replaces it with NULL for an `Erased` one.
- **A series erasure stops at the first failure** and propagates the single erasure's 503; it answers `{seriesId, erased, remaining}`.

## I/O & Edge-Case Matrix

| Case | Expected |
|---|---|
| New version of a `Ready` version whose slot is free | 201 at the declare; the PUT files a `Received` document with the predecessor's `series_id` and `predecessor_id` = the predecessor |
| Predecessor absent, or in any state but `Ready` | 409 `document-predecessor-not-ready` at the declare, or at the commit (upload aborted) |
| A live version already below the predecessor | 409 `document-version-slot-taken`, same two places |
| Erase a middle node, file a sibling of it | 409 slot taken (the live tip holds the slot); a version of the erased node itself is 409 not ready |
| Erase the only live descendant under a run of `Erased` nodes, file a sibling of the first | 201 / filed |
| Erase the last descendant | slot free |
| Quarantine the only child of an `Erased` node | slot free |
| Erase the predecessor of a `ScanFailed` child, reopen the child | 409 `document-predecessor-not-ready`, child stays `scan_failed` |
| Reopen a `ScanFailed` child whose slot a sibling holds | 409 `document-version-slot-taken` through `POST …/rescan`, end to end |
| Two concurrent new versions of one predecessor; new version vs reopen; two reopens | serialise on the series row, one wins, the other 409 slot taken |
| New version vs the predecessor's erasure | meet on the predecessor row only: erasure first → 409 not ready; new version first → the erasure proceeds and the new version stays live |
| Erasure, scan verdict while a take holds the series row | never wait |
| Series page | creation order, tombstones with no uploader, paged |
| Series erasure | every non-`Erased` version newest first, each its own ledger entry and transaction; repeat answers empty lists |

</frozen-after-approval>

## Code Map

- `api/src/Documents/Document/Domain/Versioning/DocumentVersionSlots.php`, `Infrastructure/Persistence/Doctrine/DbalDocumentVersionSlots.php` — the series mutex, the recursive slot read, the series row insert.
- `Domain/Entity/Document.php` (`succeed()`), `Domain/DocumentState.php` (`slotHolders()`), `Domain/Entity/UploadIntent.php` (predecessor on the intent and in the replay comparison).
- `Application/Versioning/TakeVersionSlot.php` (the one slot take: series row, predecessor row, recheck, recursive read), called by `Application/Upload/CommitUpload.php` (new version) and `Application/Scanning/RescanDocument.php` (reopen); `Application/Upload/DeclareUpload.php` (`declareVersion()`), `Application/ReceiveDocument.php`.
- `Application/Erasure/EraseDocument.php` (`eraseSeries()`), `Application/Listing/ReadDocumentSeries.php`, `Infrastructure/Persistence/Doctrine/DbalDocumentSeriesRepository.php`.
- `Infrastructure/Controller/{DeclareVersion,DocumentSeries,EraseDocumentSeries}Controller.php`, `Infrastructure/Http/{UploadRoutes,DocumentSeriesMapper,DocumentsIngestGate}.php`.
- Schema: `DocumentSeriesSchemaListener`, `DocumentSeriesForeignKeySchemaListener`, `DocumentPredecessorForeignKeySchemaListener`, the migration of this story (backfill).
- Records: ADR `documents-custody-log.md` D12 (and D1, D6, D11 amended), `docs/api-error-contract.md` → "Documents versioning", `PRODUCTION_SECURITY_CHECKLIST.md` §7, `api/.credential-proof-policy`, `api/.route-manifest.json`.

## Spec Change Log

- 2026-11-19 (the owner, after the architect consultation): option C replaces the design-B head pointer of the memlog and of `stories.yaml`; recorded in ADR D12 with the discarded alternatives and the reopening condition. Nothing about the head pointer was built.

- 2026-11-21 (the owner's direction of 2026-11-19, applied on the branch before its first review; it amends the
  "decided here" lines above, which stay as they were frozen): (1) the series erasure closes the series first
  (`document_series.closed_at`, one statement in its own transaction, outside every erasure transaction, so
  `TRANSACTION_LOCKS` stays 3) and `TakeVersionSlot` refuses every new version and reopen of a closed series with
  `document-predecessor-not-ready`, so the erasure terminates; (2) a slot conflict that a concurrent free makes false is
  the option-C contract, a retryable conflict, not a risk; (3) the new-version declare asks `document.write` AND
  `document.download` (RBAC, no row-level voter), which no tier but ADMIN carries, so only an administrator files a
  version; (4) the series page names uploaders to ADMIN as the listing does, stated and not accepted. The `Accepted`
  line of the `PRODUCTION_SECURITY_CHECKLIST.md` §7 entry is gone and the entry is closed. The new-version declare also refuses a closed
  series early, without a lock (`DocumentVersionSlots::isSeriesClosed()`), with the same 409 as the commit.

- 2026-11-21 (a second review, ported from a parallel session's branch at the owner's direction): a closed series answers its own type, `document-series-closed` (`SeriesClosed`, `Conflict`), final where `document-predecessor-not-ready` is not and true for a `Ready` predecessor; the reopen of a failed FIRST version now takes the series row too (`TakeVersionSlot::forFirstVersionReopen()`), so a closed series refuses it — before this, a first version reopened inside a series being erased became readable again; and the declare answers a key a filed version already holds before its early slot refusal, since that version holds the slot and the replay otherwise answered `document-version-slot-taken` once its intent was pruned (the existing replay test used a `Quarantined` version, which holds no slot). KEEP: the reversible closing (`closeSeries()`/`reopenSeries()`), option C, `TRANSACTION_LOCKS` 3.
### To apply locally with memlog.py

**Applied on 2026-11-28** by the doc-12 session (the three entries through `memlog.py`, the `stories.yaml` wording re-derived; `SPEC.md` already carried the amendment). Kept below as the record of what was applied.

`_bmad/scripts/memlog.py` is not installed in the cloud session that implemented this story, and the memlog is written only through it, so neither `.memlog.md` nor `stories.yaml` was edited here. To apply locally:

**Memlog entries** (append all three, in this order, then re-derive `stories.yaml`). The first is the option-C line; the second records the owner's direction of 2026-11-19 as applied on 2026-11-21; the third records decision A of 2026-11-21:

```
- (decision by user) doc-9 successor exclusion refined (Sergio, 2026-11-19, after the architect consultation): option C, the architect's refinement of design B, supersedes the design-B head pointer recorded above. A document_series row exists per series (inserted with the first version, backfilled by the doc-9 migration, referenced by document.series_id) and is used ONLY as a mutex (SELECT … FOR UPDATE) by the two transitions that take a version slot: filing a new version and reopening a ScanFailed successor; no head pointer is stored. Under that lock the take rechecks the predecessor under its own FOR UPDATE (Ready for a new version, not Erased for a reopen) and asks a lock-free recursive read whether the predecessor has a live strict descendant (live = Received, Scanning, Ready; Quarantined, ScanFailed and Erased hold no slot). A version becomes live only under the series lock and outside it versions only stop being live, so a concurrent free costs at most a retryable false 409, never a double successor. Erasure, the scan compare-and-sets (settle, returnToReceived, failCapped), the cap sweep and ReplayErasure take no series lock; ErasureLimits::TRANSACTION_LOCKS stays 3; the cascade freeing a contiguous run of Erased nodes is derived by the read, never written. Lock order: new version = upload intent, new scope keystore row, series, predecessor (safe because the new scope is reachable only through its intent); reopen = series, predecessor, self. The series projection orders by (created_at, id). Discarded: the stored head pointer (the series lock in every freeing transition, TRANSACTION_LOCKS 3 to 4, a forgotten head move blocks a series for ever), UNIQUE (series_id, version_no) alone, an advisory transaction lock, event sourcing. Reopened by a consumer that needs time travel or derived state over versions (approval workflows). Recorded in docs/adr/documents-custody-log.md D12.
```

```
- (decision by user) doc-9 option C amended (Sergio, direction of 2026-11-19, applied 2026-11-21): (1) document_series gains closed_at; a series erasure closes the series first, in one statement of its own outside every erasure transaction (ErasureLimits::TRANSACTION_LOCKS stays 3), and TakeVersionSlot refuses every new version and every reopen of a successor in a closed series with document-predecessor-not-ready, the declare refusing it early without a lock; the closing is for ever and is not carried by ReplayErasure. (2) POST /api/v1/documents/{id}/versions requires document.write AND document.download (RBAC, no row-level voter), so only an administrator files a version. (3) A slot conflict a concurrent free makes false is the option-C contract, a retryable conflict, not a risk; the series page naming uploaders to ADMIN mirrors the listing. Recorded in docs/adr/documents-custody-log.md D12 and PRODUCTION_SECURITY_CHECKLIST.md section 7.
```

```
- (decision by user) doc-9 series closing refined (Sergio, decision A of 2026-11-21): the series erasure closes the series before its first ledger write and opens it again only when the call that closed it fails with ErasureLedgerUnavailable (ledger entry certainly not durable) and has erased nothing; it never opens it after ErasureOutcomeUnknown, after this call erased a version, or when the series was already closed on entry. DocumentVersionSlots::closeSeries() reports whether its own statement closed the series and reopenSeries() undoes it, both refused inside a transaction and neither adding a lock (ErasureLimits::TRANSACTION_LOCKS stays 3). A run closes the series again before it reads what remains, so a concurrent run that reopened it leaves a visible, bounded window (PRODUCTION_SECURITY_CHECKLIST.md section 7, item seven).
```

**`stories.yaml` doc-9 `invoke_dev_with`, intended wording** — replace the sentence beginning "The successor-exclusion mechanism is decided: a series row whose head pointer moves …" through "… not the walk." with:

> The successor-exclusion mechanism is decided (option C, memlog): a document_series row per series used only as the mutex of the two transitions that take a slot (new version: intent, new scope keystore, series, predecessor; reopen: series, predecessor, self), no stored head pointer, and under that lock a lock-free recursive read of whether the predecessor has a live descendant; record it in the Documents ADR with its safety argument and the discarded alternatives. Erasure, the scan compare-and-sets, the cap sweep and ReplayErasure take no series lock, and ErasureLimits::TRANSACTION_LOCKS stays 3.

and add to the same entry: "A series erasure closes the series first (closed_at) so no version is filed or reopened in it afterwards (opened again only when its first ledger entry was certainly not durable and nothing was erased), and the new-version declare asks document.write and document.download." Then append to its "Tests:" sentence: "and the pairs new version against new version (one winner), new version against erasure, new version against reopen and reopen against reopen."

**`SPEC.md`, intended amendment (re-derived the same way):** in the "a version is its own `Document` aggregate" bullet, the paragraph that compares the two designs ends with "doc-9 chose a third: a series row used only as the mutex of the slot takes, with the slot derived by a lock-free read (ADR D12)"; the Open question "Version-slot mechanism" becomes "Decided by doc-9: ADR `documents-custody-log.md` D12".

## Review Triage Log

The three layers (Blind Hunter, Edge Case Hunter, Acceptance Auditor) ran over PR #1276 at `a3d3afcf`, in the coordinating
session. The hand-off of their findings did not say which layer raised each one, so a layer is named only where it was
stated (marked); every other row is "the three-layer review". Patches landed as the commit that follows this log.

| Finding | Layer | Route |
|---|---|---|
| `API Behat` red: `features/backoffice/users/erase.feature` inserted a `document` row with no `document_series` row, so `fk_document_series` refused it | three-layer review (CI) | Applied: the scenario inserts the series row first; the full Behat lane and every raw `INSERT INTO document` in the tree were checked |
| `DevStackUploadRows::remove()` left the `document_series` row a first version now inserts | three-layer review | Applied: deletes the series row it owns after the document, only while no other document holds the series |
| The new-version declare did not look at a closed series, so the controller docblock overclaimed and a body could be streamed to a refusal | three-layer review | Applied: `DeclareUpload::ensureSlotLooksFree` refuses early through `DocumentVersionSlots::isSeriesClosed()` with `document-predecessor-not-ready`; test added; Spec Change Log line updated |
| `SeriesErasure`, `SeriesErasureResource` and the controller said `remaining` can be non-empty because a version was filed during the run | three-layer review | Applied: the series closes first, so `remaining` is empty on a normal return; prose says so |
| `api/.credential-proof-policy` still said seven pinned routes | three-layer review | Applied: eight |
| `TakeVersionSlot` docblock said a closed series gates every reopen, while `RescanDocument` skips the take for a first version | three-layer review | Applied: the docblock states that a first version has no slot, so the closing does not gate its reopen (code kept) |
| "No existence oracle" comments overclaimed: `PredecessorNotReady` against `VersionSlotTaken` tells a caller that a predecessor is `Ready` with a live successor | Blind Hunter | Applied: the controller and `TakeVersionSlot` docblocks say exactly that, to an ADMIN only |
| `?afterId=` empty passed `Assert\Uuid` and then `Uuid::ensure('')` answered 400 instead of the documented 422 | three-layer review | Applied: `NotBlank(allowNull: true)` on `afterId`; route test added |
| No test for two versions sharing a `created_at` (id tiebreak, cursor across the tie) | three-layer review | Applied: `aKeysetPageCrossesTwoVersionsFiledInTheSameSecond` |
| No test for the replay branch that compares against an already-committed `Document` | three-layer review | Applied: `aReplayAgainstTheCommittedVersionIsAnsweredOnlyForTheSamePredecessor` |
| `closeSeries` inside an open transaction would keep the series row locked until it ended | three-layer review | Applied: refused with a `LogicException`; test added |
| `closed_at` is not carried by `ReplayErasure`, nor by a restore to an older dump or a down/up of the migration | three-layer review | Recorded once in section 7 (not a code comment) |
| `eraseSeries` is synchronous over N versions in one request, a cut-off surfaces as an unmapped exception, and declare and commit do not consult `ErasureLedgerOverride` | three-layer review | Recorded once in section 7 |
| Two concurrent rescans of one `ScanFailed` successor: the loser got `VersionSlotTaken` instead of `DocumentNotRescannable`, because the slot read counted the version being reopened | Edge Case Hunter (#5) | Applied: the reopen's slot read leaves its own version out (`isSlotHeld($predecessorId, $exceptId)`), the lock order series, predecessor, own row unchanged; test added |

### Open decisions

- **A. Closing before the first ledger write. RESOLVED (the owner's decision A, 2026-11-21).** The series erasure still closes the series before the first ledger write; when the call that closed it fails with `ErasureLedgerUnavailable` and has erased nothing, it opens the series again (`DocumentVersionSlots::reopenSeries()`) before propagating the 503. It never does after `ErasureOutcomeUnknown`, after this call erased a version, or when the series was already closed on entry; `closeSeries()` returns whether its own statement closed the series, so two callers never both believe they did. Two concurrent runs (one fails and reopens under the other) leave a bounded window that the second run's final closing and its `remaining` make visible, recorded in section 7 item seven.
- **B. A failed successor whose predecessor was erased singly.** A `ScanFailed` successor whose predecessor is erased by a plain single-document erase can neither be reopened nor succeeded (`TakeVersionSlot::forReopen()`); whether such a version should be reopenable, erased with it, or left as a tombstone is the owner's call.

## Verification

Rerun 2026-11-21 on the rebased branch (origin/main `9ef45a23`, no Documents change on main since the baseline), in the
per-worktree dev stack (`make app.dev`), after the changes of the 2026-11-21 Spec Change Log entry: `make php.stan` exit
0; `make php.prepush` (php.quality, php.quality.dry-run, full `php.unit`) exit 0 with 6038 tests, 10 skipped, the
live-stack gates running in the stack; `make php.behat c='features/documents'` 71 scenarios and 848 steps passed;
`doctrine:schema:validate` in sync after the migration was re-applied down and up. The earlier record follows.

Fresh runs in a PHP 8.5.12 toolbox container over a throwaway PostgreSQL 18 (`make app.dev` could not build the per-worktree images: the image build cannot reach `getcomposer.org` or PECL from this session's network), each `make` target with `IN_CONTAINER=false`:

- `make php.stan` on the 54 changed PHP files: exit 0. The full tree through PHPStan (`vendor/bin/phpstan analyse` from `/app/api`, as the dev container runs it): exit 0.
- `make php.quality` and `make php.quality.dry-run` (`-k`): every member green except `php.stan`, which fails only in this toolbox on its result-cache path (`../../var/cache` resolved from the checkout, `/var/cache` in the dev container) — the same analysis run from `/app/api` is green. `php.lint.route-manifest`, `php.lint.config-reference`, `php.lint.credential-proof`, `php.lint.bounded-context`, `php.deptrac`, `php.lint.documents-ingest`, `php.lint.document-custody`, `php.lint.person-reference`, `php.lint.step-vocabulary`, `php.lint.doctrine` and `composer.check.missing-deps` all ran and passed.
- The migration up, down and up on the dev database, the backfill checked over a planted pre-existing document, and `doctrine:schema:validate` in sync.
- `make php.unit c='--filter "Documents|Document|CredentialProof|IntNarrowingConfinementGateTest|BoundedContext"'`: 874 tests, green (7 skipped as on `main`).
- Full `make php.unit`: 6031 tests; the only errors are the 21 live-stack gates that need the Caddy edge (`AccessLogQueryContainmentGateTest`, `RequestBodyBoundGateTest`, `UploadPathAnonymousBoundGateTest`, `UploadFilenameAccessLogContainmentGateTest`, `UploadSpoolConcurrencyGateTest`, `BankLogoThroughCaddyTest`), left to CI; the one failure it found (`IntNarrowingConfinementGateTest` on the series repository) was fixed and the gate rerun green.
- `make php.behat c='features/documents'`: 70 scenarios, 837 steps, green, `versions.feature` included.
