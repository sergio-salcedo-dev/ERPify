---
title: 'ic-3 Bank logo'
type: 'feature'
created: '2026-11-17'
status: 'done'
baseline_commit: '801caedb4160b4c9e9f2b6d57d8e65b5b38378b0'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/specs/spec-images-consumers/SPEC.md'
  - '{project-root}/_bmad-output/specs/spec-images-consumers/wire-contract.md'
  - '{project-root}/_bmad-output/specs/spec-images-consumers/consumer-matrix.md'
  - '{project-root}/docs/adr/image-read-route-contract.md'
  - '{project-root}/api/CLAUDE.md'
  - '{project-root}/pwa/CLAUDE.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** A bank has no logo: `Shared/Images` serves an image but no aggregate holds one and no route uploads one, so back-office users cannot see or manage a bank's logo, and the obligations the seam deferred (release on replace and on deletion, a read policy, a proof that the wiring holds) have no first consumer.

**Approach:** Wire `Bank` to `ImageAttachment` end to end: `Bank::$logoImageId`, owner-locked `PUT`/`DELETE /api/v1/backoffice/banks/{id}/logo`, `logoImageId` on the list and detail Resource DTOs and on the realtime row, release on replace, remove and bank deletion, and the logo on the bank list and detail with a control on the edit screen. This story owns the proofs the seam leaves to its first consumer: the manifest-driven wiring gate, the ORM holder compensation after a real manager reset, and a through-Caddy body over 1 MB.

## Boundaries & Constraints

**Always:**
- **Decision, no event.** A logo change records no domain event and `BankSnapshot` stays untouched. The change is already traced by the audit diff (`Bank` is an `AuditedEntity`: `logoImageId` and `updatedAt` land in the `BANK_UPDATED` row) and the release by `ImageDeletionRequested`. A logo change still bumps `updatedAt`, and a change to the id it already holds is a no-op that bumps nothing.
- **Every realtime row that is published carries the logo id.** `RefreshRealtimeOnBankChanged` resolves `logoImageId` at publish time for `bank.created` and `bank.updated` through a new read port `BankLogoReader::logoImageIdOf(bankId): ?string` (one lookup per event, not per page), whose Doctrine adapter is a scalar DQL select on the column so it always reaches the database: the handler runs in a long-lived worker that does not clear the entity manager, and a `findById` would answer from a stale identity map. It follows `BankAccountCountEnricher` in being the one collaborator that composes the value. So a rename never blanks a logo on an open detail page (that page replaces its state with the payload) and `BankListRowContractTest` (which compares the whole HTTP row with the realtime row, keys and values) stays true because the field is added to both sides, with `null` pinned for a bank without a logo. A logo change itself publishes nothing (see Design Notes). The read tolerates a bank already gone: a late `updated` after a delete resolves `null`, the `deleted` row follows, and a unit test fixes that outcome.
- The aggregate holds only the opaque id: `private ?string $logoImageId`, `Types::GUID` column `logo_image_id`, no `ManyToOne`, no foreign key. `BankRepository` gains `findByIdForUpdate`, new work in `DoctrineBankRepository` (it does not exist for Bank; copy the DQL + `PESSIMISTIC_WRITE` + `HINT_REFRESH` method of `DoctrineBankAccountRepository`, which exists). `BankNotFoundException` already implements `NotFound`, so the holder's missing-owner path is a 404.
- **Route names and paths** (the router prefixes `Backoffice/` with `/api/v1/backoffice`): `backoffice_bank_logo_set` is `PUT /api/v1/backoffice/banks/{id}/logo` and `backoffice_bank_logo_remove` is `DELETE` on the same path. Neither name begins `shared_`; `AuditPolicy` audits only a navigational `GET`, so a write falls through it, and the one trace of a write is the entity's own `BANK_UPDATED` diff (`logoImageId`, `updatedAt`), which carries no bytes and no filename.
- The route order is: authentication, `bank.write`, `Uuid::ensure`, owner existence, `ImageRequestThrottle::ensureWithinWriteBudget()`, then `ImageRequestBodyReader::read()`, then the write through `ImageAttachment`. The route carries `RawBodyRoute::ATTRIBUTE`. `DELETE` is idempotent: `204`, no event when none is held.
- **Release.** Replace and remove go through `ImageAttachment`. `BankDeleter::delete()` is reordered, not extended: today it loads the bank and counts accounts outside any lock, then removes in one transaction. It keeps the fast-fail count, then inside the transaction re-loads the bank under `findByIdForUpdate`, calls `delete()` and `pullDomainEvents()` on that locked instance, reads `logoImageId` under the lock, removes, calls a new public `ImageAttachment::release(ImageId)` and publishes, so a concurrent replace cannot slip a new id between the read and the removal. `release()` opens no transaction and only publishes: the `EventBus` publish guard refuses it outside one, and a unit test pins that (nested units of work are savepoints, so none is needed). It stays the only creation site of `ImageDeletionRequested`, so the publisher gate needs no edit; ic-4 and ic-5 reuse it.
- **Lock order.** The `bank` row is locked first in replace, remove and delete (`lockAndRead` and `findByIdForUpdate`). No bank unit of work locks the `image` row: a release only inserts an outbox message, and the consumer deletes the image in its own transaction. So there is no ABBA with the image row; `ImageAttachmentTest::noUnitOfWorkTakesBothTheOwnerRowAndAnImageRow` is the standing pin and the new race test below is its consumer proof. `DoctrineTransactionManager` has no retry loop (it translates a retryable failure to `TransientTransactionFailure` and resets a stranded manager), so `release()` is never replayed by a retry; a failure rolls the outbox row back with the removal.
- **Read policy, recorded in ADR D1 as `D1:logo`:** audience is the authenticated session through the shared `GET /images/{imageId}`; the read is unaudited (route prefix `shared_`, D2) because a logo is not personal data; the D3 cache window stands. `ImageConsumerAuthorizationGateTest` gets one entry per property (`Bank::$logoImageId`, `BankListResource`, `BankDetailResource`, and the column), each anchored `D1:logo`.
- The three review layers run on this story, and the artifact states where each ran.

