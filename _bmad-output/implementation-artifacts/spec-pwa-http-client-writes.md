---
title: 'PWA shared HttpClient write methods + ProblemDetails error translation'
type: 'feature'
created: '2026-05-08'
status: 'done'
baseline_commit: 'e31f37260d05c866d2d10fbc81c0f360a97a1b5b'
context:
  - pwa/CLAUDE.md
  - pwa/AGENTS.md
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The shared `HttpClient` only exposes `get<T>(url)`. Upcoming write-heavy PWA features (Backoffice Bank CRUD first, others next) need `post`/`put`/`delete`. In addition, the API today returns two error envelope shapes: the canonical RFC 9457 `ProblemDetails` (already supported by `pwa/src/context/shared/domain/ProblemDetails.ts` and the `erpify` UI primitives) and a legacy `JsonApiErrorBuilder` shape (`{ errors:[{ source:{ parameter }, title }], meta:{ requestId } }`) returned from explicit controller `try/catch` blocks (e.g. Bank). The PWA has no path to surface legacy errors through its existing UI without a translator.

**Approach:** Extend the shared `HttpClient` interface and both adapters (`FetchHttpClient`, `MockHttpClient`) with `post`/`put`/`delete`. Add a typed `HttpError` carrying a `ProblemDetails`. Add a pure translator function `toProblemDetails(body, status, fallback)` that passes ProblemDetails through unchanged (per `isProblemDetails`) and otherwise maps legacy `errors[].source.parameter|title` → `violations[].field|message`, `meta.requestId` → `correlation-id`, mints a UUIDv4 for `instance` if absent. The Fetch adapter throws `HttpError(toProblemDetails(...))` on every non-2xx response. No consumer change in this spec — Bank CRUD ships in a follow-up against this surface.

## Boundaries & Constraints

**Always:**
- Translator is a pure function with no I/O and no framework imports.
- `HttpError` extends `Error`, sets `name`, and exposes a readonly `problem: ProblemDetails`. No `cause` chain manipulation beyond what `Error` provides natively.
- `FetchHttpClient` keeps its existing `cache: 'no-store'`, `Accept: application/json` defaults; write methods add `Content-Type: application/json` and serialize the body with `JSON.stringify`.
- Legacy envelope detection is shape-based (`Array.isArray(body.errors)` plus `meta?.requestId` when present). When the body is unparsable JSON or unrecognised, fall back to a synthetic ProblemDetails using `status`, `fallback.type`, `fallback.title`, current ISO timestamp.
- All ProblemDetails returned by the translator pass `isProblemDetails(value)`.
- DDD layering: the new files live under `pwa/src/context/shared/infrastructure/HttpClient/`; nothing imports from `Domain/` of any other context.
- Strict TS (no `any` outside narrow type-narrowing helpers); BEM/Tailwind/Shadcn rules don't apply (no UI in this spec).
- Container bindings in `Container.ts` are unchanged in shape — only the interface/methods grow.

**Ask First:**
- Adding any retry, timeout, or auth header logic to `FetchHttpClient` (out of scope here; flag if a downstream consumer demands it).
- Replacing native `fetch` with another HTTP library.
- Changing the existing `HttpClient.get<T>` signature.

**Never:**
- Adding any UI, route, or page in this spec.
- Adding consumers (e.g. Bank repository) — those land in the follow-up spec listed in `deferred-work.md`.
- Hand-writing a `Response`/`fetch` polyfill.
- Vitest tests beyond the translator file (E2E coverage of the full path lands in the follow-up Bank spec, which uses legacy envelopes from `route.fulfill` mocks).
- Logging or telemetry side effects in the translator or in `HttpError`.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|---------------|----------------------------|----------------|
| Already ProblemDetails | body satisfies `isProblemDetails` | Returned verbatim (same reference is fine) | n/a |
| Legacy single error 422 | `{ errors:[{source:{parameter:"name"},title:"required"}], meta:{requestId:"01H…"} }`, status 422 | `{ type:"about:blank", title:"required", status:422, instance:<uuid>, "correlation-id":"01H…", violations:[{field:"name",message:"required"}] }` | n/a |
| Legacy multi-error 422 | `errors[]` length 2 | `violations[]` length 2 in the same order; `title` = first error's title | n/a |
| Legacy 404 with one error | `errors:[{source:{parameter:"uuid"},title:"Bank not found"}]`, status 404 | `title:"Bank not found"`, `status:404`, `violations[]` length 1 | n/a |
| Missing `meta.requestId` | legacy envelope without `meta` | `correlation-id` minted as UUIDv4; everything else translated as usual | n/a |
| Missing `source.parameter` | `errors[{title:"…"}]` | `violations[i].field = ""` | n/a |
| Empty `errors[]` | `{ errors:[], meta:{requestId} }` | `title = fallback.title`, `violations: undefined` (not empty array) | n/a |
| Unparsable body | `body === null` / non-object / JSON.parse failure | `{ type: fallback.type, title: fallback.title, status, instance:<uuid>, "correlation-id":<uuid> }` | n/a |
| FetchHttpClient non-2xx | response 422 with legacy envelope | throws `HttpError`, `error.problem.status === 422`, `error.problem.violations` populated | thrown error caught upstream |
| FetchHttpClient 204 | DELETE returns 204 No Content | resolves with `undefined` (typed `Promise<void>` for `delete`) | n/a |
| FetchHttpClient network failure | `fetch` rejects | rethrows the original error (do **not** wrap in `HttpError`) | upstream handles |

