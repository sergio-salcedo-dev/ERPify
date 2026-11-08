# ADR — Documents: snapshot plus append-only custody log, erasure seam and storage port

> **Status:** accepted · **Date:** 2026-11-05 (D6: 2026-11-06) · **Scope:** `api/src/Documents/Document` (aggregate, custody log, storage port, erasure seam, upload intent) and `api/src/Shared/Privacy/Application/PersonReferenceErasure.php`.
>
> Builds on [`images-vs-documents-conservation-contract.md`](./images-vs-documents-conservation-contract.md) (why evidence is a separate context) and the Documents spec decisions of #268. The application is **not in production**; nothing here carries backward compatibility.

## Context

`Documents` holds evidence-class binaries: the original byte is the proof, so the history of what happened to a document is part of the product. The persistence strategy was decided before this code (state-oriented, event sourcing rejected on measurement: the event store has no per-aggregate stream loading and its `aggregate_version` is informative), and this ADR records what the first code under it commits to and what it discarded.

## Decisions

### D1 — `Document` is a snapshot row; every change also appends a `document_custody` row in one transaction

Two tables, not one aggregate with children: `Document` (state-oriented, extends `AggregateRoot`) and `DocumentCustody` (a `final readonly` entity mapped `#[ORM\Entity(readOnly: true)]`, so the ORM never emits an `UPDATE` for it). A new document and its custody row 1 share one transaction (`ReceiveDocument`); a rollback leaves neither. **Each version is its own aggregate**: `series_id` is the first version's own id and `predecessor_id` is written `NULL`, both without a setter and `updatable: false`; no series aggregate, cycle check, slot column or walk-up exists before versioning is designed. `scope_id` is random, never derived from the document id, and carries its own `UNIQUE` (two documents sharing an encryption scope would let one's erasure shred the other's bytes).

`document_custody.event` is a string backed by a PHP enum holding only what is written today, with **no database CHECK**, so a later story adds a case without a migration; the cost is that a typo in a future writer is caught by the enum, not by the database. The value names a custody *fact*, never the document's current state, so a stored value never changes meaning. `document.state` does carry a CHECK, because the lifecycle is closed.

**Discarded:** event sourcing (cost measured in the spec), and a PostgreSQL `SEQUENCE` for `seq` (a rolled-back append would burn a number and leave a gap among committed rows).

### D2 — `seq` is computed under the lock on the document row; the unique constraint is the second defence

`AppendCustody` is the transaction owner: lock the `document` row (`FOR UPDATE`), compute `MAX(seq) + 1`, insert, commit. A foreign-key insert from another connection also blocks on that lock (`KEY SHARE` conflicts with `FOR UPDATE`), which `DocumentPersistenceFunctionalTest` measures from a second connection. `UNIQUE (document_id, seq)` fires only when something computed a stale number; that violation maps to `CustodySequenceCollision` and the use case re-runs the **whole** closure — lock, number, insert — up to three attempts, then answers `TransientTransactionFailure`. Re-running only the INSERT would repeat a number already computed from a stale read. A `deadlock_detected` is left to `DoctrineTransactionManager`, which already answers it; retrying it here would hide a lock-order defect.

The idempotency constraint (`uniq_document_idempotency_key_hash`, nullable and global over the actor-scoped hash) is a conflict (`ConcurrentUniqueWrite`, 409) and is **never retried**. A unique violation is classified by constraint **name**: DBAL exposes no structured name and PDO none, so `ViolatedUniqueConstraint::is()` asks whether the driver message contains the exact name, delimited on both sides by a character that cannot continue an identifier. It never parses the sentence around it, because `lc_messages` translates that sentence and a lost race would then read as a 500; the delimiters keep a column name or a longer constraint name from reading as this one. Anything else is rethrown unchanged. The driver text carries the offending key and is never chained into the exception the caller sees.

### D3 — Custody is append-only except one sanctioned statement: the actor anonymisation

A person's erasure rewrites `document_custody.actor_id` and `document.uploader_id` to **one constant marker** (the nil UUID, `ErasedPersonMarker::UUID`) and nulls `document.idempotency_key_hash` (replay protection for that actor ends, accepted). Sealing the actor under the subject's scope is circular — the scope id derives from the person — and a per-subject pseudonym kept in the row is the stable identifier the erasure exists to end. Cost: custody is no longer *absolutely* append-only, and it is not claimed cryptographically immutable. A document is never erased by this path: the uploader is not the subject of the evidence.

