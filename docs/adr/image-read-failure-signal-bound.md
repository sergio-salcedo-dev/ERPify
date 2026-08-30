# ADR — Leaving the image read path's failure signal unbounded

> **Status:** accepted · **Date:** 2026-08-30 · **Scope:** the observability signal emitted by `ImageStorage::read()` failures in `Shared/Images`, and the epic acceptance criterion it supersedes.
>
> This record exists because it **overrides a written requirement**. `epics-images.md` ordered the read path's confirmed-absence signal to be bounded, and a code review of the previous story deferred that finding into the story that exposes `read()`. Measuring the complete logging path changed the answer, and a story does not get to reinterpret its own acceptance criterion from inside — hence an argued exception here rather than an edit and a shrug.

## Context

`GET /api/v1/images/{imageId}` is the first HTTP surface of `Shared/Images` and the first production caller of `ImageStorage::read()`. That method reports three failures, and all three become reachable by an authenticated client the day the route ships:

- **P1 — confirmed absence.** The `image` row is alive and its bytes are gone. One `info` record per request. Answers **404**.
- **P2 — the storage root is unusable.** The adapter guards the root's presence and traversability *before* it looks for the object, so an unmounted volume or a permission fault fails **every** read of **any** existing row. One `warning` record per request. Answers **5xx**.
- **P3 — a transient substrate failure.** Same shape as P2.

They land on the `observability` Monolog channel, which sits outside the production `fingers_crossed` handler and streams always-on to `php://stderr`. Retention is Docker's `json-file` rotation — `max-size: "10m"` × `max-file: "5"`, about 50 MB — shared with the buffered default handler, the deprecation stream and Caddy's access log, which writes a line per request unconditionally. **Rotation evicts by volume, not by age**, so a flood destroys unrelated history and there is no TTL and no erasure owner.

The requirement to bound this rested on a premise that had propagated through three documents unmeasured.

## Decisions

### D1 — The premise the requirement rested on is false, and that is why the requirement changes

The epic and the deferred finding both state that *N random identifiers evict the retained log at zero cost*. Under the resolution order the read route mandates — `findById()` first, `ImageStorage::read()` only on a hit — **a random unknown identifier never reaches storage and emits nothing**: the repository answers `null` and the request answers 404. P1 requires a row that exists whose bytes do not, and P2/P3 require a deployment fault. None of the three is a value a client simply picks.

**This is a correction, not a loophole.** The producers are still client-*triggerable*: one valid identifier is enough, and any caller who has legitimately viewed an image has one. What changed is that the volume is gated by a pre-existing fault rather than by the caller's imagination.

### D2 — The read path's failure signal is left unbounded

No sampling, no counter, no per-request cap, and no change to `emit()`.

**The decisive fact is that the available bounds are at the wrong control point.** A P2/P3 request writes the `observability` line **and** an `error` record on the *default* channel — `ExceptionResponder` logs `status >= 500` at `error` — which activates the buffered handler and flushes it. The `error` record belongs to another channel and another module and is unreachable from here. Bounding the first while the second runs unbounded does not bound the failure's logging footprint; it degrades one signal and leaves the shared-sink consumption where it was.

**Discarded: sampling (1 in N).** It is the only option that reduces anything, and what it reduces is one record of at least two, at the cost of turning deterministic forensic evidence into probabilistic evidence. Reducing a flood by a fraction is not worthless in general — that reasoning would be invalid as a principle — but here the fraction is small, unmeasured, and bought with a semantic loss on the only signal that says *this deployment cannot read images*.

**Discarded: an aggregate counter.** It needs state and a flush point. FrankenPHP in worker mode offers none for free, and flushing per request degenerates into one record per request — the case being avoided.

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
- **The frequency is not measured and cannot be.** What the implementing story measures is the **cost per event**: the bytes of an `image_storage_failure` line, of the corresponding `error` record, and of a representative access-log line, plus the arithmetic for a hypothesised P2/P3 rate. It measures **no real frequency and no production behaviour**, because there is no production deployment — a fact `PRODUCTION_SECURITY_CHECKLIST.md` states in three places. Any figure derived here is an estimate of unit cost, never an observation of exposure.

## Consequences

- `epics-images.md` carries an amended acceptance criterion pointing here, so no requirement in the tree still orders a bound that the code deliberately does not implement.
- The implementing story's acceptance criterion becomes a **declaration plus the cost-per-event measurement**, not a bound, and cites this record.
- The residual joins the `Shared/Images` block of `PRODUCTION_SECURITY_CHECKLIST.md` §7, whose framing changes from "too many logs" to **shared-sink eviction between independent producers**.
- The infrastructure concern of D4 is tracked on its own, outside this epic.
- Revisit trigger: the first of a real log collector, a production deployment, or a second client-triggerable producer on this sink. Any one of them changes the arithmetic that D2 rests on.