</frozen-after-approval>

## Code Map

- `pwa/src/context/shared/infrastructure/HttpClient/HttpError.ts` — NEW. `class HttpError extends Error { readonly problem: ProblemDetails }`.
- `pwa/src/context/shared/infrastructure/HttpClient/legacyEnvelope.ts` — NEW. `toProblemDetails(body, status, fallback)` pure function + tiny shape guards.
- `pwa/src/context/shared/infrastructure/HttpClient/HttpClient.ts` — extend the interface; add `post`/`put`/`delete` to `FetchHttpClient` and `MockHttpClient`.
- `pwa/tests/context/shared/infrastructure/HttpClient/legacyEnvelope.test.ts` — NEW. Vitest unit test mirroring the I/O matrix translator rows.
- `pwa/src/context/shared/domain/ProblemDetails.ts` — read-only; uses existing `ProblemDetails` and `isProblemDetails`.

## Tasks & Acceptance

**Execution:**
- [x] `pwa/src/context/shared/infrastructure/HttpClient/HttpError.ts` — define `class HttpError extends Error` with constructor `(problem: ProblemDetails)`, sets `this.name = 'HttpError'` and `this.message = problem.title`. Re-export `ProblemDetails` not necessary; consumers import from the existing path.
- [x] `pwa/src/context/shared/infrastructure/HttpClient/legacyEnvelope.ts` — implement `toProblemDetails(body: unknown, status: number, fallback: { type: string; title: string }): ProblemDetails`. Steps: (1) if `isProblemDetails(body)` return body; (2) treat as legacy if `body && typeof body === 'object' && Array.isArray((body as any).errors)`; (3) extract `errors[].source.parameter` → `field`, `errors[].title` → `message`; build `violations` only when there's at least one entry; (4) `correlation-id` from `meta.requestId` else `crypto.randomUUID()`; (5) `instance` from `body.instance` if present else `crypto.randomUUID()`; (6) `title` from first `errors[].title` else `fallback.title`; (7) when neither path applies, return `{ type: fallback.type, title: fallback.title, status, instance: crypto.randomUUID(), 'correlation-id': crypto.randomUUID() }`. No `console.*`. No `throw`.
- [x] `pwa/src/context/shared/infrastructure/HttpClient/HttpClient.ts` — extend `interface HttpClient` with `post<TBody, T>(url: string, body: TBody): Promise<T>`, `put<TBody, T>(url: string, body: TBody): Promise<T>`, `delete(url: string): Promise<void>`. `FetchHttpClient`: implement all three; on `!res.ok`, parse JSON safely (`await res.json().catch(() => null)`), call `toProblemDetails(parsed, res.status, { type: 'about:blank', title: 'HTTP ' + res.status })`, `throw new HttpError(problem)`. For `delete`, on 204 resolve `undefined`; for 200 with a body, ignore the body. `MockHttpClient`: add the three methods returning a generic resolved value (e.g. `as T`); tests can override per-instance if needed (the Bank follow-up spec relies on Playwright `page.route`, not MockHttpClient, so no mock-side translation is required here).
- [x] `pwa/tests/context/shared/infrastructure/HttpClient/legacyEnvelope.test.ts` — Vitest spec covering every translator row in the I/O matrix above (10 cases). Use a stable UUID stub for `crypto.randomUUID` via `vi.spyOn(crypto, 'randomUUID')` so assertions on `instance` / `correlation-id` are deterministic. No fetch, no network — translator is pure.

**Acceptance Criteria:**
- Given an already-ProblemDetails body, when `toProblemDetails` is called, then the same object passes through and `isProblemDetails` returns true.
- Given a legacy 422 envelope with two errors, when translated, then the result has `status === 422`, `violations.length === 2`, `violations[i].field` matches `errors[i].source.parameter`, and `correlation-id` matches `meta.requestId`.
- Given a legacy envelope without `meta.requestId`, when translated, then `correlation-id` is a UUIDv4 produced by `crypto.randomUUID`.
- Given a `FetchHttpClient.post` call that gets a 422 legacy response, when invoked, then it throws `HttpError` whose `problem.violations[0].field` reflects the API's `source.parameter`.
- Given a `FetchHttpClient.delete` call that gets a 204, when invoked, then it resolves with `undefined`.
- Given a `FetchHttpClient.post` call where `fetch` rejects (network failure), when invoked, then the original `Error` is rethrown unwrapped (no `HttpError`).
- `make pwa.lint` passes; `make pwa.test.unit c='context/shared/infrastructure/HttpClient/legacyEnvelope.test.ts'` passes; `make pwa.build` succeeds.