Two DBAL statements in the caller's transaction, each `UPDATE … WHERE id IN (SELECT id … WHERE <person> = :subject ORDER BY id FOR UPDATE)` — the shape of `DbalAuditActorAnonymiser` — documents first, then custody rows, in ascending id. Neither filters on state, so `Erased` rows are rewritten like any other, and both refuse to run outside a transaction (a `FOR UPDATE` outside one releases its locks at the end of the statement and orders nothing).

**Enforcement is a source gate, and nothing more:** `make php.lint.document-custody` (`DocumentCustodyMutationGateTest`, engine `tests/Support/DocumentCustodyMutations.php`) closes the set of statements `api/src` issues against `document_custody` to this one. It reads SQL text, DBAL's table-level `update()`/`delete()`, DQL and the ORM query builder naming the entity class, and `remove()` in a file naming the entity; each surface has a negative test and each shape it cannot read a pinned blind-spot test. No database role separation, `GRANT`, `REVOKE` or trigger exists in the tree and the gate sees neither a migration nor runtime-built SQL; adding a restricted role or a `BEFORE UPDATE/DELETE` trigger is a separate decision.

**ORM identity-map limit.** The statements are bulk DBAL on purpose, and nothing in the erasure path loads a `Document` or `DocumentCustody` into the unit of work: a managed instance read before the rewrite would keep the subject's id in the identity map, and a bulk statement refreshes none. Tests read the result through DBAL for the same reason. The foreign key `document_custody.document_id → document.id` has no cascade (re-injected by a `postGenerateSchema` listener) and the mapping has no association, so no ORM cascade or `orphanRemoval` can reach the log; a document is never physically deleted.

### D4 — The erasure reaches Documents through a `Shared\Privacy` port both consumers call

`PersonReferenceErasure::eraseReferencesTo(string): int` lives in `Shared/Privacy/Application` (always importable, so no allowlist or deptrac `skip_violations` entry). `FulfilIdentityErasure` and `ResweepErasedSubjects` receive it and call it inside their one transaction, after the identity, session, audit and business-log rows they already hold; its count joins `FulfilIdentityErasureResult` and the compliance metadata (`document_references_anonymised`, declared in `AuditMetadataKey`). `PurgeUserDocumentReferences` is its only implementer today and the named owner of both person columns in `api/.person-reference-policy`; each column also has a `PersonReferenceSource` that excludes the marker, so a correctly erased row is never reported. A row an in-flight action of the erased person commits after the purge statement's snapshot is missed by the `UPDATE`; the re-sweep and the detective source are the controls for that race.

Global lock order: identity, session, audit, event store, then upload intents, then documents, then their custody rows, each class in ascending id. The purge rewrites the subject's upload intents first (actor to the marker, key hash NULL, `Declared`/`Streaming` expired, nothing deleted), so a commit in flight has either committed — and its document is rewritten next — or is blocked on its intent row and finds it expired. `ErasureLockOrderTest` and `ErasureLockOrderFunctionalTest` hold it, the latter asking the database which rows are held when the document statement runs. **Discarded:** one `PurgeUser…` call per context in each consumer (every new context edits both), and a tagged collection now (one implementer; the next turns the alias into a collection by tag, as `PersonReferenceSource` already is).

### D5 — `Documents` has its own storage port, and its adapter is native filesystem calls

`DocumentStorage` carries `stage` (stream to a staging key under the scope, refusing an existing key), `promote` (create the destination exclusively, typed failure when it exists or the source is absent, not idempotent), `open` (a read stream or a typed absence) and `delete` (idempotent, naming the area it touches so a sweep of staged objects cannot reach the final one). It shares nothing with `ImageStorage`, declares no WORM guarantee, and carries neither key nor library message in a failure. The boot probe, the reaper and the fsync policy are D6's.

The first adapter uses `fopen(…, 'x')` and `link(2)` + `unlink(2)` rather than Flysystem: the library's local adapter overwrites on write and has no create-if-absent, and `rename(2)` silently replaces its destination. `link(2)` creates the destination exclusively and fails with `EXDEV` across mounts exactly as `rename(2)` does, so atomicity is claimed only for `staging/` and `final/` on one filesystem. The adapter's test asserts it on the test's own temporary filesystem only; the deployed volume is probed by the boot probe (D6: create a file in staging, promote it into final, remove it, refuse to start on any failure), not assumed from `st_dev`. The roots live on a volume of their own (`document_storage`, `DOCUMENTS_STORAGE_PATH`, a literal equal to the mount target); the entrypoint refuses to start when that path is not a mounted volume, and the adapter never creates a root, because an invented root in the container layer turns every later `delete` into a confirmed erasure of bytes elsewhere. Backup of that volume is not in place — an open residual of `PRODUCTION_SECURITY_CHECKLIST.md` §7.

### D6 — Ingest is an upload-intent state machine; the intent row is the lock the commit and the reaper share

