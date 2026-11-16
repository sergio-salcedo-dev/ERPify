---
title: 'ic-1 Shared image attachment seam (API)'
type: 'feature'
created: '2026-11-16'
status: 'done'
baseline_commit: '02675a7721459ee2885a0466fb02af75dd09e4dc'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/specs/spec-images-consumers/SPEC.md'
  - '{project-root}/_bmad-output/specs/spec-images-consumers/wire-contract.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** `Shared/Images` can serve an image but nothing can set, replace or release one over HTTP safely: the Caddy edge would cut an image `PUT` at 1 MB and store a truncated image, the image exceptions answer 500, replace and release have no owner lock or compensation, the first `ImageDeletionRequested` publisher (#872) does not exist, and `ImageConsumerAuthorizationGateTest` can never go green once a consumer exists.

**Approach:** Build the consumer-independent half in `Shared/Images`: a raw-body reader, a shared replace service that is the one creating site of `ImageDeletionRequested`, the Problem Details mapping, the edge bound, spool and session hardening, a per-identity limiter, and the gate redesign. It wires no production route; the three consumers plug into it.

## Boundaries & Constraints

**Always:** Reuse `UploadImage` (the only `new Image(` site) and `DeleteImage` unchanged. No `UploadedFile`, path, filename or URL crosses into Application, and no public parameter of the module is named `path`, `filename`, `url`, `uri`, `key` or `directory`. The replace service takes the owner row `FOR UPDATE` through a port the consumer implements, reads the old id under that lock, attaches the new id and queues the release of the old one in one transaction, and on failure rolls back and then publishes the release of the new id in a fresh transaction. Authentication, permission, id syntax and owner existence are checked before the body is read; the session lock is released before streaming; `Content-Length` is mandatory and must equal the bytes read. Image failures map to the types of `wire-contract.md`; `ImageProcessingFailed` stays 5xx. The limiter key is an HMAC of the identity and the route; the answer is 429 with `Retry-After`.

**Decided by Sergio (2026-11-16):** (1) ic-1 adds the Caddy matcher for the three planned path shapes (`/api/v1/backoffice/banks/<id>/logo`, `/users/<id>/avatar`, `/bank-accounts/<id>/image`) and the parametrised path-agreement gate; the through-Caddy measurement of more than 1 MB lands with the first consumer story, beside its route, and the matcher is proved on text until then. (2) The write-route memory bound is the `PHP_MEM_LIMIT` and worker-count arithmetic recorded as the accepted measure in `PRODUCTION_SECURITY_CHECKLIST.md` §7 item 7; no global in-flight counter. (3) The story stays one spec, not split.

**Never:** No production route, no migration, no consumer column. No import of `Documents` code (generalise by copying the shape into `Shared`). No lifting of the 100 MiB or the 1 MB edge bounds beyond the image matcher. No deletion of `ImageConsumerAuthorizationGateTest`, and no `@accepted-risk #872` tag left behind.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Replace | Owner holds image A; valid body | One `Image` B stored, holder points to B, one release queued for A, consumed after commit | N/A |
| First set | Owner holds nothing | B stored, holder set, no release queued | N/A |
| Failure after mint | Attach fails after B is stored | Rollback, then one release of B queued in a fresh transaction | Original error propagates |
| Owner gone | Owner removed before the lock | Nothing attached, release of B queued | `404` |
| Two replaces | Concurrent writes on one owner | One live image; each replaced id released once | N/A |
| Remove | Owner holds A | Holder cleared, one release of A | N/A |
| Remove, none held | Owner holds nothing | No change, no event | `204` |
| Bad body | Empty, undecodable, unsupported, over the cap | No row, no object | `422` `image-invalid`, `415` `image-unsupported-type`, `413` `image-too-large` |
| Length | `Content-Length` missing or unequal to bytes read | Nothing stored | `411` `image-length-required` |
| Polyglot | Decodable image with trailing payload | Stored re-encoded, no trailing payload | N/A |
| Over budget | Identity exceeds the write or read budget | Request refused | `429` with `Retry-After` |

</frozen-after-approval>

## Code Map

