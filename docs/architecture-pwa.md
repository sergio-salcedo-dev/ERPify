# Architecture — PWA (`pwa/`)

## Executive summary

The `pwa/` deployable is a Next.js 16.2 (App Router) + React 19.2 + TypeScript 6 application styled with Tailwind 4 (CSS-first config) and Shadcn primitives. Business logic is wired through **Inversify 8** for runtime DI and organised by **bounded context** under `src/context/`, mirroring the API's DDD layering (`domain / application / infrastructure`). Tests split between Vitest (unit) and Playwright (E2E).

## Technology stack

| Category        | Technology                                        | Version |
| --------------- | ------------------------------------------------- | ------- |
| Runtime         | Node                                              | 24      |
| Language        | TypeScript                                        | 6.0     |
| Framework       | Next.js (App Router, Turbopack dev)               | 16.2    |
| UI runtime      | React / React DOM                                 | 19.2    |
| Styling         | Tailwind CSS                                      | 4.2     |
| Component lib   | Shadcn                                            | 4.7     |
| Headless UI     | @base-ui/react                                    | 1.4     |
| Icons           | lucide-react                                      | 1.x     |
| Animation       | tw-animate-css (+ CSS)                            | 1       |
| Forms           | @hookform/resolvers                               | 5.x     |
| DI              | Inversify (+ reflect-metadata)                    | 8.1     |
| Class utilities | class-variance-authority, clsx, tailwind-merge    | —       |
| Unit tests      | Vitest (jsdom)                                    | 4.1     |
| Testing library | @testing-library/react, @testing-library/jest-dom | 16/6    |
| E2E             | Playwright                                        | 1.59    |
| Linting         | ESLint + eslint-config-next                       | 10 / 16 |
| Formatting      | Prettier                                          | 3.8     |

## Architecture pattern

**DDD + Hexagonal / Clean Architecture**, mirrored from the API. Dependencies point inward to `domain/`. The `app/` directory is the App Router shell only — business logic lives in `src/context/<bounded-context>/{domain,application,infrastructure}/`. Inversify wires concrete adapters to ports declared in `domain/` / `application/`.

### Bounded contexts

```text
pwa/src/context/
├── backoffice/
│   └── health/{application,domain,infrastructure}
├── frontoffice/
│   └── health/{application,domain,infrastructure}
└── shared/
    ├── domain/
    └── infrastructure/
        ├── DependencyInjection/   # Inversify container modules
        ├── HttpClient/            # Fetch wrapper
        └── ui/                    # Shared UI bindings
```

`src/components/` holds presentational components only:

- `ui/` — Shadcn primitives.
- `erpify/` — entity-agnostic backoffice design-system primitives, barrel-exported from `@/components/erpify`.

`src/lib/` is glue/utility only — never business logic.

### Where shared code goes (decision rule)

Cross-cutting code has several homes; pick by **purpose**, not just "is it reused". Mirrored in [`pwa/CLAUDE.md`](../pwa/CLAUDE.md).

