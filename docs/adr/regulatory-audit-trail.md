# ADR — Regulatory audit trail (ISO 27001): write-side CDC, field-level diff, crypto-shredding

> **Status:** accepted · design only — **implementation is a separate epic, sliced by dependency: the capture backbone ships pre-auth; only the RBAC access gate and production-readiness wait on the (not-yet-existing) auth/RBAC subsystem (see D9)** · **Date:** 2026-06-29 · **Scope:** cross-cutting `Shared/Audit` (capture + contract), extending [`audit-activity-log.md`](./audit-activity-log.md); read side in `Backoffice/Audit`. Builds on the actor/operational axis, does **not** revoke it.
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

### D9 — Sequencing: the capture backbone ships pre-auth; only the RBAC gate and attribution wait on auth

Implementation is **not** one block gated on auth — three independent dependencies decide order:

- **Pre-auth, zero rework** — write capture (D1/D2), reconciliation (D3), the read mechanism + resource extractor (D4) and the retention floor (D7) are auth-independent. The sibling ADR already froze "audit backbone before User/RBAC": `actor_id` stays nullable and only `ActorContextFactory` swaps when auth lands — schema, bus, storage, retention and read model do not. The capture backbone it extends is already live (#377). Until auth, actor is `anonymous`/`system`.
- **Gated on a natural-person entity, not auth** — crypto-shredding + keystore + PII classification (D6) were deferred as speculative (YAGNI) while every audited entity looked like a catalog. **Superseded by D10/D11: the data — a `BankAccount` PII field — not a person aggregate, triggers E2, so the keystore lands now.**
- **Gated on auth/RBAC** — restricted, self-audited access (D8) and production-readiness. The trail is mechanically complete pre-auth but **ISO-complete only once attribution is real** (`actor_id` NOT NULL); pre-auth every action is `anonymous`/`system`, forensically thin.

Discarded: sequencing the whole epic after auth — over-couples, stalls field-level change capture (a live #377 consumer) behind an unrelated subsystem, and forfeits the zero-rework property the actor seam already guarantees.

### D10 — The data, not the aggregate, triggers E2 (amends D9)

D9 sequenced crypto-shredding (E2) "gated on the first natural-person *aggregate* (`Customer`/`Employee`)." Correction: the trigger is the first **audited field whose erasure is legally demandable**, not the first person aggregate. Auditing `BankAccount` writes surfaces `holderName` and `iban` — personal/financial data — into the append-only `audit_log`, so E2 fires now.

Discarded: keep waiting for a person aggregate — it would either block auditing `BankAccount` (losing change evidence) or write unforgettable PII in clear into an append-only store, which the right to erasure cannot tolerate.

### D11 — `BankAccount` is PII-bearing; PII is conditional on the subject (amends D6)

D6 listed `Bank`/`BankAccount` as non-PII catalogs. Refine: `Bank` (the institution — BBVA, Santander) stays non-PII reference data, but `BankAccount` carries the holder's personal/financial data. An IBAN is **conditionally** personal: a natural person's account identifies a person (GDPR Art. 4); a legal entity's (ACME SL) does not. So "the entity is/isn't PII" is the wrong unit — PII is a property of a **field** in the context of its **subject**, which D12 classifies.

### D12 — PII classification is per field, owned by the module, declared by a passive attribute

Because GDPR protects *data*, not aggregates, classification is per **field**. The owning module declares it with a passive `#[PersonalData]` attribute on the entity field — the same passive-metadata exception that already sanctions `#[ORM]`/`#[Assert]` in `Domain/`. The classification is **domain metadata**: audit and any other infrastructure concerned with personal-data handling (GDPR export, masking, logging, indexing, search) *read* it to choose encrypt-vs-clear per column; none of them *decides* what is personal. `BankAccount`: `holderName`, `iban` → `#[PersonalData]`; `bic`, `currency`, `status`, `bankId` → clear. With no party-type distinction available yet (D13/D16), the safe default is to encrypt the classified fields of every account; over-encrypting a legal-entity account is harmless.

Discarded: entity-level classification (a single `BankAccount` mixes PII and non-PII columns — too coarse). Discarded: a central PII map in `Shared/Audit` — classification authority belongs to the aggregate's owning module, mirroring how `AuditedEntity` already owns its action vocabulary.

### D13 — Cryptographic identity is decoupled from domain identity (`EncryptionScopeId`)

Crypto-shredding needs an identity to key the DEK by and to target on erasure, but that need must not force a domain aggregate into existence. A value object `EncryptionScopeId` (`"<TYPE>:<uuid>"`) names the scope a DEK protects and over which shredding operates — `BANK_ACCOUNT:<uuid>` today. It deliberately does not presuppose what that scope represents: it may later reference another domain identity if required by the domain model, without renaming the cryptographic concept. The DEK is keyed by the `EncryptionScopeId`; the encrypted diff references it.

Discarded: a `SubjectId` name — "subject" is GDPR-loaded (*data subject*) and this is the cryptographic scope, not the person. Discarded: introducing a `Person`/`Party` aggregate now solely to anchor the DEK — it lets an infrastructure concern model the domain (the wrong direction) and is YAGNI while no business use case needs a party. The domain decides the identity; the crypto adapts.

### D14 — Envelope crypto-shredding, sized for master-data cardinality

Classified diff fields are encrypted under the scope's DEK with libsodium AEAD (`crypto_aead_xchacha20poly1305_ietf`); **DEKs are generated with a CSPRNG**. Envelope: each DEK is wrapped by a KEK and stored in a Postgres keystore table with its `encryption_scope_id` and the `kek_version` it was wrapped under; the **KEK is custodied outside the app** (env-provided), never beside the DEKs. KEK rotation = rewrap every DEK under the new KEK as a bounded one-off batch — feasible at expected master-data cardinality. **Destroying a DEK is irreversible** — an accepted operational property, not a fault.

Discarded: the KEK in Postgres beside the DEKs (defeats the envelope); one global key (destroying it shreds every scope — per-scope keying is what makes single-scope erasure possible); a per-scope HSM or key-derivation hierarchy (over-engineered here). Revisit trigger: a PII-bearing aggregate at transactional cardinality → revisit (derivation, lazy rewrap, KMS/HSM).

### D15 — Subject erasure is distinct from actor erasure

`erase-actor` (the existing `audit:gdpr:erase <actor-id>`, anonymising *who acted*) and `erase-subject` (anonymising *whose data appears in the diff*) are different legal operations and are never merged. `erase-subject` does exactly two things: (1) delete or anonymise the live record, and (2) destroy the scope's DEK — the diff's PII ciphertext becomes permanently unreadable while the row, its order and its integrity remain (append-only preserved).

Discarded: extending `erase-actor` to also shred scope DEKs — it conflates two distinct GDPR triggers (the operator who acted vs. the data subject) and couples their lifecycles.

### D17 — Cryptographic metadata stays out of the domain model

The envelope's bookkeeping — `encryption_scope_id`, `kek_version`, ciphertext, wrapped keys — belongs to the infrastructure layer (currently implemented in the keystore table and the raw-DBAL, entity-free `audit_log` persistence), never on a domain entity or value object. An aggregate carries its data and its `#[PersonalData]` classification; it never knows it is encrypted, under which key, or that a keystore exists.

Discarded: a `dek_id`/key-version field on the entity — it leaks an infrastructure concern into `Domain/`, breaking the hexagonal boundary deptrac enforces.

## Load-bearing implementation challenges

- **Ambient actor context per request** inside the Doctrine listener: `onFlush` holds the changeset but not the HTTP actor — seal `ActorContext` + `correlation_id` (the `SealedAuditEntryFactory` / `ActorContextFactory` seam) and read them inside the flush.
- **Entity snapshot on DELETE**: capture the final state *before* the row is removed, so the diff's "before" is not already gone.
- **Keystore + key management**: DEK lifecycle (mint per encryption scope, KEK-wrap, destroy on erasure) and KEK custody outside the app (D13/D14).
- **PII field classification**: each owning module declares its personal-data fields with a passive `#[PersonalData]` attribute (D12), deciding encrypted-vs-clear storage per column.

## Decided inputs (previously open)

- **Retention floor: 5 years.** Legal/policy decision; the concrete period is the user's, not invented.
- **Keystore/crypto: libsodium + a Postgres keystore table.** Boring, no cloud dependency; KEK custody is the single external concern.

## Implementation

Epic-sized and **sliced by the D9 dependency tiers**, not deferred wholesale: **(1)** pre-auth capture slice — Doctrine `onFlush` listener + actor-context seal + field-level diff + semantic action + read resource extractor + retention floor (no prerequisites); **(2)** crypto-shredding + keystore + per-field PII classification — triggered now by the first audited PII field (`BankAccount`), not deferred to a person aggregate; **(3)** RBAC access gate + production-readiness — lands with auth. Tracked as its own epic by the PM, **not** in this ADR's PR. Related: issue #376 (async-resurrection gap in actor erasure) and issue #373 (extract keyset) belong to the same subsystem and are revisited when this epic lands.
