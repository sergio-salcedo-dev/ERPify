---
title: 'Add Sentry error + performance monitoring to the PWA (dev + prod)'
type: 'feature'
created: '2026-06-08'
status: 'done'
baseline_commit: 'f81cc4f25dd848b756e294d13c7673ff028e87a4'
context: ['{project-root}/pwa/CLAUDE.md', '{project-root}/_bmad-output/implementation-artifacts/deferred-work.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The PWA has no production error/performance monitoring. Unhandled
client/server errors, React-boundary-caught render errors, and realtime failures
vanish — the console adapter is silent in prod by design. The API just gained
Sentry; the frontend is the remaining blind spot.

**Approach:** Wire `@sentry/nextjs` for the three Next runtimes (client, node,
edge), DSN-gated like the API (empty DSN → inert). Mirror the API feature set:
errors in dev+prod, performance tracing only in prod (~0.2), **no** session
replay. Two separate Sentry projects (`erpify-pwa-dev` / `erpify-pwa-prod`) → two
DSNs baked per build via `NEXT_PUBLIC_SENTRY_DSN`. Route events through a
same-origin `tunnelRoute` so the locked-down CSP `connect-src` stays untouched.
Reuse the existing `Telemetry` port: add a `SentryTelemetry` adapter behind a
vendor-neutral `CompositeTelemetry` fan-out so deliberate `telemetry.error()`
calls (error boundaries, realtime hooks) reach Sentry, and a shared
`serializeCause` scrub helper gives PII/secret parity with the API's
`SentryEventScrubber`.

## Boundaries & Constraints

**Always:** Empty/unset `NEXT_PUBLIC_SENTRY_DSN` ⇒ SDK fully inert (no init, no
network) in every runtime — tests and bare checkouts never emit. `send_default_pii: false`
everywhere. Every external-bound event/cause passes through the shared scrub
(denylist parity with the API: password/token/secret/authorization/cookie/ssn/iban,
recursive). Errors captured in ALL envs; performance `tracesSampleRate` = 1.0 in
dev (`AppEnv.DEVELOPMENT`, full traces for local verification), 0.2 in
staging/prod. Keep `ConsoleTelemetry`'s call-time env gate intact. Keep
the sink seam vendor-neutral and composite-ready (Datadog drops in as a 2nd
`CompositeTelemetry` entry later). `NEXT_PUBLIC_SENTRY_DSN` is the ONLY new public
var; register it in the allowlist guard. Mirror the existing `NEXT_PUBLIC_APP_ENV`
build-arg pattern (Dockerfile `ARG`+`ENV`, compose build args, `:?` required in prod).

**Ask First:** Widening CSP `connect-src`/`script-src`/`worker-src`. Adding any
other `NEXT_PUBLIC_*` var. Changing the tunnel path if it collides with a route.
Enabling session replay or source-map upload. Touching `proxy.ts` matchers.

**Never:** No session replay. No source-map upload / `SENTRY_AUTH_TOKEN` (deferred —
prod stack traces stay minified for now). No Datadog code (next task). No
`NEXT_PUBLIC_DATADOG_*` name in `.env.example` (allowlist guard scans it). No new
secret behind a `NEXT_PUBLIC_` prefix. Do not bypass the `Telemetry` port at call
sites. No `tailwind.config.js`/webpack-only assumptions; build stays Turbopack.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| DSN unset (test/bare checkout) | `NEXT_PUBLIC_SENTRY_DSN` empty | No `Sentry.init`, no events; telemetry = throttled console only | N/A |
| Dev with DSN | env=`dev`, DSN set | Errors captured to `erpify-pwa-dev`, traces sampled at 1.0, console still logs | events scrubbed before send |
| Prod build | env=`prod`, DSN set | Errors + ~0.2 traces to `erpify-pwa-prod`, console silent, events via tunnel | scrubbed; PII off |
| Boundary-caught render error | `SegmentErrorBoundary` fires `telemetry.error` | Forwarded to Sentry via adapter (boundary swallows it from global handler) | cause serialized+scrubbed |
| `cause` carries `{ password }` | `telemetry.error(msg,{cause})` | Sentry event has denylisted keys stripped recursively | N/A |
| Event POST | browser → `/monitoring` (tunnel) | Same-origin POST allowed by `connect-src 'self'`; CSP unchanged | ad-blocker-proof |

</frozen-after-approval>

## Code Map

- `pwa/src/context/shared/domain/Observability/Telemetry.ts` -- existing port; unchanged (adapter target)
- `pwa/src/context/shared/infrastructure/Observability/index.ts` -- replace hardcoded singleton with `createTelemetry()` factory
- `pwa/src/context/shared/infrastructure/Observability/{Console,Throttled}Telemetry.ts` -- existing adapters; reused unchanged
- `pwa/next.config.ts` -- CSP/headers (must NOT regress); wrap export in `withSentryConfig`
- `pwa/tests/next-public-env-allowlist.test.ts` -- allowlist guard (+ "exactly N vars" assertion)
- `pwa/Dockerfile` (builder stage ~L47-51), `compose.dev.yaml`, `compose.prod.yaml`, `.env.prod.example`, `pwa/.env.example` -- `NEXT_PUBLIC_APP_ENV` build-arg pattern to mirror
- `api/src/Shared/Monitoring/Infrastructure/Sentry/SentryEventScrubber.php` -- scrub denylist reference (parity)
- `deferred-work.md` -- "Sentry/Datadog sink adapter" section to update (Sentry delivered; re-scope remainder)

**Files created/changed (as built):**
- New (pwa): `instrumentation.ts`, `instrumentation-client.ts`, `sentry.server.config.ts`, `sentry.edge.config.ts`
- New (domain): `src/context/shared/domain/Observability/{redaction,serializeCause}.ts`
- New (infra): `src/context/shared/infrastructure/Observability/{SentryTelemetry,CompositeTelemetry,scrubSentryEvent,sentryInitOptions}.ts`
- Changed: `src/context/shared/infrastructure/Observability/index.ts` (createTelemetry factory), `next.config.ts`, `Dockerfile`, `compose.dev.yaml`, `compose.prod.yaml`, `.env.prod.example`, `pwa/.env.example`, `vitest.config.ts` (Sentry test alias), `tests/next-public-env-allowlist.test.ts`, `package.json`/`package-lock.json`
- New tests: `tests/context/shared/domain/Observability/{redaction,serializeCause}.test.ts`, `tests/context/shared/infrastructure/Observability/{SentryTelemetry,CompositeTelemetry,scrubSentryEvent,createTelemetry}.test.ts`, `tests/stubs/sentryNextjs.ts`
- Docs: `pwa/CLAUDE.md`, `docs/deployment-guide.md`, `pwa/docs/production-deployment.md`, `PRODUCTION_SECURITY_CHECKLIST.md`, `deferred-work.md`

**Build-env note (learned):** `make pwa.*` targets run on the HOST, not the pwa container — npm installs and `.next`/`next-env.d.ts` ownership must be host-side (`make pwa.install`; remove root-owned `.next`/`next-env.d.ts` left by the stack boot).

## Tasks & Acceptance

**Execution:**
- [x] Install `@sentry/nextjs@10.56.0` (host + container; Next 16 + Turbopack prod build verified; `npm audit` clean).
- [x] `pwa/src/context/shared/domain/Observability/redaction.ts` -- vendor-neutral denylist (parity with API) + recursive `scrubRecord(obj)`.
- [x] `pwa/src/context/shared/domain/Observability/serializeCause.ts` -- normalize unknown `cause` (name→message→stack→nested cause, size-bounded) then scrub; pure domain util reused by adapter + SDK `beforeSend`.
- [x] `pwa/src/context/shared/infrastructure/Observability/SentryTelemetry.ts` -- `Telemetry` adapter: `warn`→`captureMessage(level "warning")`, `error`→`captureException(cause ?? Error(message))`, tag `scope`, attach scrubbed serialized cause.
- [x] `pwa/src/context/shared/infrastructure/Observability/CompositeTelemetry.ts` -- fan-out to N inner `Telemetry`s (Datadog-ready).
- [x] `pwa/src/context/shared/infrastructure/Observability/index.ts` -- `createTelemetry()`: inner = `Composite([Console, …(dsn ? [Sentry] : [])])`, wrapped in `Throttled`; export `telemetry` singleton. Preserve Console's call-time env gate.
- [x] `pwa/instrumentation-client.ts` -- DSN-gated `Sentry.init` (env from `NEXT_PUBLIC_APP_ENV`, `tracesSampleRate` = dev 1.0 / staging+prod 0.2, `sendDefaultPii:false`, `beforeSend` scrub); `export const onRouterTransitionStart = Sentry.captureRouterTransitionStart`.
- [x] `pwa/sentry.server.config.ts` + `pwa/sentry.edge.config.ts` -- same DSN-gated init for node/edge runtimes.
- [x] `pwa/instrumentation.ts` -- `register()` imports server/edge config by `NEXT_RUNTIME`; `export const onRequestError = Sentry.captureRequestError`.
- [x] `pwa/next.config.ts` -- `withSentryConfig(nextConfig, { tunnelRoute: "/monitoring", silent: !process.env.CI, sourcemaps: { disable: true } })`; no `authToken`, no `org`/`project`; CSP untouched.
- [x] `pwa/Dockerfile` -- `ARG NEXT_PUBLIC_SENTRY_DSN` + `ENV` in builder stage (mirror `NEXT_PUBLIC_APP_ENV`).
- [x] `compose.dev.yaml` (default empty) + `compose.prod.yaml` (`:?` required) -- pass `NEXT_PUBLIC_SENTRY_DSN` build arg; `.env.prod.example` + `pwa/.env.example` document it; trim the "future sink" comment to "Sentry landed; Datadog still future".
- [x] `pwa/tests/next-public-env-allowlist.test.ts` -- add `NEXT_PUBLIC_SENTRY_DSN`; bump the exact-vars assertion to the three names.
- [ ] Unit tests (Vitest, mirror in `pwa/tests/`) -- `redaction`/`serializeCause` (recursive scrub, nested cause, size bound), `SentryTelemetry` (level map, scrub applied — mock `@sentry/nextjs`), `CompositeTelemetry` (fans out), `createTelemetry` (no Sentry when DSN unset).
- [x] `pwa/CLAUDE.md` (NEXT_PUBLIC_ table + telemetry section) + `docs/deployment-guide.md` / `pwa/docs/production-deployment.md` (DSN per env, tunnel) + `deferred-work.md` (update sink-adapter section) + `PRODUCTION_SECURITY_CHECKLIST.md` (new public var + scrub).

**Acceptance Criteria:**
- Given an empty `NEXT_PUBLIC_SENTRY_DSN`, when the app boots in any runtime, then no Sentry network call is made and `make pwa.test.unit` passes.
- Given `make pwa.quality` and `make pwa.test.unit`, when run after the change, then both pass (allowlist guard included).
- Given a prod build (`make pwa.production.build`), when it completes with Turbopack, then it succeeds and emits no source maps to the browser bundle.
- Given the diffed `next.config.ts`, when CSP is compared, then `connect-src`/`script-src`/`worker-src` are byte-identical to before (tunnel needs only `'self'`).

## Spec Change Log

### 2026-06-08 — step-04 adversarial review (no loopback; patches applied live)

Three reviewers (blind / edge-case / acceptance). No `intent_gap`/`bad_spec` — frozen intent held; all ACs + I/O-matrix rows verified compliant. **Patches applied in this change:**
- Whitespace-only `NEXT_PUBLIC_SENTRY_DSN` now `.trim()`-gated in `sentryInitOptions` + `createTelemetry` (no init with an invalid DSN; gates stay consistent).
- `scrubSentryEvent` extended to also scrub `breadcrumbs`, `user`, and the `request.url` query, and wired as `beforeSendTransaction` too (tracing is on in every env, so performance events are scrubbed as well).
- `scrubDeep` returns a `"[depth-limited]"` sentinel at the depth cap instead of the raw object (a denylisted key past `MAX_DEPTH` can't ride out unscrubbed).
- `SentryTelemetry` no longer double-wraps a non-Error cause (`{value:{value:…}}` → `{value:…}`).

`reject` (verified non-issues): `silent: !process.env.CI` is the documented Sentry default; `environment` is never undefined (Next inlines `NEXT_PUBLIC_*` server-side at build; Dockerfile defaults `dev`); `/monitoring` has no route collision; unconditional `withSentryConfig` is an accepted build-time limitation. `defer` → `deferred-work.md` (stub-parity guard, node-count budget, tunnel-abuse note). The `error` non-Error path uses `captureMessage(message, "error")` (a clean Sentry title) rather than the spec task's literal `captureException(new Error(message))` — deliberate as-built; still tags scope + attaches the scrubbed cause, satisfying the I/O matrix.

### 2026-06-09 — dev-DSN wiring fix (post-merge-prep verification)

End-to-end verification (real `erpify-pwa-dev` DSN in `pwa/.env.local`, `/dev-throw` → event `ERPIFY-PWA-DEV-1` captured) exposed that `compose.dev.yaml` set `NEXT_PUBLIC_SENTRY_DSN: ${…:-}` (empty) in the pwa `environment`/`args`, which shadowed `.env.local` — Next won't override an already-set env var, so the dev SDK stayed inert. Fix: removed both lines; `next dev` now reads the dev DSN from `pwa/.env.local` (the natural source). Prod is unaffected (bakes via the Dockerfile build arg + `compose.prod.yaml`).

## Design Notes

Two capture layers, both required: (1) SDK auto-capture for *unhandled* client/server
errors (global handlers + `onRequestError`); (2) the `SentryTelemetry` adapter for
*deliberate* `telemetry.error()` — React error boundaries SWALLOW render errors, so
without the adapter those never reach Sentry. The throttle wraps the composite, so a
flood coalesces before burning Sentry quota. `withSentryConfig` `org`/`project` are
upload-only — omitted since source-map upload is off, which also sidesteps the
dev/prod project-slug split at build time (the DSN alone selects the project).

## Verification

**Commands:**
- `make pwa.quality` -- expected: ESLint + Prettier clean
- `make pwa.test.unit` -- expected: all pass incl. allowlist guard + new telemetry tests
- `make pwa.production.build` -- expected: Turbopack prod build succeeds, no source maps shipped
- `npm audit` (pwa) -- expected: clean

**Manual checks:**
- With a real dev DSN in `pwa/.env.local`, throw via `/dev-throw` → event appears in `erpify-pwa-dev`, with denylisted keys absent.
- DevTools Network: error events POST to same-origin `/monitoring`, never to `de.sentry.io`.

## Suggested Review Order

**Composition (start here — the design intent)**

- Entry point: the factory that wires console + DSN-gated Sentry behind the throttle.
  [`index.ts:23`](../../pwa/src/context/shared/infrastructure/Observability/index.ts#L23)
- Fan-out to N sinks, isolating a throwing sink (Datadog slots in here later).
  [`CompositeTelemetry.ts:24`](../../pwa/src/context/shared/infrastructure/Observability/CompositeTelemetry.ts#L24)

**SDK init & gating**

- Shared init options: DSN trim-gate, env tag, dev=1.0/prod=0.2 tracing, scrub hooks.
  [`sentryInitOptions.ts:20`](../../pwa/src/context/shared/infrastructure/Observability/sentryInitOptions.ts#L20)
- `register()` per runtime + `onRequestError` (server/edge/RSC/proxy capture).
  [`instrumentation.ts:7`](../../pwa/instrumentation.ts#L7)
- Client init + router-transition tracing hook.
  [`instrumentation-client.ts:12`](../../pwa/instrumentation-client.ts#L12)

**Port adapter (deliberate diagnostics → Sentry)**

- Maps `warn`/`error` to Sentry; Error→exception, else scrubbed message+context.
  [`SentryTelemetry.ts:29`](../../pwa/src/context/shared/infrastructure/Observability/SentryTelemetry.ts#L29)

**PII scrubbing (parity with the API)**

- `beforeSend`/`beforeSendTransaction`: scrubs extra/contexts/user/breadcrumbs/request.
  [`scrubSentryEvent.ts:20`](../../pwa/src/context/shared/infrastructure/Observability/scrubSentryEvent.ts#L20)
- Recursive denylist scrub with a depth-cap sentinel (no secret rides out).
  [`redaction.ts:39`](../../pwa/src/context/shared/domain/Observability/redaction.ts#L39)
- Normalizes an unknown cause (bounded, scrubbed) — vendor-neutral, Datadog-ready.
  [`serializeCause.ts:35`](../../pwa/src/context/shared/domain/Observability/serializeCause.ts#L35)

**Build & CSP (verify no regression)**

- `withSentryConfig`: tunnel `/monitoring`, source-map upload off; CSP block untouched.
  [`next.config.ts:195`](../../pwa/next.config.ts#L195)

**Env / build wiring & guards**

- `NEXT_PUBLIC_SENTRY_DSN` baked per image (empty default → inert).
  [`Dockerfile:56`](../../pwa/Dockerfile#L56)
- Prod requires the DSN (`:?`), mirroring the API.
  [`compose.prod.yaml:60`](../../compose.prod.yaml#L60)
- Allowlist guard: the only new public var.
  [`next-public-env-allowlist.test.ts:47`](../../pwa/tests/next-public-env-allowlist.test.ts#L47)
- Vitest stub alias (unit tests don't load the heavy real SDK).
  [`vitest.config.ts:21`](../../pwa/vitest.config.ts#L21)

### Review Findings (2026-06-09)

- [x] [Review][Patch] Potential CPU jank from massive object scrubbing [pwa/src/context/shared/domain/Observability/redaction.ts]
- [x] [Review][Patch] `CompositeTelemetry` swallows sink exceptions [pwa/src/context/shared/infrastructure/Observability/CompositeTelemetry.ts]
- [x] [Review][Patch] Hardcoded truncation limits in `serializeCause` [pwa/src/context/shared/domain/Observability/serializeCause.ts]
- [x] [Review][Patch] Weak DSN presence check [pwa/src/context/shared/infrastructure/Observability/index.ts]
- [x] [Review][Patch] `scrubDeep` destroys `Date`, `Map`, `Set` [pwa/src/context/shared/domain/Observability/redaction.ts]
- [x] [Review][Patch] URL Hash Leakage [pwa/src/context/shared/infrastructure/Observability/scrubSentryEvent.ts]
- [x] [Review][Patch] Stringified Body Bypass [pwa/src/context/shared/infrastructure/Observability/scrubSentryEvent.ts]
- [x] [Review][Defer] Denylist too narrow (Parity with API) — deferred, pre-existing
- [x] [Review][Defer] Public `/monitoring` tunnel lacks rate limiting — deferred, pre-existing
- [x] [Review][Defer] Non-secret PII not scrubbed (Parity with API) — deferred, pre-existing
- [x] [Review][Defer] `sentryNextjs.ts` stub maintenance liability — deferred, pre-existing
