# pwa/CLAUDE.md — ERPify PWA (Next.js 16 App Router)

PWA-scoped guidance. Root [`../CLAUDE.md`](../CLAUDE.md) is authoritative for monorepo conventions, the Docker stack,
and the full `make` target list — this file only covers PWA specifics. For UX, [`DESIGN.md`](DESIGN.md) is the
authoritative source — the **enterprise-first UX philosophy & UI review mandate**, the design-system contract
(tokens, composites, patterns), and the accessibility non-negotiables all live there.
Also consult [`../docs/rules/frontend.md`](../docs/rules/frontend.md).

## Stack

- **Next.js 16** (App Router) + **TypeScript** (strict).
- **Tailwind 4** + **Shadcn UI**. Styling follows **BEM** class naming (`block__element--modifier`), mobile-first.
- **Inversify** for DI — constructor-inject interfaces defined in `domain`.
- **Vitest** for unit tests, **Playwright** for E2E.

## Folder structure

- `src/app/` — Next.js App Router (routes, layouts, route handlers). Keep components here thin; push logic down.
- `src/context/<bounded-context>/{domain,application,infrastructure}/` — DDD core. Dependencies point inward:
  - `domain/` — pure types, value objects, interfaces. **No** Next, Inversify, HTTP, or ORM imports.
  - `application/` — use cases / orchestration; depends only on `domain`.
  - `infrastructure/` — adapters (HTTP clients, storage, framework glue).
- `src/context/shared/` — cross-cutting **domain/application/infrastructure** code (ports + adapters). Presentation primitives and pure glue live in `src/components/` and `src/lib/` instead — see the decision rule below.
- `src/components/` — reusable UI. `src/lib/` — framework glue.
- `tests/` — mirrors `src/` structure.

### Where shared code goes (decision rule)

Several homes exist for cross-cutting code; pick by **purpose**, not just "is it reused":

