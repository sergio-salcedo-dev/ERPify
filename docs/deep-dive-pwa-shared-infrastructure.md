# Deep-Dive: PWA `shared/infrastructure`

> Generated 2026-05-08 by `/bmad-document-project`. Exhaustive analysis of every file under `pwa/src/context/shared/infrastructure/`. Pair with [`architecture-pwa.md`](./architecture-pwa.md) (high-level layering) and [`api-error-contract.md`](./api-error-contract.md) (the wire format this code consumes).

## 1. Scope and intent

`shared/infrastructure` is the cross-cutting **outermost layer** of the PWA — the only place allowed to depend on Next, Inversify, `fetch`, and Shadcn UI primitives. It contains three orthogonal sub-systems that every bounded context relies on:

| Sub-system                                                   | Files | Purpose                                                                                                                                           |
|--------------------------------------------------------------|-------|---------------------------------------------------------------------------------------------------------------------------------------------------|
| **DI container** (`DependencyInjection/`)                    | 1     | Single Inversify container, wires `HttpClient` + per-context use cases. Bootstrapped by `src/app/layout.tsx` via `import "reflect-metadata"`.     |
| **HTTP transport** (`HttpClient/` + `ApiRoutes.ts`)          | 2     | `HttpClient` port + `FetchHttpClient` / `MockHttpClient` adapters. `ApiRoutes` centralises Symfony URL paths so contexts never hard-code strings. |
| **Shared UI** (`ui/components/{atoms,molecules,organisms}/`) | 8     | Atomic-design React components shared across `app/` segments. BEM class names, Shadcn-based, motion-animated.                                     |

**Out of scope for this layer (per `pwa/CLAUDE.md` rule):** business logic, routing, persistence. Those live in per-context `application/` and `infrastructure/`. **Domain code may not import from this folder.**

## 2. File inventory

Total in scope: **11 files / ~640 LOC**.

### 2.1 `ApiRoutes.ts` — 17 LOC

- **Purpose:** Single source of truth for Symfony API paths. Mirrors `api/config/routes.yaml`. Replaces ad-hoc string literals scattered across context adapters.
- **Exports:**
  - `API_PREFIX_V1 = "/api/v1"` — `as const`, used to prefix every v1 route.
  - `ApiRoutes` — `as const` nested record: `v1.frontoffice.health`, `v1.backoffice.health`.
- **Imports:** none.
- **Used by:** `frontoffice/health/infrastructure/ApiHealthCheckRepository.ts`, `backoffice/health/infrastructure/ApiHealthCheckRepository.ts`, `HttpClient/HttpClient.ts` (used by `MockHttpClient` to discriminate canned responses).
- **Side effects:** none.
- **Contributor note:** When you add a new bounded-context endpoint (PRD epics 5+), add the path here first, then reference `ApiRoutes.v1.<bc>.<endpoint>` from the repository. Do **not** template URLs in adapters — search `MockHttpClient` for the URL discriminator pattern; you must extend it too or mocks break silently in tests.
- **Risks:** `as const` makes the literal types narrow — if you reference a route that doesn't yet exist on the API, TS will catch the typo, but adding a path here without the matching `routes.yaml` entry will compile fine until runtime.

### 2.2 `DependencyInjection/Container.ts` — 33 LOC

- **Purpose:** Build & export the singleton Inversify `Container`. Binds `HttpClient` (Fetch or Mock based on env) and the per-context use-case wiring (`FrontOfficeCheckHealth`, `BackOfficeCheckHealth`) plus their repositories.
- **Exports:**
  - `container: Container` — pre-configured Inversify container. Intended for `container.get<T>(token)` at the App Router entry-point boundary only.
- **Imports:** `reflect-metadata` (side-effect; required by Inversify decorators), `inversify.Container`, `HttpClient/HttpClient` (`FetchHttpClient`, `MockHttpClient`, `HttpClient` interface), `frontoffice/health` + `backoffice/health` `ApiHealthCheckRepository` + `CheckHealth`.
- **Used by:** `src/app/page.tsx`, `src/app/backoffice/health/page.tsx` (via `container.get<CheckHealth>(...)`).
- **Side effects:**
  - Reads `process.env.NODE_ENV` and `process.env.VITEST` at module-load time → picks `MockHttpClient` in tests, `FetchHttpClient` everywhere else. Decision is **frozen at first import**; toggling the env later in the same process has no effect.
  - Returns a **singleton container** — every module gets the same instance.