**Never:** No new event, no `BankSnapshot` change, no `BankUpdated` reuse. No logo on `BankCreateResource` or `BankUpdateResource`. No generic upload route, no new permission (`bank.write` is the update permission), no URL in a DTO. No edit to `ImageDeletionRequestedPublisherGateTest`. No `maxLength`, no truncation, no `dangerouslySetInnerHTML`. No change to the lifted-bound or the 100 MiB Caddy bound.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Set | Bank holds none; valid image, `bank.write` | `200 {imageId}`; bank holds it; `updatedAt` bumped; no domain event; one `BANK_UPDATED` audit row | N/A |
| Replace | Bank holds one; valid image | `200`; new id held; exactly one release queued for the old id; old bytes gone once the queue drains | Failure after the mint: rollback, new id released in a fresh transaction (proved over the ORM holder after a manager reset) |
| Remove | Bank holds one | `204`; none held; release queued | N/A |
| Remove, none held | Nothing held | `204`, no row change, no release | N/A |
| Refused caller | No session / no `bank.write` | `401` / `403`, nothing stored, body not read | N/A |
| Bad owner | Malformed / absent bank id | `400 invalid-uuid` / `404 bank-not-found`, nothing stored | N/A |
| Bad body | No or unequal `Content-Length`; over cap; non-image; empty | `411` / `413` / `415` / `422`, no row, no object | Problem Details per `wire-contract.md` |
| Over 1 MB | Valid 2 MiB image through Caddy | Stored, `200` (the 1 MB edge bound is not applied) | A cut body is `411`, never stored |
| Throttled | Budget spent | `429` + `Retry-After` | N/A |
| Delete bank | Bank holds a logo, no accounts | Bank removed, one release queued, bytes gone after drain | A bank with accounts stays refused (`409`), nothing released |
| Delete races replace | A replace and a delete of one bank run concurrently | Whichever takes the bank lock first wins: replace first, the delete releases the new id; delete first, the replace is `404` and its compensation releases the new id. Exactly one release per id, never a held id destroyed. Proved on the persisted owner row and the queued `ImageDeletionRequested` ids in each order, never on a realtime row | N/A |
| Late realtime update | `bank.updated` handled after the bank was deleted | Row carries `logoImageId: null`; the `deleted` row follows | N/A |
| Rename while logo held | Another client renames | Realtime row carries the current `logoImageId`; open detail keeps the logo | N/A |
| Image fails to load | Released or unreachable id | Placeholder, no broken glyph (ic-2) | N/A |

