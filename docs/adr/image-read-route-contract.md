# ADR — The image read route: a provisional authorization frontier, no audit, a bounded cache

> **Status:** accepted · **Date:** 2026-08-31 · **Scope:** `GET /api/v1/images/{imageId}` in `Shared/Images` — who may read it, whether a read is audited, how long a client may reuse the response, and what the digest in its `ETag` commits to.
>
> Narrows the read-path paragraph of [`images-vs-documents-conservation-contract.md`](./images-vs-documents-conservation-contract.md) D6, which asked the serving story to declare a voter or argue the route public, and to audit the read "like any other". The first slice ships no consumer, and both halves of that sentence turn out to need one. The failure signal of the same route is a separate record: [`image-read-failure-signal-bound.md`](./image-read-failure-signal-bound.md).

## Context

The first slice of `Shared/Images` ships the pipeline, the `Image` aggregate, the storage port and one read route, and **no consumer**: `Bank.logoImageId` and `User.avatarImageId` are the intended ones and neither exists. The module holds identity, digest, media type and dimensions — never an owner, a filename or a classification — so it cannot tell a company logo from a person's face, by construction rather than by omission.

The route is therefore an **infrastructure proof**, not a product API: it shows that canonical bytes cross the module boundary without exposing where they live. Every decision below follows from that one fact, and each carries the condition under which it stops holding. Current-state description of the route: [`../architecture-api.md`](../architecture-api.md) ("Shared image read surface").

## Decisions

### D1 — Authentication is the whole frontier, provisionally; there is no ownership voter

The route requires a full session (`IS_AUTHENTICATED_FULLY`, the firewall's `^/api` rule) and carries no `#[IsGranted]`: any authenticated caller may read any image. The intended meaning is **not** "any back-office user may read any image for ever" but "while no consumer with its own policy exists, authentication is the complete frontier". It is scoped to this slice and ends with it.

Three properties hold alongside it and are pinned by tests:

- An unauthenticated request is refused **before anything is resolved** — before the identifier's syntax is checked and before any lookup — so a caller without a session cannot distinguish a malformed id from an absent one from a live one.
- **An `ImageId` is never an authorization mechanism and never a secret.** Knowing one grants nothing without a session. It is UUIDv7, time-ordered and cheaper to enumerate within a window than a v4; that residual, and the limiter that bounds it, is item five of the `Shared/Images` block in `PRODUCTION_SECURITY_CHECKLIST.md` §7.
- **The first real consumer brings its own authorization policy.** This is the half that makes the rest defensible, so it is a tripwire rather than a sentence: `ImageConsumerAuthorizationGateTest` goes red when any aggregate outside `Shared/Images` holds a property named `…ImageId`. The diff that wires a consumer is the one place the question has an answer, and it must give one — a policy on the route, or a decision recorded **in this ADR** explaining why that particular consumer still needs none. Per-identity-and-route rate limiting is a candidate for that same diff.

**Discarded: a generic ownership voter.** There is no consumer relation to vote on, so a voter would be a permission invented ahead of the thing it governs, and its semantics would be guessed rather than derived.

**Discarded: gating the route behind a permission granted to no role.** Suggested by an external review as the safe default and measured: it answers 403 to every caller, which does not defer the decision — it deletes the slice.

**Discarded: treating the opaque identifier as the control.** Unguessable is not authorised, and a v7 identifier is not even strongly unguessable.

### D2 — A read writes no `audit_log` row; classification is per consumer, never per `Image`

`api/.audit-resource-types` classifies each `resource_type` once, as person-denoting or not. A single `Image` type would be person-denoting for an avatar and non-person for a bank logo, and one string cannot be both. The classification is therefore made **per consumer** — `BankLogo` and `UserAvatar` are different types even if they share the `image` table — on the day that consumer exists. Until then the read is not audited at all.

The mechanism is the route name: it begins `shared_`, which is the `AuditPolicy` exclusion that fits object serving. That prefix is the only thing making "zero rows" true, so renaming the route out of it silently reverses this decision.

The `resource_id` crosswalk (issue #555), which the conservation ADR named as the blocker for an audited image row, is closed and implemented; it is not what forces this decision. The type ambiguity is.

**Discarded: auditing `Image` generically as `person`.** Conservative, but it attaches erasure obligations and an acceptance scenario to a type whose rows are mostly logos, and it would still be wrong the other way for the rows that are faces — the classification is a property of the consumer, not of the pixels.

### D3 — `Cache-Control: private, max-age=3600, immutable`

- **`private`** because the route requires authentication; a shared cache must never serve one caller's response to another.
- **`immutable`** because the slice exposes no operation that replaces the bytes of an existing `ImageId` — only creation and deletion, never an in-place update.
- **One hour, not one year.** Immutability of the **identifier** does not imply indefinite cacheability of the **bytes**. The correctness argument for a year is sound — the representation never changes — but the module's contract is *reliable deletion*, and deletion is a lifecycle event distinct from mutation: with a year, every viewer keeps serving a deleted image for up to a year with no request reaching the server. That is indefensible in a module that cannot tell a logo from an avatar.

`3600` is a **prior, not a measurement**: there is no deletion SLA and no consumer from which to derive a reuse window. If that SLA turns out to be zero, the answer is `no-store`, not a smaller number.

**Discarded: `max-age=31536000`.** The value the requirement originally asked for; right on the correctness axis, wrong on the erasure axis.

A conditional request is answered `304` only after the same verified read as a `200`, so a matching `If-None-Match` never vouches for an object already gone; the cost of that choice is recorded in the architecture page and in §7.

### D4 — `Range` is ignored

A `Range` header is ignored and the full body is always returned with `200` — never `206`, never `416` — and no `Accept-Ranges` is advertised. Nobody has asked for partial content, and a partial implementation of that contract commits the route to edge cases (multipart ranges, `If-Range`, the interaction with a verified read) that nothing exercises. Declared, not forgotten; the first consumer serving large images reopens it.

### D5 — The canonicalization is an implicit version 1

The `ETag` is derived from the digest, and the digest is `SHA-256` over the bytes the current `ImageProcessor` emits. A change of encoder, compression parameters or metadata handling therefore changes the digest of the same source image without anything declaring a contract change. No version field is persisted: with a single producer and no business need it would reopen the aggregate's minimal schema for nothing. The current algorithm is **v1 by being the only one**, and the trigger to version it explicitly is **the first change to that algorithm in merged code** — not before. This matters more the day the digest enters a URL (the variant scheme of the conservation ADR), which is when it becomes irreversible.

## Consequences

- Wiring the first consumer is not an increment on this route: it owes an authorization answer (D1), a per-consumer audit classification (D2), and a re-examination of the cache window against whatever deletion promise that consumer makes (D3). The gate forces the first of the three into its diff; the other two are held only by this record.
- Residuals opened by the route — enumeration, the self-limiting inversion of a `304` loop, the failure signal — live in `PRODUCTION_SECURITY_CHECKLIST.md` §7 and in [`image-read-failure-signal-bound.md`](./image-read-failure-signal-bound.md), not here.