| Put it in… | When it is… | Examples |
| --- | --- | --- |
| `context/shared/infrastructure/<Module>/` | backed by a **domain port** / swappable adapter, or part of a port-backed module | `Notification/Toast`, `DateTimeProvider`, `HttpClient`, `Validation`, `DependencyInjection`, `Observability/Telemetry` |
| `app/_components/` (or a route's own `_components/`) | a **landing/marketing** presentational component (its own raw-palette + `tw-animate-css` / CSS language) used only by its `app/` route — co-located, not shared | `Navbar`, `Footer`, `FeatureCard` |
| `components/erpify/` | an **entity-agnostic backoffice / app-shell design-system primitive**, reused across surfaces, barrel-exported from `@/components/erpify` | `DataTable`, `AsyncBoundary`, `EmptyState`, `StatusBadge`, `Spinner`, `Logo`, `SidebarItem`, `StatCard` |
| `components/ui/` | a raw **Shadcn** primitive | `button`, `dialog`, `input` |
| `src/lib/` | a **pure helper or generic hook** with no domain identity | `safeHref`, `useDebouncedValue`, `utils` |

The back-office (token-driven Shadcn + `@/components/erpify`) and the landing/marketing surface (raw-palette + `tw-animate-css` / CSS, under `app/_components/`) are two deliberate design languages — use the one matching the surface; don't cross-import. App-shell primitives reused by both (e.g. `Logo`) live in `@/components/erpify`. The former `context/shared/infrastructure/ui/components/` folder (atomic-design `atoms`/`molecules`/`organisms`) was retired: app-shell primitives → `@/components/erpify`, marketing components → `app/_components/`.

The `Notification` module (`context/shared/{domain,infrastructure}/Notification/`) provides transient user feedback. Its first channel is **Toast**: the `ToastNotifier` port with a Sonner adapter (`SonnerToastNotifier` + `SonnerToaster`, mounted once in the root layout) and the `toastNotifier` singleton. The naming leaves room for additional channels (`Banner`, `Push`) and alternative adapters without renaming the port.

### Observability — Telemetry seam

The `Observability` module provides a non-user-facing diagnostic channel for infrastructure failures (network errors, malformed payloads, authorization retries) that should never surface as UI toasts.

- **Port** — [`Telemetry.ts`](../pwa/src/context/shared/domain/Observability/Telemetry.ts) declares `warn(message, ctx?)` / `error(message, ctx?)` with an optional `TelemetryContext { scope?, cause? }`. `Domain/` depends only on this interface. The `cause` contract is adapter-dependent: a *local* adapter (console) may forward it as-is, but any *external/network* adapter (Sentry/Datadog) MUST serialize + scrub it before transmission.
- **Adapter** — [`ConsoleTelemetry.ts`](../pwa/src/context/shared/infrastructure/Observability/ConsoleTelemetry.ts) implements the port; it emits to `console.warn` / `console.error` when `NEXT_PUBLIC_APP_ENV` is `dev` or `staging`, and is a no-op in `prod` (or unknown). `NODE_ENV` alone cannot distinguish staging from prod — both build images run in `production` mode — so `NEXT_PUBLIC_APP_ENV` is the correct gate.
- **Decorator** — [`ThrottledTelemetry.ts`](../pwa/src/context/shared/infrastructure/Observability/ThrottledTelemetry.ts) wraps any `Telemetry` and coalesces a flood of identical diagnostics (same level + scope + message) into one emit per window (10s default), surfacing the suppressed count as a `(+N suppressed)` suffix on the next emit. It protects the console today and a metered Sentry/Datadog sink tomorrow. The backing key map is bounded (size cap + TTL sweep, defaults 1000 keys / 1h) so a future dynamic-keyed call site can't grow it without limit in a long-lived tab.
- **Singleton** — `telemetry` exported from `@/context/shared/infrastructure/Observability` (`index.ts`) is the only instance: `ThrottledTelemetry` wrapping `ConsoleTelemetry`. A future Sentry/Datadog adapter (or a `CompositeTelemetry` fan-out) replaces the *wrapped* adapter behind this singleton — the throttle and every call site stay put. Adapter-selection-by-env, the `cause` scrub helper, and CSP `connect-src` widening are tracked in `_bmad-output/implementation-artifacts/deferred-work.md` for when the sink lands.
- **Realtime integration** — [`useMercureRealtime.ts`](../pwa/src/context/shared/infrastructure/RealTime/useMercureRealtime.ts) is a generic hook centralising Mercure authorize / subscribe / reconnect-reauth for all entity-specific realtime hooks. Entity hooks (e.g. [`bankRealtime.ts`](../pwa/src/context/backoffice/bank/infrastructure/bankRealtime.ts)) delegate to it by supplying `{ topics, authorizePath, parse, onEvent, scope }`. Failures previously swallowed silently — subscription skipped, cookie refresh failed, malformed payload — are now routed through `telemetry.warn` with the hook's `scope` for traceability.

## Layer responsibilities

| Layer             | Contains                                                                                                | Must NOT depend on                                     |
| ----------------- | ------------------------------------------------------------------------------------------------------- | ------------------------------------------------------ |
| `domain/`         | Entities, value objects, repository / port **interfaces**, domain errors                                | React, Next, Inversify, fetch, third-party SDKs        |
| `application/`    | Use cases, DTOs, orchestration                                                                          | Infrastructure implementations (only their interfaces) |
| `infrastructure/` | HTTP clients, Inversify bindings, Next-aware adapters, presentational hooks bridging React to use cases | — (outermost)                                          |

## Routing

- **App Router** under `src/app/`.
- Entry: `app/layout.tsx` (root layout) + `app/page.tsx` (root page).
- `app/globals.css` holds Tailwind 4's CSS-first `@theme` / `@config` directives.
- `app/backoffice/` is the backoffice route segment. `/backoffice/health` is the internal admin health page: same Atlassian-style status presentation as `/status`, but in the backoffice design language (design tokens + `@/components/erpify`, never the marketing palette). It auto-runs `BackOfficeCheckHealth` on mount, renders an aggregate `SystemStatusBanner` plus a per-component `<StatusBadge>` row, and — being an internal diagnostic surface — keeps surfacing the technical `ProblemDetails` / correlation id on failure. Both status pages reuse the pure status model in `@/lib/systemStatus` (`SystemStatus`, `deriveSystemStatus`, `systemHeadline`, `componentStatusLabel`) and the styling-agnostic `StatusBannerView` (`@/components/status/`), each passing its own design-language theme (palette + layout) so structure is shared without crossing palettes.
- `app/status/` — public, unauthenticated service status page (Atlassian-style). Auto-runs the existing `FrontOfficeCheckHealth` use case on mount, renders an aggregate banner and per-component rows. Presentation components live in `app/status/_components/` in the marketing design language (raw Tailwind palette, not `@/components/erpify`). Linked from the navbar and footer. Distinct from the internal admin `app/backoffice/health/` page.

## Dependency injection

- Inversify 8 container assembled in `src/context/shared/infrastructure/DependencyInjection/`.
- `tsconfig.json`: `strict: true`, `experimentalDecorators: true`, `emitDecoratorMetadata: true` (required for Inversify class decorators).
- `reflect-metadata` imported once at the React entry point (`app/layout.tsx`).

## Data fetching & integration

- Same-origin under FrankenPHP in dev/prod: `/api/*` is served by Symfony on `localhost`.
- Server-side fetches use `SYMFONY_INTERNAL_URL` (Compose-internal); client-side fetches use the public URL.
- Mercure SSE consumed at `/.well-known/mercure` (same origin, JWT subscribed).

### Server-driven filtered search

Filterable lists are **server-driven**: filtering, sorting, and keyset pagination are resolved by the API, not in the browser. The shared vocabulary mirrors the API's generic `filters[]` contract:

- `context/shared/domain/Search/` — `Filter` (discriminated union by `FilterOperator`:
  `eq | in | contains | gte | lte`) is the typed, framework-free vocabulary.
- `context/shared/infrastructure/Search/buildSearchParams.ts` — serializes a `Filter[]` into the exact wire
  grammar (`filters[N][field|operator|value]`; `filters[N][value][]` for `in`), returning a composable
  `URLSearchParams`.
- A repository's `search(criteria)` composes those params with `sort` + `direction` (uppercased to the API's
  `ASC`/`DESC` enum), `page`, an opaque `cursor` (replayed verbatim, never interpreted client-side), and
  `limit`. It sends no `paginationMode`, so the API defaults to `LIGHT` — it skips the `COUNT(*)` and reports
  `hasMorePages` from a single fetch, all a prev/next cursor list needs. Send `paginationMode=detailed`
  only on a view that actually renders a total (`pagination.count`), paying the extra count deliberately. See
  `context/backoffice/bank/infrastructure/ApiBankRepository.ts` as the reference consumer.

