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
- 2026-11-19 (the owner, recorded in the documents handoff; applied 2026-11-21): reduce doc-9's accepted risks to zero. Three changes supersede the frozen bullets they touch. (1) **A series erasure terminates**: it first closes the series (`document_series.closed_at`, under the series row lock, in its own transaction outside every erasure transaction, so `TRANSACTION_LOCKS` stays 3), and both takes refuse a closed series with `document-predecessor-not-ready`, as does the declare; the frozen "guarantees no termination" no longer holds. (2) **`POST …/versions` asks `document.write` AND `document.download`** (the frozen "RBAC `document.write`" is narrowed; still RBAC, still no row-level voter), so in practice ADMIN only. (3) **No §7 acceptance**: the false 409 is recorded as the option-C contract (a retryable conflict) in ADR D12 and the error contract, the series page's uploader ids are stated as mirroring the listing, and the §7 entry is closed rather than accepted. Known-bad state avoided: an erasure that could be outrun by a concurrent filer, and an editor filing a version of a document only an ADMIN may read.
- 2026-11-21 (review, `bmad-build` step 4): a closed series answers its own problem type, `document-series-closed` (`SeriesClosed`, `Conflict`), rather than `document-predecessor-not-ready`, whose title was false for a `Ready` predecessor and whose remedy differs (a closed series never opens again); and the reopen of a failed FIRST version takes the series row too, so nothing in a closed series becomes live (the frozen "a first version has no slot and takes no series lock" still holds for the slot; only the closed check was added). Known-bad state avoided: a first version reopened and readable inside a series being erased. KEEP: option C untouched, `TRANSACTION_LOCKS` 3, freeing transitions off the series lock.

### To apply locally with memlog.py

`_bmad/scripts/memlog.py` is not installed in the cloud session that implemented this story, and the memlog is written only through it, so neither `.memlog.md` nor `stories.yaml` was edited here. To apply locally:

**Memlog entry** (append, then re-derive `stories.yaml`):

```
- (decision by user) doc-9 successor exclusion refined (Sergio, 2026-11-19, after the architect consultation): option C, the architect's refinement of design B, supersedes the design-B head pointer recorded above. A document_series row exists per series (inserted with the first version, backfilled by the doc-9 migration, referenced by document.series_id) and is used ONLY as a mutex (SELECT … FOR UPDATE) by the two transitions that take a version slot: filing a new version and reopening a ScanFailed successor; no head pointer is stored. Under that lock the take rechecks the predecessor under its own FOR UPDATE (Ready for a new version, not Erased for a reopen) and asks a lock-free recursive read whether the predecessor has a live strict descendant (live = Received, Scanning, Ready; Quarantined, ScanFailed and Erased hold no slot). A version becomes live only under the series lock and outside it versions only stop being live, so a concurrent free costs at most a retryable false 409, never a double successor. Erasure, the scan compare-and-sets (settle, returnToReceived, failCapped), the cap sweep and ReplayErasure take no series lock; ErasureLimits::TRANSACTION_LOCKS stays 3; the cascade freeing a contiguous run of Erased nodes is derived by the read, never written. Lock order: new version = upload intent, new scope keystore row, series, predecessor (safe because the new scope is reachable only through its intent); reopen = series, predecessor, self. The series projection orders by (created_at, id). Discarded: the stored head pointer (the series lock in every freeing transition, TRANSACTION_LOCKS 3 to 4, a forgotten head move blocks a series for ever), UNIQUE (series_id, version_no) alone, an advisory transaction lock, event sourcing. Reopened by a consumer that needs time travel or derived state over versions (approval workflows). Recorded in docs/adr/documents-custody-log.md D12.
```

**`stories.yaml` doc-9 `invoke_dev_with`, intended wording** — replace the sentence beginning "The successor-exclusion mechanism is decided: a series row whose head pointer moves …" through "… not the walk." with:

> The successor-exclusion mechanism is decided (option C, memlog): a document_series row per series used only as the mutex of the two transitions that take a slot (new version: intent, new scope keystore, series, predecessor; reopen: series, predecessor, self), no stored head pointer, and under that lock a lock-free recursive read of whether the predecessor has a live descendant; record it in the Documents ADR with its safety argument and the discarded alternatives. Erasure, the scan compare-and-sets, the cap sweep and ReplayErasure take no series lock, and ErasureLimits::TRANSACTION_LOCKS stays 3.

and append to its "Tests:" sentence: "and the pairs new version against new version (one winner), new version against erasure, new version against reopen and reopen against reopen."

**`SPEC.md`, intended amendment (re-derived the same way):** in the "a version is its own `Document` aggregate" bullet, the paragraph that compares the two designs ends with "doc-9 chose a third: a series row used only as the mutex of the slot takes, with the slot derived by a lock-free read (ADR D12)"; the Open question "Version-slot mechanism" becomes "Decided by doc-9: ADR `documents-custody-log.md` D12".

## Review Triage Log

