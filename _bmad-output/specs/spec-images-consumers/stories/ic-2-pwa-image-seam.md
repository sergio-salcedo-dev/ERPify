---
title: 'ic-2 PWA image seam (upload field and display)'
type: 'feature'
created: '2026-11-17'
status: 'done'
baseline_commit: '91733c4ff199e19e0dad8bf9c42cee421014a74d'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/specs/spec-images-consumers/SPEC.md'
  - '{project-root}/_bmad-output/specs/spec-images-consumers/wire-contract.md'
  - '{project-root}/pwa/CLAUDE.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The PWA has no way to send an image or to show one: `FetchHttpClient` always `JSON.stringify`s the body and forces `Content-Type: application/json`, there is no `<img>` anywhere in `src`, and no screen maps the image Problem Details types, so ic-3..ic-5 would each reinvent upload, placeholder and error copy.

**Approach:** Build the consumer-independent half in `pwa/` against `wire-contract.md`, with no API code: a shared `images` capability (port, adapter, pre-upload check, Problem Details to English copy), one display component and one upload control in `components/erpify`, and a Playwright test of set, replace and remove against a stubbed route.

## Boundaries & Constraints

**Always:** Reuse the `HttpClient` transport (timeout, 401 bounce, Problem Details parsing, network and timeout minting) by adding one raw-body method to the port and `FetchHttpClient`, never a second fetch path. The consumer passes its own write URL: ic-2 adds no write key to `API_ENDPOINTS`, because `api-endpoint-contract.test.ts` reds on a key the route manifest lacks. The read URL is composed from the shared route key plus `encodeURIComponent(imageId)` and goes through `safeHref`. The display shows the placeholder on any load failure and when no id is held. The `accept` allowlist is `image/jpeg`, `image/png`, `image/webp`, `image/gif`; the size precheck refuses an empty file or one over the API cap and never truncates, resizes or re-encodes; the server stays the authority. Copy is English; each type of the contract's table maps to its own message, plus `401`, `403`, `404`, `429` (with the `Retry-After` seconds when present), `network-error`, `request-timeout` and an unknown fallback. A failed write shows a persistent error, not a toast alone, and names the file kept. Reusable primitives take `testId`/`testIdPrefix`; no `maxLength`; no `dangerouslySetInnerHTML`.

**Never:** No API, migration or Caddy change. No consumer screen, no logo, avatar or account wiring (ic-3..ic-5). No client-side image editing or cropping. No generic upload route. No fetch-to-blob read of the image. No new library.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Set | Owner holds no image; valid file | `PUT` raw bytes, `200 {imageId}`; display shows the image | N/A |
| Replace | Owner holds an image; valid file | Same call; display re-keys to the new id | N/A |
| Remove | Owner holds an image | `DELETE`, `204`; placeholder shown | N/A |
| Remove, none held | No image | Control offers no remove | N/A |
| Precheck refuses | Empty file, type outside the allowlist, or over the cap | No request sent; message names the reason | Inline error, field stays usable |
| Server refuses | `411`, `413`, `415`, `422` | Message for that `type` | Persistent error, retry possible |
| Throttled | `429` with `Retry-After` | Message states the wait | Persistent error |
| Image fails to load | `404`, offline, corrupt bytes | Placeholder, no broken-image glyph | `onError` handled |
| Double submit | Second pick while one is in flight | Ignored; control shows busy | N/A |

</frozen-after-approval>

## Code Map

- `pwa/src/context/shared/http-client/domain/HttpClient.ts` -- port; add one `put`-shaped method taking a `Blob`; `MockHttpClient` and every implementer follow.
- `pwa/src/context/shared/http-client/infrastructure/FetchHttpClient.ts:343-373` -- `sendWithBody` forces JSON; the new method calls `request()` (:219-281) with the `Blob` as body and no `Content-Type` (fetch takes `blob.type` and sets `Content-Length`), then `toHttpError`/`parseBody`. Do not change the JSON paths.
- `pwa/src/context/shared/http-client/infrastructure/ApiEndpoints.ts`, `pwa/tests/api-endpoint-contract.test.ts:206` -- registry and `METHOD_BY_KEY`; only the read key `shared_image_get` (`GET /api/v1/images/{imageId}`) exists in the manifest.
- `pwa/src/context/shared/access/infrastructure/ApiRecoverySecretRepository.ts`, `.../domain/RecoverySecretRepository.ts` -- port, adapter and `*_KEY` precedent; bind in `dependency-injection/infrastructure/Container.ts:323` (`MockHttpClient` under test at :98).
- `pwa/src/context/shared/error/domain/ProblemDetails.ts`, `SharedProblemType.ts`, `backoffice/bank/domain/BankProblemType.ts` -- type constants precedent; image types go in a new `ImageProblemType`, not in `HttpClient.ts` (`client-minted-problem-types.test.ts` owns those).
- `pwa/src/context/shared/navigation/domain/safeHref.ts` -- `safeHref(href, fallback)`; it does not prefix the API base, which `FetchHttpClient.ts:159` keeps private.
- `pwa/src/components/erpify/{MutationError,ProblemDisplay,Spinner,FormField,MonogramAvatar}.tsx`, `index.ts`; `components/ui/{button,input,label}.tsx` -- reuse; erpify may reach `context/shared` and never `@/app` or a bounded context (`.dependency-cruiser.cjs`, `eslint.config.mjs:202-222`). No erpify component calls the container, so the control receives async callbacks.
- `pwa/src/app/backoffice/dev-tools/` (`page.tsx`, `error-gallery/`), `devToolGroups.ts`, `src/proxy.ts`, `tests/proxy.test.ts` -- harness host for the e2e; gated by `isDevToolsAvailable`.
- `pwa/tests/e2e/fixtures/{authenticatedTest,banks-api}.ts` -- `page.route` stubbing, `fulfillProblem`; `tests/e2e/global-setup.ts` needs the live API stack.
- `pwa/next.config.ts:58` -- `img-src 'self' data: blob: https:` already admits a same-origin `<img>`.
- `api/config/` (`erpify.images.max_input_bytes`, 20 MiB) -- the cap the precheck mirrors.

