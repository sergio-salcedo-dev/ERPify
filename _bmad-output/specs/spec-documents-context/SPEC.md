---
id: SPEC-documents-context
companions:
  - evidence-lifecycle.md
  - ../../../docs/adr/images-vs-documents-conservation-contract.md
sources: []
---

> **Canonical contract.** This SPEC and the files in `companions:` are the complete, preservation-validated contract for what to build, test, and validate. Source documents listed in frontmatter are for traceability — consult them only if you need narrative rationale or prose color this contract intentionally omits.

# Documents bounded context — evidence-class binaries

## Why

A mandate and a vision. The ERP targets construction, which produces technical documentation whose **original byte is the proof**: drawings, reports, contracts, certificates, RAW, and the site photograph attached to a progress certificate. The images module re-encodes and strips on purpose, so it destroys exactly what makes these evidence; nothing in the tree accepts them yet, and the images pipeline rejects them rather than absorbing them. Guidelines are fixed now so that the first `Documents` story inherits decided boundaries and named open questions instead of rediscovering them. Boundary authority: the accepted ADR in `companions:`.

## Capabilities

- **CAP-1**
  - **intent:** A caller can file an evidence-class binary through `UploadEvidence`, which stores the original untransformed and records its digest, actor, date, legal basis and retention policy.
  - **success:** The stored bytes equal the uploaded bytes and the recorded digest matches them.
- **CAP-2**
  - **intent:** A document is consumable only once it is scanned and ready; scanning classifies and never cleans.
  - **success:** A read before ready is refused; an infected or unscanned document never reaches a consumer.
- **CAP-3**
  - **intent:** Originals are sealed at rest by a stream-oriented sibling of `EnvelopeEncryptor` under a per-document `EncryptionScopeId`.
  - **success:** A multi-gigabyte upload is sealed without buffering it in memory and decrypts back byte-identical.
- **CAP-4**
  - **intent:** Erasure ends the conservation contract by `destroyScope()`, leaving digest and custody chain intact.
  - **success:** After erasure the content cannot be decrypted while digest and custody remain readable.
- **CAP-5**
  - **intent:** Derivatives of a document (previews) are materialised through the images module with their origin recorded, and die with it.
  - **success:** After erasure no derivative of that document is retrievable by any route, cached URLs included.
- **CAP-6**
  - **intent:** Each state change of a document is auditable.
  - **success:** The sequence of states of one document can be listed in order.
- **CAP-7**
  - **intent:** A new version is a new immutable original linked to its predecessor, never a modification.
  - **success:** Both versions are retrievable with distinct digests until erased.
- **CAP-8**
  - **intent:** Scanned PDFs yield a searchable text derivative.
  - **success:** A phrase present only in a scanned page is found by search.

## Constraints

- The contract is chosen by the use case (`UploadEvidence` vs `UploadImage`), never by a heuristic over MIME or size. Until `Documents` ships, no surface accepts an evidence-class binary.
- There is no promotion between contracts. A fungible image never becomes evidence; the supported path is re-supplying the original through `UploadEvidence`.
- `Documents` has its **own** storage port, written as a stream and sealed. No generic port spans it and `ImageStorage`.
- The upload seam is designed against the Symfony 8.1 argument resolver (`mergeParamsAndFiles`) from the start, using the existing `Shared/Http/Infrastructure/TransportOnlyUploadedFileDenormalizer`. Neither a transport type nor a caller-supplied path, filename or URL reaches `Application`; bytes arrive as a stream the Infrastructure adapter produced.
- Erasure is by key destruction. A document id referencing a natural person is personal data: mark it `#[PersonalData]` so `PiiDiffSealer` seals it under the subject's scope, and classify the resource type in `api/.audit-resource-types`.
- A column holding a person id needs a named erasure owner (`api/.person-reference-policy` plus a `PersonReferenceSource`); no event about a person goes on a persisted transport (`api/.persistent-transport-policy`).
- Byte removal is never synchronous inside the owner's transaction: publish the removal, consume it after commit. A document id and an `ImageId` are each held by exactly one aggregate instance.
- Other contexts reference a document by id only; reads compose through an explicit DQL JOIN projection.
- Evidence semantics (retention, legal basis, what counts as proof) live in `Documents`, never in `Shared/`.
- Every story touching erasure or derivative lifecycle records an adversarial pass; the three-layer review is owed per story.

## Non-goals

- No code, schema or route is produced by this spec; it fixes guidelines and a story order.
- No deduplication, shared blob identity, reference counting or GC (ADR invariant 3).
- No transformation of an original, and no virus scanning, OCR or versioning inside the images module.
- No migration of images into evidence; no best-effort original kept in the images module.
- Storage technology is not decided here; it is an infrastructure adapter.

## Success signal

A first `Documents` story can start from this folder without reopening the fungible-vs-evidence boundary, the storage-port split, the erasure mechanism or the story order, and with the open questions below answered or consciously deferred. In particular, a demonstration where an uploaded drawing is stored byte-identical, is unreadable until scanned, and after erasure leaves digest and custody while every derivative is gone.

## Assumptions

- `Documents` lives outside `Shared/`, because evidence semantics are business meaning (ADR D4); its host context is open below.
- Written in English like the ADRs and docs, although the BMad core config says Spanish.
- The `#268` item "`UploadedDocument` port wrapping the request file" is superseded by ADR invariant 6.
- Fail closed while the scanner is unavailable.
- `Shared/Images` has shipped (`UploadImage`, `DeleteImage`, `FlysystemImageStorage`); `#266` and `#267` are closed.

## Open Questions

- **Persistence strategy of the `Document` aggregate (the user's call).** State-oriented (current snapshot plus `audit_log`) or event-sourced? The conservation contract promises a custody chain and an auditable sequence of states, so history may be the business, which makes event sourcing a candidate; the original byte itself is immutable either way. Decide before any modelling; criteria in `docs/adr/bank-bankaccount-modeling.md`.
- Which bounded context hosts `Documents` (new top-level or under `Backoffice`), and what is its first consumer? The ADR names bank-account evidence and progress certificates only as examples.
- Storage backend for evidence: local filesystem vs S3-compatible or Dropbox. Reversible per ADR D6, but WORM / object-lock needs differ from images.
- Scanner: ClamAV container vs external service.
- Retention and legal-basis vocabulary for a construction business (prescription and liability periods).
- Does the misfiling guard of ADR D3 (confirm the contract before the pipeline runs) belong here or to the first UI offering both entry points?
