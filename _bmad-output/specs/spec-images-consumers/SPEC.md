---
id: SPEC-images-consumers
companions:
  - consumer-matrix.md
  - wire-contract.md
  - ../../../docs/adr/images-vs-documents-conservation-contract.md
  - ../../../docs/adr/image-read-route-contract.md
  - ../../../docs/adr/image-deletion-signal-transport.md
sources: []
---

> **Canonical contract.** This SPEC and the files in `companions:` are the complete, preservation-validated contract for what to build, test, and validate. Source documents listed in frontmatter are for traceability — consult them only if you need narrative rationale or prose color this contract intentionally omits.

# Image consumers: bank logo, user avatar

## Why

A vision to realize and a mandate to meet. `Shared/Images` shipped (img-1-1..3) as a seam with no consumer and no way in: it can serve an image over `GET /api/v1/images/{imageId}` but nothing uploads one over HTTP, and no aggregate holds an `ImageId`. Back-office users cannot see a bank's logo or a user's face, which the conservation ADR named as the first consumers. Wiring them is also where the obligations the seam deliberately deferred come due: releasing bytes on replace, removal and erasure, a read policy per consumer, per-consumer audit classification, a cache window against a real deletion promise, and a per-identity rate bound.

## Capabilities

- **CAP-1**
  - **intent:** An authorized caller sets, replaces or removes a consumer's single image, and the previous image is released.
  - **success:** `ic-1` proves the protocol on a test-double holder over the real transaction manager and outbox: replacing queues exactly one `ImageDeletionRequested` for the old id, consumed after commit; a failure after the mint queues the release of the new id after rollback, in a fresh transaction; two concurrent replaces of one owner leave one live image and one release per replaced id. Each consumer story proves its own route: a valid body stores one `Image` and the aggregate holds only its id; an empty, oversized, truncated or malformed body is a 4xx Problem Details with no row and no object; an absent owner or a refused caller stores nothing; a decodable polyglot is stored re-encoded with no trailing payload.
- **CAP-2**
  - **intent:** Each consumer declares who may read its image, whether the read is audited and how long a client may keep it.
  - **success:** `image-read-route-contract.md` D1–D3 carry one recorded decision per consumer; `ImageConsumerAuthorizationGateTest` holds a registry entry per holder property, derived from the code, each pointing at its ADR anchor; where a consumer's audience is narrower than an authenticated session, a read outside it is refused in a scenario.
- **CAP-3**
  - **intent:** A back-office user with the bank update permission sets, replaces or removes a bank's logo, and the logo shows in the bank list and detail.
  - **success:** An end-to-end test sets a logo and sees it on both screens; deleting the bank or replacing the logo leaves no retrievable bytes for the old id once the queue drains.
- **CAP-4**
  - **intent:** A user sets, replaces or removes an avatar, every change is traceable, and erasing the identity releases the avatar bytes.
  - **success:** Each avatar write leaves one person-denoting audit row without the image id; after `FulfilIdentityErasure` runs and the queue drains, the avatar bytes resolve to 404 by the owner route and by the shared route, the avatar's audit rows are anonymised, no event payload or queued message resolves to the face, and the erasure result states the release is queued, not done.
