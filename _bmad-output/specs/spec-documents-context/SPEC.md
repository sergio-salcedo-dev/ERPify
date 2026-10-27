---
id: SPEC-documents-context
companions:
  - evidence-lifecycle.md
  - ../../../docs/adr/images-vs-documents-conservation-contract.md
  - ../../../docs/adr/image-deletion-signal-transport.md
sources: []
---

> **Canonical contract.** This SPEC and the files in `companions:` are the complete, preservation-validated contract for what to build, test, and validate. Source documents listed in frontmatter are for traceability — consult them only if you need narrative rationale or prose color this contract intentionally omits.

# Documents bounded context — evidence-class binaries

## Why

A mandate and a vision. The ERP targets construction, which produces technical documentation whose **original byte is the proof**: drawings, reports, contracts, certificates, RAW, and the site photograph attached to a progress certificate. The images module re-encodes and strips on purpose, so it destroys exactly what makes these evidence. Nothing in the tree accepts evidence yet: the images pipeline rejects non-image formats, but a site JPEG passes `UploadImage` and is stripped (the ADR D3 misfiling hazard). Guidelines are fixed now so that the first `Documents` story inherits decided boundaries and named open questions instead of rediscovering them. Boundary authority: the accepted ADR in `companions:`.

## Capabilities

- **CAP-1**
  - **intent:** A caller can file an evidence-class binary, which is stored untransformed with its digest, actor, date, legal basis and retention policy recorded.
  - **success:** The bytes read back and decrypted equal the uploaded bytes, and the recorded digest equals the digest of the uploaded bytes. A truncated or aborted upload leaves no document, no stored bytes and no live key.
- **CAP-2**
  - **intent:** A document is consumable only once it is scanned and ready; scanning classifies and never cleans.
  - **success:** A read in `Received`, `Scanning`, `Quarantined` or `ScanFailed` is refused with a defined RFC 9457 problem type; only a read in `Ready` returns bytes.
- **CAP-3**
  - **intent:** Originals are sealed at rest, including multi-gigabyte ones, under a key scoped to the document.
  - **success:** A multi-gigabyte upload is sealed with bounded peak memory (measured, not asserted) and decrypts back byte-identical.
- **CAP-4**
  - **intent:** Erasure ends the conservation contract by destroying the document's key, from any non-terminal state, leaving digest and custody record intact.
  - **success:** After erasure the content cannot be decrypted, sealing under the same scope is refused, and digest and custody remain readable.
- **CAP-5**
  - **intent:** Derivatives of a document (previews) are materialised through the images module with their origin recorded, and die with it.
  - **success:** After erasure, the variant URL answers 404 or 410, no `Image` row with that origin remains, and no storage key for it remains; a derivative that arrives after erasure is deleted on arrival.
- **CAP-6**
  - **intent:** Each state change of a document is recorded, and the document's metadata and custody trail can be listed in order.
  - **success:** The ordered sequence of states of one document can be listed, including after erasure.
- **CAP-7**
  - **intent:** A new version is a new immutable original linked to its predecessor, never a modification.
  - **success:** Both versions are retrievable with distinct digests until erased; erasing one leaves the other's link to it as a readable tombstone.
- **CAP-8**
  - **intent:** Scanned PDFs yield a searchable text derivative.
  - **success:** A phrase present only in a scanned page is found by search; the text derivative dies with its document.
- **CAP-9**
  - **intent:** An authorised user can read a `Ready` document, and the read is audited.
  - **success:** The route declares its voter, refuses non-`Ready` states (CAP-2), writes an audit row, and serves the bytes with `nosniff` and a locked-down `Content-Security-Policy`.

## Constraints