`upload_intent` (`UploadIntent`) is created by the declare step and moves `Declared` → `Streaming` (the PUT's claim, before a byte is read or a key minted) → `Committed` (the commit, with the document id), or to `Expired` (the writer's own abort, the person purge, or implicitly by time: a `Declared` intent past `ttl_expires_at`, a `Streaming` one past `lease_expires_at` or `upload_deadline_at`). Every judgement of time uses the database clock read inside the transaction holding the row. Every transition to `Expired` nulls the key hash and sets the lease to now. The deadline is fixed at the claim (`ceil(size / 256 KiB) s + 60 s`); the heartbeat renews the lease (90 s, every 10 s of reading) and never past it. The constants live in `UploadLimits`.

No transaction spans the stream: the claim commits, the sealer mints the key after the first chunk and commits on its own, then one short transaction files the document. **Lock order, each class in ascending id:**

| Flow | Takes, in order |
|---|---|
| Claim | intent row → advisory lock per actor → advisory lock global (nothing else while held) |
| Heartbeat, writer's abort | intent row |
| Commit | intent row → keystore row of the not-yet-visible scope (the one sanctioned inversion, reachable only through the intent lock) → document insert + custody row 1 |
| Reaper | intent row → keystore row → (staged delete, tombstone, intent delete) |
| Person purge | intent rows → document rows → custody rows |
| Roll-forward | document row |
| Declare | advisory lock per actor (declare namespace) → existing intent row (replay) → intent insert |

The commit asserts under both locks that the intent is `Streaming` with a live lease and deadline and that the scope is `Live` (`Absent` is a snapshot and never passes); actor and key hash come from the locked row. The reaper rechecks expiry and the absence of a document row under the same two locks: a claimed intent with no document gets its staged object deleted and an `aborted-upload` tombstone (minted even with no key), a never-claimed one is deleted with no tombstone, and one whose scope a document holds is deleted alone. `UploadLockOrderFunctionalTest` interleaves the pairs with a second connection and shows the waiter rechecks and stands down. A committed row owns its staged object: only the roll-forward (rechecking under the document lock that the row is still `Received`) moves it, accepting a final object already present and never overwriting it — and syncing the final directory before reporting it promoted (`syncFinal`), since a run that died after the move may have died before its sync; the staging age sweep deletes only an object older than 20 min that neither a row nor a live intent holds, its limit counting deletions rather than names walked; a final object with no row is alarmed on, never deleted. Every sweep logs and passes over an item it cannot process, so one poisoned row never stops a batch, and a writer's abort deletes its staged object only after expiring a still-`Streaming` intent under its lock — a commit whose answer was lost leaves a `Committed` intent whose document owns that object. The server's own plaintext copy of the body — PHP's `upload_tmp_dir` spool, held by the worker thread until its next request — is destroyed when the upload route's response leaves, filed, aborted or refused: the body is read to its end there and the one spool matching exactly what was read is truncated and unlinked, never one that could be another in-flight request's.

**Every pair, interleaved** (`UploadLockOrderFunctionalTest`, `UploadLockOrderPairsFunctionalTest`, a real second connection holding one side mid-transaction). The first lock each flow takes decides the pair: a shared first lock serialises, disjoint lock sets never wait, and no flow takes the other's first lock second, so no pair can close a cycle.

| Pair | Shared first lock | The waiter, once the holder commits |
|---|---|---|
| claim / commit | intent row | a claim after a commit answers the document; a commit after a claim files it |
| claim / reaper | intent row | a claim after the reaper is gone; the reaper after a claim stands down on a live stream |
| claim / purge | intent row | a claim after the purge is gone (actor rewritten); the purge after a claim expires the stream |
| commit / reaper | intent row, then keystore row | a commit after the reaper aborts; the reaper after a commit stands down, the key stays live |
| commit / purge | intent row | a commit after the purge aborts (expired); the purge after a commit rewrites the filed document next |
| reaper / purge | intent row | the reaper after the purge tombstones the minted scope; the purge after the reaper rewrites nothing |
| purge / roll-forward | document row | the roll-forward after the purge still promotes; the purge after the roll-forward rewrites the row |
| roll-forward / claim, commit, reaper | none (document row vs intent and keystore rows; a committing document is invisible until commit) | never waits |

**Discarded:** deleting the intent at commit (the intent keeps holding the key until its 7-day retention sweep, then the document's own `UNIQUE` and the declare's re-read take over); a `NOT EXISTS` guard inside the reaper's `UPDATE` (under `READ COMMITTED` its snapshot predates a row committing while it waits); a pre-allocated document id on the intent (the scope id is enough to join them, and a document id before the document exists invites a dangling reference).

