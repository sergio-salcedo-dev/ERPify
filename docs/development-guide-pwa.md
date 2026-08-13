# Development Guide — PWA (`pwa/`)

All commands below are run from the **repo root** via the root `Makefile`. The Make layer decides whether to exec inside the `pwa` container.

## Prerequisites

- Docker + Docker Compose (v2) — default flow runs Next inside the container.
- GNU Make.
- **Node.js ≥ 24.15.0 + npm 12 on the host** — required. Vitest and Playwright run host-only via `$(pwa_cmd)`; there is no container variant (rationale: `make/CONVENTIONS.md` §8). Versions are pinned in `.nvmrc` (Node) and `pwa/package.json#engines`; `nvm use` reads the former. 24.15.0 is the floor npm 12 declares for the 24.x line.

### Node/npm across host, CI and container

npm is pinned to **12.0.1** on all three surfaces — the host (`.nvmrc` + `engines`), CI (`.github/actions/node-setup`) and the `pwa` image (`pwa/Dockerfile` installs it globally). One npm resolves `package-lock.json` everywhere, so a lockfile written on a dev machine is the one CI and the image consume.

**Dependency install scripts are blocked by default.** npm 12 skips `preinstall`/`install`/`postinstall` for any dependency not listed in `package.json#allowScripts`, and ends every install with the list it skipped — so the warning below is the expected steady state, not a broken install:

```
@google/genai (preinstall: no-op) · @sentry/cli (postinstall: downloads the sentry-cli binary)
protobufjs (postinstall) · unrs-resolver (postinstall: selects a native binding)
```

None of the four is load-bearing: the `@google/genai` preinstall is a literal no-op, `protobufjs` only prints a version-scheme warning, and the `@sentry/cli` / `unrs-resolver` postinstalls are **fallback binary downloaders** — both packages ship the real artifact as a platform `optionalDependency` (`@sentry/cli-linux-x64`, `@unrs/resolver-binding-linux-x64-gnu`), which npm installs normally. `sentry-cli` therefore stays functional on the host and in the image, so Sentry source-map upload remains available when `SENTRY_AUTH_TOKEN` is eventually wired.

Review the list with `npm install-scripts ls`; `npm install-scripts approve|deny <pkg>` writes a pinned entry to `allowScripts`. Approve only after reading the script — the default-deny is a supply-chain control, not friction to route around. A platform without a prebuilt optional binary (e.g. a non-glibc or non-x64 image) would need an explicit approval to fall back to the download.

**Node major is deliberately *not* aligned.** The `pwa` image pins upstream Node by digest, currently **26.x on Debian 13**, while host and CI run the **24 LTS** line. Both satisfy `engines.node` (`^24.15.0 || >=26.0.0`) and npm 12's own range, so dependency resolution is identical — but the runtime executing `next build` and `node server.js` in containers is a different Node major from the one the unit suite validates on the host. Weigh that when debugging a failure that reproduces in Docker but not locally (or vice versa). corepack is unavailable in the image (Node no longer bundles it), which is why the npm pin is an explicit global install rather than a `packageManager` field.

## First-time setup (Docker flow, default)

```bash
make docker.up                 # full stack on http(s)://localhost (FrankenPHP fronts Next)
make pwa.install               # install npm deps into the pwa container
```

Browser opens at `http://localhost` (and `https://localhost`). Accept the dev certificate if prompted.

## Run / build / tests

| Task                 | Command                     | Notes                                                    |
|----------------------|-----------------------------|----------------------------------------------------------|
| Next dev (container) | `make pwa.dev`              | Turbopack on `:3000` inside `pwa`; proxied by FrankenPHP |
| Production build     | `make pwa.production.build` | `next build`                                             |
| Install deps         | `make pwa.install`          | `npm ci`                                                 |
| Unit tests           | `make pwa.test.unit`        | Vitest; optional `c='path/to/file.test.ts'`              |
| Unit — watch         | `make pwa.test.unit.watch`  |                                                          |
| E2E tests            | `make pwa.test.e2e`         | Playwright — targets **`:3000`**                         |
| Playwright reports   | `make pwa.test.e2e.reports` |                                                          |
| Unit + E2E           | `make pwa.test`             |                                                          |