| Put it in…                                           | When it is…                                                                                                                                                     | Examples                                                                                                               |
| ---------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| `context/shared/infrastructure/<Module>/`            | backed by a **domain port** / swappable adapter, or part of a port-backed module                                                                                | `Notification/Toast`, `DateTimeProvider`, `HttpClient`, `Validation`, `DependencyInjection`, `Observability/Telemetry` |
| `app/_components/` (or a route's own `_components/`) | a **landing/marketing** presentational component (its own raw-palette + `tw-animate-css` / CSS language) used only by its `app/` route — co-located, not shared | `Navbar`, `Footer`, `FeatureCard`                                                                                      |
| `components/erpify/`                                 | an **entity-agnostic backoffice / app-shell design-system primitive**, reused across surfaces, barrel-exported from `@/components/erpify`                       | `DataTable`, `AsyncBoundary`, `EmptyState`, `StatusBadge`, `Spinner`, `Logo`, `SidebarItem`, `StatCard`                |
| `components/ui/`                                     | a raw **Shadcn** primitive                                                                                                                                      | `button`, `dialog`, `input`                                                                                            |
| `src/lib/`                                           | a **pure helper or generic hook** with no domain identity                                                                                                       | `safeHref`, `useDebouncedValue`, `utils`                                                                               |

Note: the back-office (token-driven Shadcn + `@/components/erpify`) and the landing/marketing surface (raw-palette + `tw-animate-css` / CSS, under `app/_components/`) are two deliberate design languages — reach for the one matching the surface you're building, don't cross-import. App-shell primitives reused by both (e.g. `Logo`) live in `@/components/erpify`. The former `context/shared/infrastructure/ui/components/` folder was retired: app-shell primitives (`Logo`, `SidebarItem`, `StatCard`) moved to `@/components/erpify`, marketing components (`Navbar`, `Footer`, `FeatureCard`) to `app/_components/`, and `PlaceholderCard` was folded into `<EmptyState>` (via its new optional `icon` prop).

## Make targets (run from repo root)

- `make pwa.install` — `npm ci`. Auto-cleans the empty root-owned `pwa/node_modules/` that the dev compose volume leaves on the host.
- `make pwa.install.if-missing` — guard used as a prerequisite of `make app.dev`; runs `pwa.install` only when `pwa/node_modules/` is missing or unhealthy.
- `make pwa.dev` — Next dev (Turbopack, host :80).
- `make pwa.production.build` — production build.
- `make pwa.test` = `pwa.test.unit` (Vitest) + `pwa.test.e2e` (Playwright).
  - Single file: `make pwa.test.unit c='path/to/file.test.ts'`.
  - Watch mode: `make pwa.test.unit.watch`. Report viewer: `make pwa.test.e2e.reports`.
  - E2E sharding: `CI_SHARD=N CI_TOTAL_SHARDS=M make pwa.test.e2e`.
- `make pwa.quality` — ESLint + Prettier check. Fixers: `pwa.lint` (ESLint --fix), `pwa.format` (Prettier --write).
- `make pwa.clean.all` — remove `node_modules`, `.next` (destructive).

Full-stack targets (`make app.dev`, `make docker.up`, `make docker.down`, …) live in the root `Makefile` — see root `CLAUDE.md`.

## Env

- **Docker stack** (default): `NEXT_PUBLIC_API_BASE_URL=https://localhost`, `SYMFONY_INTERNAL_URL=http://php:80` (set in Compose).
- `NEXT_PUBLIC_APP_ENV` (`dev` | `staging` | `prod`) — public, non-secret, baked at build (`pwa/Dockerfile` ARG fed from the same-named `NEXT_PUBLIC_APP_ENV` Compose build arg; set it per environment — `staging` on staging hosts enables console diagnostics, `prod` keeps them silent). Drives client telemetry verbosity; `NODE_ENV` can't distinguish staging from prod (the built image is always `production`).

## Rules that bite

- `Domain/` must not import from Next, Inversify, `fetch`, or any infrastructure.
- New bounded contexts follow the `domain`/`application`/`infrastructure` split — don't flatten into `src/app/` or `src/lib/`.
- Prefer functional components + hooks; strict TS types (no `any` unless justified).
- BEM class names — `.card__header--highlighted`, not arbitrary utility clusters that escape the component.

## Security review (mandatory on every change)

Every PR — even small fixes — runs the security checklist documented in
the root [`../CLAUDE.md`](../CLAUDE.md) ("Security review on every
change"). The frontend-specific items below are part of that
checklist; do not treat them as optional.

## XSS prevention rules

React escapes JSX text by default, but the framework does **not** block
script-bearing URL schemes and several attribute / sink categories remain
attack surface. Treat the following as load-bearing:

- **Dynamic `href` / `src`** — every URL whose value is influenced by API
  data, route params, query strings, or user input MUST go through
  `safeHref(value, fallback)` from `@/lib/safeHref` (rejects `javascript:`,
  `data:`, `vbscript:`, `file:` regardless of casing or whitespace
  obfuscation). Combine with `encodeURIComponent` on the dynamic segment
  when inserting it into a path. Never interpolate raw API data straight
  into an `href` template literal.
- **`router.push` / programmatic navigation** — same rule: wrap the URL in
  `safeHref` so a malicious `javascript:` payload cannot be navigated to.
- **`dangerouslySetInnerHTML`** — banned outside very specific, sanitized
  Markdown / SVG embeds. If you genuinely need it, the input MUST be
  produced by a vetted sanitizer (e.g. DOMPurify) and reviewed; do not
  reach for it for "richtext" or "format-as-bold" hacks.
- **`innerHTML` / direct DOM writes / `document.write` / `eval` /
  `new Function(string)`** — banned. Use React state instead.
- **Attributes that don't execute scripts (`title`, `aria-label`,
  `data-*`)** — safe to interpolate; React escapes them. Still keep
  static `aria-label`s where row context already conveys the resource
  name (see `BanksTable`'s actions cell).
- **Server response headers** — the security baseline lives in
  `next.config.ts#headers()`: a strict-ish `Content-Security-Policy`
  (`object-src 'none'`, `frame-ancestors 'none'`, `base-uri 'self'`,
  `form-action 'self'`, `upgrade-insecure-requests`), plus
  `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`,
  `Permissions-Policy`, COOP / CORP, HSTS. `script-src` keeps
  `'unsafe-inline'` for now to support Next.js hydration; the future
  state is a nonce-based CSP via `middleware.ts`. Do NOT add
  `'unsafe-eval'` outside development.
- **Clipboard / navigator APIs** — `CopyButton` is the canonical path;
  it never trusts the value as HTML. Don't compose your own
  `navigator.clipboard.writeText` flows from entity components.

## Shared building blocks (use these, don't reinvent)

UI-level primitives live under `src/components/erpify/` and are exported from
its barrel (`@/components/erpify`). Framework glue and generic hooks with no
domain identity (e.g. `safeHref`, `useDebouncedValue`) live under `src/lib/`.
Reach for these from every entity instead of re-implementing them locally:

- **Dates** — the `dateTimeProvider` singleton from
  `@/context/shared/infrastructure/DateTimeProvider`, typed as the
  `DateTimeProvider` port (never as the concrete `DateFnsDateTimeProvider`).
  No `date-fns` / `dayjs` / `Temporal` types leak past this boundary. Render
  `created_at` / `updated_at` (and any other ISO timestamp) via
  `dateTimeProvider.formatIsoToLocalDateTime(iso)` — it returns the raw input back
  on unparseable values so tables never show "Invalid Date". Use
  `formatToDisplay(date)` / `formatToDate(date)` for `Date` objects. Never
  call `new Date(...).toLocaleString()` directly in entity components.
  For glanceable "2 days ago" timestamps use `dateTimeProvider.formatIsoToRelative(iso)` and pair it with the absolute value in a `title` tooltip; never compute relative time inline.
- **Date filter inputs** — `<DateField>` from `@/components/erpify`. Renders
  the canonical `dd/mm/yyyy` text input with the right `pattern` /
  `inputMode` / `placeholder` / tooltip / `(dd/mm/yyyy)` label hint, and
  pairs with `dateTimeProvider.parseDdMmYyyyToStartTimestamp` /
  `parseDdMmYyyyToEndTimestamp` for inclusive filter bounds. For the native
  `<DatePickerField>` (`yyyy-mm-dd`) use `parseIsoDateToStartTimestamp` /
  `parseIsoDateToEndTimestamp` instead.
- **Copy-to-clipboard** — `<CopyButton value={…}>` from `@/components/erpify`.
  Handles the success / error feedback flip, the icon swap, the sr-only
  fallback, and the async-clipboard / `execCommand` path. Never call
  `navigator.clipboard.writeText` from an entity component directly.
- **Tables / boundaries / sheets** — `<DataTable>`, `<AsyncBoundary>`,
  `<RecordSheet>`, `<EmptyState>`, `<FormField>`, `<ProblemDisplay>`,
  `<StatusBadge>`, `<CorrelationIdChip>`, `<AppShell>`.
- **Error module** — every Next.js error surface (`not-found.tsx`,
  `error.tsx`, `global-error.tsx`, the Next 15+ `forbidden.tsx` /
  `unauthorized.tsx` convention files, plus the navigable
  `/maintenance`, `/rate-limited`, `/offline`, `/unauthorized`,
  `/unauthenticated` routes inside the `app/(errors)/` route group)
  composes a single bounded module:
  - `src/context/shared/error/domain/IconTone.ts` — pure domain types
    (`IconTone` constant + type).
  - `src/context/shared/error/infrastructure/ui/ErrorScreen.tsx` —
    responsive shell. Tune mobile / tablet / laptop / desktop / large
    breakpoints here, once.
  - `src/context/shared/error/infrastructure/ui/ErrorActions.tsx` —
    Client Component that renders the canonical 2-button row (primary
    `Home` outside `Routes.BACKOFFICE`, primary `Return to BackOffice`
    inside it; secondary `Go back` via `router.back()` with a fallback
    to the primary destination when there is no history).
  - One Screen / Boundary component per surface lives in the same
    `infrastructure/ui/` folder: `<NotFoundScreen>`,
    `<AccessDeniedScreen>` (HTTP 403), `<SignInRequiredScreen>` (HTTP
    401), `<SegmentErrorBoundary>` (HTTP 500), `<RootErrorBoundary>`
    (root-layout crash). The Next convention files at
    `app/{error,forbidden,global-error,not-found,unauthorized}.tsx`
    are **thin re-exports** of these — the JSX has a single source of
    truth that's discoverable next to the rest of the module. The
    navigable `app/(errors)/<slug>/page.tsx` routes import the same
    Screen so a `forbidden()` boundary and `/unauthorized` look
    identical by construction.
  - Exported via `@/context/shared/error/infrastructure/ui`. Do NOT
    re-export from `@/components/erpify` — keep the boundary explicit.
  - Local-test recipes for every error surface (and how to verify
    production redaction) live in
    [`docs/error-pages-testing.md`](docs/error-pages-testing.md). The
    matching automated coverage is
    [`tests/e2e/error-pages.spec.ts`](tests/e2e/error-pages.spec.ts);
    drive `error.tsx` deterministically via the dev-only `/dev-throw`
    fixture at `src/app/(errors)/dev-throw/page.tsx`.
  - The `error.tsx` / `global-error.tsx` boundaries must continue to gate
    `error.message` behind
    `process.env.NODE_ENV === NodeEnv.DEVELOPMENT` so production never
    leaks stack traces.
- **Toast notifications** — `toastNotifier` from
  `@/context/shared/infrastructure/Notification/Toast`, typed as the
  `ToastNotifier` port (never as the concrete `SonnerToastNotifier`). Call
  `toastNotifier.success("…")` / `.error` / `.info` / `.warning` from any
  client component for transient feedback; pass
  `{ description, durationMs, id }` via `ToastOptions`. The Sonner adapter
  (`SonnerToastNotifier` trigger +
  `SonnerToaster` viewport) is co-located under
  `src/context/shared/infrastructure/Notification/Toast/`; the viewport is
  mounted once in `app/layout.tsx`. Messages are plain strings rendered as
  escaped text — never pass HTML. To swap libraries, replace the two Sonner
  files and the singleton; the port and call sites stay put. Future channels
  (`Banner`/`Push`) are siblings under `domain/Notification/`.
- **Client telemetry** — `telemetry` from
  `@/context/shared/infrastructure/Observability`, typed as the `Telemetry`
  port (never the concrete `ConsoleTelemetry`). Call
  `telemetry.warn(message, { scope, cause })` / `.error(...)` for non-user-facing
  diagnostics. The console adapter emits only in `dev`/`staging` (gated by
  `NEXT_PUBLIC_APP_ENV`) and is silent in `prod`; future Sentry/Datadog adapters
  slot in behind the same port with no call-site changes. Realtime hooks route
  through it via `useMercureRealtime`
  (`@/context/shared/infrastructure/RealTime/useMercureRealtime`): supply
  `{ topics, authorizePath, parse, onEvent, scope }` and authorize + subscribe +
  reconnect-reauth + failure telemetry are handled for you (see `useBankRealtime`
  for the canonical wiring). Messages are plain strings; never pass secrets/PII in
  `cause`.
- **Dev Tools module** — internal QA / engineering hub at
  `https://localhost/dev-tools`, gated behind
  `isDevToolsAvailable()` (`process.env.NODE_ENV !== NodeEnv.PRODUCTION`).
  - `src/context/shared/dev-tools/domain/DevTool.ts` — `DevTool` /
    `DevToolGroup` types.
  - `src/context/shared/dev-tools/domain/isDevToolsAvailable.ts` —
    central env predicate. Use it everywhere that mounts a dev/QA
    surface (route file, navbar link, sidebar item) so the production
    gate stays consistent.
  - `src/context/shared/dev-tools/domain/devToolRoutes.ts` —
    authoritative URL inventory (`/dev-tools` and its nested tools,
    `/dev-throw`). Add a new dev URL here once and the middleware
    matcher + the page-level guard pick it up.
  - `src/context/shared/dev-tools/infrastructure/ui/devToolGroups.ts` —
    authoritative registry. Adding a new tool = a new entry here; the
    menu picks it up automatically.
  - `src/context/shared/dev-tools/infrastructure/ui/DevToolsMenu.tsx` —
    page UI. Re-exported from `app/dev-tools/page.tsx` (thin Next
    binding with a `notFound()` guard).
  - Entry points: a "Dev Tools" link in the frontoffice
    `<Navbar>` (rendered only when `isDevToolsAvailable()`) and a
    `Development` sidebar group with a `Dev Tools` item in
    `BackOfficeLayoutClient.tsx`. Both disappear in production builds.
  - **Production short-circuit** — `pwa/src/proxy.ts` (Next 16's
    successor to `middleware.ts`) rewrites every dev-tool URL to a
    guaranteed-unmatched path _before_ the page handler runs in
    production, so the branded `not-found.tsx` is served and the dev
    surface is unreachable even if a future contributor accidentally
    drops the page-level `isDevToolsAvailable()` check. Turbopack
    requires `config.matcher` to be a static literal, so the matcher
    array in `proxy.ts` is hardcoded — its parity with
    `DEV_TOOL_ROUTE_PREFIXES` is locked by
    [`tests/proxy.test.ts`](tests/proxy.test.ts) so a forgotten entry
    fails the build.
- **String constants** — never compare `process.env.NODE_ENV` against
  the literal `"development"` / `"production"` / `"test"`; use
  `NodeEnv` from `@/context/shared/domain/types/nodeEnv`. Never hard-
  code top-level paths (`/`, `/backoffice`) in shared infrastructure
  code (error pages, navigation guards, fallbacks); use `Routes` from
  `@/context/shared/domain/types/routes`. Entity-scoped paths
  (`/backoffice/banks/${id}`) stay next to the use case that builds
  them.
- **`buttonVariants` import path** — import from
  `@/components/ui/button-variants`, never from
  `@/components/ui/button` (the latter is `"use client"` and Next 16
  blocks server invocations of the cva helper). Import the `Button`
  component itself from `@/components/ui/button` as before.
- **UUIDs** — generate every client-side identifier with `uuidV7()` from
  `@/lib/uuidV7` (a thin wrapper over the `uuid` library). **Never call
  `crypto.randomUUID()`** — it always returns a UUID **v4**, while the
  whole stack is UUID **v7**: the API's persisted PKs and minted
  `correlation-id` are v7 (its `CorrelationIdListener` strictly rejects
  non-v7), and `ProblemDetails.instance` / `correlation-id` are typed as
  v7. This matters wherever the UI fabricates a fallback `ProblemDetails`
  (no response / non-ProblemDetails body). Keep the `uuid` import inside
  `@/lib/uuidV7` only — don't scatter `import … from "uuid"` across
  components.
- **Form validation** — `Validator` / `ZodValidatorAdapter` / `useZodForm`
  from `@/context/shared/infrastructure/Validation`. Each entity declares
  its own schema in `src/context/<bounded-context>/<entity>/application/schemas/`
  (e.g. `BankSchema.ts`) and exports a Zod schema **plus** the inferred
  `*FormValues` type. React components consume the schema via
  `useZodForm(schema, { defaultValues })` and the inferred type — they
  never import `zod` or `@hookform/resolvers/zod` directly. Use the same
  schema with `ZodValidatorAdapter` from non-React application services
  to validate API payloads end-to-end. Match the schema's per-field
  error messages to the strings the API returns in 422 responses so a
  single set of UI assertions covers both client- and server-side
  surfacing; map server `ProblemViolation`s onto RHF errors via
  `setError(field, { type: "server", message: violation.message })`.

When you need a new cross-entity primitive, add it to `components/erpify/` (or
`src/lib/` for a pure helper or generic hook) and export it from the matching
barrel.

## Test ID rules

QA scripts target controls by `data-testid`. The contract is simple: **every
static `data-testid` literal must be unique across the source tree** so that
two elements with the same id never end up on the same page (Playwright's
strict-mode locators fail with "more than one element matched" when they do).

- Use BEM-flavoured prefixes that already match the entity / surface
  (e.g. `banks-list__title`, `banks-detail__copy-id`,
  `banks-pagination__next`).
- For lists / tables, the testid MUST encode the row identity using the
  **backend entity id** (typically a UUID) from the API — for example
  ``data-testid={`banks-table__row-${row.id}`}`` for the row itself and
  ``data-testid={`banks-table__edit-${row.id}`}`` /
  ``data-testid={`banks-table__delete-${row.id}`}`` for the per-row
  actions. The entity id is unique by construction, so the rendered DOM
  stays unique. Do NOT emit a parallel `data-row-id` (or similar)
  attribute — the `data-testid` is the canonical row identity for QA.
  `<DataTable>` enforces this with its `rowTestId` prop.
- For reusable components, **never hardcode a testid**. Accept a `testId`
  prop and let the consumer set it (see `<DataTable testId rowTestId>`,
  `<CopyButton testId>`, `<DateField testId>`). Hardcoding traps every
  consumer into the same id and triggers the strict-mode failure mode.
- The guard `tests/data-testid-uniqueness.test.ts` walks `src/` at CI time
  and fails if a literal `data-testid="..."` appears in more than one file
  or more than once in the same file. Do not weaken or skip it.

## Accessibility rules for action buttons

Every interactive control that triggers an action (button, anchor styled as
button, dialog trigger, pagination control, form submit/cancel) must carry **all**
of the following:

- `title` — descriptive hover tooltip; include the resource name when meaningful
  (e.g. `title={\`Edit bank ${row.name}\`}`).
- `aria-label` — keep the accessible name **short and static** (`"Edit"`,
  `"Delete"`, `"Previous page"`). When the control lives inside a `role="cell"`
  or `role="row"`, the cell's accessible name is computed from descendant
  control names, so dynamic labels containing the row's text break Playwright's
  strict-mode locators (e.g. `getByRole("cell", { name: "Acme Savings" })`
  matches both the name cell and the actions cell). Put the dynamic part in
  `title` instead.
- A textual fallback for icon-only controls — either visible text, an
  `<span className="sr-only">…</span>`, or both — so the control still has a
  name when CSS fails to load.
- `aria-hidden="true"` on every decorative icon inside the control.
- For pagination/navigation controls that have no valid target (no previous or
  no next page), **hide** the control instead of rendering it disabled — a
  disabled control is still discovered by assistive tech and adds noise.

For destructive actions also wire the user through a confirmation dialog
(`Dialog.*` from `@/components/ui/dialog`) and surface API failures inside the
dialog via `<ProblemDisplay variant="inline" />` — never silently dismiss them.