- **Bindings (DI symbols, all string-keyed):**
  - `"HttpClient"` → `FetchHttpClient` | `MockHttpClient` (singleton)
  - `"FrontOfficeHealthCheckRepository"` → `FrontOfficeApiHealthCheckRepository` (singleton)
  - `"BackOfficeHealthCheckRepository"` → `BackOfficeApiHealthCheckRepository` (singleton)
  - `"FrontOfficeCheckHealth"` → use case (transient — no `inSingletonScope()`)
  - `"BackOfficeCheckHealth"` → use case (transient)
- **Contributor note:** The container imports concrete classes from sibling contexts. **This makes `shared/infrastructure` depend on every other context's `infrastructure/` and `application/` layers** — it is the composition root. When you add a new bounded context, add the bindings *here*, not in the context's own folder. Use the `FrontOfficeXxx` / `BackOfficeXxx` import-rename pattern when symbols collide between contexts.
- **Risks:**
  - String tokens are **not type-checked at the binding ↔ inject seam**. A typo in `@inject("FrontOfficeHealthCheckRepository")` will compile and only fail at runtime. Consider migrating to `Symbol`-based identifiers (or a typed `TYPES` const map) before the container grows beyond ~10 bindings.
  - `BackOfficeCheckHealth` and `FrontOfficeCheckHealth` are bound *transient*. If you start to inject them deeply, the lack of singleton scope means a new instance per `.get()` — fine today (no state) but watch for accidental in-flight caching.
  - The env-based mock toggle relies on `process.env.VITEST === "true"`; if a future test runner doesn't set that flag, you'll hit real HTTP in tests. Prefer overriding the binding at the test boundary if you switch runners.
- **Verification:** `make pwa.test.unit` (mock path) and `make pwa.dev` then exercise `/` & `/backoffice/health` (fetch path).

### 2.3 `HttpClient/HttpClient.ts` — 79 LOC

- **Purpose:** HTTP transport port + two adapters. The PWA's only sanctioned outbound HTTP client. Origin-aware: same-origin in browser, internal Compose hostname on the server (SSR/RSC).
- **Exports:**
  - `interface HttpClient { get<T>(url: string): Promise<T> }` — minimal port; only `GET` for now.
  - `class FetchHttpClient implements HttpClient` (`@injectable`) — production adapter. Resolves base URL once in the constructor.
  - `class MockHttpClient implements HttpClient` (`@injectable`) — test adapter. Returns canned `{data: {status, service, datetime}}` envelopes after a 500 ms `setTimeout`, discriminated by URL substring against `ApiRoutes`.
- **Internal helpers (not exported):**
  - `trimBase(url)` — strips trailing `/`.
  - `browserApiBase()` — reads `NEXT_PUBLIC_SYMFONY_API_BASE_URL`, falls back to `https://localhost`. Public env, leaks to the client bundle by Next convention.
  - `serverApiBase()` — reads `SYMFONY_INTERNAL_URL` (e.g. `http://php:80` inside Compose); falls back to `browserApiBase()`.
- **Imports:** `inversify.injectable`, `../ApiRoutes`.
- **Used by:** `frontoffice/health/infrastructure/ApiHealthCheckRepository.ts`, `backoffice/health/infrastructure/ApiHealthCheckRepository.ts`. Always via `@inject("HttpClient")` — never instantiated directly.
- **Side effects:** Real network I/O via `fetch` (`FetchHttpClient`), `setTimeout` in `MockHttpClient`.
- **Origin-resolution rule:** `typeof window !== "undefined" ? browserApiBase() : serverApiBase()`. SSR/RSC use the internal URL; browser code uses the public one. **Resolved once per container lifetime.**
- **Contributor note:**
  - `get<T>` returns the **raw JSON-decoded body**. There is currently **no parsing of RFC 9457 `application/problem+json`** — `!res.ok` throws a generic `Error("HTTP <status>")`, dropping `correlation-id`, `instance`, and `type`. The API error contract requires consumers to surface those fields; until `HttpClient` parses Problem Details, every adapter has to do it manually (today no adapter does — see the `// TODO` in `app/backoffice/health/page.tsx`).
  - When you add `post<T>` / `put<T>` / `delete<T>`, do it on the same port and wire body serialisation + `Content-Type: application/json` here, not in callers.
