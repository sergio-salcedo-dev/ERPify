---
title: 'doc-8: Dependent derivatives through the images module'
type: 'feature'
created: '2026-11-19'
status: 'in-review'
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
- 2026-11-19: I/O matrix row 1 superseded: a dependent image is never served by `GET /api/v1/images/{id}`, which any authenticated session may call while a document is ADMIN-only and audited; the route answers 404 for it before and after erasure. CAP-5's "variant URL answers 404 or 410" therefore holds by construction, and the substantive witness of the erasure is the row's absence and `ImageBytesNotFound` (asserted in `DocumentDerivativeLifecycleFunctionalTest`). Fail-closed decided here because no producer exists; reopening it is the first producer's decision (ADR D8, §7 item Five).
- 2026-11-19 (review): a failure whose outcome is unknown re-reads the row and deletes bytes no committed row holds, handing a failed delete to the transport — `MaterialiseDerivative` becomes the third registered publisher of `ImageDeletionRequested`. The guard refuses to join a caller's transaction. The release sweep asks about a document again only four hours after its last request (`document.derivatives_requested_at`), `ReleaseDerivatives` pages through every derivative in one call, the migration marks documents erased before it as released, and the read route asks `Image::isDependent()` rather than rebuilding the origin.

## Review Triage Log

Three layers, run in parallel as read-only subagents over the diff `3f96ac3..c280f53` in this session, before the PR was opened. The Blind Hunter is the adversarial pass the SPEC and ADR `images-vs-documents-conservation-contract.md` (Consequences) require for derivative lifecycle work. None found a GRAVE defect; the race between the guard's `FOR SHARE` and the erasure's `FOR UPDATE` was checked independently by all three and held.

| Finding | Layers | Verdict | Route |
|---|---|---|---|
| Bytes left behind on an unknown-outcome failure, and a failed discard logged by class only | Blind, Edge, Acceptance | real | patch: re-read the row, delete if absent, queue a deletion on a failed delete, log the image id |
| Unbounded re-requests and starved candidates while a deletion keeps failing | Blind, Edge, Acceptance | real | patch: `derivatives_requested_at`, 4 h re-request interval (bounded, documented in §7 Four) |
| A document with many derivatives drains 100 per tick | Blind | real | patch: page inside `ReleaseDerivatives::of()` |
| False alarm over documents erased before the migration | Blind, Edge | real | patch: backfill `derivatives_released_at` |
| Read route could answer 500 on a stored tag the value object refuses | Edge | real | patch: `Image::isDependent()` |
| A guard inside a caller's transaction holds the erasure and closes a shared manager | Edge | real | patch: the guard refuses nesting |
| Image storage restored separately brings back row-less derivative bytes | Blind, Edge | real, no fix here | recorded: §7 Two |
| Frozen matrix row 1 versus the fail-closed route | Blind, Edge, Acceptance | real | Spec Change Log above |
| Publisher wording, read-route ADR D1, architecture bullet, source tree, `CAP-n` and register-type comments in `src`, inline FQCN, problem-mapping test, alarm 20-id cap test, reporter list order | Acceptance | real | patch |
| `services.yaml` bindings of the unconsumed seam witnessed by no test | Acceptance | real | defer: the first producer consumes the seam, and the container then compiles the binding |

## Verification

Fresh runs on head `afc2812`, in a local PHP 8.5.12 toolbox over PostgreSQL 18 (this session's container cannot run the compose stack):

- `php.quality` then `php.quality.dry-run`: PHPStan, PHPMD, phpcs, Rector, CS-Fixer, Deptrac and every `php.lint.*` gate that can run here green. Three could not run here and are left to CI: `php.lint.route-manifest` (no `jq`), `php.lint.config-reference` (the worktree's `.git` points at a primary checkout the toolbox does not mount) and `composer.check.missing-deps` (no `apcu`).
- Full PHPUnit suite: 5926 tests, no failure; 20 errors, all of them the live-stack gates that need the Caddy edge running, identical to the baseline taken on `main` before this story.
- The migration up, down and up on the dev and test databases, with `doctrine:schema:validate` in sync.
- Not run: Behat (no scenario was added; the acceptance is the PHPUnit functional case `DocumentDerivativeLifecycleFunctionalTest`).

`done` waits on the story's done checkpoint (the owner).
