# ADR — Conservation contract: fungible representations vs evidence

> **Status:** accepted (design, no code yet) · **Date:** 2026-07-29 · **Scope:** every binary the ERP holds, uploaded or produced — bank and company logos, user avatars, product images, thumbnails, and the future technical documentation of a construction business (drawings, reports, DWG/IFC, scanned PDFs, RAW, video).
>
> **Supersedes** [media-vs-documents-upload-boundary.md](./media-vs-documents-upload-boundary.md) — that ADR drew the same boundary by file format and size; this one draws it by the promise made over the byte, and reaches a smaller first module.
>
> The application is **not in production**; no decision here carries backward compatibility, and no existing data has to be migrated. The first slice does add schema — the `Image` table and the owning columns that reference it.

## Context

The API currently has no upload surface: the previous one was withdrawn rather than migrated, because nothing consumed it. The architecture decision was left deliberately open and has to be taken before code is written; this document takes it.

The obvious framing — *small images here, large documents there* — does not survive its first real counterexample. A site photograph is an 8 MB JPEG carrying GPS and a timestamp; it is attached to a monthly progress certificate and ends up supporting an invoice. Sent through an image pipeline it gets its EXIF stripped and is re-encoded, which destroys precisely what made it proof. Format is the wrong discriminant, and so is size.

## Decisions

### D1 — The boundary is the conservation contract over the byte, not the file format

Two contracts, and a JPEG can fall under either:

- **Fungible representation.** The original byte carries no evidentiary weight; what matters is the resulting pixels. It may be decoded, normalised, stripped of metadata, resized, re-encoded and converted at will, because it can be replaced by a canonical representation generated afresh from a newly supplied source. The pipeline destroys information on purpose, so this is replacement, not recovery. Logos, avatars, product images, thumbnails, banners, favicons.
- **Evidence.** The original byte *is* the asset. It is never transformed; any processing produces derivatives alongside it. Drawings, technical reports, contracts, certificates, RAW, and the site photograph.

Re-encoding is what sanitises a fungible image — of what the raster and its metadata carry, not of the risk of parsing hostile input, since the decoder is itself an attack surface. That residual risk is identical on both sides and argues for hardening the processor, not for moving the scanner: virus scanning belongs to the evidence side because an antivirus is the price of having given up the right to transform the byte.

**Discarded: classification by MIME type or by size.** It puts `Strip metadata` and "preserve the GPS" on the same file, and it is the framing that loses the site photograph.

### D2 — The use case chooses the contract, explicitly

`UploadImage` and `UploadEvidence` are two entry points, not two routes into one. The decision belongs to the caller — which knows perfectly well whether it is creating an avatar, a catalogue picture, an invoice, a drawing or a site photograph — and never to a backend heuristic over MIME or size. What is public here is the **distinction**, not these identifiers: the concrete classes take their shape from [`rules/cqrs-naming.md`](../rules/cqrs-naming.md), whose template has no upload category yet and gains one with the first slice.

A **third** entry point materialises a dependent derivative on behalf of another context (D5, D6). It is not an upload: its caller supplies an origin, not a contract choice.

**Interim rule, until `Documents` exists.** No surface accepts an evidence-class binary. The images pipeline rejects rather than absorbs, because absorbing is irreversible under D3 and "it was the only door" is how a supplier's scanned invoice ends up stripped and re-encoded. The gap is a named blocker on #268, not something a caller works around.

### D3 — No promotion between contracts

A resource that entered as fungible cannot later become evidence. Not merely because the original may be gone, but because **the history cannot be reconstructed**: whether it was scanned, what digest it had on upload day, whether it was ever altered. Promotion does not create evidence; it creates evidence *as of today*, which is a different promise. Re-supplying the original as evidence is the supported path, and the resulting restriction is accepted deliberately — for a resource *deliberately* filed as fungible.

**Operator misfiling is a different input, and that acceptance does not cover it.** D2 puts the whole classification burden on a human, and the pipeline discards the original in one irreversible step: a foreman who attaches a site photograph through an image-shaped control loses the GPS and the timestamp, and the copy on the phone is gone a week later. Whether the surface must confirm the contract before the pipeline runs is open, and belongs to the first UI that offers both.