</frozen-after-approval>

## Code Map

- `api/src/Backoffice/Bank/Domain/Entity/Bank.php` -- add the column and a mutator (no-op when equal, bumps `updatedAt`, records nothing); `rename()` is the model for the early return. No clone path exists; a unit test pins that none copies the id.
- `api/src/Backoffice/Bank/Domain/Repository/BankRepository.php`, `.../Doctrine/DoctrineBankRepository.php` -- `findByIdForUpdate`, copy `DoctrineBankAccountRepository.php:57-83` (`PESSIMISTIC_WRITE`, `HINT_REFRESH`); `tests/Unit/Backoffice/Bank/Application/InMemoryBankRepository.php` follows.
- `api/src/Shared/Images/Application/ImageHolder.php`, `ImageAttachment.php` (`replace`, `remove`, private `requestRelease`) -- implement the holder for Bank in `Backoffice/Bank/Application`; add public `release()`; reuse, do not fork. Doubles to copy: `tests/Unit/Shared/Images/Application/Double/InMemoryImageHolder.php`, `tests/Functional/Shared/Images/Fixtures/{ProbeHolder,FailingAfterWorkTransactionManager,ImageReleaseRig}.php`.
- `api/src/Backoffice/Bank/Application/BankDeleter.php:33-73` -- `delete()` loads and counts outside any lock (:35-37), builds `$bank->delete()` and pulls events (:43-46), then removes and publishes in one `transactional()` (:51-54) with a `ReferentialIntegrityViolation` catch (:55-72). Move the load, `delete()`, event pull and the logo read inside the transaction under `findByIdForUpdate`; keep the count and the FK catch. `BankDeleterTest` and the in-memory repository follow.
- `api/src/Backoffice/Bank/Infrastructure/Controller/BankPutController.php` (pattern), new logo controllers, `Shared/Http/Infrastructure/Upload/RawBodyRoute.php`, `Shared/Images/Infrastructure/Http/{ImageRequestBodyReader,ImageRequestThrottle}.php` -- the order above; the spool listener and session release are already the seam's.
- `api/src/Backoffice/Bank/Application/Resource/{BankListResource,BankDetailResource}.php`, `Infrastructure/Http/BankResourceMapper.php`, `Infrastructure/Messenger/RefreshRealtimeOnBankChanged.php` (today it holds only the `HubInterface` and the count enricher, no bank read; add the `BankLogoReader` port beside the other `Domain/Repository` ports and a Doctrine scalar-DQL adapter), `tests/Unit/Backoffice/Bank/Infrastructure/BankListRowContractTest.php` -- DTO field and publish-time enrichment; golden `BankDetailResponseGoldenFunctionalTest.php`.
- `api/frankenphp/Caddyfile:183-217` -- `@imageWrite` already admits `banks/<uuid>/logo`; no edit unless the manifest path differs. `tests/Unit/Gate/UploadRoutePathAgreementGateTest.php:225-251` is the one-direction forward guard to extend into the two-direction gate.
- Registries: `api/.route-manifest.json` (`make sf.routes.manifest`), `api/.credential-proof-policy` (two `ordinary` lines), `api/.person-reference-policy` (`Bank::$logoImageId => non-person`), `tests/Unit/Shared/Images/ImageConsumerAuthorizationGateTest.php` `REGISTRY`, `docs/adr/image-read-route-contract.md` D1, `PRODUCTION_SECURITY_CHECKLIST.md` §7 items five, six and twelve(c) plus its closing line.
- Through-Caddy precedent: `tests/Functional/Shared/Http/RequestBodyBoundGateTest.php`, `tests/Functional/Shared/Monitoring/DevStackUploadRows.php`; Behat has no binary raw-body step, search `tests/Behat/Context/ImageFixtureContext.php` and `HttpRequestContext.php` before writing one, and classify it in `api/.behat-step-vocabulary`.
- `pwa/src/context/backoffice/bank/domain/Bank.ts`, `infrastructure/{ApiBankRepository,bankRealtime}.ts` -- `logoImageId` (optional in primitives), guards, and `toBankPrimitives`, which drops unknown fields.
- `pwa/src/context/shared/http-client/infrastructure/ApiEndpoints.ts:69-78`, `pwa/tests/api-endpoint-contract.test.ts:206` -- two keys (`LOGO_SET`, `LOGO_REMOVE`), one verb each, and the route manifest must hold the route first.
- `pwa/src/context/shared/access/domain/Permission.ts` -- add `BANK_WRITE: "bank.write"`; `ALL_PERMISSIONS` is derived from the object, and `ApiIdentityRepository` drops anything outside it, so the one line makes `/me` carry it. Gate with `Can` as `users/_components/UserRolesControl.tsx` does, with a test for granted and absent.
- `pwa/src/app/backoffice/banks/_components/{BanksTable,BanksCards,BanksStackedList}.tsx`, `banks/[id]/page.tsx:267-310`, `banks/[id]/edit/page.tsx` + `_components/BankForm.tsx` -- `ImageDisplay` with `alt=""` (the name is beside it), `ImageUploadField` on the edit screen, ids keyed by `row.id`.
- `pwa/tests/e2e/fixtures/banks-api.ts`, `pwa/tests/e2e/backoffice/banks-real-api*.spec.ts` -- extend; a real-API e2e now has the route.

