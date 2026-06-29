# ADR — Regulatory audit trail (ISO 27001): write-side CDC, field-level diff, crypto-shredding

> **Status:** accepted · design only — **implementation is a separate epic and is sequenced after the (not-yet-existing) auth/RBAC subsystem** · **Date:** 2026-06-29 · **Scope:** cross-cutting `Shared/Audit` (capture + contract), extending [`audit-activity-log.md`](./audit-activity-log.md); read side in `Backoffice/Audit`. Builds on the actor/operational axis, does **not** revoke it.
>
> Temporal context: the application is **not in production**, so the new columns/tables, the keystore and the retention floor are born without backward-compatibility constraints.

## Context

[`audit-activity-log.md`](./audit-activity-log.md) established the actor/operational axis: today `audit_log` captures **reads** (generic `GET` via `AuditPolicy` + `AccessLogAuditListener`) and **security** (`AccessDeniedAuditListener`). **Writes are not audited** — create/update/delete travel the `DomainEvent → event_store` axis (business truth), which is why the investigation UI (#377) shows no bank create/edit/delete. That axis answers *what happened to the aggregate*, not *who changed which field, from what value to what value* — the question an ISO 27001 audit asks.

This ADR evolves the trail from navigation observability into a **regulatory record** under **ISO 27001:2022**: every create/update/delete/read attributable to an actor, carrying a **field-level diff** ("BBVA" → "BBVA S.A."), integrity-protected, access-restricted, and PII-erasable without breaking append-only. It is the follow-up the sibling ADR's D4 anticipated as a revisit trigger — *"the day an action stores PII in the payload, this policy grows a redactor"* — now fired by the diff.

## Decisions

### D1 — Write capture is Doctrine-level CDC, not per-event instrumentation

A single Doctrine `onFlush` listener (`Shared/Audit/Infrastructure`) reads `UnitOfWork::getEntityChangeSet()` for every insert/update/delete. Doctrine **already computes** the field-level changeset; reusing it yields the before/after diff for free. Justified flexibility: the listener depends on the ORM by design — change-data-capture is an infrastructure concern, never domain — exactly the kind of deviation [`external-dependencies-in-domain.md`](./external-dependencies-in-domain.md) sanctions when argued.

Discarded: deriving the diff from each `DomainEvent` — events carry **intent**, not a uniform per-column before/after; re-serialising old/new state per aggregate duplicates what the UnitOfWork already holds (anti Rule-of-Three) and silently misses any field a given event forgot to carry. Discarded: a third-party audit bundle (Sonata, DataDogAuditBundle) — its entity/listener model fights deptrac, the bounded-context gate and the per-view DTO contract; build is ~1 listener + an actor-context seam, far cheaper than the integration friction.

### D2 — A semantic action sits on top of the raw diff

The diff is the evidence; a **semantic action** (`BANK_UPDATED`, `BANK_DELETED`) labels it, so the trail reads as intent, not a wall of column deltas. The action is the row's cardinality-1, indexable `action`; the structured diff rides in `metadata`. This reuses the `action` contract of the sibling ADR rather than inventing a parallel one.

Discarded: storing only the diff (unreadable, not queryable by intent) or only the action (loses the regulator's "from what value to what value").

### D3 — Reconciliation: `event_store` = business · `audit_log` + diff = compliance

The two axes stay **orthogonal and non-duplicating**. `event_store` is the reproducible **business** log (replayable, drives projections; see [`event-store-and-projections.md`](./event-store-and-projections.md)). The regulatory trail is the **compliance** record (who/what/when/diff, access-controlled, retention-bound, PII-erasable). A write produces both; neither is the other's source of truth, so this is not a dual-write to reconcile — they answer different questions and carry different guarantees.

### D4 — Reads: audit every read of every entity, with a resource extractor

Extends `AuditPolicy` from "generic navigational `GET`" to **every read of every entity**, and adds a **resource extractor** so each row records *which* resource was seen. Today the generic `GET` path stores `resource_type`/`resource_id` = `null·null` ("someone viewed a list", not *which record*) — insufficient for the ISO "who accessed which personal record" question. The extractor resolves the resource identity from the matched route/controller, keeping `AuditPolicy` free of a per-module route catalogue (the seam the sibling ADR already defends).

Discarded: keep read auditing list-level only — cannot answer access-to-a-specific-subject, the core data-protection read query.

### D5 — Framework: ISO 27001:2022 controls mapped to mechanisms

- **A.8.15 (event logging + log protection)** → append-only writes (no in-place edit/delete of evidence) + restricted access (D8). Stronger tamper-evidence (hash-chaining / WORM) is a **revisit trigger**, not decided here.
- **A.8.17 (clock synchronisation)** → `occurred_on` sealed from a synchronised clock in `SealedAuditEntryFactory`.
- **A.5.12 (information classification)** → per-field PII classification drives D6.
- **A.5.18 / A.8.15 (restricted access + audit-the-auditor)** → D8.

ISO fixes **no** retention period — it requires a *justified, documented* policy plus log integrity (D6, D7).

### D6 — PII forgetting = crypto-shredding (envelope, DEK per data-subject)

Storing field-level diffs means the trail now holds **PII of the record's data subject** (a person's name, email…). Append-only (A.8.15) forbids redacting or deleting the row. **Crypto-shredding** resolves the tension: a PII-bearing diff is stored **encrypted under a per-subject Data Encryption Key (DEK)** (row references its `dek_id`); erasure ("forget me") **destroys that subject's DEK** → the ciphertext is permanently unreadable while the row, its ordering and its integrity stay intact. Envelope scheme: DEKs wrapped by a KEK held outside the app; **libsodium** AEAD, **DEKs in a dedicated Postgres keystore table**.

**Catalogs are not encrypted** — a `Bank` is reference data, not personal data; only entities representing **natural persons** get a DEK. This **composes with**, and does not re-open, the sibling ADR's actor-identity erasure (its D4 remints `actor_id`, its D4.1 materialises `actor_erased`): that forgets *who acted*; crypto-shredding forgets *the personal data inside the diff of what changed*. Different PII loci, both required for a complete erasure.

Discarded: redact/delete the PII row in place — breaks append-only and the integrity the regulator trusts. Discarded: one global encryption key — destroying it shreds every subject; per-subject keying is precisely what makes single-subject erasure possible. Discarded: keyed-hash pseudonymisation of the diff — reversible with the key, so the row stays personal data (GDPR Art. 4(5)), and the key becomes a re-identification secret to guard forever.

### D7 — Retention with a floor (legal minimum), not only a ceiling

`AuditRetentionPolicy` today expresses a **ceiling** (delete older than a per-level cutoff — privacy minimisation). The regulatory trail adds a **floor**: a record must be retained **at least** the legal minimum before it becomes prune-eligible. Floor = **5 years** (a legal/policy decision; revisit if regulation changes). `DbalAuditLogPruner` must never delete below the floor; **erasure within the floor is satisfied by crypto-shredding (D6), not by deletion** — the row survives for the regulator, the PII does not.

Discarded: ceiling-only retention — lets minimisation prune compliance evidence before its legal minimum. Discarded: deleting rows to honour erasure inside the floor — destroys evidence ISO requires retained.

### D8 — Trail access is restricted and self-audited → blocks production

Reading the regulatory trail is itself privileged: access must be **RBAC-restricted** and **self-audited** (the auditor is audited — A.5.18 / A.8.15). ERPify has **no firewall/voter today**, so the regulatory read surface — and the existing #377 investigation route — **must not reach production until the auth/RBAC subsystem exists**. This makes auth a hard prerequisite of the trail, not an afterthought.

Discarded: ship the read UI behind network-only restriction — unauthenticated trail access is itself an ISO finding and leaves no record of who read the audit.

## Load-bearing implementation challenges

- **Ambient actor context per request** inside the Doctrine listener: `onFlush` holds the changeset but not the HTTP actor — seal `ActorContext` + `correlation_id` (the `SealedAuditEntryFactory` / `ActorContextFactory` seam) and read them inside the flush.
- **Entity snapshot on DELETE**: capture the final state *before* the row is removed, so the diff's "before" is not already gone.
- **Keystore + key management**: DEK lifecycle (mint per subject, KEK-wrap, destroy on erasure) and KEK custody outside the app.
- **PII field classification**: a per-entity map of which fields are personal data, deciding D6 encrypted-vs-clear storage per column.

## Decided inputs (previously open)

- **Retention floor: 5 years.** Legal/policy decision; the concrete period is the user's, not invented.
- **Keystore/crypto: libsodium + a Postgres keystore table.** Boring, no cloud dependency; KEK custody is the single external concern.

## Implementation

Epic-sized and **sequenced after auth/RBAC** (multi-context: Doctrine `onFlush` listener + actor-context seal + keystore + per-entity PII classification + pruner floor + read-side RBAC + resource extractor). Tracked as its own epic by the PM, **not** in this ADR's PR. Related: issue #376 (async-resurrection gap in actor erasure) and issue #373 (extract keyset) belong to the same subsystem and are revisited when this epic lands.