- **Risks (must fix before non-trivial usage):**
  - **No Problem Details parsing.** Errors lose `correlation-id`, breaking the cross-stack debug story documented in [`api-error-contract.md`](./api-error-contract.md). High-priority cleanup.
  - **`cache: "no-store"` is unconditional.** Fine for health/dynamic data; will fight Next.js's RSC fetch cache for any future read-mostly endpoint. Make it caller-controlled.
  - **No `Accept: application/problem+json`.** Add it once you parse Problem Details, otherwise content negotiation may regress in unrelated upgrades.
  - **No request-side `correlation-id` header.** The API generates one if missing, but the PWA could propagate one for full-stack tracing.
- **Suggested tests:** add `tests/context/shared/infrastructure/HttpClient/HttpClient.test.ts` covering: (a) browser vs. server base-URL resolution, (b) trailing-slash trimming, (c) leading-slash normalisation in `get`, (d) `!res.ok` throws, (e) Problem Details decoding once implemented.

### 2.4 `ui/components/atoms/Button.tsx` — 69 LOC

- **Purpose:** App-wide animated button. Built on `motion/react` (Framer Motion successor) with 7 variants × 5 sizes. Distinct from `components/ui/button.tsx` (raw Shadcn primitive) — this one adds motion + project tokens.
- **Exports:** `Button: React.FC<ButtonProps>` — extends `HTMLMotionProps<"button">` (sans drag/animation/style props), adds `variant`, `size`, `loading`.
- **Imports:** React, `motion/react` (`motion`, `HTMLMotionProps`), `@/lib/utils.cn`.
- **Used by:** `ui/components/molecules/FeatureCard.tsx`, `ui/components/organisms/Navbar.tsx`. (Not used by `components/erpify/*` — those use the Shadcn `Button` directly.)
- **Side effects:** none beyond motion.
- **BEM:** `btn`, `btn--primary|secondary|outline|emerald|slate|ghost|link`, `btn--sm|md|lg|xl|icon`.
- **Contributor note:** `loading={true}` swaps children with the literal string `"Loading..."` — no spinner. If you need a spinner, extend here so every consumer benefits. There are leftover comment notes (lines 46–50) musing about whether to wrap Shadcn's button — clean those up next time you touch the file.
- **Risks:** Two `Button` components in the codebase (this one + `components/ui/button.tsx`). Easy to import the wrong one. The codebase's `BackOfficeLayoutClient` and `health/page.tsx` use the Shadcn one; landing/`Navbar`/`FeatureCard` use this one. Keep an eye on drift.

### 2.5 `ui/components/atoms/Logo.tsx` — 26 LOC

- **Purpose:** Brand logo + wordmark, wraps `next/link`. Accepts custom `children` to swap the icon (used by `Navbar` to wrap a coloured pill around the icon).
- **Exports:** `Logo: React.FC<LogoProps & { children?: React.ReactNode }>`.
- **Imports:** React, `next/link`, `lucide-react.ShieldCheck`.
- **Used by:** `ui/components/organisms/Navbar.tsx`, `app/backoffice/BackOfficeLayoutClient.tsx`.
- **Hard-coded text:** `"Erpify"`. If marketing renames, grep for the literal — it appears here and in `Footer`.
- **Default `href`:** `"/"`. BackOffice consumers should pass `href="/backoffice"` if they want the brand to anchor to the dashboard.

### 2.6 `ui/components/molecules/FeatureCard.tsx` — 63 LOC

