---
title: 'doc-6: Custody trail listing and document metadata'
type: 'feature'
created: '2026-11-17'
status: 'done'
baseline_commit: 'f6a5622c736dc1784a8265d80cfb723b36cad037'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/specs/spec-documents-context/SPEC.md'
  - '{project-root}/docs/adr/documents-custody-log.md'
  - '{project-root}/docs/adr/api-resource-dtos.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** CAP-6 does not exist: a document's metadata and its ordered custody trail can be read by nobody, so an operator cannot tell whether a document is `Received`, `Ready` or `Quarantined`, nor see an erased document's trail. The original filename is sealed and `Keystore` only fetches one wrapped DEK per call, so a page of N names would cost N keystore queries.

**Approach:** Two ADMIN-only routes: a capped keyset page of documents (`GET /api/v1/documents`) and one document's metadata with its ordered custody trail, paged by `seq` (`GET /api/v1/documents/{id}/custody`). A `DocumentNameOpener` opens the sealed names of a page through a new batch seam (one keystore query per page) and degrades per document, never failing the page.

**Contract:** `stories.yaml` entry `doc-6` is the owner's contract and prevails over any summary here. Every rule in its `description` and `invoke_dev_with` applies verbatim; this file adds the code map, the order of the work and the decisions below.

## Boundaries & Constraints