## Tasks & Acceptance

**Execution:**
- [x] `api/src/Backoffice/Bank/**`, `api/migrations/2026/` -- column, mutator, `findByIdForUpdate`, holder, set/remove use cases, controllers, DTOs, mapper, realtime enrichment, deleter release; migration via `make db.diff`
- [x] `api/src/Shared/Images/Application/ImageAttachment.php` -- public `release()`; unit test and publisher-gate registration unchanged
- [x] `api/tests/**` -- unit (mutator, no-op, clone tripwire, holder double, deleter including the bank vanishing between the first find and the locked reload (404), the FK violation after rollback and its recount, and no event or release left behind by a rolled-back delete; `release()` refused outside a transaction, mapper, row contract on both sides, `BankLogoReader` against a vanished bank and a stale identity map), functional (ORM holder compensation after `EntityManager` reset; two concurrent replaces leave one live image and one release per replaced id; delete against replace in both orders; a write leaves exactly one `BANK_UPDATED` row whose diff holds no bytes or filename, with the serialized diff asserted for null-to-id, id-to-id and id-to-null (what `AuditChangeDiff` produces, not an assumed shape), kept apart from the domain-event assertions; deletion release), Behat feature (set, replace, remove, refusals, access control, deletion) with the binary step
- [x] manifest-driven gate -- fails a `PUT` route the matcher admits without `RawBodyRoute::ATTRIBUTE`, and a route carrying the attribute or reaching `ImageAttachment` that no `@imageWrite` alternative matches; a truncated image is the second case
- [x] through-Caddy test -- a 2 MiB image `PUT` is stored; a cut body is `411` and stores nothing
- [x] migration audit -- `make db.diff` yields only `ADD logo_image_id UUID DEFAULT NULL` (mapping nullable `Types::GUID`, no default); `BankCountSchemaListener` only adds the `bank_count` table and the raw `INSERT INTO bank (…)` in `features/backoffice/bank/delete.feature` and `TransactionManagerReferentialFailureTest.php` name their columns, so a nullable column breaks neither (confirm, do not assume)
- [x] registries and docs -- manifest, credential-proof, person-reference, authorization-gate registry (only what the gate derives: promoted properties and the column; the DTO fields are properties, the mapper locals and the realtime array key are not, and are pinned by the row-contract and DTO tests), ADR `D1:logo`, §7 rows, `docs/architecture-api.md`, `docs/architecture/event-catalog.md` (one line: a logo change records no event), `docs/architecture-pwa.md`, `docs/source-tree-analysis.md`
- [x] `pwa/**` -- model, guards, realtime mapper (a realtime test proves `logoImageId` survives parsing, and that an absent key becomes null), endpoint keys, `BANK_WRITE`, list/cards/stacked/detail display, edit-screen control behind `Can` (state owner: the edit page holds `logoImageId`, seeded from the loaded bank and set from the write response given to `onImageChange`, with no refetch, so a stale read cannot restore the old id; the control sits outside `BankForm`, whose key is the unchanged bank id, so a name typed and not yet saved survives a logo change, and a test proves it), Vitest, Playwright set/replace/remove and both screens