- **Purpose:** Hero card on the landing page surfacing one tenant feature (icon, title, description, CTA button, optional extra slot).
- **Exports:** `FeatureCard: React.FC<FeatureCardProps>`.
- **Imports:** React, `motion/react`, `lucide-react.LucideIcon`, sibling `../atoms/Button`, Shadcn `Card*` from `@/components/ui/card`, `@/lib/utils.cn`.
- **Used by:** `app/page.tsx` (landing page).
- **BEM:** `feature-card`, `feature-card__container|header|icon-wrapper|icon|title|description|content|button|extra-content`.
- **Contributor note:** `loading` and `onClick` are forwarded to the inner `Button`. The `children` slot renders below the button (`feature-card__extra-content`) — used by `app/page.tsx` to show health-check status text after the CTA fires.

### 2.7 `ui/components/molecules/PlaceholderCard.tsx` — 30 LOC

- **Purpose:** "Empty/coming-soon" tile. Used to fill the back-office dashboard grid before real widgets exist.
- **Exports:** `PlaceholderCard: React.FC<PlaceholderCardProps>`.
- **Imports:** React, `lucide-react.LucideIcon`, Shadcn `Card`, `CardContent`.
- **Used by:** `app/backoffice/page.tsx`.
- **BEM:** `placeholder-card`, `placeholder-card__content|icon-wrapper|icon|title|description`.
- **Contributor note:** Distinct from `components/erpify/EmptyState.tsx` — this one is a static dashboard tile; `EmptyState` is the canonical zero-data placeholder for data-bound views (lists, tables). Don't merge them.

### 2.8 `ui/components/molecules/SidebarItem.tsx` — 115 LOC ✅ `"use client"`

- **Purpose:** Recursive sidebar entry with optional `subItems` accordion. Auto-opens when `isActive` and has children. Reads `next/navigation.usePathname` to highlight active sub-items.
- **Exports:** `SidebarItem: React.FC<SidebarItemProps>`.
- **Imports:** React (`useState`, `useEffect`), `next/navigation.usePathname`, `lucide-react` (`LucideIcon`, `ChevronRight`, `ChevronDown`).
- **Used by:** `app/backoffice/BackOfficeLayoutClient.tsx`.
- **State:** local `isOpen` boolean, opens automatically when `isActive && hasSubItems`.
- **BEM:** `sidebar-item`, `sidebar-item--active`, `sidebar-item__content|icon|name|chevron|sub-items|sub-item`.
- **Contributor note:** Only **one level of nesting** is supported (`subItems: SubItem[]`, no `subItems` on `SubItem`). If product asks for deeper trees, refactor `SidebarItem` to recurse on itself before bolting on `subSubItems`.
- **Edge cases (verified by code reading):**
  - `isCompact && hasSubItems`: clicking the parent navigates to the parent's `path` rather than expanding (no flyout panel today). Worth flagging UX.
  - `isCompact` hides the chevron (no accordion in collapsed sidebar) — visually correct, but the `useEffect` still toggles `isOpen` invisibly.

### 2.9 `ui/components/molecules/StatCard.tsx` — 45 LOC

- **Purpose:** Animated KPI tile (name, big value, coloured icon). Staggered enter animation via `index * 0.1` delay.
- **Exports:** `StatCard: React.FC<StatCardProps>`.
- **Imports:** React, `motion/react`, `lucide-react.LucideIcon`, Shadcn `Card`, `CardContent`, `cn`.
- **Used by:** `app/backoffice/page.tsx`.
- **BEM:** `stat-card`, `stat-card__container|content|icon-wrapper|icon|name|value`.
- **Contributor note:** `value` is `string`, not `number` — caller is responsible for formatting/locale. If you start computing values in components, consider a `<StatCard.Loading />` skeleton variant before adding async logic here.

### 2.10 `ui/components/organisms/Footer.tsx` — 34 LOC

- **Purpose:** Static landing-page footer (logo + 3 stub links + copyright).
- **Exports:** `Footer: React.FC`.
- **Imports:** React, `lucide-react.ShieldCheck`. **Does not reuse `Logo`** — duplicates the icon + text inline.
- **Used by:** `app/page.tsx`.
- **Hard-coded:** `"© 2026 Erpify SaaS. All rights reserved."` — annual update required. Footer links (`href="#"`) are placeholders.
- **Contributor note:** Refactor opportunity — replace the inline logo with `<Logo>` so brand changes propagate from one place.

