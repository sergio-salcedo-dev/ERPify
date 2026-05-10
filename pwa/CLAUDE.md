# pwa/CLAUDE.md — ERPify PWA (Next.js 16 App Router)

PWA-scoped guidance. Root [`../CLAUDE.md`](../CLAUDE.md) is authoritative for monorepo conventions, the Docker stack, and the full `make` target list — this file only covers PWA specifics. Also consult [`AGENTS.md`](AGENTS.md) and `../.cursor/rules/frontend.mdc`.

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
- `src/context/shared/` — cross-cutting code. Don't scatter shared utilities elsewhere.
- `src/components/` — reusable UI (Shadcn-based). `src/lib/` — framework glue.
- `tests/` — mirrors `src/` structure.

## Make targets (run from repo root)

- `make pwa.install` — `npm ci`. Auto-cleans the empty root-owned `pwa/node_modules/` that the dev compose volume leaves on the host.
- `make pwa.install.if-missing` — guard used as a prerequisite of `make dev` / `make dev.local`; runs `pwa.install` only when `pwa/node_modules/` is missing or unhealthy.
- `make pwa.dev` — Next dev (Turbopack, host :80). Pair with `make api-up-http` or use `make dev.local` (runs both).
- `make pwa.build` — production build.
- `make pwa.test` = `pwa.test.unit` (Vitest) + `pwa.test.e2e` (Playwright).
  - Single file: `make pwa.test.unit c='path/to/file.test.ts'`.
  - Watch mode: `make pwa.test.unit.watch`. Report viewer: `make pwa.test.e2e.reports`.
  - E2E sharding: `CI_SHARD=N CI_TOTAL_SHARDS=M make pwa.test.e2e`.
- `make pwa.lint` — ESLint + Prettier check. Fixers: `pwa.lint.eslint.fix`, `pwa.format.prettier.fix`.
- `make pwa.clean` — remove `node_modules`, `package-lock.json`, `.next` (destructive).

Full-stack targets (`make dev`, `make docker.up`, `make docker.down`, …) live in the root `Makefile` — see root `CLAUDE.md`.

## Env

- **Docker stack** (default): `NEXT_PUBLIC_SYMFONY_API_BASE_URL=https://localhost`, `SYMFONY_INTERNAL_URL=http://php:80` (set in Compose).
- **`make dev.local`** (host Next + Docker API on :8000): set in `pwa/.env.local`:
  - `NEXT_PUBLIC_SYMFONY_API_BASE_URL=http://localhost:8000`
  - `SYMFONY_INTERNAL_URL=http://localhost:8000`

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
its barrel (`@/components/erpify`). Framework-agnostic helpers live under
`src/lib/`. Reach for these from every entity instead of re-implementing them
locally:

- **Dates** — `formatDateTime` / `parseDdMmYyyy` / `startOfDdMmYyyy` /
  `endOfDdMmYyyy` from `@/lib/formatDate`. Render `created_at` / `updated_at`
  (and any other ISO timestamp) via `formatDateTime`; never call
  `new Date(...).toLocaleString()` directly in entity components.
- **Date filter inputs** — `<DateField>` from `@/components/erpify`. Renders
  the canonical `dd/mm/yyyy` text input with the right `pattern` /
  `inputMode` / `placeholder` / tooltip / `(dd/mm/yyyy)` label hint, and
  pairs with the `parseDdMmYyyy` helpers above.
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
    authoritative URL inventory (`/dev-tools`, `/dev-error-gallery`,
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
`src/lib/` for a pure helper) and export it from the matching barrel.

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