## Tasks & Acceptance

**Execution:**
- [x] `pwa/src/context/shared/http-client/{domain/HttpClient.ts,infrastructure/FetchHttpClient.ts}` and the mock -- add the raw-body method on the shared `request()` -- one transport, one timeout and 401 policy
- [x] `pwa/src/context/shared/images/domain/` -- `ImageUploader` port and key, `ImageProblemType`, allowlist and cap constants, `checkImageFile()`, `imageProblemMessage()` -- pure and framework-free
- [x] `pwa/src/context/shared/images/infrastructure/ApiImageUploader.ts` and `Container.ts` -- adapter over `HttpClient` (`put` bytes, `delete`), response guard for `{imageId}` -- bound as a singleton
- [x] `pwa/src/context/shared/images/` read URL helper plus the `API_ENDPOINTS` read key and `METHOD_BY_KEY` line -- compose through `safeHref` and `encodeURIComponent`
- [x] `pwa/src/components/erpify/{ImageDisplay,ImageUploadField}.tsx`, `index.ts` -- placeholder on any failure, accessible picker, busy and error states, `testIdPrefix`
- [x] `pwa/src/app/backoffice/dev-tools/image-upload/` plus group and proxy registration -- harness wiring the port to a stub URL, distinct `metadata.title`
- [x] `pwa/tests/` -- Vitest for the transport (raw body, no JSON header, 4xx mapping, timeout), adapter, precheck, message table, both components; `tests/e2e/backoffice/image-upload.spec.ts` for set, replace, remove and a refusal
- [x] `pwa/docs/`, `docs/architecture-pwa.md`, `docs/source-tree-analysis.md` -- the new capability and components; `pwa/CLAUDE.md` if a rule is new

**Acceptance Criteria:**
- Given a `File`, when the transport sends it, then the request body is the file's bytes, `Content-Type` is not JSON and a 4xx surfaces as an `HttpError` carrying the Problem Details.
- Given any image load failure, when the display renders, then the placeholder shows and no broken-image glyph is visible.
- Given each type of the wire-contract table, when the control receives it, then it shows that type's own English message and `ui-copy-language` stays green.
- Given the Playwright harness, when a user sets, replaces and removes through the stub, then the display and control reflect each state.
- `make pwa.quality` and `make pwa.test` exit 0.

## Implementation Notes

- The raw-body method is `HttpClient.putRaw(url, blob, guard?, options?)`. Adding it to the port forced `putRaw: vi.fn()` into the 25 existing test files that build a full `HttpClient` literal, and into `MockHttpClient`. Mechanical, no behaviour change.
- The wait of a throttled write is read from the 429 **body** (`retryAfterSeconds`, the extension `RateLimitExceeded` already carries), not from the `Retry-After` header. A first version copied the header into a client-added problem member in the shared `toHttpError`; review showed the body already carried it, and that change was reverted.
- `HttpStatus` gained 411, 413 and 415 so a bodyless edge or proxy rejection maps to the image messages by status.
- The 120 s upload budget in `ApiImageUploader` is a deliberate choice: the 30 s default is sized for a JSON round trip, and 20 MiB on a slow uplink would be reported as an outage.
- `src/proxy.ts` and `tests/proxy.test.ts` needed no edit: the existing `/backoffice/dev-tools/:path*` matcher already short-circuits the new route. `pwa/docs/` has nothing on this capability.
- Not proven: that the browser sends `Content-Length` for a `Blob` body. Playwright cannot see it on a request fulfilled by `page.route`, so the unit test pins the body's size and bytes and the e2e pins its length. The through-Caddy proof belongs to the first consumer story (ic-3), which owns the real route.
- Review: the four layers listed in the Review Triage Log ran on this worktree, in one session, before the PR existed. The three review layers `CLAUDE.md` names are covered (Blind Hunter, Edge Case Hunter, and the Acceptance Auditor plus the verification-gap layer for the third).