### 2.11 `ui/components/organisms/Navbar.tsx` — 129 LOC

- **Purpose:** Sticky landing-page top bar — desktop nav + user dropdown + CTA + mobile hamburger drawer.
- **Exports:** `Navbar: React.FC<NavbarProps>` — `onGetStarted: () => void` callback.
- **Imports:** React (`useState`), `motion/react` (`motion`, `AnimatePresence`), `lucide-react` icons, sibling `../atoms/Logo` + `../atoms/Button`, Shadcn `dropdown-menu`.
- **Used by:** `app/page.tsx`.
- **State:** local `isMenuOpen` boolean for the mobile drawer.
- **BEM:** `navbar`, `navbar__container|inner|logo|logo-icon|logo-text|menu|link|user-trigger|user-dropdown|user-label|user-item|button|mobile-toggle|mobile-menu`.
- **Hard-coded:** `navLinks = [Features, Pricing, About]` (all `href="#"`), `My Account`, `Profile`, `Settings`, `Support`, `Log out`. Stub UX until Epic-N adds real auth.
- **Contributor note:** `Logo` is rendered with custom `iconClassName` and `children` (a coloured pill wrapping `ShieldCheck`) — that's the canonical override pattern. The dropdown items wire to nothing — when auth lands, route them to real handlers.

## 3. Dependency graph

### 3.1 Internal (within `shared/infrastructure`)

```dot
ApiRoutes.ts          ──┐
                        ├─► HttpClient/HttpClient.ts
                      [used by adapters in other contexts]

Container.ts ─► HttpClient/HttpClient.ts (FetchHttpClient, MockHttpClient, HttpClient)
             ─► [external] frontoffice/health/{infrastructure,application}
             ─► [external] backoffice/health/{infrastructure,application}

ui/atoms/Logo.tsx
ui/atoms/Button.tsx
       ▲
       │
ui/molecules/FeatureCard.tsx ──► atoms/Button
ui/molecules/PlaceholderCard.tsx
ui/molecules/SidebarItem.tsx
ui/molecules/StatCard.tsx
ui/organisms/Navbar.tsx ──► atoms/Logo, atoms/Button
ui/organisms/Footer.tsx
```

**Entry points (modules nothing else inside `shared/infrastructure` imports):** `Container.ts`, every UI organism (`Navbar`, `Footer`), `PlaceholderCard`, `SidebarItem`, `StatCard`, `FeatureCard`. (Yes, all leaves except `ApiRoutes`, `Button`, `Logo`, `HttpClient`.)

**Leaves (modules that import nothing inside scope):** `ApiRoutes.ts`, `Button.tsx`, `Logo.tsx`, `Footer.tsx`, `PlaceholderCard.tsx`, `SidebarItem.tsx`, `StatCard.tsx` (all `*.tsx` UI files only depend on Shadcn/Lucide/motion + `@/lib/utils`).

**Circular dependencies:** none.

### 3.2 External — who imports `shared/infrastructure`

| Importer | Symbols | Notes |
|---|---|---|
| `src/app/page.tsx` | `container`, `Navbar`, `Footer`, `FeatureCard` | Landing page. Calls `container.get<CheckHealth>("FrontOfficeCheckHealth")` on CTA. |
| `src/app/backoffice/page.tsx` | `StatCard`, `PlaceholderCard` | Back-office dashboard grid. |
| `src/app/backoffice/health/page.tsx` | `container` | Resolves `BackOfficeCheckHealth`. |
| `src/app/backoffice/BackOfficeLayoutClient.tsx` | `Logo`, `SidebarItem` | Backoffice shell. |
| `src/context/frontoffice/health/infrastructure/ApiHealthCheckRepository.ts` | `ApiRoutes`, `type HttpClient` | Adapter; injected via `@inject("HttpClient")`. |
| `src/context/backoffice/health/infrastructure/ApiHealthCheckRepository.ts` | `ApiRoutes`, `type HttpClient` | Same pattern. |

