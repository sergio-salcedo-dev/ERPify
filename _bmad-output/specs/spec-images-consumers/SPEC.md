---
id: SPEC-images-consumers
companions:
  - consumer-matrix.md
  - ../../../docs/adr/images-vs-documents-conservation-contract.md
  - ../../../docs/adr/image-read-route-contract.md
  - ../../../docs/adr/image-deletion-signal-transport.md
sources: []
---

> **Canonical contract.** This SPEC and the files in `companions:` are the complete, preservation-validated contract for what to build, test, and validate. Source documents listed in frontmatter are for traceability — consult them only if you need narrative rationale or prose color this contract intentionally omits.

# Image consumers: bank logo, user avatar, bank-account image

## Why

A vision to realize and a mandate to meet. `Shared/Images` shipped (img-1-1..3) as a seam with no consumer and no way in: it can serve an image over `GET /api/v1/images/{imageId}` but nothing uploads one over HTTP, and no aggregate holds an `ImageId`. Back-office users cannot see a bank's logo or a user's face, which the conservation ADR named as the first consumers. Wiring them is also where five obligations the seam deliberately deferred come due: releasing bytes on replace, removal and erasure, a read policy per consumer, per-consumer audit classification, a cache window against a real deletion promise, and a per-identity rate bound.

## Capabilities

- **CAP-1**
  - **intent:** An authorized caller replaces or removes a consumer's single image with one raw-body request, and the previous image is released.
  - **success:** For each route, a functional test shows: a valid body stores one `Image` and the aggregate holds only its id; replacing queues exactly one `ImageDeletionRequested` for the old id, consumed after commit; a failure after the mint queues a deletion for the new id; a malformed, oversized or polyglot body is a 4xx Problem Details with no row and no object left.
- **CAP-2**
  - **intent:** Each consumer declares who may read its image, whether the read is audited and how long a client may keep it.
  - **success:** `image-read-route-contract.md` D1–D3 carry one recorded decision per consumer, `ImageConsumerAuthorizationGateTest` is green over the new properties, and a read by a caller outside the declared audience is refused in a scenario.
- **CAP-3**
  - **intent:** A back-office user with the bank update permission sets, replaces or removes a bank's logo, and the logo shows in the bank list and detail.
  - **success:** An end-to-end test sets a logo and sees it on both screens; deleting the bank or replacing the logo leaves no retrievable bytes for the old id once the queue drains.
- **CAP-4**
  - **intent:** A user sets, replaces or removes an avatar, and erasing the identity releases the avatar bytes.
  - **success:** After `FulfilIdentityErasure` runs and the queue drains, the avatar id resolves to 404 and no audit row or event payload resolves to the face (`avatarImageId` diffs are sealed under the subject's scope).
- **CAP-5**
  - **intent:** A user with the bank-account update permission sets, replaces or removes the image of a bank account, with the same release guarantees.
  - **success:** Same release scenarios as CAP-3 over `BankAccount`, plus the erasure path of `EraseBankAccountSubject` if the open question below classifies the image as personal.
- **CAP-6**
  - **intent:** Every consumer screen composes one upload-and-display component pair instead of re-solving the flow.
  - **success:** The component renders an image, a placeholder on 404 or 429, and an accessible upload control that maps Problem Details types to English messages; `make pwa.quality` is green and a Playwright test drives set, replace and remove through it.

## Constraints

- **No generic upload route.** Each consumer owns a raw-body `PUT`/`DELETE` on its own resource (the shape of the Documents ingest `PUT`), because a generic route cannot authorize per consumer and a mint-then-attach pair leaves unattached images whose bytes nothing can find.
- **Transport stays out of Application.** The Infrastructure adapter reads the body into bytes plus an optional declared media type; no `UploadedFile`, `SplFileInfo`, path, filename or URL crosses (ADR invariant 6; `TransportOnlyUploadedFileDenormalizer` stays).
- **A consumer holds only the opaque id** (`private ?string $fooImageId`, UUID v7, `Uuid::ensure()` at the edge): no `ManyToOne`, no foreign key into `image` (a foreign key makes the row deletion fail the same way on every retry).
- **An `ImageId` has exactly one holder and is never copied** — not by a clone, a duplicate or a snapshot carrying it into another aggregate.
- **Release is a queued request, never a synchronous side effect.** The aggregate records `ImageDeletionRequested`, the use case publishes it through the outbox, a handler consumes it after commit; every creating site is registered in `ImageDeletionRequestedPublisherGateTest` with its use case and functional test.
- **`avatarImageId` is `non-person` in `api/.person-reference-policy`** (it holds an image id, not a person id) and carries `#[PersonalData]` so its audit diff is sealed under the subject's scope; the erasure obligation sits in `FulfilIdentityErasure`, which has no detective control — review owns it.
- **Per-identity-and-route rate limit** on every new image write route and on each consumer's read path; the PWA must not render a broken `<img>` on 429.
- **The edge bound stays.** The body limit for the raw-body routes follows the Documents upload gates (`UploadRoutePathAgreementGateTest`); the 100 MiB bound is never lifted to fit an image.
- **PWA rules:** `src` through `safeHref`, no `dangerouslySetInnerHTML`, `accept` allowlist plus a client size precheck that never truncates, English copy (`ui-copy-language` gate), the upload client behind an Inversify port, `metadata.title` on every new back-office route.
- **Cut by guarantee axis, ≤ ~70 files per PR.** A story projected above ~100 files is a bad cut and is split, not accepted.

## Non-goals

- Deduplication, shared blobs, reference counting or GC (redesign-forcing per ADR D6).
- Variants, thumbnails, cropping or any editing.
- `Range` support, an S3 backend, virus scanning of images, `Documents` derivatives.
- Company, collaborator and product images.
- A reconciliation job for orphaned objects or lost deletion requests (declined by the no-bookkeeping decision; residuals stay in `PRODUCTION_SECURITY_CHECKLIST.md` §7).

## Success signal

A back-office user uploads a bank logo and sees it in the list, replaces it and the old bytes stop being retrievable, and an erased user's avatar is gone from storage, from every cache window the contract declares and from every readable audit payload — with every gate green and no consumer reading an image under a policy nobody wrote down.

## Assumptions

- Logo and bank-account image writes reuse the existing bank / bank-account update permission; a new permission is minted only if a story shows that one is wrong.
- The raw body carries the media type in `Content-Type`; the processor's allowlist, preflight and anti-polyglot defence are reused unchanged.
- No production deployment exists, so the new columns need no backfill or dual-read period.
- **Reversible before dispatch:** the no-generic-upload decision. If Sergio prefers mint-then-attach, CAP-1 and the three consumer stories change shape.

## Open Questions

- What does the bank-account image depict (card, cheque, logo, holder)? It decides whether the image denotes a natural person, hence its audit type and whether `EraseBankAccountSubject` must release it. Story ic-5 cannot start without it.
- Who may set an avatar: the user on their own profile, an administrator on behalf of another user, or both?
- May any authenticated identity read any avatar? If a session can exist outside the back-office (frontoffice or portal), bare authentication is a cross-user read of a face, and the alternative is a consumer-scoped read route with its own voter. Story ic-4 decides before code.
- Cache window for avatars: keep `private, max-age=3600` or move to `no-store` (ADR D3: a zero deletion SLA means `no-store`).
- Does the logo id travel in `BankSnapshot` and the read models, so the list shows it without a second request? That is an event-contract and projection-rebuild decision for ic-3.