- `api/src/Shared/Images/Application/UploadImage.php`, `DeleteImage.php` -- reuse unchanged; `UploadImage` is the sole `new Image(` site (`ImageWriterSurfaceGateTest`).
- `api/src/Shared/Images/Application/` -- new replace service (creating site) and the owner port; it calls `new ImageDeletionRequested($id)` and `EventBus::publish` inside `TransactionManager::transactional`.
- `api/src/Shared/Images/Domain/Event/ImageDeletionRequested.php`, `Infrastructure/Messenger/DeleteImageOnDeletionRequested.php` -- event and consumer; routed to `async` (`messenger.yaml:52`); `Shared.Image => person` stays (`.persistent-transport-policy:74`).
- `api/src/Shared/Persistence/Infrastructure/DoctrineTransactionManager.php` -- `transactional()` rolls back and resets the manager; a fresh `transactional()` after a caught failure is the compensation.
- `api/src/Shared/Images/Domain/Exception/` -- five exceptions extend native `\DomainException`; re-parent to `Shared\ErrorContract\Domain\Exception\DomainException` with a marker and `type` (keep `ImageProcessingException`); `ImageProcessingFailed` untouched in status.
- `api/src/Shared/ErrorContract/Infrastructure/Http/ProblemDetailsFactory.php` (`MARKER_STATUS_MAP`, `MARKER_DEFAULT_TYPE_MAP`), `Shared/Monitoring/Infrastructure/Sentry/SentryEventFilter.php`, `docs/api-error-contract.md`, `ErrorContractGateTest` -- new markers for 413 and 415 (411 and 422 exist: `LengthRequired`, `InvariantViolation`).
- `api/src/Documents/Document/Infrastructure/Http/DiscardUploadSpoolListener.php`, `Upload/PhpUploadBodySpool.php`, `UploadEvidenceController.php:70-120` -- the shape to copy into `Shared`: spool discard on `kernel.response`, `session->save()` before streaming, `Content-Length` regex.
- `api/frankenphp/Caddyfile:171-198`, `api/tests/Unit/Gate/UploadRoutePathAgreementGateTest.php` (`assertCount(2`) -- add the image matcher and parametrise the gate.
- `api/src/Documents/Document/Infrastructure/Security/DeclareUploadThrottle.php`, `config/packages/rate_limiter.yaml`, `.env` -- limiter shape; add write and read limiters.
- `api/tests/Unit/Shared/Images/ImageConsumerAuthorizationGateTest.php` -- regex `/\$[a-z][A-Za-z0-9_]*ImageId\b/`, asserts `[]`; redesign as a registry of `property => ADR D1 anchor`, universe derived from `src`, sweep-size test kept.
- `api/tests/Unit/Gate/ImageDeletionRequestedPublisherGateTest.php` -- register the service in `PUBLISHERS` with a functional test under `tests/Functional/` importing its FQCN; model the rollback test on `tests/Functional/Shared/Event/AsyncDoctrineTransportFunctionalTest.php` and its `AsyncProbeRig`.
- `PRODUCTION_SECURITY_CHECKLIST.md` (#872 row ~2502, items 2, 3, 5, 6, 7), `docs/adr/image-deletion-signal-transport.md` (`@accepted-risk #872` at ~29, ~50), `docs/adr/image-read-route-contract.md`, `docs/architecture-api.md` (~350), `docs/architecture/event-catalog.md` (~52) -- close #872 and update the residuals.
- `api/tests/Unit/Iam/Identity/Application/ErasureLockOrderTest.php`, `Unit/Shared/Persistence/Double/LockOrderJournal.php` -- declare "user row before image".

## Tasks & Acceptance

**Execution:**
- [x] `api/src/Shared/Images/Domain/Exception/*` + ErrorContract markers, `ProblemDetailsFactory`, `SentryEventFilter`, `docs/api-error-contract.md` -- map the five exceptions to 413/415/422 types -- an HTTP caller no longer gets 500
- [x] `api/src/Shared/Images/Infrastructure/Http/` -- raw-body reader (bytes, optional clean `image/*` hint, mandatory `Content-Length` equal to bytes read, read capped before allocating)
- [x] `api/src/Shared/Images/Application/` -- replace service and owner port -- the one creating site, lock, compensation
- [x] `api/src/Shared/Http/Infrastructure/` + `services.yaml` -- spool-discard listener and session release keyed on a route attribute, copied from Documents
- [x] `api/frankenphp/Caddyfile` + `UploadRoutePathAgreementGateTest` -- image matcher bounded at `erpify.images.max_input_bytes`, cookie-gated; gate parametrised
- [x] `api/config/packages/rate_limiter.yaml`, `.env`, a Shared throttle -- HMAC-keyed write and read limiters; apply the read limiter to `shared_image_get`
- [x] `ImageConsumerAuthorizationGateTest` -- registry redesign; `ImageDeletionRequestedPublisherGateTest` -- register the service
- [x] `tests/Functional/Shared/Images/` -- protocol tests on a committed DBAL holder table dropped in `tearDown`: replace, first set, remove, compensation, two writers, owner gone, rollback seen from a second connection
- [x] `tests/Unit/Iam/Identity/Application/ErasureLockOrderTest.php` + `LockOrderJournal` -- declare the lock order
- [x] `PRODUCTION_SECURITY_CHECKLIST.md`, `docs/adr/*`, `docs/architecture-api.md`, `docs/architecture/event-catalog.md` -- close #872, update §7 items, drop the tags
- [x] Unit tests for the I/O matrix cases the reader, mapping and limiter own

**Acceptance Criteria:**
- Given a test-double holder, when the replace service runs the matrix scenarios, then each outcome holds and the rollback case shows neither the holder row nor a `messenger_messages` row from a second connection.
- Given a body of exactly the cap plus one byte, or a `Content-Length` one byte off, when the reader runs, then it refuses with `image-too-large` or `image-length-required` and stores nothing.
- Given the redesigned gate, when a holder `$fooImageId` exists in `src` without a registry entry, then the gate fails naming it; with an entry pointing at its ADR D1 anchor it passes.
- Given the PR, then `make php.quality` is green and `php.lint.accepted-risk` finds no `#872` tag or row.

## Implementation Notes

- The service is `ImageAttachment` (`replace()`, `remove()`) over the `ImageHolder` port (`lockAndRead()`, `attach()`); a consumer passes its own holder per call. It is the one file creating the deletion request, registered in `ImageDeletionRequestedPublisherGateTest` with `ImageAttachmentFunctionalTest`.
- Session release lives in `ImageRequestBodyReader`, not in a listener: the reader runs after the consumer's checks and before the stream, so the ordering holds by construction. The listener (`DiscardRawBodySpoolListener`) keys on the route default `RawBodyRoute::ATTRIBUTE`.
- The media-type hint is passed on only when it is exactly one of the four types the processor accepts (`ImagePreflightGuard::SUPPORTED_MEDIA_TYPES`): the wire contract's `image/jpg` is "clean" by token shape and must still be ignored.
- Numbers decided here: write budget 30 per 10 minutes, read budget 600 per minute, both per identity and route.
- Kept apart on purpose: `ImageProcessingFailed` stays on the native `DomainException` (500 unchanged); `ContentTooLarge` (413) and `UnsupportedMediaType` (415) are new markers, and 413 joined `HTTP_STATUS_TYPE_MAP`.
- `PhpUploadBodySpool` is copied into `Shared/Http/Infrastructure/Upload`, as the spec said; the Documents twin can adopt it later.

## Spec Change Log

- 2026-11-16, review pass 1, finding 1 (all three layers): the frozen matrix and Always text queue the release of the new id on any failure after the mint. A failure raised after the attach actually committed (a lost commit acknowledgement) would release bytes the owner now holds. Amended without touching the frozen block: the fresh compensation transaction re-reads the owner under its lock and skips the release when it already holds the new id; an absent owner still releases. Known-bad state avoided: a live reference over destroyed bytes. KEEP: the compensation still runs after rollback in a fresh transaction, and a failed compensation never replaces the original failure. Matrix row added by implication, not text: "commit landed, acknowledgement lost → nothing released".

## Review Triage Log

Layers: Blind Hunter, Edge Case Hunter and Acceptance Auditor ran as parallel read-only subagents over the full diff (pass 1, 2026-11-16). No PR existed yet, so no review threads were read.

| # | Finding | Verdict | Route / evidence |
|---|---------|---------|------------------|
| 1 | Compensation releases the NEW id after an ambiguous commit (lost ack): owner points at a queued-for-deletion image (all 3 layers) | high | patch: in the fresh transaction re-read the owner and skip the release when it already holds the new id; an absent owner (`NotFound`) still releases. `ImageAttachment.php:69-73` catches any `Throwable` |
| 2 | Lock-order "declaration" cannot fail; `ImageAttachmentTest` pins `[IMAGE, IDENTITY_USER]`, the reverse; `ErasureLockOrderTest` prose describes an avatar erasure that does not exist yet | medium | patch: reword to what is pinned and assert per unit of work |
| 3 | Gate registry keyed by property name only; column regex misses `options: [...]` before `name:` | medium | patch: key by `file::holder`, widen the column match |
| 4 | Read budget on `shared_image_get` has no test that fails when moved or removed | medium | patch: controller test with a budget of 1, 200 then 304 with a matching `If-None-Match`, finder not called |
| 5 | Spool listener has no positive test of the discard | medium | patch: recording spool, assert `discard()` args for a marked main request |
| 6 | "Queued before commit" probe runs before `requestRelease`; "Two replaces" proves the row lock, not a blocked second writer | medium | patch: move the probe after the publish; state exactly what the second case proves |
| 7 | Docs now false: `api-error-contract.md` 415 "no marker backs it", `MARKER_FREE_BRIDGE_STATUSES` and the test name; `ProblemDetailsFactory` 413 "bridge raises" with no producer in vendor | medium | patch: rewrite; delete the empty constant if unread |
| 8 | `wire-contract.md` says "clean `image/*` token", code passes only the four supported types | medium | patch: wire-contract states the exact rule (ic-2 codes against it) |
| 9 | Memory residual says "several identities"; a 30-per-10-minutes budget bounds requests, so one identity can reach the OOM kill | medium | patch: §7 item 7 wording |
| 10 | Drain of a refused or anonymous body holds a worker up to 120 s, same as the Documents listener, unrecorded | medium | patch: record in §7 item 11 (parity, accepted) |
| 11 | Seam wired by convention only: a consumer route missing `RawBodyRoute::ATTRIBUTE`, the throttle-before-reader order, or `ImageAttachment` leaves a plaintext spool with every gate green; forward guard misses a path outside the three shapes | medium | patch: acceptance lines in `stories.yaml` for ic-3..ic-5, §7 item 11 notes it; a manifest gate needs a consumer route and lands with the first one |
| 12 | Stale or present-tense comments: publisher gate docblock "real map is empty", "No such route is mounted yet", "(no production route yet)"; PWA mapping claimed in docs with no PWA code | low | patch (boy scout, same files) |
| 13 | `source-tree-analysis.md` lacks `Shared/Http/Infrastructure/Upload/`; §7 items 2 and 6 not touched | low | patch: one row, one pointer each |
| 14 | `BestEffortReportChannelGateTest` duplicate `PhpUploadBodySpool.php` | false | two files, Documents and Shared, share the basename; the test compares basenames (AA verified) |
| 15 | Pixel and dimension refusals answer 413; malformed `Content-Length` reuses "not as long as declared" | false / low | 413 is the wire contract's rule; wording is cosmetic and the fix adds a factory |
| 16 | New gate sees declared properties only, narrower than the old whole-file regex | low | rejected: a holder must be a declared ORM property to persist; fix would add machinery |
| 17 | ADR anchor satisfied by any backticked mention; gate pins an ADR phrase | low | rejected: the phrase is the anchor the gate exists to hold |
| 18 | `ImageAttachment` does not pre-check owner before decoding if a consumer skips the pre-check | low | consumer contract in `ImageHolder` docblock; proved per consumer story (ic-3..ic-5) |
| 19 | Functional suite imports Unit doubles; inline FQCN catch | low | rejected: precedent exists in the suite |

### Review Findings

Pass 2 (2026-11-17): `bmad-code-review` over commit `5c5e93a9` — Blind Hunter, Edge Case Hunter, Verification Gap and Acceptance Auditor in parallel, read-only, the Edge and Acceptance layers told the pass-1 table. No `decision-needed`.

- [x] [Review][Patch] Throttle key is not verified: two actors and two routes over one shared storage, and the `ip:` fallback, have no test; a constant identity or route passes every test [`ImageRequestThrottleTest`] — medium (VG)
- [x] [Review][Patch] Compensation re-read takes the owner `FOR UPDATE`, the lock that may have just failed the attach, so contention can orphan the new id; the failure mode is in no docblock or §7 item 3 [`ImageAttachment::holds`] — medium (AA)
- [x] [Review][Patch] `ImageHolder` docblock says both methods run inside the attach transaction and the holder's not-found "own exception"; the compensation now calls `lockAndRead` alone and catches only the `NotFound` marker — state both [`ImageHolder.php`] — medium (AA)
- [x] [Review][Patch] A lost acknowledgement plus a later writer releases the same id twice; harmless only because `DeleteImage` is idempotent, which no docblock or test states [`ImageAttachment`] — low (BH, AA)
- [x] [Review][Patch] An image `PUT` refused before routing (per-IP 429 at priority 512, or no route yet under the lifted 20 MiB matcher) never gets `RawBodyRoute::ATTRIBUTE`, so its spool is not discarded; Documents' twin has the same hole — record both causes in §7 item 11 (a) [`PRODUCTION_SECURITY_CHECKLIST.md`] — medium (EC, BH)
- [x] [Review][Patch] `PhpUploadBodySpool` is duplicated between Documents and Shared with adoption deferred; Documents adopts the Shared class here and its copy is deleted (this also removes the double `REPORTERS` entry) [`Documents/…/Upload/PhpUploadBodySpool.php`] — medium (BH)
- [x] [Review][Patch] Stale `clean image/* hint` in `docs/architecture-api.md:146`, the same rule corrected in `wire-contract.md` [`docs/architecture-api.md`] — low (BH, AA)
- [x] [Review][Patch] `ContentTooLarge` is described as the endpoint's pre-decode bound and also carries the post-decode pixel and dimension limits; 415 is called "one wire type" while the image refusal is `image-unsupported-type` and the framework one `unsupported-media-type`; a framework 413 is re-typed from `http-error` to `content-too-large` without a line saying so (check the PWA mapping and gallery) [`docs/api-error-contract.md`, marker docblocks] — low (BH)
- [x] [Review][Patch] §7 item 11 mixes the publish-guard residual with the upload-seam ones, and item 5 says the write throttle "is spent" by routes that do not exist; retitle or split, and say "will be spent by routes that follow the convention" [`PRODUCTION_SECURITY_CHECKLIST.md`] — low (BH)
- [x] [Review][Patch] The compensation-failure log carries the exception class only; say in §7 item 3 that the new id is kept out on purpose and what an operator can use instead [`PRODUCTION_SECURITY_CHECKLIST.md`] — low (BH)
- [x] [Review][Patch] `LockOrderJournal::IMAGE` and the `$journal` plumbing of `ImageAttachmentTest` are written and never read; delete the dead instrumentation or assert `crossTableOrder()` [`ImageAttachmentTest`, `LockOrderJournal`] — low (VG)
- [x] [Review][Patch] Reader docblock says nothing after the release writes the session; Security's `ContextListener` saves the token on the response and reopens it — reword to what holds (no lock during the stream) [`ImageRequestBodyReader.php`] — low (EC)
- [x] [Review][Patch] The holder pattern misses a promoted `readonly` property without visibility, and `var` [`ImageConsumerAuthorizationGateTest`] — low (EC)
- [x] [Review][Patch] The matrix has no row for "commit landed, acknowledgement lost" and the Spec Change Log is empty; record the rule there [story file] — low (AA)
- [x] [Review][Patch] Name the owner of the manifest-driven wiring gate (ic-3), tell ic-3..ic-5 that a DTO or event property of a holder also joins the registry, and make ic-3 prove the compensation through an ORM-backed holder after a real manager reset [`stories.yaml`] — medium (BH, AA)
- [x] [Review][Patch] Commit message says five exceptions map "through two new markers"; four are re-parented and 411 uses the existing `LengthRequired` marker [commit `5c5e93a9`, unpushed, amend] — low (EC)

Rejected: owner pre-check before decoding (carried from pass 1, row 18: consumer contract, proved per consumer story) · "two replaces" blocked-writer test (carried, row 6) · drain deadline and time-limit untested under CLI (parity with the Documents twin) · listener registration unverified (autoconfigured by the `Erpify\` glob) · test-double counter order and `parent::tearDown()` (cosmetic) · compensation-failure `message` and `context.event` duplicate (cosmetic).

## Design Notes

The compensation cannot run inside the failed transaction: Doctrine closes the manager and `RequireTransactionalPublishMiddleware` demands an active transaction, so the release of the new id is published by a second `transactional()` after the first one threw. A crash or OOM between the store and the attach still leaves an orphan; that residual stays in §7 item 3.

## Verification

**Commands:**
- `make php.stan` -- expected: no errors on changed files
- `make php.quality` -- expected: exit 0
- `make php.unit c='--filter Image'` -- expected: green
- `make php.lint.error-contract`, `make php.lint.persistent-transport`, `make php.lint.accepted-risk` -- expected: green
