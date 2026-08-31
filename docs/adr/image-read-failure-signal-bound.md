# ADR — Bounding the image read path's failure signal

> **Status:** accepted · **Date:** 2026-08-30 · **Scope:** the observability signal emitted by `ImageStorage::read()` failures in `Shared/Images`, and the epic acceptance criterion it supersedes.
>
> This record exists because it **overrides a written requirement**. `epics-images.md` ordered the read path's confirmed-absence signal to be bounded, and a code review of the previous story deferred that finding into the story that exposes `read()`. Measuring the complete logging path changed the answer, and a story does not get to reinterpret its own acceptance criterion from inside — hence an argued exception here rather than an edit and a shrug.
>
> **Amended 2026-08-31, and the amendment reverses D2.** The story's own code review found two things this record could not have known when it was written: the read path had grown to **five** producers while every document describing it still said three, and D2's decisive discarding argument — that worker mode offers no state for free — is false. The signal is now bounded. The original reasoning is kept below rather than rewritten, because what makes the new decision trustworthy is seeing exactly which premise failed.

## Context

`GET /api/v1/images/{imageId}` is the first HTTP surface of `Shared/Images` and the first production caller of `ImageStorage::read()`. That method reports three failures, and the read use case adds two more above it; all five become reachable by an authenticated client the day the route ships:

- **P1 — confirmed absence.** The `image` row is alive and its bytes are gone. One `info` record per request. Answers **404**.
- **P2 — the storage root is unusable.** The adapter guards the root's presence and traversability *before* it looks for the object, so an unmounted volume or a permission fault fails **every** read of **any** existing row. One `warning` record per request. Answers **5xx**.
- **P3 — a transient substrate failure.** Same shape as P2.
- **P4 — the row declares a byte size above the serving budget.** One `warning` record per request. Answers **500**.
- **P5 — the stored bytes do not hash to the digest the row attests.** One `warning` record per request. Answers **500**.

**P4 and P5 arrived with the read use case and are the reason for the amendment.** They are not variants of P1–P3: the adapter cannot even ask their question, since it never sees `Image::digest()` and never sees the serving budget. P5 in particular has exactly the property D1 uses to reframe P1 — a corrupt object stays corrupt, so every later request for it emits again, for ever — and, answering 500, it also writes the default-channel `error` record that activates the buffered handler. So the surface this record governs is wider than the surface it described, and wider in the direction that matters.

They land on the `observability` Monolog channel, which sits outside the production `fingers_crossed` handler and streams always-on to `php://stderr`. Retention is Docker's `json-file` rotation — `max-size: "10m"` × `max-file: "5"`, about 50 MB — shared with the buffered default handler, the deprecation stream and Caddy's access log, which writes a line per request unconditionally. **Rotation evicts by volume, not by age**, so a flood destroys unrelated history and there is no TTL and no erasure owner.

The requirement to bound this rested on a premise that had propagated through three documents unmeasured.

## Decisions

### D1 — The premise the requirement rested on is false, and that is why the requirement changes

The epic and the deferred finding both state that *N random identifiers evict the retained log at zero cost*. Under the resolution order the read route mandates — `findById()` first, `ImageStorage::read()` only on a hit — **a random unknown identifier never reaches storage and emits nothing**: the repository answers `null` and the request answers 404. P1 requires a row that exists whose bytes do not, and P2/P3 require a deployment fault. None of the three is a value a client simply picks.

**This is a correction, not a loophole.** The producers are still client-*triggerable*: one valid identifier is enough, and any caller who has legitimately viewed an image has one. What changed is that the volume is gated by a pre-existing fault rather than by the caller's imagination.

### D2 — The read path's failure signal is bounded by a per-worker suppression window

**Superseded 2026-08-31. The original decision, and everything discarded to reach it, is preserved below.**

One record per `(operation, category)` per 60 seconds, in `FailureSignalWindow`, applied by both emitters — the read reporter and the storage adapter, which this route made client-reachable for the first time. The key is deliberately not the identifier: keying on that would make the map the unbounded thing, with its cardinality supplied by the caller.

**What reversed the decision is a measurement, not a change of taste.** D2 discarded an aggregate counter because *"it needs state and a flush point. FrankenPHP in worker mode offers none for free."* That is false here, and it is checkable in three commands: `frankenphp/Caddyfile` imports `worker.Caddyfile` unconditionally, `FRANKENPHP_RESET_KERNEL` is set in no compose file, environment file or Dockerfile — so `FrankenPhpWorkerRunner` never clones the kernel between requests — and nothing tags these services `kernel.reset`. The container therefore survives across requests within a worker, and in-process state is free after all. No flush point is needed because a window is not a counter: it decides at emit time and keeps nothing to flush.

The second thing that changed is the threat model, and D1 is what makes the difference visible. D1 correctly established that the volume is gated by a pre-existing fault rather than by the caller's imagination — but it reasoned about **cardinality**, how many distinct identifiers can produce a signal, and said nothing about **rate**, how fast one identifier can produce them. One orphaned or corrupt row plus the global limit of 120 requests per minute per IP is a client-controlled write rate against a sink evicted by volume. That is a different axis, and D1's correction does not reach it.

**What the original D2 got right and this amendment does not disturb:** bounding the `observability` line does not bound the failure's whole logging footprint, because a 5xx also writes the default-channel `error` record that flushes the buffer, and that record is another channel's and another module's. The window is therefore an improvement on one of two producers, not a closure. It is worth taking because the improvement is large and free; it is not worth describing as a fix.

