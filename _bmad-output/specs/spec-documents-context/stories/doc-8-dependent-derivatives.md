---
title: 'doc-8: Dependent derivatives through the images module'
type: 'feature'
created: '2026-11-19'
status: 'in-progress'
baseline_commit: '3f96ac340e06e592e058f672c390dee0b7a8c544'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/specs/spec-documents-context/SPEC.md'
  - '{project-root}/docs/adr/images-vs-documents-conservation-contract.md'
  - '{project-root}/docs/adr/image-deletion-signal-transport.md'
  - '{project-root}/docs/adr/documents-custody-log.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** CAP-5 does not exist. `Image` records no origin, so a page preview of an erased contract is indistinguishable from a fungible logo and nothing can find it to delete it; there is no entry point through which another context materialises a derivative, and nothing releases derivatives when their document is erased.

**Approach:** The origin lives on the derivative (ADR D5): two nullable columns on `image`. A third Images entry point stores the canonical bytes and inserts the row only under a lock the owning context's guard takes on the origin. The owner's sweep releases derivatives after erasure by re-querying by origin until none is left, and alarms when an erased document's derivatives outlive a bound.

**Contract:** `stories.yaml` entry `doc-8` is the owner's contract and prevails over any summary here.

## Boundaries & Constraints

**Decided by the user, 2026-11-19 (after an architect review and an adversarial review run as stand-in subagents — the usual external reviewers were unavailable in that session — which reached the same outcome independently):**
- **Scope is the seam and the lifecycle, no producer.** No renderer, no new image library, no route that materialises anything. The seam is exercised by tests and waits for its first generator.
- **The origin is on `image`** (`origin_type`, `origin_id`, a `CHECK` that they are set together, a partial index), behind a `DerivativeOrigin` value object in `Shared/Images/Domain` that names no consumer (`Documents.Document` is a string the caller supplies). Discarded: a link table owned by `Documents` — it is D5's "remembered out of band", an image whose link never commits is invisible to every sweep, and `ImageHolder` holds one id, not a set.
- **The module keeps the name `Images`** (ADR D4 trigger fired and declined). `erpify.shared.image.deletion_requested` and `Shared.Image` are persisted for ever and are never renamed; the next trigger is the first derivative that is not a canonical raster image.
- **Alarm bound 6 h** (`ErasureLimits::DERIVATIVE_ALARM_SECONDS`), twice the async retry horizon, measured from the `Erased` custody row; one `error` line, ids and counts only.

**Design (decided here, recorded in ADR D8):**
- `MaterialiseDerivative::materialise(DerivativeOrigin, OriginGuard, bytes, ?mediaType)`: build the aggregate, store the canonical bytes outside any transaction, then one transaction: `guard->admits(origin)` locks the origin and answers whether it may receive a derivative; refused → `DerivativeOriginNotLive`, and the stored bytes are deleted after the rollback; admitted → insert. A failure whose outcome is unknown deletes the bytes only when the row is confirmed absent.
- `OriginGuard` is an Images `Application` port the caller passes per call (the `ImageHolder` pattern): `Shared` imports nothing from `Documents`. `Documents` implements it in `Infrastructure` with `SELECT state FROM document WHERE id = … FOR SHARE` and admits only `Ready`. That lock conflicts with the erasure's `FOR UPDATE`, so a derivative either commits before the erasure or is refused after it; there is no lock cycle because the erasure never touches `image`.
- `ReleaseDerivatives::of(DerivativeOrigin, limit)`: re-queries the ids by origin and publishes `ImageDeletionRequested` for each, in one transaction (the publish guard requires one); answers how many it queued. Second registered publisher of the event (`ImageDeletionRequestedPublisherGateTest`).
- `Documents` owns the policy: `ReleaseErasedDerivatives` (every 5 min, `documents_maintenance`) pages `Erased` rows with `derivatives_released_at IS NULL`, calls `ReleaseDerivatives`, and sets the marker only when a call finds nothing — a confirmed absence, never "published". `ApplyErasure` is not touched (its statement budget, and invariant 4: no removal inside the owner's transaction). Duplicates while a deletion is in flight are absorbed by `DeleteImage`'s idempotence.
- `image.origin_id` is classified `non-person` in `api/.person-reference-policy` (a document id denotes no person); a person-denoting origin type would reopen the line.

## I/O & Edge-Case Matrix

| Case | Expected |
|---|---|
| Materialise for a `Ready` document | row with the origin; bytes retrievable through `GET /api/v1/images/{id}` |
| Materialise for `Received`/`Scanning`/`Quarantined`/`ScanFailed`/`Erased`, or an unknown id | `DerivativeOriginNotLive`; no row; stored bytes deleted |
| Materialise racing the erasure | either the row commits first and the sweep releases it, or the insert is refused; never a row over an erased origin left unreleased |
| Erasure of a document with derivatives | sweep queues one request per row; next tick finds none and sets `derivatives_released_at`; image route answers 404; storage key gone |
| A deletion request dead-lettered | origin still on the row, so the next tick re-queues it |
| Erased document without derivatives | first tick finds none and sets the marker |
| Derivatives not released 6 h after the `Erased` custody row | one `error` line: count, bound, up to 20 document ids |
| Fungible image (no origin) | unchanged behaviour; `ImageAttachment` attaches only ids it minted, so a derivative never reaches a holder |

</frozen-after-approval>

## Code Map

- `api/src/Shared/Images/Domain/Entity/Image.php`, `Domain/Repository/ImageRepository.php`, `Infrastructure/Persistence/Doctrine/DoctrineImageRepository.php` — origin columns, `idsByOrigin`.
- `api/src/Shared/Images/Application/UploadImage.php`, `ImageAttachment.php` — the write and release templates the new use cases follow.
- `api/src/Documents/Document/Application/Erasure/DeleteErasedObjects.php`, `Domain/Erasure/{DocumentErasures,ErasureLimits}.php`, `Infrastructure/Persistence/Doctrine/DbalDocumentErasures.php`, `Infrastructure/Messenger/Maintenance/*` — sweep, marker and alarm templates.
- Gates: `ImageWriterSurfaceGateTest` (writer set), `ImageDeletionRequestedPublisherGateTest` (publisher set), `api/.person-reference-policy`, `DocumentsMaintenanceSchedule` unit test (tick set).

## Spec Change Log

- 2026-11-19: SPEC's "Documents is the holder of its derivative ids" read as "owns their lifecycle"; the holding is recorded once, on the derivative's origin (ADR D8).

## Verification

To be filled from fresh runs before the PR.