## Design Notes

**Translator golden example (legacy 422):**
```
input  : { errors:[{source:{parameter:"name"},title:"required"}], meta:{requestId:"01H…"} }
status : 422
output : { type:"about:blank", title:"required", status:422,
           instance:"<uuid>", "correlation-id":"01H…",
           violations:[{ field:"name", message:"required" }] }
```

**MockHttpClient minimal surface:** the Bank follow-up uses Playwright `page.route` to intercept HTTP calls in E2E. The MockHttpClient's new methods exist to satisfy the interface and any latent unit tests, not to power Bank tests; trivial implementations (`async post() { return {} as T }` etc.) are acceptable.

**Why a Vitest spec here (despite the user's E2E-only preference for Bank):** the translator is a pure shared-infra function with no UI to exercise via Playwright. The Bank follow-up will exercise the full path end-to-end through `page.route` returning legacy envelopes; this Vitest unit test verifies translator correctness in isolation. If you want it removed, say so at checkpoint and I'll drop it.

## Verification

**Commands:**
- `make pwa.lint` — expected: 0 ESLint/Prettier errors.
- `make pwa.test.unit c='context/shared/infrastructure/HttpClient/legacyEnvelope.test.ts'` — expected: all cases green.
- `make pwa.build` — expected: build succeeds (verifies the new methods on `HttpClient` typecheck against existing consumers).

## Suggested Review Order

**Translator (the load-bearing piece)**

- Entry point — three-branch dispatch (pass-through, legacy, synthetic fallback).
  [`legacyEnvelope.ts:22`](../../pwa/src/context/shared/infrastructure/HttpClient/legacyEnvelope.ts#L22)

- Pass-through: trust `isProblemDetails` shape; no copy.
  [`legacyEnvelope.ts:27`](../../pwa/src/context/shared/infrastructure/HttpClient/legacyEnvelope.ts#L27)

- Legacy mapping: `errors[].source.parameter|title` → `violations[].field|message`; defensive optional chaining survives null entries.
  [`legacyEnvelope.ts:33`](../../pwa/src/context/shared/infrastructure/HttpClient/legacyEnvelope.ts#L33)

- Shape-only legacy guard — `Array.isArray(v.errors)` is the gating predicate.
  [`legacyEnvelope.ts:66`](../../pwa/src/context/shared/infrastructure/HttpClient/legacyEnvelope.ts#L66)

**Typed error**

- `HttpError extends Error` carrying a readonly `ProblemDetails`; consumers branch on `instanceof HttpError`.
  [`HttpError.ts:3`](../../pwa/src/context/shared/infrastructure/HttpClient/HttpError.ts#L3)

**HttpClient surface extension**

- Interface grows `post`/`put`/`delete` — the contract every adapter must satisfy.
  [`HttpClient.ts:6`](../../pwa/src/context/shared/infrastructure/HttpClient/HttpClient.ts#L6)

- Centralized error path: parse safely, translate, throw `HttpError` — used by every method.
  [`HttpClient.ts:149`](../../pwa/src/context/shared/infrastructure/HttpClient/HttpClient.ts#L149)

- POST/PUT funnel through `sendWithBody`, JSON Content-Type, no-store cache.
  [`HttpClient.ts:118`](../../pwa/src/context/shared/infrastructure/HttpClient/HttpClient.ts#L118)

- 204 handling on `get` and `sendWithBody` (post/put) — short-circuits before `res.json()`.
  [`HttpClient.ts:91`](../../pwa/src/context/shared/infrastructure/HttpClient/HttpClient.ts#L91)

**Tests (peripheral)**

- Pass-through aliasing is part of the contract — `expect(result).toBe(problem)`.
  [`legacyEnvelope.test.ts:19`](../../pwa/tests/context/shared/infrastructure/HttpClient/legacyEnvelope.test.ts#L19)

- Multi-error translation preserves order; first title wins as `problem.title`.
  [`legacyEnvelope.test.ts:54`](../../pwa/tests/context/shared/infrastructure/HttpClient/legacyEnvelope.test.ts#L54)

- Patched edge case: null entries inside `errors[]` produce empty-string field/message.
  [`legacyEnvelope.test.ts:146`](../../pwa/tests/context/shared/infrastructure/HttpClient/legacyEnvelope.test.ts#L146)