`shared/infrastructure` has **no test coverage** (`tests/context/shared/infrastructure/` does not exist). This is a gap — see §6.

### 3.3 External — what `shared/infrastructure` depends on

- **NPM:** `inversify` (8.x), `reflect-metadata`, `motion` (12.x, ex-Framer), `lucide-react`, `next` (16.x, `Link`, `usePathname`), `react` (19.x).
- **Project:** `@/lib/utils.cn` (`clsx + twMerge` wrapper), Shadcn primitives in `@/components/ui/{card,dropdown-menu}`.
- **Sibling contexts (only Container.ts):** `frontoffice/health/{application,infrastructure}`, `backoffice/health/{application,infrastructure}`. Cross-context coupling is **load-bearing** — this is the composition root.

### 3.4 Layer-rule check

Per `pwa/CLAUDE.md`: *"`Domain/` must not import from Next, Inversify, `fetch`, or any infrastructure."*

- ✅ Nothing in scope imports `domain/` directly.
- ✅ The two `shared/domain/` files (`ProblemDetails.ts`, `ValueObject.ts`) have **zero infrastructure imports**.
- ⚠ `Container.ts` is the only place that imports across contexts (`frontoffice/health` *and* `backoffice/health`). That's intentional — it's the composition root — but document it loudly when onboarding.

## 4. Data-flow analysis

### 4.1 Outbound HTTP (canonical request path)

```
React component (e.g. app/page.tsx)
  └─► container.get<CheckHealth>("FrontOfficeCheckHealth")
        └─► CheckHealth.run()             [application]
              └─► HealthCheckRepository.check()  [domain port]
                    └─► ApiHealthCheckRepository.check()   [infra adapter]
                          ├─ ApiRoutes.v1.frontoffice.health
                          └─► httpClient.get<{data: HealthCheckData}>(path)
                                └─► FetchHttpClient
                                      ├─ baseUrl = browserApiBase() | serverApiBase()
                                      ├─ fetch(`${baseUrl}${path}`, {Accept: "application/json", cache: "no-store"})
                                      └─ throws Error("HTTP <status>") on !res.ok   ← ⚠ swallows Problem Details
```

In tests (`NODE_ENV==="test"` || `VITEST==="true"`), the same call resolves to `MockHttpClient.get`, which discriminates on URL substring against `ApiRoutes` and resolves `{data: {status, service, datetime}}` after 500 ms.

### 4.2 Origin resolution

| Run mode | `NEXT_PUBLIC_SYMFONY_API_BASE_URL` | `SYMFONY_INTERNAL_URL` | Browser base | Server (RSC) base |
|---|---|---|---|---|
| Docker `make app.dev` | `https://localhost` (Compose default) | `http://php:80` | `https://localhost` | `http://php:80` |
| Vitest | (irrelevant — `MockHttpClient` is bound) | — | — | — |
| Production | `https://<public-host>` | `http://php:80` (Compose internal) | public host | internal hostname |

Trailing slashes are trimmed. Leading slash on the path is normalised in `FetchHttpClient.get`.

### 4.3 Error path (current vs. target)

**Current:**
- API returns `application/problem+json` body with `type`, `title`, `status`, `detail`, `instance`, `correlation-id`, optional `violations`.
- `FetchHttpClient.get` checks `!res.ok`, **discards the body**, throws `new Error("HTTP 422")`.
- Caller sees a stringly-typed error, has no `correlation-id` to surface in the toast.

**Target (per [`api-error-contract.md`](./api-error-contract.md) and the live `// TODO` in `app/backoffice/health/page.tsx`):**
- On `!res.ok && content-type startsWith "application/problem+json"`, parse the body, guard with `isProblemDetails` (already implemented in [`shared/domain/ProblemDetails.ts`](../pwa/src/context/shared/domain/ProblemDetails.ts)), throw a typed `ProblemError extends Error` carrying the envelope.
- Components route on `problem.type`, render `<ProblemDisplay>` (already exists at `components/erpify/ProblemDisplay.tsx`), and surface `correlation-id` via `<CorrelationIdChip>`.