A bounded context must not change the historical meaning of an object. Entering the images module is the system stating "I will never rely on this original to prove anything"; wanting to prove something with it later is not an attribute change, it is a contract change, and that earns a new aggregate.

**Discarded: keeping a best-effort original in the images module to make promotion possible.** It introduces a third state — *preserved but unguaranteed* — and intermediate states are exactly what people end up depending on.

**Worked example.** A company logo printed on every issued invoice never changes nature: the logo stays fungible and the *emitted PDF* is the evidence. `Logo (images) → render → Invoice.pdf (documents)`.

### D4 — The images module owns canonical representations, not preservation

Its rule is technical and stable — *this context manages canonical visual representations, it does not preserve originals* — and deliberately not legal. No notion of tax law, prescription or construction liability lives in `Shared/`; the semantics of "this is proof" are born in the use case that calls `UploadEvidence`.

The name stays `Images` while it is honest. At ten years' distance the concept is closer to *renditions* — materialised representations of some other resource (a document's page previews, a video's poster frame, a RAW file's preview). Renaming a `Shared` module is a mechanical refactor over code, not a data migration, so it is not decided today; **the trigger is the first time another bounded context materialises a rendition through this module in merged code** — implemented, not merely foreseen, since D6 already foresees it.

### D5 — Lifecycle belongs to the owning aggregate, never to storage and never to the derivative

Storage keeps bytes. Bounded contexts are the sole owners of the meaning, the identity and the lifecycle of those bytes.

The consequence that is easy to miss: **a derivative of evidence is not fungible, it is dependent.** The JPEG preview of the first page of a contract lives among the fungible images, but when that contract reaches its erasure the preview must die with it — otherwise a legible page of a document containing personal data survives its own erasure, and a flawless cryptographic deletion leaks through the door next to it. Documents owns the lifecycle of its derivatives and can order their removal without knowing where they are stored; the images module owes it a **reliable** `delete(ImageId)`.

**Dependence is carried on the derivative, not remembered out of band.** A dependent image records the origin it derives from — owning context plus resource id — and an image with no origin is fungible. Without that field the dependence exists only in whatever `Documents` happens to remember, so every other path (regenerating variants, sweeping orphans, warming a cache, copying a reference) sees an image like any other and treats it as replaceable. That is the leak restated, not avoided.

### D6 — First slice: two irreversible rules, everything else deferred

Irreversible means *persisted, or a public contract* — not merely expensive to refactor. By that test two decisions cannot be postponed. The first: **the domain never knows a physical storage key.** Aggregates reference a stable `ImageId`; where and how the bytes live is nobody's business upstream. The second is the pipeline's edge, argued below and pinned as invariant 6 — a producer that is not an upload would otherwise have to enter behind the pipeline instead of through it, which is the redesign this section promises no deferred item forces.

In the first slice: `UploadImage`, the `Image` aggregate it produces, an image processor, a deterministic pipeline, storage addressed by an opaque identifier, an `ImageStorage` port, and a read path.

**The port's two halves promise different things, and the asymmetry is deliberate.** `ImageStorage` promises nothing about *where or how* bytes live — that is what the opaque identifier buys — but it does promise that an accepted write is retrievable and that a completed delete is not. A port free to lose an accepted byte leaves D5's reliable `delete()` with nothing to stand on. What "reliable" means operationally — synchronous or through the outbox, idempotency, behaviour on failure — is the port story's call, and it is a decision, not an oversight.

**The read path belongs to the slice, not to a later one.** An opaque identifier is unguessable, which is not the same as authorised, and serving an avatar is a read of a personal record — a case [`regulatory-audit-trail.md`](./regulatory-audit-trail.md) D4 already brought under "every read of every entity" with a resource extractor. The story that serves bytes declares the voter it expects, or declares the route consciously public and argues it, and the read is audited like any other.

`Image` is **state-oriented**, not event-sourced: the business needs where it is, its dimensions and its digest, never the sequence of changes that produced them. Change history belongs to `Audit`, to the consuming aggregate, or later to `Documents`. The aggregate stays small enough that a domain entity referencing it will hold nothing but an `ImageId` — `Bank.logoImageId` and `User.avatarImageId` are the intended consumers, neither of which exists yet — which is also where invariant 4 locates its owner.

