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
└── shared/                        # capability modules (each {domain,…,infrastructure}) + kernel — adr/shared-module-organization.md
    ├── access/ error/ resource/ dev-tools/                       # IAM, error, CRUD, dev tooling
    ├── DateTimeProvider/ Notification/ Observability/ RealTime/  # ports + adapters
    ├── RateLimit/ Search/ Validation/ DebugToken/
    ├── domain/                    # kernel: types/, ProblemDetails, ValueObject
    └── infrastructure/            # kernel: api/, DependencyInjection/ (Inversify), HttpClient/
```

`src/components/` holds presentational components only:

- `ui/` — Shadcn primitives.
- `erpify/` — entity-agnostic backoffice design-system primitives, barrel-exported from `@/components/erpify`.

`src/lib/` is glue/utility only — never business logic.

### Shared execution toolkits — access (IAM) & resource (CRUD)

Two `context/shared/` modules abstract repeated **logic**, not repeated **structure**: entity modules keep owning their tables, forms, columns, and badges explicitly, and wire that UI into these hooks. The Bank module is the reference and is deliberately left untouched by them.

- **`context/shared/access/`** — the mocked IAM core. `domain/` holds the access primitives (`UserStatus`, `Role`, `Permission` + `*` wildcard, `AccessContext`) plus the `Session`/`Identity` types and the pure `authorize(session, permission)` evaluator (`ALLOW = status===ACTIVE ∧ hasPermission ∧ roleContextValid ∧ domainPolicyAllow`). `AccessPolicyRegistry` is an intentionally empty seam for a future ABAC engine. `infrastructure/ui/` provides the `AuthProvider` (seeds an ADMIN session, persists to `localStorage` under `erpify:session` — identity only, never a password), the `<Can permission|role>` hide-guard, the `RequireAuth` redirect guard (non-ACTIVE → `/login`), and a dev-only `DevSessionSwitcher`. `application/` exposes `useSession` and `useCan`/`useCanRole`. Access-level `UserStatus` is an auth primitive and is kept separate from any business status of the same name.
- **`context/shared/resource/`** — the generic CRUD execution core. `domain/` defines the `CrudRepository<T,TInput>` port, the cursor-only `ResourceSearchCriteria`/`ResourceSearchPage<T>` (reusing the shared `PageEnvelope`), and the `ResourceSearchNavigator`. `infrastructure/` ships an `InMemoryCrudRepository` + `InMemoryResourceNavigator` over opaque base64 offset links (the client forwards links verbatim, mirroring the real keyset contract). `application/` provides `useQueryState`, `useResourceItem`, `useResourceMutations`, and `useResourceList` — the list state machine generalized from the Bank list page (two load paths, monotonic request guard, query-reset-on-change, optimistic single + bulk delete, record peek, empty-page recovery) **minus** the Mercure realtime block.

The first consumer is the **User module** (`context/backoffice/user/` + `app/backoffice/users/` + the public `app/(auth)/` pages), which binds an `InMemoryUserRepository` into the DI container and is backend-swap-safe: an `Api*Repository` later fills the same ports with no consumer change. Design record: [`docs/superpowers/specs/2026-06-14-iam-user-management-frontend-design.md`](superpowers/specs/2026-06-14-iam-user-management-frontend-design.md).

### Where shared code goes (decision rule)

Cross-cutting code has several homes; pick by **purpose**, not just "is it reused". Mirrored in [`pwa/CLAUDE.md`](../pwa/CLAUDE.md).

| Put it in… | When it is… | Examples |
| --- | --- | --- |
| `context/shared/<Module>/infrastructure/` (kernel infra: `context/shared/infrastructure/`) | backed by a **domain port** / swappable adapter, or part of a port-backed module | `Notification`, `DateTimeProvider`, `Validation`, `Observability` (capability modules); `HttpClient`, `DependencyInjection` (kernel) |
| `app/_components/` (or a route's own `_components/`) | a **landing/marketing** presentational component (its own raw-palette + `tw-animate-css` / CSS language) used only by its `app/` route — co-located, not shared | `Navbar`, `Footer`, `FeatureCard` |
| `components/erpify/` | an **entity-agnostic backoffice / app-shell design-system primitive**, reused across surfaces, barrel-exported from `@/components/erpify` | `DataTable`, `AsyncBoundary`, `EmptyState`, `StatusBadge`, `Spinner`, `Logo`, `SidebarItem`, `StatCard` |
| `components/ui/` | a raw **Shadcn** primitive | `button`, `dialog`, `input` |
| `src/lib/` | a **pure helper or generic hook** with no domain identity | `safeHref`, `useDebouncedValue`, `utils` |

The back-office (token-driven Shadcn + `@/components/erpify`) and the landing/marketing surface (raw-palette + `tw-animate-css` / CSS, under `app/_components/`) are two deliberate design languages — use the one matching the surface; don't cross-import. App-shell primitives reused by both (e.g. `Logo`) live in `@/components/erpify`. The former `context/shared/infrastructure/ui/components/` folder (atomic-design `atoms`/`molecules`/`organisms`) was retired: app-shell primitives → `@/components/erpify`, marketing components → `app/_components/`.

The `Notification` module (`context/shared/{domain,infrastructure}/Notification/`) provides transient user feedback. Its first channel is **Toast**: the `ToastNotifier` port with a Sonner adapter (`SonnerToastNotifier` + `SonnerToaster`, mounted once in the root layout) and the `toastNotifier` singleton. The naming leaves room for additional channels (`Banner`, `Push`) and alternative adapters without renaming the port.

### Observability — Telemetry seam

The `Observability` module provides a non-user-facing diagnostic channel for infrastructure failures (network errors, malformed payloads, authorization retries) that should never surface as UI toasts.

- **Port** — [`Telemetry.ts`](../pwa/src/context/shared/Observability/domain/Telemetry.ts) declares `warn(message, ctx?)` / `error(message, ctx?)` with an optional `TelemetryContext { scope?, cause? }`. `Domain/` depends only on this interface. The `cause` contract is adapter-dependent: a *local* adapter (console) may forward it as-is, but any *external/network* adapter (Sentry/Datadog) MUST serialize + scrub it before transmission.
- **Adapter** — [`ConsoleTelemetry.ts`](../pwa/src/context/shared/Observability/infrastructure/ConsoleTelemetry.ts) implements the port; it emits to `console.warn` / `console.error` when `NEXT_PUBLIC_APP_ENV` is `dev` or `staging`, and is a no-op in `prod` (or unknown). `NODE_ENV` alone cannot distinguish staging from prod — both build images run in `production` mode — so `NEXT_PUBLIC_APP_ENV` is the correct gate.
- **Decorator** — [`ThrottledTelemetry.ts`](../pwa/src/context/shared/Observability/infrastructure/ThrottledTelemetry.ts) wraps any `Telemetry` and coalesces a flood of identical diagnostics (same level + scope + message) into one emit per window (10s default), surfacing the suppressed count as a `(+N suppressed)` suffix on the next emit. It protects the console today and a metered Sentry/Datadog sink tomorrow. The backing key map is bounded (size cap + TTL sweep, defaults 1000 keys / 1h) so a future dynamic-keyed call site can't grow it without limit in a long-lived tab.
- **Singleton** — `telemetry` exported from `@/context/shared/Observability/infrastructure` (`index.ts`) is the only instance: `ThrottledTelemetry` wrapping `ConsoleTelemetry`. A future Sentry/Datadog adapter (or a `CompositeTelemetry` fan-out) replaces the *wrapped* adapter behind this singleton — the throttle and every call site stay put. Adapter-selection-by-env, the `cause` scrub helper, and CSP `connect-src` widening are tracked in `_bmad-output/implementation-artifacts/deferred-work.md` for when the sink lands.
- **Realtime integration** — [`useMercureRealtime.ts`](../pwa/src/context/shared/RealTime/infrastructure/useMercureRealtime.ts) is a generic hook centralising Mercure authorize / subscribe / reconnect-reauth for all entity-specific realtime hooks. Entity hooks (e.g. [`bankRealtime.ts`](../pwa/src/context/backoffice/bank/infrastructure/bankRealtime.ts)) delegate to it by supplying `{ topics, authorizePath, parse, onEvent, scope }`. Failures previously swallowed silently — subscription skipped, cookie refresh failed, malformed payload — are now routed through `telemetry.warn` with the hook's `scope` for traceability.

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
- `app/backoffice/` is the backoffice route segment. `/backoffice/health` is the internal admin health page: same Atlassian-style status presentation as `/status`, but in the backoffice design language (design tokens + `@/components/erpify`, never the marketing palette). It auto-runs `BackOfficeCheckHealth` on mount, renders an aggregate `SystemStatusBanner` plus a per-component `<StatusBadge>` row, and — being an internal diagnostic surface — keeps surfacing the technical `ProblemDetails` / correlation id on failure. Both status pages reuse the pure status model in `@/context/shared/system-status/domain/SystemStatus` (`SystemStatus`, `deriveSystemStatus`, `systemHeadline`, `componentStatusLabel`) and the styling-agnostic `StatusBannerView` (`@/components/status/`), each passing its own design-language theme (palette + layout) so structure is shared without crossing palettes.
- `app/status/` — public, unauthenticated service status page (Atlassian-style). Auto-runs the existing `FrontOfficeCheckHealth` use case on mount, renders an aggregate banner and per-component rows. Presentation components live in `app/status/_components/` in the marketing design language (raw Tailwind palette, not `@/components/erpify`). Linked from the navbar and footer. Distinct from the internal admin `app/backoffice/health/` page.

## Dependency injection

- Inversify 8 container assembled in `src/context/shared/dependency-injection/infrastructure/`.
- `tsconfig.json`: `strict: true`, `experimentalDecorators: true`, `emitDecoratorMetadata: true` (required for Inversify class decorators).
- `reflect-metadata` imported once at the React entry point (`app/layout.tsx`).

## Data fetching & integration

- Same-origin under FrankenPHP in dev/prod: `/api/*` is served by Symfony on `localhost`.
- Server-side fetches use `SYMFONY_INTERNAL_URL` (Compose-internal); client-side fetches use the public URL.
- Mercure SSE consumed at `/.well-known/mercure` (same origin, JWT subscribed).

Every `/api/*` response is read once in `FetchHttpClient`, which publishes the Symfony profiler token from the `X-Debug-Token` / `X-Debug-Token-Link` headers through the `DebugTokenObserver` domain port (`context/shared/DebugToken/domain/`). A dev-only `<SymfonyDebugToolbar>`, mounted in `app/layout.tsx` behind `isDevToolsAvailable()`, subscribes to that port and on each new token fetches Symfony's `/_dev/wdt-loader/{token}` loader to mount the real interactive toolbar inside the PWA. In production the container binds an inert `NoopDebugTokenObserver` and the component is never mounted (dead-code-eliminated), and the prod API emits no debug-token headers. Under automated e2e the live-stack runs the PWA in dev mode, so the toolbar would mount and its profiler DOM (the AJAX panel grows a `<tbody><tr>` per `/api/*` call) would inflate document-wide Playwright locators such as `tbody tr`; Playwright therefore sends an `x-erpify-e2e` request header and the layout suppresses the toolbar for those runs via `isAutomatedTestRequest()`. Design record: [`2026-06-14-pwa-symfony-debug-toolbar-design.md`](superpowers/specs/2026-06-14-pwa-symfony-debug-toolbar-design.md).

### Server-driven filtered search

Filterable lists are **server-driven**: filtering, sorting, and keyset pagination are resolved by the API, not in the browser. The shared vocabulary mirrors the API's generic `filters[]` contract:

- `context/shared/Search/domain/` — `Filter` (discriminated union by `FilterOperator`:
  `eq | in | contains | gte | lte`) is the typed, framework-free vocabulary.
- `context/shared/Search/infrastructure/buildSearchParams.ts` — serializes a `Filter[]` into the exact wire
  grammar (`filters[N][field|operator|value]`; `filters[N][value][]` for `in`), returning a composable
  `URLSearchParams`. **Filters-only** since PR3 — it never emits `after`/`before` (W11): a cursor only ever
  reaches the API via a server link replayed verbatim, never one the client builds.
- **Cursor-only consumer (PR3, W11).** The API wire is cursor-only: the `pagination` envelope is
  `{ hasNext, hasPrev, count, links: { next, prev } }` (`context/shared/Search/domain/PageEnvelope.ts` +
  `PaginationLinks.ts`; `next`/`prev` are `string | null`, always present). There are **no** page numbers and
  **no** exposed cursor scalar — the opaque cursor lives inside `links.next`/`links.prev`. Two seams keep the
  client from ever decoding or reconstructing it:
  - **first page / query change** → the *domain* port `BankRepository.search(criteria)` is **link-free**
    (`criteria` = `filters` + `sort` + `direction` + `limit`, no cursor). It sends no `paginationMode`, so the
    API defaults to `LIGHT` (no `COUNT(*)`, `count: null`); request `paginationMode=detailed` only on a view that
    renders a total. `limit` is clamped to `WIRE_MAX_LIMIT` (100, mirror of the API ceiling, D-Cap).
  - **next / prev** → the *application* port `BankSearchNavigator.follow(link)`
    (`context/backoffice/bank/application/BankSearchNavigator.ts`; impl `ApiBankSearchNavigator`) re-sends
    `envelope.links.next!` / `links.prev!` **verbatim** after a same-origin/relative `safeHref` check — it never
    parses the link to extract a cursor or filters. The transport `string` lives in the application layer, never
    the domain port. See `context/backoffice/bank/infrastructure/ApiBankRepository.ts` as the reference consumer.

Two list-behaviour rules are load-bearing:

- **Discard the cursor when the query changes (W8).** Any change to a filter, the sort, or the page size resets
  to the first page via `search(criteria)` (no cursor) — so a stale cursor is dropped by construction. Only
  sequential prev/next navigation follows the last response's `links`. A `null` link renders as a **disabled
  (not hidden)** prev/next control (D-A11y, AR15 — see `pwa/CLAUDE.md`; `BanksPagination.tsx`).
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
