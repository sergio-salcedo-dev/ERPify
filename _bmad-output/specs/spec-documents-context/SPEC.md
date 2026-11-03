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

- **Decided (the user, 2026-10-30): state-oriented persistence plus a custody log.** `Document` is a snapshot row; every transition also appends a `document_custody` row in the same transaction. Event sourcing was rejected on measurement: the event store has no per-aggregate stream loading (`DbalEventStore::stream()` is a global, sequence-ordered projection feed), and its `aggregate_version` is informative (`MAX+1` at `DbalEventStore.php:55`, under a `UNIQUE (tenant_id, aggregate_id, aggregate_version)` from `api/migrations/2026/Version20260616201857.php` that never fires because `tenant_id` is always `NULL` and PostgreSQL treats `NULL`s as distinct), so it would first need aggregate loading, optimistic concurrency and a serialised append. `document_custody` is not `audit_log`: reads stay in `audit_log`, which has a retention prune; custody never expires.
- **Custody guarantees.** The aggregate is loaded under the lock (`PESSIMISTIC_WRITE`, or re-read after `SELECT … FOR UPDATE`), so the compare-and-set never runs against a stale snapshot; `seq` is computed inside that same transaction. `UNIQUE (document_id, seq)` is the second defence, not a substitute, and a violation maps to a retry, never a 500. The first row (`seq` 1) has no row to lock: the document insert and its first custody row share one transaction and only the `UNIQUE` guards it. Lock order is document, then keystore, and a flow that locks several documents (a subject's erasure over a chain) takes them in ascending id. A repeated erasure appends nothing. `Erased` is a snapshot state, never a row delete, and the custody foreign key has no cascade. Custody rows are never deleted and are updated by no statement except the single sanctioned actor-pseudonymisation one, if the actor question below chooses that route. **Enforcement is a source gate over `api/src` in the style of `SanctionedLogMutationGateTest`, and nothing more:** no database role separation, `GRANT`, `REVOKE` or trigger exists in the tree, the gate sees neither a migration nor runtime-built SQL, and adding a restricted role or a `BEFORE UPDATE/DELETE` trigger is a separate decision. Custody stays readable after the key is destroyed and is not claimed cryptographically immutable. Retries of a scan are counted on the snapshot; custody records a lease claim and a verdict, not each failed attempt.
- **Decided: a version is its own `Document` aggregate.** It carries its own bytes, digest, key scope, retention, scan lifecycle and custody. It links to its predecessor by an immutable id and to its series by a scalar series id (the first version's series id is its own id); both columns exist from the first migration. No series aggregate exists and a read projection lists versions. A consumer references the exact version it relied on. **The chain is linear:** a partial `UNIQUE` on `predecessor_id` (or a lock on the predecessor row) refuses a second child, a new version requires a `Ready` predecessor, and a subject's erasure re-queries until no non-erased version of that subject remains, so a version created mid-iteration is not missed. A tombstone carries only id and digest.
- **Decided: the first evidence adapter is the local filesystem behind the `Documents` port**, and the port declares no WORM guarantee an adapter cannot enforce. S3 with object-lock waits for a first consumer and a defined retention vocabulary; the port keeps the move reversible (ADR D6). **Before first real use** (owned by doc-3, verified before doc-5 serves real documents) the stored bytes need an encrypted offsite backup, digest verification and a proven restore of originals and metadata: a database dump is not a backup of the evidence. **Ordering on the local filesystem:** seal into staging on the same mount as the final namespace (`rename(2)` is atomic only within one filesystem) with the file and its directory synced, insert the `Received` row in the transaction that mints the key, then rename staging to final; a sweep over the final namespace deletes objects with no row and flags rows with no object. The stream seal authenticates truncation (the last chunk carries a final-chunk marker in its associated data), so a short file from a partial restore or a full disk fails instead of decrypting as a prefix. A `Ready` document whose bytes are missing or mismatch the digest fails closed with a defined problem type and an alarm. The staging reaper honours a liveness lease, so it cannot destroy the scope of an upload still in flight.
- **Mechanisms the capabilities lean on.** Ingest is a dedicated `UploadEvidence` use case; sealing is a stream-oriented sibling of `EnvelopeEncryptor` over `EncryptionScopeId` (one scope per document); erasure is `destroyScope()`. The sibling must keep the existing guarantee that sealing under a destroyed scope throws `DekDestroyed` instead of minting a fresh key; a test seals, destroys, seals again and expects refusal. **That guarantee has a hole today:** `DbalKeystore::destroy()` is an `UPDATE … WHERE destroyed_at IS NULL`, so on a scope never minted it affects zero rows and leaves no tombstone, and `SodiumEnvelopeEncryptor::mint()` then mints a live key for a late writer. Erasure and the staging reaper therefore leave a tombstone even when no key was minted (an upsert of a destroyed row, or the key is minted at document creation), and `Erased` is decided by the aggregate's compare-and-set, never by the boolean `destroyScope()` returns, which is `false` both for a repeat and for a scope that never existed. A test destroys an unminted scope, seals, and expects `DekDestroyed`. Scopes of aborted uploads are a class the erasure ledger and the tombstone reconciler do not carry, so abuse cannot grow them for ever. **Erasure converges across its stores:** the `Erased` transition, its custody row and the key destruction commit together or are re-drivable from a recorded intent, with a scenario for a crash at each point. **The scanner receives a decrypt stream only:** no plaintext reaches a disk file or a spool, size limits are measured against the largest supported original, and exceeding one ends in `ScanFailed`, never `Ready`.
- The contract is chosen by the use case (`UploadEvidence` vs `UploadImage`), never by a heuristic over MIME or size. The upload category joins `docs/rules/cqrs-naming.md` with the first slice.
- There is no promotion between contracts. A fungible image never becomes evidence; the supported path is re-supplying the original through `UploadEvidence`.
- `Documents` has its **own** storage port, written as a stream and sealed. No generic port spans it and `ImageStorage`.
- The upload seam is designed against the Symfony 8.1 argument resolver (`mergeParamsAndFiles`) from the start, using the existing `Shared/Http/Infrastructure/TransportOnlyUploadedFileDenormalizer`. Neither a transport type nor a caller-supplied path, filename or URL reaches `Application`; bytes arrive as a stream the Infrastructure adapter produced. A scan over the context's own tree plus a regression test that goes red when the scan stops matching enforces it, because deptrac cannot express it.
- **Ingest is atomic from the caller's view.** Bytes land under a staging key and are promoted only when the stream is complete and matches any declared length or digest; a reaper deletes stale staging objects and destroys their scope. A replayed `UploadEvidence` carries an actor-scoped idempotency key and returns the existing document, which is not deduplication; the same key with different bytes is a conflict, and a replay after the original became `Erased` returns the tombstone, never a revived document.
- **Two digests with different meanings:** the original's (integrity, custody, signature) and, for images, the canonical image's (visual identity, caching). Neither is an identity or a uniqueness key; no unique index on a digest.
- **Every state change is a compare-and-set on the aggregate and `Erased` rejects all of them.** A late scan verdict or a late derivative never revives or outlives an erased document. The compare-and-set of a scan transition also carries the lease token or attempt number, not only the source state, so a verdict from a superseded lease is dropped and cannot override the verdict of the scanner that now holds it.
- Erasure keeps nothing that names a person. Metadata fields that can carry one (original filename, title, uploader) are either sealed under the subject's scope or forbidden as free text in the surviving custody record; what survives is digest, timestamps, actor pseudonym and legal basis. The decision is taken in the story that first persists the field (doc-2 for the schema, doc-3 for the values), never after: a plaintext filename or uploader persisted between those stories would outlive the erasure. Each such field names the scope that seals it, because the document's bytes die with the document's scope while a person's fields die with theirs.
- A consumer's `*DocumentId` field that references a natural person's evidence is personal data in that consumer: mark it `#[PersonalData]` so `PiiDiffSealer` seals it under the subject's scope. The document's own id is not marked. Classify the `Document` resource type in `api/.audit-resource-types`.
- A column holding a person id needs a named erasure owner (`api/.person-reference-policy`, `#[PersonSubjectReference]` and a `PersonReferenceSource`), registered in the story that introduces the column, never later. The actor column of `document_custody` and the actor-scoped idempotency key are such columns.
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
- No S3, object-lock or other non-local backend, and no hash-chaining of custody, in the first slices.
- Retention is recorded but not enforced: no schedule erases on expiry. Erasure is on request until a story says otherwise (it would need a schedule consumer on a single replica).

## Success signal

A first `Documents` story can start from this folder without reopening the fungible-vs-evidence boundary, the storage-port split, the erasure mechanism or the story order, and with the open questions below answered or consciously deferred. In particular, a demonstration where an uploaded drawing is stored byte-identical, is unreadable until scanned, and after erasure leaves digest and custody while every derivative is gone.

## Assumptions

- `Documents` lives outside `Shared/`, because evidence semantics are business meaning (ADR D4); its host context is open below.
- The `#268` item "`UploadedDocument` port wrapping the request file" is superseded by ADR invariant 6.
- Fail closed while the scanner is unavailable.
- `Shared/Images` has no origin on `Image` and no entry point for another context to materialise a derivative; the derivatives story creates both.

## Open Questions

- **Key resurrection on restore.** A restored PostgreSQL dump brings back the wrapped key of a scope destroyed since, and the tombstone reconciler lives in the same restored database. Candidate: a ledger of erasures outside PostgreSQL holding scope ids only, plus a mandatory restore step that stops writers and readers, replays the ledger, verifies, and only then re-enables. Consulting it on every read is rejected as an availability dependency. Constraints on any candidate: an entry is a write-ahead intent recorded before `destroyScope()` and completed forward, never rolled back, because written after the destruction a crash between the two loses the erasure and written before a commit that rolls back it names a live document; a replay goes through the erasure use case, not the key alone, so the `Document` snapshot, its custody row, the `ImageDeletionRequested` signal and the key converge (destroying the key alone leaves a `Ready` row and resurrected derivatives); the scope id is a random value not derivable from the document id, since the ledger outlives the erasure; the ledger itself needs a backup, a retention rule and its own residual in `PRODUCTION_SECURITY_CHECKLIST.md` §7. Open: its authority and durability, and the change to `restore.prod`. Blocks doc-7.
- **Actor pseudonymisation in `document_custody`.** The actor column is a person reference, so it needs an erasure owner and a `PersonReferenceSource` under `api/.person-reference-policy`, while the custody row survives the document's erasure. Two routes: the actor id sealed under the actor's subject scope, so erasing the person is a crypto-shred with no `UPDATE` of custody; or one sanctioned anonymising `UPDATE` declared in the gate's `SANCTIONED` set with a line in its ADR. A plaintext actor id in custody is forbidden, and a stable pseudonym shared across rows still links one subject's documents, so it is rewritten to a non-linkable value on erasure or its mapping is destroyed. A `PersonReferenceSource` over sealed values must understand them. Blocks doc-2.
- **Custody against a PostgreSQL administrator.** Hash-chaining or external anchoring stays the revisit trigger of `docs/adr/regulatory-audit-trail.md` (A.8.15), not a decision made here.
- Which bounded context hosts `Documents` (new top-level or under `Backoffice`), and what is its first consumer? The ADR names bank-account evidence and progress certificates only as examples.
- Scanner: ClamAV container vs external service. The scanner needs plaintext, so the choice is to scan during ingest before sealing or to decrypt for the scan; it shapes doc-4. `evidence-lifecycle.md` puts `Received` after sealing, so scanning before sealing needs a pre-seal state and decrypt-for-scan does not.
- Scan retry: who drives it, the attempt cap before `ScanFailed`, the lease expiry, and whether `Ready` is ever rescanned.
- Retention and legal-basis vocabulary for a construction business (prescription and liability periods).
- Does the misfiling guard of ADR D3 (confirm the contract before the pipeline runs) belong here or to the first UI offering both entry points?
- Residual to record in `PRODUCTION_SECURITY_CHECKLIST.md` §7 when erasure ships: the digest and timestamps that survive erasure of a person's document can confirm possession of a known file; and a stable actor pseudonym or series id that survives across custody rows links the documents of one subject.
