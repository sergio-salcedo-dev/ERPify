# PWA client telemetry seam + shared Mercure realtime hook

**Date:** 2026-06-02
**Scope:** `pwa/` (+ repo-wide env-var rename)
**Status:** Design — awaiting approval

## Problem

`pwa/src/context/backoffice/bank/infrastructure/bankRealtime.ts` silently swallows
two Mercure realtime failures (subscriber-cookie mint failure before opening the
stream, and re-mint failure on stream-error reconnect). They are correctly
non-user-facing, but today they are also invisible to operators: the only
diagnostic is gated behind `NODE_ENV === development`, and the production image
hardcodes `NODE_ENV=production`, so **staging is silent too** — `NODE_ENV` cannot
distinguish staging from prod.

We want these (and future) client-side diagnostics observable in staging now, and
a clean seam so the planned **Sentry + Datadog** integrations slot in later without
touching call sites. Separately, Mercure realtime will power many future ERPify
entities, so the per-entity realtime orchestration that currently lives in the bank
file must become reusable rather than copy-pasted.

## Goals

- A swappable client-side telemetry channel (port + adapter + singleton) matching
  the repo's existing `Notification/Toast` / `DateTimeProvider` shape.
- Staging-visible, prod-silent (sink-ready) diagnostics, keyed off a new
  browser-readable env signal.
- Extract a reusable `useMercureRealtime` hook so every future entity inherits
  authorize + subscribe + telemetry behavior.
- Rename `NEXT_PUBLIC_SYMFONY_API_BASE_URL` → `NEXT_PUBLIC_API_BASE_URL` repo-wide.

## Non-goals (explicit follow-ups)

- Actual Sentry / Datadog adapters, `CompositeTelemetry` fan-out, DSN secrets,
  PII/secret scrubbing, CSP `connect-src` widening.
- Migrating the error boundaries (`SegmentErrorBoundary`, `RootErrorBoundary`) onto
  `telemetry.error` — a follow-up; the port intentionally already exposes `error`
  so that migration is a pure call-site change.
- Server-side (RSC / route-handler) telemetry.

## Part A — Telemetry seam

**Port** — `pwa/src/context/shared/domain/Observability/Telemetry.ts`

```ts
export interface TelemetryContext {
  /** Low-cardinality scope tag, e.g. "realtime:bank". */
  scope?: string;
  /** Triggering error/cause; adapters serialize + scrub it. Never assume PII-free. */
  cause?: unknown;
}
export interface Telemetry {
  warn(message: string, context?: TelemetryContext): void;
  error(message: string, context?: TelemetryContext): void;
}
```

**Env constant** — `pwa/src/context/shared/domain/types/appEnv.ts` (mirrors `nodeEnv.ts`)

```ts
export const AppEnv = { DEVELOPMENT: "dev", STAGING: "staging", PRODUCTION: "prod" } as const;
export type AppEnv = (typeof AppEnv)[keyof typeof AppEnv];
```

Plus `resolveAppEnv(): AppEnv` reading `process.env.NEXT_PUBLIC_APP_ENV`, defaulting
**unknown → `prod`** (safest / quietest). Read at call time, not module load, so
tests can stub it.

**Adapter** — `pwa/src/context/shared/infrastructure/Observability/ConsoleTelemetry.ts`

Per-env emit policy:

| `NEXT_PUBLIC_APP_ENV` | `warn` / `error` |
| --- | --- |
| `dev` | `console.warn` / `console.error` |
| `staging` | `console.warn` / `console.error` (the gap this closes) |
| `prod` (and unknown) | **silent** until a real sink adapter lands |

Format: `console.warn(\`[${scope ?? "telemetry"}] ${message}\`, cause)`. Lint-safe
(`no-console` allows `warn`/`error`).

**Singleton barrel** — `pwa/src/context/shared/infrastructure/Observability/index.ts`

```ts
export const telemetry: Telemetry = new ConsoleTelemetry();
export type { Telemetry, TelemetryContext } from "@/context/shared/domain/Observability/Telemetry";
```

Consumers type against `Telemetry`, never the adapter.

## Part B — Shared Mercure realtime hook

**New** — `pwa/src/context/shared/infrastructure/RealTime/useMercureRealtime.ts`

```ts
interface UseMercureRealtimeOptions<E> {
  topics: readonly string[];
  authorizePath: string;               // entity supplies its own endpoint
  parse: (data: unknown) => E | null;  // entity supplies its parser
  onEvent: (event: E) => void;         // wrapped in useEffectEvent internally
  scope: string;                       // telemetry scope, e.g. "realtime:bank"
}
export function useMercureRealtime<E>(opts: UseMercureRealtimeOptions<E>): void;
```

Centralizes: authorize-URL resolution (the SSR-safe origin logic currently in
`authorize()`), the `fetch` authorize, **`telemetry.warn` on both failure paths**
(`"subscription skipped"`, `"subscriber-cookie refresh failed"`),
subscribe-with-`onError`-refresh, and cleanup. `topicsKey` dependency behavior is
preserved.