**The image pipeline does not know how its input arrives.** *The pipeline* is everything between accepting content for processing and handing bytes to `ImageStorage` — decode, validate, normalise, re-encode, digest. Translating a request is the job of the module's **Infrastructure** adapter, the only layer allowed a framework type; `UploadImage` is an `Application` use case and so holds none either. That puts the edge where `deptrac` already enforces it, one layer stricter than "outside the pipeline" would suggest — the superseded ADR drew the same line and was right to.

Only the negative half is settled here: what the input *is* stays open, since that type is internal to the module and by this section's own test neither persisted nor public, while what may not cross is stated so it can be checked. A producer that is not an upload — rendering a document's first page — enters through D2's third entry point without touching an invariant, and what it produces is an `Image` carrying its origin (D5). The aggregate is named for what it is, not for how it got there.

Deferred and free to land later: multipart, S3/Dropbox backends, virus scanning, OCR, versioning, retention.

Deferred **and redesign-forcing**, said out loud so nobody ships them as an increment: deduplication and a shared blob with its own identity, together with the reference counting and GC they drag in. The deduplication paragraph below is the argument — they replace the identity model and make `delete()` unsafe, and `delete()` is the operation D5's whole guarantee rests on.

**Materialising derivatives from another bounded context is deliberately outside the first slice** — `Documents` producing page previews and thumbnails, with no HTTP and no interactive user. Only the seam is recorded here, not built: the pipeline must admit producers other than `UploadImage` without modifying any invariant defined below.

**Deduplication is dropped from the first slice on purpose.** It is what gives the blob an independent identity and lifecycle, what makes `delete()` unsafe, and what drags in refcounting, GC, ownership and concurrency — all to save storage that small images barely consume. Avatars carry personal data, so an unsafe delete there has GDPR teeth from the first sprint. The one real collision — the same bank logo referenced from many accounts — is a modelling problem, not a storage one: `Bank → Logo → N accounts` says there is a single official logo per bank, and the duplication never reaches infrastructure.

The canonical digest is only a stored attribute until something consumes it; it becomes irreversible **the day it enters a URL**, which is when immutable caching of variants (`/{imageId}/{hash}/{variant}`) needs it. That URL opens a branch to answer rather than discover: a request whose hash segment is not the image's current digest. Serving current bytes under a stale hash poisons an `immutable` cache for its whole TTL; answering 404 breaks every page already rendered before a logo was replaced; validating against historical digests needs the bookkeeping invariant 3 forbids. The segment is a cache-busting token, so redirecting to the current URL is the only branch that keeps both the invariant and the cache honest — the variants story decides, with the branch named here so it cannot be skipped.

**Hard constraint carried over:** the upload seam is designed against the Symfony 8.1 argument resolver (`mergeParamsAndFiles`) from the start, not patched afterwards — that resolver change is what exposed an arbitrary file read in the withdrawn implementation. The guard for it already exists and is not to be re-derived: `Shared/Http/Infrastructure/TransportOnlyUploadedFileDenormalizer` refuses an upload that did not come from the request's file bag, anchored on `SplFileInfo` rather than on `UploadedFile` because the vector is constructibility from a path — `File` and `SplFileObject` walk straight past the narrower anchor.

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
4. **A derivative does not change owner by living in `Shared/`.** Its lifecycle stays with the aggregate that ordered it, which is what keeps a derivative of evidence dying with its evidence (D5). The aggregate that owns the reference is responsible for it: replacing or removing the reference includes **issuing `delete(ImageId)` so the bytes and every materialised variant stop being retrievable by any route, cached URLs included**. Unreachability through the domain model does not discharge the obligation — the repo's default erasure is a hard `DELETE` of the owning row, which removes the reference and destroys nothing. This binds equally when an image is replaced and when the owning subject is erased. *How* it happens is the implementing story's decision; *that* the bytes go is not.

   Three consequences the wording exists to force:

   - **An `ImageId` is held by exactly one aggregate instance and is never copied to another.** It is an opaque scalar, so an ordinary "duplicate this bank" copies it for free and leaves two owners over one lifecycle — and invariant 3 removes the reference counting that would notice. A second holder materialises its own image. Sharing bytes does not require deduplication; copying an id is enough.
   - **Byte removal is not a synchronous side effect inside the owner's transaction.** `Iam`'s erasure path is already all-or-nothing — `FulfilIdentityErasure` wraps identity, both audit axes and the session purge in one transaction and promises a safe re-run — and a filesystem or S3 delete cannot roll back with it. Publishing the removal from inside that transaction and consuming it after commit is the shape the repo already uses; the port story confirms it.
   - **An id that references an image of a natural person is itself personal data.** The audit trail sits outside the domain model on purpose and is never deleted, so a captured diff `avatarImageId: null → …` outlives the erasure this invariant governs and resolves to the subject's photograph. The owning module marks the field `#[PersonalData]` so `PiiDiffSealer` seals it, and the sealing scope is the subject's rather than the image's — otherwise crypto-shredding is bypassed by a UUID.