**Acceptance Criteria:**
- Given a bank whose logo id first reads `200` through `GET /images/{imageId}`, when it is replaced or the bank deleted and the queue drains, then that id reads `404`, exactly one `ImageDeletionRequested` was queued for it, and the bank holds the new id or no row.
- Given the PWA suite, then `ui-copy-language`, the test-id uniqueness and naming gates (ids keyed by `row.id`) and `api-endpoint-contract` stay green.
- Given a rename by another client, when the detail page receives the realtime payload, then the logo stays.
- Given the manifest, when a logo-shaped `PUT` is matched without the attribute or carries it without a matcher alternative, then the gate reds.
- Given a user with `bank.write`, when they set, replace and remove through the edit screen, then the list and the detail, loaded afterwards in that client, show each state (other open clients learn of a logo-only change on their next load, not by push); a reader without it sees no control.
- `make php.quality`, `make php.unit`, `make php.behat` scoped to the bank and image features, and `make pwa.quality` plus `make pwa.test` exit 0.

## Implementation Notes

- **Single-write path.** `BankLogoPutController`/`BankLogoDeleteController` look the bank up (`BankFinder::find`, id syntax then 404), spend `ImageRequestThrottle::ensureWithinWriteBudget()`, read the body (PUT only) and hand the id to `BankLogoSetter`/`BankLogoRemover`, which run `ImageAttachment` over `BankLogoHolder`. The holder reads under `BankFinder::findForUpdate` (`BankRepository::findByIdForUpdate`: DQL, `PESSIMISTIC_WRITE`, `HINT_REFRESH`; the hint is load-bearing, falsified).
- **`BankDeleter`** keeps the unlocked fast-fail load and count, then does reload, `delete()`, event pull, logo read, `remove`, `ImageAttachment::release()` and `publish` inside one transaction. Its Behat query budget went 9 to 10 (the `FOR UPDATE` reload).
- **Realtime.** `RefreshRealtimeOnBankChanged` takes the new `BankLogoReader` port (`DoctrineBankLogoReader`, scalar DQL) and adds `logoImageId` to the row for created and updated; a logo change publishes nothing.
- **Gate placement.** The manifest-driven gate is `ImageWriteRouteAgreementGateTest` over the new `tests/Support/ImageRouteAgreement` (the existing `UploadRoutePathAgreementGateTest` was already at its PHPMD ceilings). `BankLogoControllerOrderTest` pins the call order of both controllers.
- **Wire shape.** `PUT` answers the bare `{"imageId"}` of `wire-contract.md` (a `JsonResponse`, no `data` envelope: the ic-2 `ApiImageUploader` guards that shape), while the bank list and detail keep the `data` envelope with `logoImageId`.
- **429 is not a Behat scenario.** The per-identity write budget resets per request in the Behat lane, so `BankLogoThrottledFunctionalTest` spends the real budget on the real routes and asserts the refusal, including that a wrong or missing `Content-Length` answers 429 and never 411 (the reader was not reached).
- **Review layers.** Four read-only layers ran on this worktree, in one session, before the pull request existed: Blind Hunter, Edge Case Hunter, the verification-gap layer and an Acceptance Auditor (the third layer `CLAUDE.md` names). They are listed below; no pull request existed yet, so there were no review threads to read. The spec itself went through two external read-only review rounds before implementation.

## Spec Change Log

## Review Triage Log

Four read-only layers ran on the worktree diff against `baseline_commit`, in parallel. No finding was `intent_gap` or `bad_spec`, so no loopback; every `patch` was applied by the implementation subagent and the full verification re-run on the orchestrator's side.