---

**Original D2, superseded — the read path's failure signal is left unbounded.** No sampling, no counter, no per-request cap, and no change to `emit()`.

**The decisive fact is that the available bounds are at the wrong control point.** A P2/P3 request writes the `observability` line **and** an `error` record on the *default* channel — `ExceptionResponder` logs `status >= 500` at `error` — which activates the buffered handler and flushes it. The `error` record belongs to another channel and another module and is unreachable from here. Bounding the first while the second runs unbounded does not bound the failure's logging footprint; it degrades one signal and leaves the shared-sink consumption where it was.

**Discarded: sampling (1 in N).** It is the only option that reduces anything, and what it reduces is one record of at least two, at the cost of turning deterministic forensic evidence into probabilistic evidence. Reducing a flood by a fraction is not worthless in general — that reasoning would be invalid as a principle — but here the fraction is small, unmeasured, and bought with a semantic loss on the only signal that says *this deployment cannot read images*.

**Discarded: an aggregate counter.** It needs state and a flush point. FrankenPHP in worker mode offers none for free, and flushing per request degenerates into one record per request — the case being avoided. *(This is the premise the amendment measured false; worker mode does keep the container between requests.)*

**Discarded: a per-request cap.** Measured, it is a **no-op**: the read path calls `read()` once and emits at most one failure record per failed read, so "at most one per request" is already the behaviour.

**Discarded: lowering the record to `debug`.** `ObservabilityChannelGateTest` pins the handler at `level: info` for `test` and `prod`, so a `debug` record is discarded in every environment. It would satisfy the requirement's wording while destroying the property the requirement exists to preserve.

**Discarded: excluding 503 from the buffered handler's activation.** It would change the global semantics of 5xx handling for every subsystem, suppressing exactly the server-side failures that handler exists to capture.

**Discarded: answering something other than 5xx for P2/P3.** The status describes the failure, and a broken substrate is a server-side failure. Choosing a status to control logging corrupts the wire contract.

### D3 — The signal is a forensic log, not an alarm, and it must not be described as one

An earlier version of this argument justified leaving the producers loud on the grounds that an operational alarm should be loud when a deployment is broken. **Measured, that justification is false here.** In all three environments `observability` is a plain `stream` handler; the Monolog Sentry handler is commented out and, even commented, excludes this channel; and no compose file configures any external log collector — the driver is plain `json-file`. **Nothing listens.** These records are read after the fact or not at all.

So D2 does not buy a preserved alarm. It buys not paying a semantic cost for a control that would not control the thing.

### D4 — The right control point is the sink, and it is not this module's to build

The real problem the measurements expose is that Caddy's access log, the buffered default channel and `observability` compete for **one** retention budget with no isolation, so any producer can evict any other's history. That is a property of the logging infrastructure — routing, isolation, quotas, retention — and it is transverse to every context, not a defect of `Shared/Images`. Fixing it inside this module would be the wrong owner and would leave the same hole for every other producer.

Recorded as a separate concern rather than absorbed here.

## What this ADR does **not** close

Stated so nothing here is ever read as a mitigation:

- **The retention residual is real and open.** A sustained P2/P3 fault consumes the shared 50 MB and evicts unrelated history. Nothing bounds it and nothing reports it.
- **There is no collector, no alerting and no external retention**, so a fault is visible only to whoever opens the container log while the lines survive.
- **The sink has no isolation and no TTL.** Eviction is by volume, and no erasure path reaches it.
- **P1 is permanent, not transient.** It arises from a deletion request that was never consumed — recorded as residual two of `PRODUCTION_SECURITY_CHECKLIST.md` §7, *"silent and permanent"* — so once a row is orphaned every later request for it writes another `info` record, for ever. Being `info` and outside `fingers_crossed`, it reaches the sink directly and depends on no flush.
- **The window is per worker, not global.** Its state is process memory and FrankenPHP runs several workers per core, so the bound is one record per key per window *per worker* and the aggregate scales with the worker count. It is a large reduction from one per request rather than a guarantee, and a global bound would need shared state — a network round trip on a failure path, to bound a log.
- **What the window costs is frequency information.** The log stops carrying how OFTEN a permanent failure was retried and keeps only that it is still happening. No metric replaces it, because none exists here.
- **The frequency is not measured and cannot be.** What the implementing story measures is the **cost per event**: the bytes of an `image_storage_failure` line, of the corresponding `error` record, and of a representative access-log line, plus the arithmetic for a hypothesised P2/P3 rate. It measures **no real frequency and no production behaviour**, because there is no production deployment — a fact `PRODUCTION_SECURITY_CHECKLIST.md` states in three places. Any figure derived here is an estimate of unit cost, never an observation of exposure.

## Consequences

- `epics-images.md` carries an amended acceptance criterion pointing here, so no requirement in the tree still orders a bound that the code deliberately does not implement.
- The implementing story's acceptance criterion becomes a **declaration plus the cost-per-event measurement**, not a bound, and cites this record.
- The residual joins the `Shared/Images` block of `PRODUCTION_SECURITY_CHECKLIST.md` §7, whose framing changes from "too many logs" to **shared-sink eviction between independent producers**.
- The infrastructure concern of D4 is tracked on its own, outside this epic.
- Revisit trigger: the first of a real log collector, a production deployment, or a second client-triggerable producer on this sink. Any one of them changes the arithmetic that D2 rests on. **The third fired**: P4 and P5 are that producer, and the amendment above is the revisit.
