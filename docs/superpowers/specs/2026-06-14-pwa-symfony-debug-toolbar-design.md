# Surface the Symfony debug toolbar inside the Next.js PWA

- **Issue:** #262 (follow-up to PR #260 — dev-only Symfony Profiler & Web Debug Toolbar).
- **Status:** ready-for-dev
- **Scope:** cross-deployable (`api/` + `pwa/`), dev/test only, never production.
- **Goal:** show Symfony's floating Web Debug Toolbar while using the **real** Next.js app, not only on the Symfony-served `/_dev` page.

## Architecture & data flow

The PWA never passes through Symfony's `WebDebugToolbarListener` (that listener injects the
toolbar into HTML responses Symfony itself renders; PWA HTML is rendered by Next). The toolbar is
therefore reconstructed client-side from the per-request profiler token that Symfony already emits
on every `/api/*` response in dev (`X-Debug-Token`, `X-Debug-Link`), then loaded from Symfony's own
`/_wdt/{token}` endpoint — which already routes through FrankenPHP.

```
/api/* response ──(X-Debug-Token, X-Debug-Link headers)──> FetchHttpClient
        │  this.debugTokens.publish({ token, profilerUrl })
        ▼
DebugTokenObserver  (domain port)
        │  dev adapter: EventTarget-backed pub/sub, replays latest to late subscribers
        │  prod adapter: no-op (publish/subscribe do nothing)
        ▼
useLatestDebugToken()  ──>  <SymfonyDebugToolbar>   (dev-only, mounted once in root layout)
        │  on token change: fetch /_wdt/{token}, inject the fragment into a fixed
        │  bottom container, re-creating <script> nodes so they execute
        ▼
   Symfony renders the real Web Debug Toolbar  (same-origin, served by FrankenPHP)
```

Why this shape:

- **One read chokepoint.** Every `/api/*` response is read in exactly one place — `FetchHttpClient`
  (`pwa/src/context/shared/infrastructure/HttpClient/HttpClient.ts`), on both the success and the
  error path. The token is read there once, so no other layer needs to know about the profiler.
- **Port/adapter seam, not a global side-channel.** The HTTP layer depends on a `DebugTokenObserver`
  domain port, never on `window`. This keeps `FetchHttpClient` unit-testable, matches the repo's
  DDD/Inversify discipline, and lets the prod build bind a no-op so the feature is inert by
  construction in production — independent of the (also-true) fact that prod never emits the header.
- **Same-origin, no new infra.** FrankenPHP's `@pwa` matcher (`api/frankenphp/Caddyfile`) already
  excludes `/_wdt*`, `/_profiler*`, `/_dev*` from the reverse proxy to Next, so a relative
  `fetch('/_wdt/{token}')` from the PWA reaches Symfony with no Caddy change.
- **Latest request wins.** The toolbar reflects the most recent `/api/*` call, mirroring Symfony's
  native single-toolbar behaviour. Most PWA routes fire one or two API calls per navigation; the
  container re-loads only when the token actually changes (keyed by token), so no flicker.

## Components

Each unit has one purpose, a defined interface, and is testable in isolation.

### API side (`api/`) — expected zero code change