5. **The conservation contract is the boundary** between the images module and `Documents` — never format, never size.
6. **Neither an HTTP transport type nor a caller-supplied location reaches the module's `Application` layer.** Two axes, because generalising only the first misses the defect that actually shipped. The **type** axis bans `UploadedFile` and any successor transport type, stated over the category so a different type tomorrow does not escape it. The **value** axis bans a path, filename or URL chosen by the caller — none of which is a transport type at all, and which is precisely what the withdrawn implementation turned into an arbitrary file read. Bytes reach the pipeline as a stream or blob the Infrastructure adapter produced from the request's file bag, or from a sibling context materialising a derivative; never from a location the caller named.

   Enforcement is named rather than assumed, because the obvious instrument does not reach: `deptrac` folds nested `Shared/` modules into three layers (`src/Shared/(.*/)?Application/.*`), so it cannot express "`HttpFoundation` may not enter `Shared/Images`" — [`shared-module-organization.md`](./shared-module-organization.md) discarded a per-module axis for that reason. What deptrac *does* enforce (`Shared.Application` may not depend on `Vendor.Symfony`) covers the type axis for free and the value axis not at all. The pipeline story therefore carries a scan over the module's own tree plus a regression test that goes red when the scan stops matching — a path-based check that silently covers nothing after a rename is the failure mode to design against.

**Review criterion for a story.** If it forces the reader to ask *"is this an image or a document?"*, the story is written against the superseded model. The aligned question is *"what conservation contract does this resource promise?"* — and if the answer to that question determines the behaviour, the story is on contract.

## Consequences

- The images module ships smaller than the design that opened this discussion: no shared blob, no dedup, no refcount, no GC. Every future need examined — S3, WORM, content-addressed storage, IFC, video, a project-wide case file able to order coordinated destruction across all its documents and derivatives — reinforced D5 instead of adding infrastructure.
- Issue #268 (`Documents` epic) keeps its enablers #266 (`writeStream`) and #267 (Dropbox/S3 backends) but inherits a different frontier: not *image vs non-image*, but *fungible vs evidence*. A JPEG can belong to `Documents`.
- The derivative-of-evidence leak in D5 is a privacy defect by construction, so any story touching erasure or derivative lifecycle needs the recorded adversarial pass required by the root `CLAUDE.md`; self-certification does not close it.
- **Whether `Image` is audited is a first-slice decision with no default available.** `api/.audit-resource-types` classifies each `resource_type` once as person or non-person, and `Image` would be person-denoting for an avatar and non-person for a bank logo — one class, one type string, and `make php.lint.audit-resource` fails the build without a line. Until the `resource_id` crosswalk of issue #555 is closed, an audited person-denoting image row can place a real id beside the pseudonym erasure has just minted, so the slice either leaves `Image` unaudited or closes #555 first.
- This ADR is the durable record of what was decided. What it deliberately leaves open — the operational meaning of a reliable delete, whether an `Image` is mutable, the transactional boundary between storing bytes and persisting the reference — is named here so the first story inherits the questions instead of rediscovering them, and the working notes that enumerate them are transient rather than a dependency of this document.