**Suggested home for the new typed error:** `pwa/src/context/shared/infrastructure/HttpClient/ProblemError.ts`. Keep the `ProblemDetails` *type* in `shared/domain/`; the throwable wrapper belongs in infrastructure.

### 4.4 UI composition flow

```
app/layout.tsx ─ "use client" ─ "import 'reflect-metadata'"
  └─ children
       ├─ app/page.tsx (landing) → Navbar(Logo, Button) + FeatureCard(Button) + Footer
       ├─ app/backoffice/BackOfficeLayoutClient.tsx → Logo + SidebarItem (recursive)
       ├─ app/backoffice/page.tsx → StatCard + PlaceholderCard
       └─ app/backoffice/health/page.tsx → container.get(...)
```

The container is **only resolved inside `app/`**. No deeper code calls `container.get`. Good — keeps the DI-resolution boundary tight.

## 5. Integration points

| Channel | Counterpart | Mechanism | Notes |
|---|---|---|---|
| HTTP `GET /api/v1/...` | Symfony API | `fetch` via `FetchHttpClient` | Same-origin in browser (FrankenPHP reverse-proxy); internal `http://php:80` from RSC. |
| Mercure SSE | Symfony API at `/.well-known/mercure` | **Not yet wired in `shared/infrastructure`.** The architecture allows for it; today no PWA code subscribes. Add an `EventSourceClient` here when needed. |
| `process.env.NODE_ENV` / `VITEST` | Vitest, Next runtime | Picked up at module-load to swap `HttpClient` adapter | See §2.2 Risks. |
| `process.env.NEXT_PUBLIC_SYMFONY_API_BASE_URL` | Compose / `pwa/.env.local` | Browser base URL | Public — leaks to client bundle. |
| `process.env.SYMFONY_INTERNAL_URL` | Compose service network | RSC base URL | Server-only. |

No outbound integration outside the Symfony API.

## 6. Testing analysis

- **Unit tests for `shared/infrastructure`:** ❌ none.
- **Unit tests for sibling code:** present (e.g. `tests/components/erpify/AsyncBoundary.test.tsx`, `ProblemDisplay.test.tsx`).
- **Coverage gaps (highest value first):**
  1. `HttpClient` — origin resolution, error path, future Problem Details parsing. Vitest, no DOM needed.
  2. `Container` — verify `MockHttpClient` is bound when `VITEST==="true"`, snapshot the `bind` set so accidental wiring drift is loud.
  3. `SidebarItem` — accordion logic + `isCompact` edge cases. Vitest + `@testing-library/react`.
  4. `Button` — `loading` swap, variant/size class application. Trivial smoke test.
- **E2E:** the Playwright suite covers the landing-page flow indirectly through `app/page.tsx`.
- **Suggested commands:**
  - `make pwa.test.unit c='context/shared/infrastructure'` — once tests exist, runs only this surface.
  - `make pwa.quality` — ESLint + Prettier; mandatory before merging.

## 7. Related code & reuse opportunities

### 7.1 Look here before adding new infrastructure code

| You want to add… | Look at first |
|---|---|
| Another HTTP verb (`post`/`put`/`delete`) | `HttpClient/HttpClient.ts` — extend the port + both adapters together. |
| A new bounded-context endpoint | `ApiRoutes.ts` (path), `MockHttpClient` URL discriminator, the `frontoffice/health/infrastructure/ApiHealthCheckRepository.ts` template. |
| A new use case wired to React | Mirror `frontoffice/health/application/CheckHealth.ts` (`@injectable`, `@inject` repo by string token), bind in `Container.ts`. |
| A new sidebar entry with sub-items | `SidebarItem.tsx` already supports 1 level — see `BackOfficeLayoutClient.tsx` for the consumer shape. |
| A new card variant | Compare `FeatureCard` vs. `PlaceholderCard` vs. `StatCard` first to pick the right base; consider extracting a shared `<Card.Frame>` if you find yourself copying their `Card className="…rounded-2xl shadow-sm"` chain a third time. |
| A new error renderer | `components/erpify/ProblemDisplay.tsx` and `AsyncBoundary.tsx` already exist — they are the canonical RFC 9457 UI surface. Wire `HttpClient` to a typed `ProblemError` rather than rebuilding the renderer. |

