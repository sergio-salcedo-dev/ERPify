# ADR — Conservation contract: fungible representations vs evidence

> **Status:** accepted (design, no code yet) · **Date:** 2026-07-29 · **Scope:** every uploaded binary the ERP holds — bank and company logos, user avatars, product images, thumbnails, and the future technical documentation of a construction business (drawings, reports, DWG/IFC, scanned PDFs, RAW, video).
>
> **Supersedes** [media-vs-documents-upload-boundary.md](./media-vs-documents-upload-boundary.md) — that ADR drew the same boundary by file format and size; this one draws it by the promise made over the byte, and reaches a smaller first module.
>
> The application is **not in production**; no decision here carries backward compatibility, and none of it requires a schema migration.

## Context

The API currently has no upload surface: the previous one was withdrawn rather than migrated, because nothing consumed it. The architecture decision was left deliberately open in `_bmad-output/implementation-artifacts/deferred-work.md` and has to be taken before code is written.

The obvious framing — *small images here, large documents there* — does not survive its first real counterexample. A site photograph is an 8 MB JPEG carrying GPS and a timestamp; it is attached to a monthly progress certificate and ends up supporting an invoice. Sent through an image pipeline it gets its EXIF stripped and is re-encoded, which destroys precisely what made it proof. Format is the wrong discriminant, and so is size.

## Decisions

### D1 — The boundary is the conservation contract over the byte, not the file format

Two contracts, and a JPEG can fall under either:

- **Fungible representation.** The original byte carries no evidentiary weight; what matters is the resulting pixels. It may be decoded, normalised, stripped of metadata, resized, re-encoded and converted at will, because it can always be regenerated or requested again. Logos, avatars, product images, thumbnails, banners, favicons.
- **Evidence.** The original byte *is* the asset. It is never transformed; any processing produces derivatives alongside it. Drawings, technical reports, contracts, certificates, RAW, and the site photograph.

Re-encoding is what sanitises a fungible image, which is why virus scanning belongs only to the evidence side: an antivirus is the price of having given up the right to transform the byte.

**Discarded: classification by MIME type or by size.** It puts `Strip metadata` and "preserve the GPS" on the same file, and it is the framing that loses the site photograph.

### D2 — The use case chooses the contract, explicitly

`UploadImage` and `UploadEvidence` are two commands, not two routes into one. The decision belongs to the caller — which knows perfectly well whether it is creating an avatar, a catalogue picture, an invoice, a drawing or a site photograph — and never to a backend heuristic over MIME or size. This is a public contract and part of the ubiquitous language; see [`rules/cqrs-naming.md`](../rules/cqrs-naming.md).

### D3 — No promotion between contracts

A resource that entered as fungible cannot later become evidence. Not merely because the original may be gone, but because **the history cannot be reconstructed**: whether it was scanned, what digest it had on upload day, whether it was ever altered. Promotion does not create evidence; it creates evidence *as of today*, which is a different promise. Re-supplying the original as evidence is the supported path, and the resulting restriction is accepted deliberately.

A bounded context must not change the historical meaning of an object. Entering the images module is the system stating "I will never rely on this original to prove anything"; wanting to prove something with it later is not an attribute change, it is a contract change, and that earns a new aggregate.

**Discarded: keeping a best-effort original in the images module to make promotion possible.** It introduces a third state — *preserved but unguaranteed* — and intermediate states are exactly what people end up depending on.

**Worked example.** A company logo printed on every issued invoice never changes nature: the logo stays fungible and the *emitted PDF* is the evidence. `Logo (images) → render → Invoice.pdf (documents)`.

### D4 — The images module owns canonical representations, not preservation

Its rule is technical and stable — *this context manages canonical visual representations, it does not preserve originals* — and deliberately not legal. No notion of tax law, prescription or construction liability lives in `Shared/`; the semantics of "this is proof" are born in the use case that calls `UploadEvidence`.

The name stays `Images` while it is honest. At ten years' distance the concept is closer to *renditions* — materialised representations of some other resource (a document's page previews, a video's poster frame, a RAW file's preview). Renaming a `Shared` module is a mechanical refactor over code, not a data migration, so it is not decided today; **the trigger is the arrival of the second producer of derivatives.**

### D5 — Lifecycle belongs to the owning aggregate, never to storage and never to the derivative

Storage keeps bytes. Bounded contexts are the sole owners of the meaning, the identity and the lifecycle of those bytes.

The consequence that is easy to miss: **a derivative of evidence is not fungible, it is dependent.** The JPEG preview of the first page of a contract lives among the fungible images, but when that contract reaches its erasure the preview must die with it — otherwise a legible page of a document containing personal data survives its own erasure, and a flawless cryptographic deletion leaks through the door next to it. Documents owns the lifecycle of its derivatives and can order their removal without knowing where they are stored; the images module owes it a **reliable** `delete(ImageId)`.

### D6 — First slice: one irreversible rule, everything else deferred

Irreversible means *persisted, or a public contract* — not merely expensive to refactor. By that test exactly one decision cannot be postponed: **the domain never knows a physical storage key.** Aggregates reference a stable `ImageId`; where and how the bytes live is nobody's business upstream.

In the first slice: `UploadImage`, the `Image` aggregate it produces, an image processor, an `ImageStorage` port that promises nothing about persistence, a deterministic pipeline, and storage addressed by an opaque identifier.