`bankRealtime.ts` shrinks to bank-specific bits: `bankTopics`,
`parseBankRealtimeEvent` (+ `isBankPrimitives` + types), and a thin `useBankRealtime`
that maps parsed events onto `BankRealtimeHandlers` and delegates to
`useMercureRealtime({ …, scope: "realtime:bank" })`. The local `logRealtimeWarning`
helper and the `NodeEnv` import are removed (gating now lives in the adapter).

**Bonus reuse win:** the malformed-payload `catch` in `BrowserMercureSubscriber`
also routes through `telemetry.warn` (`scope: "realtime:mercure"`), so every entity
benefits from one fix.

## Part C — Env-var rename

`NEXT_PUBLIC_SYMFONY_API_BASE_URL` → `NEXT_PUBLIC_API_BASE_URL`, clean cutover (no
dual-read alias). 31 in-repo occurrences across:

- **Code/tests:** `bankRealtime.ts`, `HttpClient.ts`, `BrowserMercureSubscriber.ts`,
  `lib/frankenphp-hot-reload.ts` (+ its test), `next.config.ts`, `playwright.config.ts`,
  `e2e/backoffice/banks-real-api*.spec.ts`.
- **Config:** `pwa/Dockerfile` (ARG+ENV), `compose.yaml`, `compose.dev.yaml`,
  `compose.prod.yaml` (incl. the `:?` required-error string), `pwa/.dockerignore`
  comment, `make/deploy.mk` `PROD_REQUIRED_KEYS`, `.env.prod.example`, `pwa/.env.example`.
- **Docs:** the deployment/integration/dev-guide set listed in the grep sweep.

### ⚠️ Out-of-repo (operator action, documented in PR)

`pwa/.env.local` and any VPS/CI secret named `NEXT_PUBLIC_SYMFONY_API_BASE_URL` must
be renamed in lockstep — `compose.prod.yaml` hard-fails (`:?`) on the old name's
absence.

## New env var: `NEXT_PUBLIC_APP_ENV`

Plumbed exactly like the (renamed) base URL — public, baked at build:

- `pwa/Dockerfile`: `ARG NEXT_PUBLIC_APP_ENV=dev` + `ENV NEXT_PUBLIC_APP_ENV=${NEXT_PUBLIC_APP_ENV}`.
- `compose.yaml` pwa build args: `NEXT_PUBLIC_APP_ENV: ${APP_ENV:-dev}` (reuse existing `APP_ENV`).
- `compose.dev.yaml` pwa `environment:` `NEXT_PUBLIC_APP_ENV: dev` (dev runs `next dev`, runtime env).
- `compose.prod.yaml`: set to `prod` (or derive from `APP_ENV`).
- `pwa/.env.example` documents it.

## Testing

- `tests/context/shared/infrastructure/Observability/ConsoleTelemetry.test.ts` —
  per-env matrix (stub `NEXT_PUBLIC_APP_ENV`, spy `console.warn`/`error`): emits for
  `dev`/`staging`, silent for `prod`/unknown.
- Existing `bankRealtime.test.ts` stays green: test env leaves `NEXT_PUBLIC_APP_ENV`
  unset → `prod` default → silent (same observable behavior as today). Update only
  if the hook refactor changes its seams; preserve the 7 existing assertions.
- Rename: `frankenphp-hot-reload.test.ts` `vi.stubEnv` keys updated; `make pwa.quality`
  + targeted `make pwa.test.unit` green.

## Docs to update

`pwa/CLAUDE.md` (decision-rule table: add `Observability`; "Shared building blocks";
Env section: rename + new var), `docs/architecture-pwa.md`, `docs/deployment-guide.md`,
`pwa/docs/production-deployment.md`, and the rename's doc occurrences. `PRODUCTION_SECURITY_CHECKLIST.md`
key rename.

## Sequencing

Two commits on `feat/pwa-client-telemetry-seam`:

1. `refactor(pwa): rename NEXT_PUBLIC_SYMFONY_API_BASE_URL to NEXT_PUBLIC_API_BASE_URL`
2. `feat(pwa): client telemetry seam + shared mercure realtime hook`

## Decisions

1. **Env var name** — the staging/prod signal is `NEXT_PUBLIC_APP_ENV` (values
   `dev`/`staging`/`prod`), distinct from the renamed `NEXT_PUBLIC_API_BASE_URL`.
2. **Module/port naming** — `Observability` / `Telemetry`, methods `warn` + `error`.
3. **Rename style** — clean cutover, no dual-read.
4. **Mercure** — extract shared `useMercureRealtime` now (not inline).

## Future work

- `SentryTelemetry` / `DatadogTelemetry` adapters + `CompositeTelemetry` fan-out
  behind the same `Telemetry` port (PII/secret scrubbing, CSP `connect-src`, DSN secrets).
- Migrate error boundaries onto `telemetry.error`.
- Server-side telemetry channel if needed.