## Lint / format

```bash
make pwa.quality                    # ESLint + dependency-cruiser + Prettier + tsc
make pwa.lint                       # ESLint --fix
make pwa.lint.graph                 # dependency-cruiser boundary gate over pwa/src (check-only)
make pwa.format                     # Prettier --write
```

ESLint 10 + `eslint-config-next` + Prettier are **authoritative** — do not hand-format against them.

## Directory discipline

```text
pwa/src/
├── app/                    # Next.js App Router — routes & UI shells only (no business logic)
├── components/ui/          # Shadcn primitives + shared UI
├── context/                # Business logic per bounded context
│   └── <bc>/
│       ├── domain/         # Interfaces + entities/value objects (framework-free)
│       ├── application/    # Use cases, orchestration
│       └── infrastructure/ # Adapters, Inversify bindings
└── lib/                    # Glue / utilities only
```

- **App Router only.** No `pages/` directory.
- **No default exports under `src/context/**`** — named exports only (Next's `page.tsx`/`layout.tsx` are the exception).
- Shared cross-cutting code belongs in `src/context/shared`, not ad-hoc folders.

## Dependency injection

- **Inversify 8** with constructor injection of **domain interfaces**.
- `reflect-metadata` imported **once** at the app entry.
- `tsconfig.json` already has `experimentalDecorators` + `emitDecoratorMetadata`.
- Bindings live per bounded context (e.g. `src/context/<bc>/infrastructure/container.ts`), composed into the root container under `src/context/shared/infrastructure/`.

## Styling & UI

- **Tailwind 4**: CSS-first. **No `tailwind.config.js`** — configuration lives in `pwa/src/app/globals.css` via `@theme {}` / `@config`.
- Shadcn UI primitives in `src/components/ui/`; extend locally, do not fork upstream.
- BEM class naming (`block__element--modifier`) on top of Tailwind utilities.
- Compose classes with `cn()` (clsx + tailwind-merge) — never string-concatenate class names.
- Mobile-first. Accessibility: semantic HTML, keyboard nav, visible focus, color contrast.

## Forms

- `react-hook-form` + `@hookform/resolvers`.
- Validate at the resolver layer; do not trust client-supplied IDs in Server Actions.

## Server vs Client boundary

- Default is **Server Component**. Add `'use client'` only when state, effects, browser APIs, or event handlers are required.
- Mark server-only modules with `import 'server-only'`.
- Server Actions (`'use server'`) live in server-only modules.
- Prefer Server Components + direct fetch / DI-resolved services over client-side fetch.

## Environment variables

| Var                                | Scope           | Purpose                            |
|------------------------------------|-----------------|------------------------------------|
| `NEXT_PUBLIC_API_BASE_URL` | Client + server | API base URL the browser uses      |
| `SYMFONY_INTERNAL_URL`             | Server only     | URL used for SSR / RSC fetches     |
| `NEXT_PUBLIC_*`                    | Public          | Must not contain secrets           |
| Any other var                      | Server only     | Never read from a client component |

## Critical rules to load before coding

Load [`project-context.md`](./project-context.md) before generating code. Key callouts for the PWA:

- Next 16 / React 19 / Tailwind 4 / Inversify 8 / TS 6 are beyond most training data — **read existing code before inventing patterns**.
- **Playwright targets `:3000`**, not `:80`. `baseURL: http://localhost:3000`.
- No `React.FC`, no `enzyme`, no shallow rendering; use Testing Library with role/label/text queries.
- Turbopack is the dev bundler; Webpack-specific `next.config.*` entries silently no-op.
- `reflect-metadata` imported once; don't re-import per module.
- Mercure client must subscribe via same-origin `/.well-known/mercure`.
- API errors are RFC 9457 Problem Details ([`api-error-contract.md`](./api-error-contract.md)) — switch UI logic on the body's `type` (opaque, stable), not on status code or message text. Capture `correlation-id` (body or `X-Correlation-Id` header) for support tickets.