Four layers ran on 2026-11-21, in parallel and read-only, over `98d6d449..02af5ad` (the implementation plus the owner's three directions), in the cloud session that closed the story: Blind Hunter (context-free, the adversarial pass this story owes), Edge Case Hunter and Verification Gap (`bmad-build` step 4), and Acceptance Auditor (root `CLAUDE.md`). BH = Blind Hunter, EC = Edge Case Hunter, VG = Verification Gap, AA = Acceptance Auditor.

| # | Finding (layers) | Verdict | Route and evidence |
|---|---|---|---|
| 1 | `features/backoffice/users/erase.feature` seeds a `document` with no `document_series` row (EC, VG, AA) | high | patch — `fk_document_series` rejects the seed, the "a person's erasure touches no document" acceptance went red; the series row is seeded first |
| 2 | A failed FIRST version reopens inside a closed series (BH, EC, AA) | medium | patch — `RescanDocument` skipped the take for a first version; `TakeVersionSlot::forFirstVersionReopen()` now takes the series row and refuses a closed one |
| 3 | `document-predecessor-not-ready` answers a closed series whose predecessor IS `Ready` (BH) | medium | patch — distinct `document-series-closed`; recorded in the error contract and ADR D12 |
| 4 | The take's series-row `FOR UPDATE` is never tested apart from the predecessor lock (VG) | medium | patch — `everyTakeWaitsOnTheSeriesRowAloneSoASeriesCloseCannotBeOutrun` holds only the series row |
| 5 | The series erasure's stop-at-first-failure path and its one ledger entry per version are untested (VG, BH, AA) | medium | patch — `aSeriesErasureStopsAtTheFirstLedgerFailureKeepsWhatItErasedAndARepeatFinishesIt` (`LedgerFailingFromTheNthWrite`) |
| 6 | The series page's 410 under the ledger override and its HEAD refusal are untested (VG, BH, AA) | medium | patch — two cases in `DocumentListingControllersTest` with the repository asserted never read |
| 7 | `answerDocument()`'s predecessor comparison is untested (VG) | medium | patch — the test written for it exposed a defect: a replay of a filed version's key, once its intent is pruned, answered `document-version-slot-taken` (the version holds its own slot); the declare now answers a key a document holds before the early slot refusal |
| 8 | Stale `SeriesErasure` / `SeriesErasureResource` docblocks ("a version filed meanwhile") (BH, EC, VG, AA) | low | patch |
| 9 | `.credential-proof-policy` header, the §7 doc-7 line and a gate-test name still say seven / one Documents route (BH, AA) | low | patch |
| 10 | Documents feature Backgrounds do not reset `document_series`, so a closed stamp survives (BH, EC, AA) | low | patch — all six Backgrounds truncate it |
| 11 | "Exactly three foreign keys" is false now that the schema holds five (AA) | low | patch in `api/.person-reference-policy` and `docs/rules/database.md`; root `CLAUDE.md` is an agent-context file and is left to the owner (deferred, named in the PR) |
| 12 | The version declare's per-actor budget spend is untested (VG) | low | patch — `aSpentDeclareBudgetRateLimitsAVersionDeclareAndCreatesNothing` on an administrator of its own |
| 13 | The two new foreign keys and `closed_at` are not proven in the schema test (AA) | low | patch — presence, `NO ACTION` both ways, orphan refusals, nullable `closed_at` |
| 14 | Tombstone retention fields asserted only as NULL; ordering does not separate `(created_at, id)` from either alone (AA) | low | patch — real retention values on page and wire; a later-stamped version and a same-second tie |
| 15 | Out-of-range `limit` untested (AA) | low | patch — `limit=0` and `limit=101` answer 422 |
| 16 | `UploadIntent` validates the predecessor through the three-id helper (BH) | low | patch — `ensureLowerCaseUuid()` |
| 17 | `VersionSlotTaken`, `DeclareUpload` and `DocumentPermission::DOWNLOAD` docblocks out of date (BH, AA) | low | patch |
| 18 | A repeat over an erased series logs "a series was erased" (BH) | low | patch — no line when nothing was erased |
| 19 | `evidence-lifecycle.md` still says "once doc-9 ships" (AA) | low | patch |
| 20 | Page size clamped at the edge, the use case and the repository (BH) | false | the use-case clamp is the module's pattern (`ReadCustodyTrail`) for non-HTTP callers; no wrong outcome |
| 21 | `versions.feature` has no series-page or closed-series scenario (BH) | false | both are pinned over HTTP by `DocumentVersionRoutesFunctionalTest`; no behaviour is unpinned |
| 22 | Size bounds on the version path untested (AA) | low, rejected | the bounds are `declareFor()`'s, shared with the first-version declare and tested there |
| 23 | One request erases a whole series with no cap (BH) | maybe-false | decision for the owner (a per-call cap would change the frozen "every non-`Erased` version"); asked in the PR, not changed |
| 24 | `stories.yaml` / `SPEC.md` still carry the head pointer (BH) | known | the memlog step below; `memlog.py` is now installed, applying it is the owner's call |
| 25 | This section and Verification predate the second commit (BH) | low | patch — rewritten below |

## Verification

Fresh runs on 2026-11-21 over the final tree (the review patches plus `origin/main` merged in), in this cloud session's per-worktree stack. The `php` image was built from a copy of `api/Dockerfile` without `apcu`, `xdebug` or the `getcomposer.org` download, which this session's network refuses; `vendor/` was installed with `--prefer-source` and then through `make composer` in the container, so the Composer plugins and scripts ran.

- `make php.stan` (full tree): exit 0.
- `make php.quality` (apply) then `make -k php.quality.dry-run`: every member green except `composer.check.missing-deps`, which stops on `Extension "apcu" does not exist` — the image here lacks it, CI's does not.
- `make php.deptrac`: 0 violations, 0 uncovered.
- The migration down and up on the dev database, then `doctrine:schema:validate`: mapping correct, schema in sync, `closed_at` included.
- `make php.unit`: 6047 tests, 53914 assertions, green (10 skipped).
- `make php.behat`: 619 scenarios, 6118 steps, green — `features/backoffice/users/erase.feature` included, which the first round never ran.
