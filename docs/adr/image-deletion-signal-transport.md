# ADR — Queuing the image-deletion signal on a persistent transport

> **Status:** accepted · **Date:** 2026-08-28 · **Scope:** `ImageDeletionRequested` and its routing; the classification of `Shared.Image` in `api/.persistent-transport-policy`.
>
> Required by that registry: a `person ::` verdict is only a valid spelling when it names the ADR arguing why the type is queued anyway. This is that argument, and it is deliberately also a record of what the decision does **not** buy.

## Context

`api/.persistent-transport-policy` classifies every `aggregateType()` by what its **aggregate id denotes**, and the rule it enforces is that an "aggregate id alone" payload is safe on a persisted transport if and only if the aggregate is not a natural person. Both queues are Doctrine tables no erasure path touches: `async` has no TTL and no prune at all, and `failed` is swept by a 30-day retention window that bounds the exposure without closing it. A queued person id therefore outlives the erasure the application confirmed to the subject.

`Shared.Image` is the awkward case the registry anticipates. The same type covers an image that is a bank's logo — plainly not a person — and one that is a person's avatar, where the identifier is a reference to that person's likeness. The module cannot tell them apart **by construction**: it holds no owner, no filename and no classification, and the ADR governing it makes that absence structural rather than incidental.

## Decisions

### D1 — `Shared.Image` is classified `person`, conservatively

The registry states the rule for a mixed type: *"Where a type is mixed, the conservative verdict is the correct one, and routing any of its events then needs an argued ADR exception."* The precedent is live in the same file — `Iam.Session => person`, where one type likewise covers events with different id semantics.

**Discarded:** `non-person`, on the argument that an image is a technical resource. It would be true of most images and false of exactly the ones that matter, and the registry's own header records that it cannot judge a classification — `non-person` over a person's id passes silently, and review is the only control on that direction. A verdict that is right on average and wrong on avatars is not a verdict.

### D2 — The event is routed to `async` anyway

Leaving it unrouted is not the safe default it appears to be; it is the dangerous one.

An unrouted `DomainEvent` is handled **in process**. `RunProjectionsOnDomainEvent` is registered for `DomainEvent::class`, so every domain event has a handler, and use cases publish inside `TransactionManager::transactional(...)`. The deletion of bytes would therefore run **inside the owner's transaction**, where a storage failure rolls back the owner's business write and leaves a live reference over bytes that were already destroyed. Three separate documents prohibit exactly that: the epic's own NFR on after-commit publication, its decision firewall (*"byte removal is not a synchronous side effect inside the owner's transaction"*), and invariant 4 of the conservation-contract ADR.

Routing also buys the retry the deletion contract leans on: a transient storage failure is retried by the worker rather than surfacing to a user request that has nothing to do with images.

**What actually provides the after-commit guarantee is the mechanism, not the transport's name.** `async` resolves to the Doctrine transport over the same connection, and `EventBus::publish()` is called inside `transactional(...)`, so the `INSERT` into `messenger_messages` lives in the same transaction and a rollback takes it with it — the worker cannot see an uncommitted row. **The property depends on the DSN.** Point `MESSENGER_TRANSPORT_DSN` at an external broker and the publish leaves the transaction, the guarantee disappears, and every gate here stays green. That is why the guarantee is asserted by a test that demonstrates both directions, not inferred from the routing table.

**Discarded:** leaving it unrouted and performing the deletion as a post-commit effect in the consuming context's use case. It is what the root instructions prescribe for a person-denoting type, and it is defensible — but it moves the physical deletion into every future consumer, gives up the worker's retry, and puts the module's own invariant in the hands of code this module does not own. The cost of D2 is paid once here; that cost would be paid again in every consumer, and silently forgotten in one of them.

### D3 — What this decision does **not** close, stated so it is never sold as a mitigation

The classification is a decision about **transport**, not a mitigation of erasure. It deletes nothing and protects nothing:

- The `ImageId` sits in `messenger_messages` while queued, and for 30 days more if it dead-letters. Neither window is reached by any erasure use case.
- `event_store` retains the identifier **for ever, and no routing changes that**. `PersistDomainEventMiddleware` appends every dispatched event with its real `aggregate_id` before Messenger chooses a transport — the registry names this as its own blind spot. Identity erasure rewrites by the value of the **subject's** identifier, and an `ImageId` is not that value, so the row survives.
- There is no reference counting and no ownership, so nothing here prevents a consumer requesting the deletion of an image another consumer believes it still holds.

Reading D1 as "the id is protected because it was classified `person`" is the specific error this section exists to prevent. What the classification buys is that the decision was **forced into a diff and reviewed**, which is all the registry ever claimed for it.

There is a fourth residual, and it is the one no gate can reach: the after-commit guarantee itself depends on `MESSENGER_TRANSPORT_DSN` still resolving to the Doctrine transport on the caller's own connection. Three of the five places declaring it are `${MESSENGER_TRANSPORT_DSN:-…}` interpolations in the compose files, so a deployment's environment wins — deliberately, since the DSN has to be configurable. `make php.lint.persistent-transport` pins what the repository declares (both halves falsified by mutation: rewriting the DSN, and deleting the routing line), and that is all a green there means. Accepted rather than closed, and tracked where it can be audited instead of in this paragraph. @accepted-risk #872

## Consequences

- `api/.persistent-transport-policy` carries `Shared.Image => person :: docs/adr/image-deletion-signal-transport.md`.
- The event's payload stays empty: only the identifier travels, as the envelope's aggregate id. Anything added to `toPrimitives()` is retained by both sinks above.
- The residuals in D3 belong to the consuming epic to close or accept; they are recorded here so that epic does not rediscover them.