The profiler already emits `X-Debug-Token` / `X-Debug-Link` on `/api/*` in dev/test (PR #260), and
`/_wdt/{token}` already routes through FrankenPHP. No controller, route, or config change is planned.
The single contingency is **Approach B** below (a tiny dev-only loader route), taken only if the
spike proves it necessary.

### PWA side (`pwa/`)

| Unit | Path | Responsibility |
| --- | --- | --- |
| `DebugToken` type + `DebugTokenObserver` port | `context/shared/domain/DebugToken/` | Pure types: `DebugToken = { token: string; profilerUrl: string \| null }`; `publish(t)`, `subscribe(fn): () => void`. No framework imports. |
| `EventTargetDebugTokenObserver` (dev adapter) | `context/shared/infrastructure/DebugToken/` | `EventTarget`-backed pub/sub; retains the latest `DebugToken` and replays it to subscribers that attach after the first publish. |
| `NoopDebugTokenObserver` (prod adapter) | `context/shared/infrastructure/DebugToken/` | `publish` is a no-op; `subscribe` returns a no-op unsubscribe. |
| DI binding | existing Inversify container module | Binds `DebugTokenObserver` to the dev adapter when `isDevToolsAvailable()`, else the no-op. |
| `FetchHttpClient` (edit) | `context/shared/infrastructure/HttpClient/HttpClient.ts` | Constructor-injects `DebugTokenObserver`; a private `request()` wrapper around `fetch` reads `X-Debug-Token` / `X-Debug-Link` off every response (success **and** error) and publishes once. `MockHttpClient` is injected the no-op. |
| `useLatestDebugToken()` | `context/shared/infrastructure/ui/` | React hook subscribing to the observer; returns the current `DebugToken \| null`. |
| `<SymfonyDebugToolbar>` | `context/shared/dev-tools/infrastructure/ui/` | `"use client"`, dev-only. Fixed-bottom container; on token change fetches `/_wdt/{token}` and injects the fragment, re-creating `<script>` nodes so they execute. Mounted once in the root layout behind `isDevToolsAvailable()`. |

The toolbar lives under the existing **Dev Tools module** (`context/shared/dev-tools/`) to reuse its
`isDevToolsAvailable()` gate and prod short-circuit conventions.

## Production exclusion (defense in depth)

1. `<SymfonyDebugToolbar>` is mounted only when `isDevToolsAvailable()` (`NODE_ENV !== production`),
   so the branch is dead-code-eliminated in the prod bundle.
2. `DebugTokenObserver` is bound to `NoopDebugTokenObserver` in prod — the HTTP layer's `publish`
   call becomes inert.
3. The prod API ships no profiler, so no `X-Debug-Token` header is ever emitted.

Any one of these alone disables the feature; all three hold simultaneously.

## CSP

The issue anticipated relaxing the PWA CSP in dev. The current dev CSP (`pwa/next.config.ts`) already
permits everything the toolbar fragment needs:

- `script-src 'self' 'unsafe-inline' 'unsafe-eval'` (dev) — inline + same-origin toolbar scripts.
- `style-src 'self' 'unsafe-inline'` — inline toolbar styles.
- `img-src 'self' data: blob: https:`, `font-src 'self' data:` — toolbar icons/fonts.
- `connect-src 'self' …` — the same-origin `/_wdt/{token}` fetch.

**Plan: verify empirically, change nothing speculatively.** Only if a concrete gap surfaces during
manual verification is a **dev-only** CSP addition made (guarded by the existing `isProd` branch),
and the security checklist / `pwa/CLAUDE.md` updated accordingly. No production CSP change under any
outcome.

## Error handling

- No / empty `X-Debug-Token` → observer never publishes → toolbar absent (normal for non-profiled
  responses and for prod).
- `/_wdt/{token}` fetch fails → `<SymfonyDebugToolbar>` logs via `telemetry.warn` (scope
  `api:wdt`, built with `apiScope("wdt")`) and renders nothing; the error never propagates into the
  app tree.
- Malformed fragment → confined to the dev-only container; cannot affect the app's React tree.

## Testing

- **Unit (Vitest):**
  - `EventTargetDebugTokenObserver`: publish→subscribe delivery, latest-value replay to late
    subscribers, unsubscribe stops delivery.
  - `NoopDebugTokenObserver`: publish is inert, subscribe returns a callable no-op.
  - `FetchHttpClient`: publishes the parsed `{ token, profilerUrl }` when the headers are present
    (on both 2xx and error responses); does not publish when the header is absent.
  - `useLatestDebugToken`: re-renders with the latest token on publish.
- **Prod-config guard:** assert the container binds the no-op adapter under prod config and that the
  toolbar is not mounted (consistent with the existing dev-tools prod short-circuit tests).
- **Manual browser verification** (per root `CLAUDE.md` local-check rule — accept the self-signed
  cert, don't downgrade to curl-only): load a real PWA route (e.g. `/backoffice/banks`), confirm the
  toolbar renders and reflects the `/api/*` call and links into `/_profiler/{token}`; confirm it is
  absent in a production build.

## Open question resolved by a spike (does not change the PWA contract)

Whether Symfony 8's `/_wdt/{token}` fragment is **self-contained** (defines its own loader/`Sfjs`
and styles → inject directly: **Approach A, recommended**) or requires the
`@WebProfiler/Profiler/toolbar_js.html.twig` loader (→ add a tiny **dev-only**
`/_dev/wdt-loader/{token}` Symfony route that renders it: **Approach B, fallback**). The spike is the
first implementation step. Either way, the PWA-side seam (`DebugTokenObserver` → hook →
`<SymfonyDebugToolbar>`) is unchanged; only the toolbar component's injection source differs.

## Out of scope

- Any production toolbar surface.
- Replacing or removing the `/_dev` page from PR #260 (it stays as the Symfony-served playground).
- Cross-origin dev setups: if a future dev config serves the PWA and API on different origins, the
  API must add `Access-Control-Expose-Headers: X-Debug-Token, X-Debug-Link`; the default
  same-origin (FrankenPHP) setup needs nothing. Noted, not built.