## Spec Change Log

## Review Triage Log

Four read-only layers ran on the worktree diff against `baseline_commit`, in parallel: Blind Hunter, Edge Case Hunter, the verification-gap layer and an Acceptance Auditor (the layer the repo's `CLAUDE.md` names). No pull request existed yet, so there were no review threads to read.

| Finding | Verdict | Evidence / route |
|---|---|---|
| `retry-after-seconds` member added to every 429 in the shared `toHttpError`; doc says "no 429 body carries the wait" (Blind, Edge, Auditor) | high | False premise: `RateLimitExceeded` puts `retryAfterSeconds` in the body (`RateLimitListenerFunctionalTest.php:165`) and `ImageRequestThrottle` throws it. Patch: read the body member, revert the transport and the `api-error-contract.md` paragraph |
| Loaded-before-hydration `<img>` never marked loaded (Blind) | medium | Mount effect handles only `naturalWidth === 0`. Patch + test |
| Placeholder named "(not available)" while loading (Blind, Edge) | medium | Announced beside the real `alt`. Patch + test |
| `describeFailure()` rebuilt each render re-focuses `MutationError` (Edge, Auditor) | medium | `MutationError.tsx:49-52` effect depends on `problem`. Patch (memoise) + test |
| Timeout/network detail says "unchanged" under a "may not have been saved" title (Edge) | medium | Contradictory copy. Patch + test |
| Removal failure titled "could not be saved" (Blind, Edge, Auditor) | medium | One fallback for two actions. Patch + test |
| 411/413/415/422 without a Problem Details body fall to the generic fallback (Edge, verification-gap) | medium | Edge matcher and proxies answer without one. Patch: route by status + test |
| Own-property lookup: `type: "constructor"` returns a function (Edge) | low | Real but needs a hostile server; one-line fix, patched with a test |
| 415 copy wrong for a declared-type mismatch (Blind, Auditor) | low | Patch (copy) |
| Action buttons lack `title` / static `aria-label` (Auditor) | low | `pwa/CLAUDE.md` rule. Patch |
| PWA mirrors of the four types and the 20 MiB cap have no parity check (verification-gap, Blind) | medium | Proposed `defer`; rejected as a disposition: the repo's rule is to fix in the PR and the fix is one test. Patch: `image-contract-parity.test.ts` |
| Untested: body bytes, 401 bounce through `putRaw`, `content-length`, pick button, container binding (Blind, Auditor) | low | Patch (tests) |
| `checkImageFile` comment overclaims "certain to refuse" (Blind) | low | Patch (comment only) |
| `<img>` comment "new element, clean slate" false; no `loading="lazy"` (Edge, Blind) | low | Patch |
| Transient load failure is remembered per id with no retry (Blind, verification-gap) | low | The spec asks for a placeholder on any failure; the doc comment states the per-id memory. Rejected: a retry policy is a guess without a measured case |
| Stale `loadedSrc` after id → null → same id (Edge) | low | Ids are minted fresh per write, so the same id never returns. Rejected |
| `onImageChange` throwing after a success (Edge) | false | A consumer callback that throws is the consumer's defect; guarding it would swallow it |
| `ApiImageUploader` skips the precheck (Edge) | false | The port documents the server as the authority; the precheck is the control's convenience |
| Fixed 120 s upload budget (Blind, Edge) | low | A size-scaled budget needs a tuning constant nobody has measured; a timeout keeps the file and offers a retry. Rejected |
| `label.toLowerCase()`, hint not `aria-describedby`, 9-digit wait, `'.'`/`'..'` ids, `apiBase()` duplicate (Blind, Edge) | low | Unlikely in use; each fix adds a branch, a parameter or coupling. Rejected |
| Empty/odd `file.type` refused by the precheck (Blind) | low | Frozen intent says a type outside the allowlist is refused. Recorded; the server stays the authority for what passes |
| `ui-copy-language` unverified; proxy registration absent; `pwa/docs/` untouched (Blind, Edge, Auditor) | false | `ui-copy-language` is inside the 2483 passing unit tests; `proxy.ts` already matches `/backoffice/dev-tools/:path*`; nothing in `pwa/docs/` concerns this |

## Design Notes

The raw-body method is on the port rather than a separate adapter because `request()` carries the timeout, the 401 bounce and the transport-error minting; a second fetch path would duplicate all three and drift. The cost is one more method on a port with a mock and one implementer. The control takes callbacks instead of resolving the port itself so `components/erpify` stays out of the container, as every sibling is.

## Verification

**Commands:**
- `make pwa.quality` -- expected: exit 0 (ESLint, dependency-cruiser, Prettier, tsc)
- `make pwa.test.unit` -- expected: exit 0
- `make pwa.test.e2e` (this spec) -- expected: exit 0 against the live stack