- **CAP-5** — dropped (2026-11-28, memlog): every candidate depiction of a bank-account image already has a better owner (the bank's logo, Documents evidence, or no business need), so `BankAccount` holds no image id.
- **CAP-6**
  - **intent:** Every consumer screen shows its image and offers upload through one shared component pair.
  - **success:** The component renders the image, a placeholder on any load failure, and an accessible upload control that maps the Problem Details types of `wire-contract.md` to English messages; `make pwa.quality` is green and a Playwright test drives set, replace and remove through it.

## Constraints

- **No generic upload route.** Each consumer owns a raw-body `PUT`/`DELETE` on its own resource (shape and error set in `wire-contract.md`), because a generic route cannot authorize per consumer and a mint-then-attach pair leaves unattached images. The consumer-scoped route narrows the orphan window; it does not remove it. Authentication, permission, id syntax and owner existence are checked before the body is read.
- **Transport stays out of Application.** The Infrastructure adapter reads the body into bytes plus an optional declared media type; no `UploadedFile`, `SplFileInfo`, path, filename or URL crosses (ADR invariant 6; `TransportOnlyUploadedFileDenormalizer` stays).
- **A consumer holds only the opaque id**: `private ?string $logoImageId`, `$avatarImageId` (UUID v7, `Uuid::ensure()` at the edge), each named `…ImageId` so the tripwire sees it; no `ManyToOne`, no foreign key into `image`. An `ImageId` has exactly one **holder**, meaning the aggregate column; events, read models and Resource DTOs may carry it as a read-only reference, and a unit test enumerates the clone and duplicate paths of the three aggregates (none exists today, so it is a tripwire).
- **Release is a queued request, never a synchronous side effect.** One shared replace service in `Shared/Images` is the creating site of `ImageDeletionRequested`, registered in `ImageDeletionRequestedPublisherGateTest` with its use case and a functional test following that gate's rollback recipe, so no consumer story edits that constant.
- **Order and failure.** Store the bytes; one transaction attaches the new id and queues the release of the old one; on failure roll back, then publish the release of the new id in a fresh transaction. A crash, an out-of-memory kill or a failure inside `UploadImage` after `store()` leaves an orphan: accepted and recorded in `PRODUCTION_SECURITY_CHECKLIST.md` §7 item 3.
- **Owner lock.** The attach transaction loads the owner `FOR UPDATE` (`Bank` gains `findByIdForUpdate`; `User` and `BankAccount` have it), reads the old id under that lock, treats a vanished owner as 404 and compensates the new id. The lock order (user row before image) is declared beside `ErasureLockOrderTest`.
- **Edge.** Caddy bounds every PHP request but the Documents content `PUT` at 1 MB and a cut body reaches PHP as a clean shorter stream, which would store a truncated image. Image write routes get their own cookie-gated matcher at `erpify.images.max_input_bytes`, a mandatory `Content-Length` equal to the bytes read, and `UploadRoutePathAgreementGateTest` extended to both matchers; a through-Caddy test sends more than 1 MB. The 100 MiB bound is never lifted to fit an image.
- **Spool and session.** The upload's plaintext sits in the PHP tmpfs spool until the worker serves another request, and a slow `PUT` holds the session lock. A shared (not Documents-importing) listener discards the spool on the image write routes, and the controller releases the session lock before streaming.
- **Errors.** The five `Shared/Images` exceptions extend the native `DomainException` with no error-contract marker, so an HTTP caller gets 500. `ic-1` maps them to the Problem Details types of `wire-contract.md` and edits `docs/api-error-contract.md` (drift gate `php.lint.error-contract`).
- **Read policy.** The logo reads through the shared route, and ADR D1 records that for them the audience is the authenticated session. The avatar gets an owner-scoped read route that resolves the image only through the target user's current `avatarImageId`, with its own voter, audit type and cache window. The user Resource DTO exposes `hasAvatar`, never the id: the shared route serves any id to any session and an id is not a secret, so exposing it would let a session bypass the owner route.
- **Avatar classification.** `avatarImageId` is `non-person` in `api/.person-reference-policy` (it holds an image id, not a person id) with a `Types::GUID` column. `User` is not an `AuditedEntity` (`password_hash`), so no diff is captured and `#[PersonalData]` would seal nothing; each avatar write writes an explicit `AuditLogger` row of the person-denoting type `UserAvatar`, in the same transaction as the mutation and the queued release, recording operation, actor and subject and never the image id, with `AuditResourceAnonymiser` as its erasure owner and an acceptance scenario.
- **Erasure.** `FulfilIdentityErasure` has no detective control, so review owns it: the identity erasure reads `avatarImageId` under `findByIdForUpdate` before `users->remove`, queues the release in the same transaction and reports a new result category `avatar_release_queued`.
- **Tripwire.** `ImageConsumerAuthorizationGateTest` asserts an empty list today and its regex needs a capital-I `ImageId` suffix. `ic-1` redesigns it as a registry `property => anchor in ADR D1`, with the universe derived from the code, so `ic-3` and `ic-4` only add entries.
- **Rate limit.** Every image write route and each read path has a per-identity-and-route limit keyed by an HMAC of the identity (the limiter pool is reached by no erasure path), answering 429 with `Retry-After`. The read budget is sized against a list page, since N rows cost N reads; a global concurrency cap or `PHP_MEM_LIMIT` sizing bounds the write routes (about 10 B/px, roughly 200 MB at 20 MP against 256M).
- **PWA rules:** `src` through `safeHref`, no `dangerouslySetInnerHTML`, `accept` allowlist plus a client size precheck that never truncates, English copy (`ui-copy-language` gate), the upload client behind an Inversify port, `metadata.title` on every new back-office route.
- **Registries owed per story:** `api/.route-manifest.json`, an `ordinary` line per route in `api/.credential-proof-policy`, `api/.person-reference-policy`, `api/.audit-resource-types`, the Resource DTO golden tests, the event catalog if `BankSnapshot` changes, the architecture pages, a binary raw-body Behat step searched for before written, and the §7 rows each story closes.
- **Review.** Every story owes the three review layers, and its artifact states where each ran.
- **Cut by guarantee axis, ≤ ~70 files per PR.** A story projected above ~100 files is split, not accepted.

## Non-goals

- Deduplication, shared blobs, reference counting or GC (redesign-forcing per ADR D6).
- Variants, thumbnails, cropping or any editing.
- `Range` support, an S3 backend, virus scanning of images, `Documents` derivatives.
- Company, collaborator and product images.
- A reconciliation job for orphaned objects or lost deletion requests (declined by the no-bookkeeping decision; residuals stay in `PRODUCTION_SECURITY_CHECKLIST.md` §7).
- A batch avatar-read endpoint to absorb list fan-out.

## Success signal

A back-office user uploads a bank logo and sees it in the list, replaces it and the old bytes stop being retrievable, and an erased user's avatar is gone from storage and from every readable audit payload within the windows the contract declares — with every gate green and no consumer reading an image under a policy nobody wrote down.

## Assumptions

- Logo writes reuse the existing bank update permission; a new permission is minted only if a story shows that one is wrong.
- No production deployment exists, so the new columns need no backfill or dual-read period.
- Re-encoding strips metadata (ADR D1), so avatars carry no EXIF.
- Residual, recorded rather than closed: a leaked or enumerated avatar id still reaches the shared route, with its one-hour cache, because closing it needs a kind on `Image`, which the module refuses by construction.

## Open Questions

- Who may set an avatar: the user on their own profile, an administrator on behalf of another user, or both?
- Who may read an avatar through the owner route: the user, an administrator, any back-office session? Can a session exist outside the back-office (frontoffice, portal)?
- Cache window for avatars: `private, max-age=3600` or `no-store` (ADR D3: a zero deletion SLA means `no-store`), given the list fan-out.
- Is the avatar read audited, sampled, or is the write row the only trace? An audited read writes one row per avatar on a user list.
- Logo event contract (`ic-3`): `Bank::rename()` returns early on a no-op and records nothing, so a logo-only change emits no event unless one is added; reusing `BankUpdatedDomainEvent` mails and broadcasts on every logo change; `BankSnapshot::stringField` coerces null to an empty string. No event, or a separate event that preserves null?
- Per-identity write and read budgets and the global write bound (`ic-1`): the numbers.