| Finding | Verdict | Evidence / route |
|---|---|---|
| `sendRawBytes` leaves its auto-added `Content-Length` in `$this->headers` for the rest of the scenario (Blind, Edge) | medium | `HttpRequestContext` never resets `$headers`; a second PUT of a different size skips declaring and gets a spurious 411. Patch + a two-sizes scenario that fails without the fix |
| Throttled test reads `Fixtures/Images/valid.png` from a path that does not exist, so it sends an empty body (Edge); nothing observes throttle-before-read, only a `strpos` over the source (Verification gap) | medium | `dirname(__DIR__, 2)` lands in `tests/Functional/`, the file is `tests/Fixtures/Images/valid.png`. Patch: right path, non-empty assertion, and a spent-budget PUT with a missing or wrong `Content-Length` that must be 429, never 411; falsified by swapping `read()` first |
| Through-Caddy cut-body case asserts `COUNT(*) FROM image` equal on the shared dev stack while the worker drains a release (Acceptance, Edge); `imageCount()` returns -1 on a non-numeric result (Blind) | medium | Count can move under the assertion. Patch: compare sets of image ids, throw on a non-string id, and name the bank probe's rows in the `DevStackUploadRows` docblock |
| Replace-first order of delete-vs-replace uses a raw `UPDATE`, so the replace's own release is never produced (Acceptance) | medium | Test asserted only the delete half. Patch: a real `ImageAttachment::replace` in the window, old id released once, new id once, total 2; the old test is renamed to its real claim |
| `ImageRouteAgreement` substitutes only a literal `{id}` (Edge) | low | A `{bankId}` route keeps its braces and is never matched against the Caddy pattern. Direct fix (`preg_replace`) + a unit case |
| `BankLogoTest` clone tripwire passes on an empty method list (Edge) | low | One assertion before the loop |
| ADR intro, D1 lead-in and checklist items five, eight and the closing line still say no consumer exists or that the through-Caddy proof is still to come; the checklist registration sentence sits inside the bot-fix passage; `D1:logo` cites a `BankLogo` type that does not exist (Blind, Acceptance) | medium | Stale or misplaced statements about the story's own surface. Patch: reworded to "the first consumer is `D1:logo`", sentence moved, closing line points at `BankLogoThroughCaddyTest`, paragraph shortened |
| Comments that tell rollout history or name one consumer in generic files: `ApiBankRepository.ts`, `Permission.ts` (Blind, Acceptance) | low | `CLAUDE.md` bans change-relative comments. Direct rewrite |
| New lines out of alphabetical order in `.artifact-gate-placement` and `.behat-step-vocabulary` (Blind, Acceptance) | low | Direct move; the two lints re-run |
| Postman collection lacks the two logo requests; source-tree page lacks the PWA control (Blind, Acceptance) | low | `CLAUDE.md` "Keeping docs": new endpoints go to `api/docs/`. Patch |
| The detail page's `onUpdated: setBank(incoming)` hop is untested although an acceptance criterion names it (Verification gap) | medium | Proven only in pieces. Patch: `bankDetailRealtimeLogo.test.tsx` |
| `BankDeleterRollbackTest` would pass if the release had never been issued (Verification gap, note) | false | Its positive twin `BankDeleterLogoReleaseTest` asserts the release inside the unit of work; the note states what each proves and claims no gap |
| `Bank::changeLogo()` accepts any string and compares by spelling (Blind) | low | `ImageId` lower-cases on construction and the only caller passes `ImageId::toString()`, so an upper-case spelling cannot reach it today; a guard in the aggregate adds a branch for a caller that does not exist. Rejected |
| `ImageAttachment::release()` is public with no caller gate (Blind, Verification gap) | low | The spec decided it (single creation site kept, publish guard enforces the transaction); no caller misuses it and a caller-registry gate is a design of its own. Rejected; reopen when the second consumer lands |
| `BankLogoHolder::attach()` relies on `lockAndRead()` having run first (Blind) | false | `ImageAttachment` calls `lockAndRead` before every `attach`, the compensation path calls only `lockAndRead`, and `ImageHolder` documents the pairing |
| No test submits the bank form after a logo change; `updatedAt` could drive optimistic concurrency (Blind) | false | `BankUpdater` and `UpdateBankCommand` read no `updatedAt`, version or ETag; a save after a logo change cannot conflict |
| `logoImageId` `""` is normalised differently in three PWA places (Blind) | low | The API sends null or a UUID, never `""`; one shared normaliser is more than a direct correction for a value that cannot arrive. Rejected |
| `down()` of the migration drops the only reference to stored logos (Blind) | low | Pre-production, and orphaned bytes are already accepted in `PRODUCTION_SECURITY_CHECKLIST.md` §7 item three. Rejected |
| `logo.feature` Examples row misaligned (Blind) | false | `php.gherkin` is clean on the final tree |
| Logo lookup throwing in the realtime handler aborts the publish (Edge) | false | The existing `accountCount` read has the same failure mode, and swallowing it would publish a null logo that blanks an open detail page: worse than a retried message |
| Route and controller detection in `ImageRouteAgreement` is textual (`#[Route(`) and follows only class-typed constructor arguments (Edge); `BankLogoControllerOrderTest` reads the order with `strpos` over text including comments (Edge) | low | Declared blind spots of a text gate; a hostile comment or an interface-typed collaborator is not a case the tree has, and the fixes (reflection, tokenising) are larger than a direct correction. Rejected |
| Test docblocks restate "what a green does not prove" a second time (Acceptance) | low | Gate headers do the same across the tree; the residual itself is in §7. Rejected |
| `CLAUDE.md` and `docs/rules/testing.md` should name the rows the bank probe leaves in the dev database (Acceptance) | defer (unverified-severity: n/a) | The fix edits agent-context files. The code-site half is done in the `DevStackUploadRows` docblock; the rest waits for the user's decision |