- **Mechanisms the capabilities lean on.** Ingest is a dedicated `UploadEvidence` use case; sealing is a stream-oriented sibling of `EnvelopeEncryptor` over `EncryptionScopeId` (one scope per document); erasure is `destroyScope()`. The sibling must keep the existing guarantee that sealing under a destroyed scope throws `DekDestroyed` instead of minting a fresh key; a test seals, destroys, seals again and expects refusal.
- The contract is chosen by the use case (`UploadEvidence` vs `UploadImage`), never by a heuristic over MIME or size. The upload category joins `docs/rules/cqrs-naming.md` with the first slice.
- There is no promotion between contracts. A fungible image never becomes evidence; the supported path is re-supplying the original through `UploadEvidence`.
- `Documents` has its **own** storage port, written as a stream and sealed. No generic port spans it and `ImageStorage`.
- The upload seam is designed against the Symfony 8.1 argument resolver (`mergeParamsAndFiles`) from the start, using the existing `Shared/Http/Infrastructure/TransportOnlyUploadedFileDenormalizer`. Neither a transport type nor a caller-supplied path, filename or URL reaches `Application`; bytes arrive as a stream the Infrastructure adapter produced. A scan over the context's own tree plus a regression test that goes red when the scan stops matching enforces it, because deptrac cannot express it.
- **Ingest is atomic from the caller's view.** Bytes land under a staging key and are promoted only when the stream is complete and matches any declared length or digest; a reaper deletes stale staging objects and destroys their scope. A replayed `UploadEvidence` carries an actor-scoped idempotency key and returns the existing document, which is not deduplication.
- **Two digests with different meanings:** the original's (integrity, custody, signature) and, for images, the canonical image's (visual identity, caching). Neither is an identity or a uniqueness key; no unique index on a digest.
- **Every state change is a compare-and-set on the aggregate and `Erased` rejects all of them.** A late scan verdict or a late derivative never revives or outlives an erased document.
- Erasure keeps nothing that names a person. Metadata fields that can carry one (original filename, title, uploader) are either sealed under the subject's scope or forbidden as free text in the surviving custody record; what survives is digest, timestamps, actor pseudonym and legal basis.
- A consumer's `*DocumentId` field that references a natural person's evidence is personal data in that consumer: mark it `#[PersonalData]` so `PiiDiffSealer` seals it under the subject's scope. The document's own id is not marked. Classify the `Document` resource type in `api/.audit-resource-types`.
- A column holding a person id needs a named erasure owner (`api/.person-reference-policy`, `#[PersonSubjectReference]` and a `PersonReferenceSource`), registered in the story that introduces the column, never later.
- **Derivative removal rides the existing signal.** `Shared.Image` is classified `person` in `api/.persistent-transport-policy` and `ImageDeletionRequested` routes to `async` under `docs/adr/image-deletion-signal-transport.md`, which closes no erasure risk and records its own residuals (the id stays in `event_store`; a dead letter swept at 30 days drops the instruction). The `Document` aggregate stays unrouted; the payload carries image ids only. Removal is never synchronous inside the owner's transaction, retries re-query by origin rather than from a list built once, and an alarm fires on derivatives of an erased document that outlive a bound.
- An `ImageId` is held by exactly one aggregate instance. `Documents` is the holder of its derivative ids.
- Other contexts reference a document by id only; reads compose through an explicit DQL JOIN projection.
- Evidence semantics (retention, legal basis, what counts as proof) live in `Documents`, never in `Shared/`.
- The first merged code in which another context materialises a rendition through the images module is the ADR D4 trigger to decide renaming `Images`; that story records the decision.
- Every story touching erasure or derivative lifecycle records an adversarial pass; the three-layer review is owed per story.

## Non-goals

- No code, schema or route is produced by this spec; it fixes guidelines and a story order.
- No deduplication, shared blob identity, reference counting or GC (ADR invariant 3).
- No transformation of an original, and no virus scanning, OCR or versioning inside the images module.
- No migration of images into evidence; no best-effort original kept in the images module.
- Storage technology is not decided here; it is an infrastructure adapter.
- Retention is recorded but not enforced: no schedule erases on expiry. Erasure is on request until a story says otherwise (it would need a schedule consumer on a single replica).

## Success signal

A first `Documents` story can start from this folder without reopening the fungible-vs-evidence boundary, the storage-port split, the erasure mechanism or the story order, and with the open questions below answered or consciously deferred. In particular, a demonstration where an uploaded drawing is stored byte-identical, is unreadable until scanned, and after erasure leaves digest and custody while every derivative is gone.

## Assumptions

- `Documents` lives outside `Shared/`, because evidence semantics are business meaning (ADR D4); its host context is open below.
- The `#268` item "`UploadedDocument` port wrapping the request file" is superseded by ADR invariant 6.
- Fail closed while the scanner is unavailable.
- `Shared/Images` has no origin on `Image` and no entry point for another context to materialise a derivative; the derivatives story creates both.

## Open Questions

- **Persistence strategy of the `Document` aggregate (the user's call).** State-oriented costs one table and a snapshot, with history only in `audit_log`; it is enough if the custody chain is a log, not the model. Event-sourced costs events, projections and versioning, and buys the custody chain and temporal reconstruction natively; the contract's "auditable sequence of states" makes it a candidate. The original byte is immutable either way. Decide before any modelling; criteria in `docs/adr/bank-bankaccount-modeling.md`.
- **Aggregate boundary (also the user's call):** is a version (CAP-7) a new `Document` aggregate or an entity inside one? ADR D3 already speaks of "a new aggregate" for a contract change.
- Which bounded context hosts `Documents` (new top-level or under `Backoffice`), and what is its first consumer? The ADR names bank-account evidence and progress certificates only as examples.
- Storage backend for evidence: local filesystem vs S3-compatible or Dropbox. Reversible per ADR D6, but WORM / object-lock needs differ from images. No story covers a non-local backend yet.
- Scanner: ClamAV container vs external service. The scanner needs plaintext, so the choice is to scan during ingest before sealing or to decrypt for the scan; it shapes doc-4.
- Scan retry: who drives it, the attempt cap before `ScanFailed`, the lease expiry, and whether `Ready` is ever rescanned.
- Retention and legal-basis vocabulary for a construction business (prescription and liability periods).
- Does the misfiling guard of ADR D3 (confirm the contract before the pipeline runs) belong here or to the first UI offering both entry points?
- Residual to record in `PRODUCTION_SECURITY_CHECKLIST.md` §7 when erasure ships: the digest and timestamps that survive erasure of a person's document can confirm possession of a known file.