Two list-behaviour rules are load-bearing:

- **Discard the cursor when the query changes.** Any change to a filter, the sort, or the page size resets to
  page 1, which sends no cursor — so a stale cursor is dropped by construction. Only sequential prev/next
  navigation replays the last response's cursor.
- **Reconcile realtime by refetching.** Under server-driven search the client cannot decide whether a Mercure
  `created`/`updated`/`deleted` belongs on the current page, so every event triggers a coalesced silent
  refetch of the current page (yielding to an in-flight optimistic bulk delete). There is no in-memory merge.

Recipe to make a new list filterable → [`pwa/docs/server-driven-search.md`](../pwa/docs/server-driven-search.md).

## Error consumption

The PWA consumes the API's [RFC 9457 Problem Details](./api-error-contract.md) contract. Routing/UI logic determines the semantic category from `type` (opaque, stable identifier) — never from message text or status code alone. `correlation-id` from the body (or the `X-Correlation-Id` response header) links to server-side log lines for support tickets.

## Configuration

- Build/dev: `next.config.*` (Turbopack-aware), `eslint.config.*`, `tsconfig.json`.
- Tailwind 4: configured via CSS in `app/globals.css` (no separate `tailwind.config.*` required).
- Tests: `vitest.config.ts`, `playwright.config.ts`.
- Env: `pwa/.env.local` for host overrides; `NEXT_PUBLIC_*` vars are inlined at build time.

## Testing strategy

| Layer         | Tool                 | Entry                                                                                            |
| ------------- | -------------------- | ------------------------------------------------------------------------------------------------ |
| Unit          | **Vitest 4** (jsdom) | `pwa/vitest.config.ts`, run via `make pwa.test.unit`                                             |
| E2E           | **Playwright 1.59**  | `pwa/playwright.config.ts`, run via `make pwa.test.e2e`                                          |
| Watch         | Vitest               | `make pwa.test.unit.watch`                                                                       |
| Reports       | Playwright HTML      | `make pwa.test.e2e.reports`                                                                      |
| Lint / format | ESLint + Prettier    | `make pwa.quality` (check), `make pwa.lint` (ESLint --fix), `make pwa.format` (Prettier --write) |

`tests/` mirrors `src/`. Tests are colocated by bounded context.

## Source tree

See [`source-tree-analysis.md`](./source-tree-analysis.md) for the full annotated tree.

## Development & deployment

- Dev setup: [`development-guide-pwa.md`](./development-guide-pwa.md).
- Prod deploy: [`deployment-guide.md`](./deployment-guide.md).