### 7.2 Reusable patterns established here (worth copying for new contexts)

- **Re-export with rename to disambiguate cross-context symbols** (`Container.ts`):
  ```ts
  import { ApiHealthCheckRepository as FrontOfficeApiHealthCheckRepository } from "...";
  ```
- **String DI tokens namespaced by `<Office><Concept>`** — `FrontOfficeHealthCheckRepository`, `BackOfficeCheckHealth`. Keep this until the token soup justifies a `TYPES` map.
- **Atomic-design BEM naming** — `block__element--modifier`. Mirrors the `pwa/CLAUDE.md` rule literally; every component in scope follows it.
- **`as const` URL tables** (`ApiRoutes.ts`) — narrow string literal types fall out for free, plus a single grep target when routes change.
- **Env-toggled adapter binding** — `MockHttpClient` for tests, `FetchHttpClient` for everything else. Copy this shape if you add a new outbound integration (e.g. an analytics SDK that should be a no-op in tests).

### 7.3 Anti-patterns to avoid (observed in scope)

- **Two `Button` components.** This file's `Button` (motion + custom variants) and `components/ui/button.tsx` (raw Shadcn). Pick one per consumer; don't mix in the same file.
- **Footer duplicates the logo.** Use `<Logo>` instead.
- **Stringly-typed `loading` UI.** `Button` renders the literal `"Loading..."` — fix once a real spinner exists.
- **Generic `Error("HTTP <status>")`.** Drops every diagnostic the API contract was designed to carry.

## 8. Per-file contributor checklist (before changing this folder)

- [ ] **Did you touch `Container.ts`?** Re-run `make pwa.dev` and exercise `/`, `/backoffice`, `/backoffice/health` — wiring failures don't show up in unit tests today.
- [ ] **Did you touch `HttpClient.ts`?** Verify the base-URL path (`make app.dev` browser) and the test bind (`make pwa.test.unit`).
- [ ] **Did you touch `ApiRoutes.ts`?** Cross-check `api/config/routes.yaml`. Update `MockHttpClient` URL discrimination if the new path is one your tests will hit.
- [ ] **Did you touch a UI component?** BEM names must follow `block__element--modifier`. Verify the consumer renders unchanged in a real browser — Vitest will not catch motion / layout regressions.
- [ ] **Did you add a binding?** Bind via string token, scope to singleton unless the symbol carries per-call state, and document the token next to the binding (no central registry yet).
- [ ] **Did you remove a binding?** Grep `@inject("…")` for the token across all contexts before deleting — TS will not catch a stale string.
- [ ] **Run `make pwa.quality` before pushing.**

## 9. Known follow-ups (file an issue if you don't do them now)

1. **Parse RFC 9457 in `HttpClient`.** Replace the generic `Error` with a `ProblemError` that carries the envelope. Wire `app/backoffice/health/page.tsx` to drop its synthesised fallback.
2. **Add `tests/context/shared/infrastructure/`.** At least cover `HttpClient` origin resolution + error path.
3. **Migrate string DI tokens to a typed `TYPES` map** (e.g. `Symbol.for("HttpClient")` exported from `DependencyInjection/types.ts`). Do it before the container exceeds ~15 bindings.
4. **Refactor `Footer` to use `<Logo>`.** One brand-change vector instead of two.
5. **Extract a `<Card.Frame>` primitive** if a fourth project-specific card variant appears.
6. **Decide between this `Button` and `components/ui/button.tsx`.** Either consolidate, or document the use-case.

---

**File count:** 11 in scope (+ 2 referenced from `shared/domain/`).
**LOC analysed:** ~640 in scope (+ ~60 in `shared/domain/`).
**External consumers:** 6 PWA files import this surface.
**Generated:** 2026-05-08 by `/bmad-document-project` deep-dive workflow.