**Always:**
- **Authorization (decided, the user, 2026-11-17): ADMIN only, through a new non-tier verb `document.custody`**, exactly as `document.download`: `DocumentPermission::CUSTODY`, a `'document.custody' => [ADMIN]` row in `EXPLICIT_GRANTS`, `PermissionCatalog` entry, `TIER_VERBS` and `TIER_OPT_OUT` untouched. Both routes carry `#[IsGranted]`.
- **Routes:** `documents_list` `GET /api/v1/documents` and `documents_custody` `GET /api/v1/documents/{id}/custody` (constants in `UploadRoutes`), no `_audit_canonical`: the generic access hook writes its ACTIVITY row, and no `DOCUMENT_READ`-class row exists (CAP-9 audits byte reads only; recorded in `PRODUCTION_SECURITY_CHECKLIST.md` §7). The custody route sets the audit resource type so that row names the document. HEAD is refused as in `ReadDocumentController`; `Uuid::ensure()` first.
- **Ledger override:** both routes answer 410 `gone` while `ErasureLedgerOverride::isActive()`, before any lookup (after a restore without the erasure ledger an erased document's key is back and its name would open). `documents.read_enabled` does NOT gate them: they serve no bytes.
- **Page:** `SearchQuery` + the keyset engine, default order `createdAt` descending with the id tie-break, limit capped at the engine's 100, one filter (`state`, equality only). Items carry `id`, `seriesId`, `predecessorId`, `state`, `digest`, `sizeBytes`, `name` (string or null), `retentionReason`, `legalBasis`, `reviewAt`, `createdAt`; never the uploader id, the scope id or the ciphertext. Erased documents are listed, with `name: null`.
- **Trail:** `GET …/{id}/custody` returns custody rows ascending by `seq`: `seq`, `event`, `actorId` (nullable; the erased-person marker is returned as stored, documented as a marker and never a user), `reason` (nullable), `occurredAt`. Paged by a plain cursor on `seq`, not the multi-document keyset engine: query `afterSeq` (integer ≥ 0, absent = from the start) and `limit` (default 25, max 100, via the shared `WirePaginationPolicy`); a malformed or out-of-range value answers 422 `validation-failed`. The cursor is scoped to the document in the query (`WHERE document_id = :id AND seq > :afterSeq ORDER BY seq LIMIT :limit + 1`, served by `UNIQUE(document_id, seq)`); the response carries `nextAfterSeq` (null on the last page). The document's metadata and `name` are returned ONLY when `afterSeq` is absent (the first page), so a later page opens no name and costs no keystore query. A row appended between two requests appears on a later page; no snapshot is promised across requests. An `Erased` document answers 200 with its trail and no name; an unknown id answers 404 `document-not-found` on every page.
- **Names (SPEC):** the sealed name is opened once per document, never per custody row. A page costs ONE keystore query: `Keystore` gains a batch method returning the usable wrapped DEKs of the requested scopes in one `WHERE … IN`, and the encryptor port gains a batch open that returns, per item, the plaintext or the crypto exception it would have thrown — a failure of one document never throws and never reaches another. An `Erased` row (ciphertext NULL) triggers no keystore lookup; a destroyed or never-minted scope lists `name: null` silently; any other failure (`decryption-failed`, a corrupt live key row) on a non-`Erased` document lists `name: null`, and a decrypt failure never fails the page. A keystore or database outage still fails it (5xx).
- **Alarm, not a per-request log:** the request path logs nothing about a name. A new scheduled report (`ReportDocumentNamesHandler`, daily, beside the storage and scan reports in `DocumentsMaintenanceSchedule`) opens the names of non-`Erased` documents in id-ordered batches through the same opener, under a wall-clock budget, and writes ONE aggregate line per run on the `observability` channel with the counts per cause — `key-missing` (a destroyed or never-minted key), `decryption-failed` (a failed authentication or a corrupt live key row, both `DecryptionFailed`) and `unreadable` (any other per-document failure) — and at most 20 document ids, never a filename, scope or actor.
- **Registers in the same PR:** `DocumentNameOpener` in `CryptoReadBoundaryGateTest::READERS` with its functional test and an `api-error-contract.md` line citing its path and `dek-destroyed`; the handler in `BestEffortReportChannelGateTest::REPORTERS`; `api/.route-manifest.json` regenerated; both routes `ordinary` in `api/.credential-proof-policy`; a "Documents listing" section in `docs/api-error-contract.md`; the §7 record that names are listed with no byte-read audit row, and the listing's override-only gating; `docs/architecture-api.md` and `api/CLAUDE.md` for the new surface.
- A response containing names carries `Cache-Control: no-store`.

**Never:**
- A filename in a header, a log line, an audit row or an error body.
- A per-custody-row or per-document keystore query on the page; a marker on `DekDestroyed`/`DecryptionFailed`; a page that fails because one name cannot open.
- Decrypting names on the request path to raise an alarm; a new problem type for an unopenable name (the field is simply null).
- Widening the permission to a tier verb, or opening `documents.read_enabled`.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Page, happy | ADMIN, 3 documents | 200, newest first, names opened, one keystore query | N/A |
| Page cap | `limit=500` | Refused by the engine's cap (422) | existing `validation-failed` |
| Erased in page | one `Erased` row, ciphertext NULL | listed, `name: null`, no keystore lookup for it | N/A |
| Destroyed scope in page | live-state row whose scope is destroyed | listed, `name: null`; other names intact | none raised on the request |
| Tampered ciphertext in page | non-`Erased`, authentication fails | `name: null`, rest of page intact | alarm only via the scheduled report |
| Trail, first page | existing id, no `afterSeq` | 200: metadata + first rows by `seq`, name once, `nextAfterSeq` | N/A |
| Trail, later page | `afterSeq=<n>` | 200: next rows only, no metadata, no name, no keystore query | N/A |
| Trail > 100 rows | document with 130 rows, `limit=100` then `afterSeq` | two pages, no loss, no duplicate, `nextAfterSeq` null on the last | N/A |
| Trail of erased | `Erased` id | 200, trail kept, `name: null` | N/A |
| Trail bad cursor | `afterSeq=-1`, `afterSeq=x`, `limit=101` | 422 | existing `validation-failed` |
| Unknown / malformed id | random / `x` | 404 `document-not-found` / 400 `invalid-uuid` | existing contract |
| Not admin | EDITOR/MANAGER/VIEWER, no session | 403 / 401, nothing read | existing contract |
| Restore without ledger | override active | 410 `gone` on both routes | existing `Gone` marker |
| HEAD | HEAD | 405 | as doc-5 |
| Keystore outage | batch query throws | 5xx, no partial page | untranslated |

</frozen-after-approval>

## Code Map

- `api/src/Documents/Document/Infrastructure/Controller/ReadDocumentController.php` -- template for HEAD refusal, `Uuid::ensure`, override check, `final readonly` shape; do not change.
- `api/src/Documents/Document/Infrastructure/Http/UploadRoutes.php`, `Security/DocumentPermission.php` -- add the `LIST` and `CUSTODY` route names and the `CUSTODY` permission.
- `api/src/Iam/Identity/Infrastructure/Security/PermissionCatalog.php`, `StaticAuthorizationPolicy.php` (`EXPLICIT_GRANTS`, ~line 83) -- one verb, one row.
- `api/src/Documents/Document/Domain/Entity/Document.php`, `DocumentCustody.php`, `CustodyEvent.php` -- columns to project; no read queries exist yet (`DocumentRepository` has none, `DocumentCustodyRepository` only `nextSequenceFor/append/anonymiseActor`; add a paged `afterSeq` read there, a read-only query that the `php.lint.document-custody` write gate does not touch). `Application/Scanning/RescanDocument.php:61` appends `RescanRequested` with no cap, which is why the trail is paged.
- `api/src/Documents/Document/Application/Upload/CommitUpload.php:61,87` -- `filenameAssociatedData()` and the seal call; the opener decrypts with `EncryptionScopeId::forDocument($scopeId)` and that AAD.
- `api/src/Shared/Crypto/Application/Keystore.php`, `Infrastructure/Persistence/DbalKeystore.php:42` -- per-scope `wrappedDekFor`; add the batch beside it. `Shared/Crypto/Infrastructure/{ScopeKeyring,SodiumEnvelopeEncryptor}.php` -- unwrap lives only in `ScopeKeyring`; the batch open goes through it.
- `api/src/Shared/Crypto/Application/EnvelopeEncryptor.php` -- port gains the batch open (counts as a READ for the gate).
- `api/src/Shared/Search/**` (`SearchQuery`, `DoctrineSearchEngine`, `SearchResponder`, `WirePaginationPolicy`) and `Backoffice/BankAccount/Infrastructure/Controller/BankAccountSearchCollectionController.php` + `DoctrineBankAccountCollectionSearchRepository.php` -- the list pattern to mirror (`SELECT NEW` projection, field maps, responder).
- `api/src/Documents/Document/Application/Resource/`, `Infrastructure/Http/UploadResourceMapper.php`, `docs/adr/api-resource-dtos.md` -- flat readonly DTOs, ATOM timestamps (`ResourceDtoContractTest`).
- `api/src/Documents/Document/Infrastructure/Messenger/Maintenance/{ReportDocumentStorageHandler,ReportDocumentScansHandler,DocumentsMaintenanceSchedule}.php` -- model for the daily report (raw DBAL, ≤20 ids, observability channel).
- `api/tests/Unit/Gate/{CryptoReadBoundaryGateTest,BestEffortReportChannelGateTest}.php`, `docs/api-error-contract.md` (~lines 285-336, 367) -- registrations.
- `api/.route-manifest.json` (`make sf.routes.manifest`), `api/.credential-proof-policy` (~122), `api/.behat-step-vocabulary`; fixtures `tests/Functional/Documents/Document/PlantsDocumentRows.php`, `ScriptedUnsealEncryptor` pattern.

## Tasks & Acceptance

**Execution:**
- [x] `Shared/Crypto` -- `Keystore` batch method (one query, usable rows only; a corrupt live row degrades its own entry), `EnvelopeEncryptor` batch open with per-item outcome, implemented in `SodiumEnvelopeEncryptor` over `ScopeKeyring` -- the seam the SPEC requires
- [x] `Documents/Application/Listing/DocumentNameOpener` -- opens names for a set of rows, skipping Erased/NULL, one batch call, returns name or failure cause per document
- [x] `Documents/Application` + `Infrastructure/Persistence` -- list projection and searcher (keyset, `state` filter, caps), custody trail read by `afterSeq` cursor (document-scoped, `limit + 1` to derive `nextAfterSeq`), a query DTO with `#[Assert]` for `afterSeq`/`limit`, per-view flat Resource DTOs and mapper (trail page DTO carries scalars and a list of flat rows)
- [x] `Documents/Infrastructure/Controller` -- `ListDocumentsController`, `DocumentCustodyController`; `UploadRoutes`, `DocumentPermission::CUSTODY`, `PermissionCatalog`, `StaticAuthorizationPolicy`
- [x] `ReportDocumentNamesHandler` + message + schedule entry -- the daily alarm line
- [x] Tests: opener and batch keystore (functional, including a destroyed scope and a tampered name inside one page, and a query-count assertion), both routes (functional, voter, 400/404/410/405/422, erased trail, ordering, cap; a 130-row trail walked to its end with no loss or duplicate; a cursor never crossing into another document with the same `seq` values; a row appended between pages; a later page costing no keystore query), controller unit, handler, policy test, Behat `features/documents/custody.feature`
- [x] Registries and docs listed under Always (manifest, credential-proof, READERS, REPORTERS, error contract, §7, architecture, `api/CLAUDE.md`)

**Acceptance Criteria:**
- Given a page holding a destroyed scope and a tampered name, when an ADMIN lists, then the page answers 200, those two names are null, the others are intact, and the page cost one keystore query.
- Given an EDITOR, MANAGER or VIEWER, when either route is called, then it is refused and nothing is read.
- Given an erased document, when its trail is read, then its custody rows are returned in `seq` order across pages and the name is null.
- Given a document with more than 100 custody rows, when the trail is walked with `nextAfterSeq`, then each response holds at most 100 rows, none is lost or repeated, and the metadata and name appear only on the first page.
- Given the scheduled report, when non-erased documents' names cannot open, then ONE aggregate observability line carries the count per cause (`key-missing`, `decryption-failed`, `unreadable`) and at most 20 ids (a 21st is counted, not listed), and no filename, scope or actor.
- Given a trail whose actor was erased, when it is read, then `actorId` is the erased-person marker as stored, documented as a marker and never a user.
- Given an ADMIN reads a trail, when the request ends, then the generic ACTIVITY row names the document as its resource and carries no name, digest or actor.
- Given the override is active, when either route is called, then it answers 410 whatever the id.

## Implementation Notes

## Spec Change Log

## Review Triage Log

Layers: Blind Hunter, Edge Case Hunter and Acceptance Auditor (with the verification-gap instructions), run as three parallel read-only subagents over the diff since `baseline_commit`, in this worktree, before the first commit. Before them, an external design review of the draft spec (Codex, read-only) closed the unbounded trail, the alarm's causes, the marker AC and the error-contract gate.

| # | Finding | Verdict | Route | Evidence |
|---|---------|---------|-------|----------|
| 1 | A run that stops on budget with no failing name writes nothing, so `complete: false` is invisible; §7 and ADR D10 claim it says when it did not finish | medium | patch | `ReportDocumentNamesHandler` returns when `0 === $failed`; log a line when `!$complete` too |
| 2 | The report keeps every failing id and sorts at the end | low | patch | batches arrive in ascending id, keep only the first 20 |
| 3 | Report tests: one batch only, no budget-expiry case, `complete: false` and `examined` of a partial run unasserted; failure-case filter only `Error` level | medium | patch | batch size becomes a constructor argument beside the budget; add two-batch and expiry tests; assert over every record |
| 4 | Corrupt live key row and failed unwrap inside `decryptMany` never exercised | medium | patch | deleting `ScopeKeyring.php:92` demotes a corrupt key to `key-missing`; replacing `unwrapOrFailure` 500s the page; both stay green |
| 5 | Trail default `limit` 25 and `afterSeq=0` unpinned | medium | patch | no test omits `limit` over >25 rows; `afterSeq=0` follows the frozen "metadata only when `afterSeq` is absent", so it is pinned as the later-page shape |
| 6 | "410 whatever the id" is false for a malformed id (`Uuid::ensure` precedes the override, as in doc-5) | low | patch | reword ADR D10 and the error contract to "any well-formed id"; the code order is the spec's own ("`Uuid::ensure()` first") |
| 7 | "carries no actor" is false: the generic access row attributes the call to the ADMIN who made it | medium | patch | reword §7, the controller docblock and the scenario to "no name, digest or custody actor"; assert the row's `actor_id` is the caller |
| 8 | Plaintext keys unwiped when `keysForOpening` throws midway | low | patch | wipe the keys already unwrapped, then rethrow |
| 9 | Opener calls `decryptMany([])` when every source is nameless | low | patch | skip the call; assert zero keystore reads |
| 10 | Ticket reference and dated decision in new docblocks | low | patch | `(#418)` in `DocumentNameOpener`, "(the owner, 2026-11-17)" in `DocumentPermission` and `StaticAuthorizationPolicy` |
| 11 | `ListDocuments::nameSourceOf` is a public static on a sibling use case | low | patch | move it to `NameSource` |
| 12 | Test `theRowsComeBackBySeqWhateverOrderTheyWereWritten` does not do what its name and comment say | low | patch | insert out of order or rename |
| 13 | Equal-`createdAt` tie-break across a page boundary, and an explicit `sort`, untested | low | patch | add the test |
| 14 | Docs missed: `source-tree-analysis.md`, `claude-code-quickref.md`, `api/docs/`, `architecture-api.md:311` ("Nine ticks"), `deployment-guide.md:294` alarm list | medium | patch | CLAUDE.md "Keeping docs up to date" |
| 15 | Report counts an erasure that commits between its SELECT and the open as a `key-missing` alarm | low | rejected | one false line at most per race; the guard adds a re-read for no everyday case |
| 16 | Exception objects returned as values by the batch seam | low | rejected | a typed result is one more type; the daily run builds them once per failing document |
| 17 | A later trail page hydrates the whole row to answer 404 | low | rejected | one row by primary key; a cheaper existence method is a new port method for no measured cost |
| 18 | No config switch closes the routes like `documents.read_enabled` | n/a | rejected | the spec's frozen decision (override-only gating, recorded in §7 with doc-7 as owner); raised to the owner in the summary |
| 19 | `#[MapQueryString]` resolves before `#[IsGranted]`, so a non-admin with a bad query gets 422 | low | rejected | reads nothing, same order as sibling routes |
| 20 | 410 verified at controller level only | low | rejected | the only adapter is never set; same as doc-5 |

## Design Notes

The alarm lives in a scheduled probe, not in the request, because a page of broken names would otherwise write one error line per name per request into the prod buffered log, and an unopenable name on a document nobody lists would never alarm at all. The probe reuses the opener, so there is one place that knows how a name opens and one `READERS` entry. The trail is paged because it is not bounded: the scan caps bound the automatic rows, but `RescanDocument` appends a row per manual rescan with no limit, and a tail of the log is not something to hide behind a `truncated` flag. The cursor is a plain `seq` rather than the keyset engine, which models a multi-document filtered, ordered set; here the order is fixed and `(document_id, seq)` is already unique and indexed. Discarded: capping rescans per document (a business policy on a different use case that would not bound future event sources) and a hard cap with `truncated` (no way to reach the older or newer rows). Metadata and name ride the first page only so that walking a long trail costs one name open.

## Verification

**Commands:**
- `make php.lint.error-contract` -- expected: exit 0
- `make php.stan` -- expected: no errors
- `make php.quality` then `make php.quality.dry-run` -- expected: exit 0
- `make php.unit c='--filter Document'` and `make php.behat` for `features/documents/custody.feature` -- expected: green