**The image pipeline does not know how its input arrives.** No HTTP transport type reaches it — `UploadImage` is an adapter towards the pipeline, not the pipeline. Only the negative half is settled here: what the input *is* stays open, since that type is internal to the module and by this section's own test neither persisted nor public, while what may not cross is stated so it can be checked. A producer that is not an upload — rendering a document's first page — then enters the same way without touching an invariant, and what it produces is an `Image` like any other. The aggregate is named for what it is, not for how it got there.

Deferred, none of it forcing a redesign when it lands: deduplication, a shared blob with its own identity, reference counting and GC, multipart, S3/Dropbox backends, virus scanning, OCR, versioning, retention.

**Materialising derivatives from another bounded context is deliberately outside the first slice** — `Documents` producing page previews and thumbnails, with no HTTP and no interactive user. Only the seam is recorded here, not built: the pipeline must admit producers other than `UploadImage` without modifying any invariant defined below.

**Deduplication is dropped from the first slice on purpose.** It is what gives the blob an independent identity and lifecycle, what makes `delete()` unsafe, and what drags in refcounting, GC, ownership and concurrency — all to save storage that small images barely consume. Avatars are `PersonalData`, so an unsafe delete there has GDPR teeth from the first sprint. The one real collision — the same bank logo referenced from many accounts — is a modelling problem, not a storage one: `Bank → Logo → N accounts` says there is a single official logo per bank, and the duplication never reaches infrastructure.

The canonical digest is only a stored attribute until something consumes it; it becomes irreversible **the day it enters a URL**, which is when immutable caching of variants (`/{imageId}/{hash}/{width}.webp`) needs it.

**Hard constraint carried over:** the upload seam is designed against the Symfony 8.1 argument resolver (`mergeParamsAndFiles`) from the start, not patched afterwards — that resolver change is what exposed an arbitrary file read in the withdrawn implementation.

### D7 — Evidence semantics, for the context that does not exist yet

Recorded here because they constrain what the first slice may assume, not as a licence to build them:

- A document is a **conservation contract**, not a file. It promises that something existed, with a given digest, supplied by an actor, on a date, under a legal basis, with a retention policy and an auditable sequence of states — never that its content stays retrievable forever.
- The original is **immutable while it exists**. There is no modify operation, only two terminal ones: keep, and render unrecoverable. Erasure ends the conservation contract by destroying the key rather than the bytes, so the digest and the custody chain survive a content that no longer can. `Shared/Crypto` already implements this (`EnvelopeEncryptor`, `Keystore`, `EncryptionScopeId`, `destroyScope()`); what is missing is a **stream-oriented sibling** of the encryptor, since `encrypt(scope, string, aad): string` cannot seal a multi-gigabyte upload.
- Two digests with different meanings and different types: the original's (integrity, custody, signature) and the canonical image's (visual identity, caching).
- Scanning classifies, it cannot clean an immutable byte. State lives on the aggregate, and the invariant is not "everything is scanned" but **no consumer reaches the content before the document is ready**.

## Invariants for downstream work

Every story derived from this ADR preserves these explicitly; a story that violates one is misframed, not merely incomplete.

1. **Storage keeps bytes; semantics belong to the aggregate.** Nothing in `Shared/` decides what a byte means, who owns it, or when it dies.
2. **The canonical digest is an attribute, never an identity or a uniqueness key.** Two users uploading the same avatar produce two independent images that happen to share a hash. That is correct, not an anomaly to reconcile — the reflex to add a unique index is the failure mode this invariant exists to stop.
3. **The first iteration introduces neither deduplication nor global bookkeeping.** No shared blob identity, no reference counting, no GC.
4. **A derivative does not change owner by living in `Shared/`.** Its lifecycle stays with the aggregate that ordered it, which is what keeps a derivative of evidence dying with its evidence (D5).
5. **The conservation contract is the boundary** between the images module and `Documents` — never format, never size.
6. **No HTTP transport type crosses the image pipeline's edge.** Stated over the category rather than over `UploadedFile`, so a different transport type tomorrow does not escape it. The invariant is intended to be mechanically enforceable — otherwise it cannot reliably act as an architectural boundary — so the story that introduces the pipeline carries a structural check (deptrac or equivalent) in its definition of done. This ADR defines the rule; the implementation owns its enforcement.

**Review criterion for a story.** If it forces the reader to ask *"is this an image or a document?"*, the story is written against the superseded model. The aligned question is *"what conservation contract does this resource promise?"* — and if the answer to that question determines the behaviour, the story is on contract.

## Consequences

- The images module ships smaller than the design that opened this discussion: no shared blob, no dedup, no refcount, no GC. Every future need examined — S3, WORM, content-addressed storage, IFC, video, a project-wide case file able to order coordinated destruction across all its documents and derivatives — reinforced D5 instead of adding infrastructure.
- Issue #268 (`Documents` epic) keeps its enablers #266 (`writeStream`) and #267 (Dropbox/S3 backends) but inherits a different frontier: not *image vs non-image*, but *fungible vs evidence*. A JPEG can belong to `Documents`.
- The derivative-of-evidence leak in D5 is a privacy defect by construction, so any story touching erasure or derivative lifecycle needs the recorded adversarial pass required by the root `CLAUDE.md`; self-certification does not close it.
- The reasoning behind this decision is preserved in the brainstorming session under `_bmad-output/brainstorming/`, which is transient by repo policy — this ADR is the durable record.