## Design Notes

**No event (decision, 2026-11-17, Sergio, after an external consult).** Reusing `BankUpdated` needs a version bump and an `Upcaster`, never used here, and mails the notification address on every logo change; a new event has no consumer but a realtime push (event-catalog rule: a field earns its place by an existing consumer). The trace already exists twice. Discarded: a separate `BankLogoChanged` preserving null. Cost accepted: a logo-only change pushes nothing, so other open clients show the old logo until reload, and a released id falls to the ic-2 placeholder; it is recorded as a residual in `PRODUCTION_SECURITY_CHECKLIST.md` §7 item six, with the new event as the reopening path (no production history is lost by adding it later).

**Control on the edit screen.** `ImageUploadField` acts immediately and owns its preview, so it sits beside the form rather than inside its submit; the detail header only displays. Discarded: an inline control on the detail header, which would duplicate the display. Surfaced, not done here: the Edit link and delete button on the detail page are not permission-gated today (`bank.write`/`bank.delete` exist only server-side).

**Blind spot kept, not closed.** `api-endpoint-contract.test.ts` checks that `LOGO_SET` and `LOGO_REMOVE` are mounted paths and have a `METHOD_BY_KEY` row, but it scans verbs only at `httpClient.<verb>(API_ENDPOINTS…)` call sites; the URL handed to `uploader.upload`/`remove` is not one, so the verb is held by the Playwright and Behat proofs, not by that gate.

**Size.** Projected at about 35 API and 25 PWA files, under the ~70 cut; the API/PWA split is not taken because the screens depend on the route, the permission and the realtime row. Above ~100 files it is split at that seam.

**Release on deletion** adds a public `ImageAttachment::release()` instead of `BankDeleter` publishing the event, because the publisher gate registers exactly one creation site and two consumers follow.

## Verification

**Commands:**
- `make php.stan` on every changed PHP file, then `make php.quality` (includes `php.deptrac` and `php.lint.bounded-context`: the holder lives in `Backoffice/Bank/Application` and depends inward on `Shared\Images\Application`, which is always importable) -- expected: exit 0
- `make php.unit c='--filter Bank'` and the Images filter, `make php.behat` for the bank and image features, `make php.lint.route-manifest`, `make php.lint.credential-proof`, `make php.lint.person-reference` (the column is `Types::GUID`, so the new `non-person` line is mandatory), `make php.lint.error-contract` -- expected: exit 0
- `make pwa.quality`, `make pwa.test` -- expected: exit 0

**Manual checks:**
- Through Caddy on the dev stack: a 2 MiB PNG `PUT` to the real logo path returns 200; browser sends `Content-Length` for the `Blob` (the ic-2 open point).
