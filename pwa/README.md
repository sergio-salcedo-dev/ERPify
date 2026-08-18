# ERPify PWA (Next.js)

Next.js **App Router** app. In the **default Docker stack**, the browser uses **http(s)://localhost** on the host; **FrankenPHP** in the **`php`** container reverse-proxies HTML to this app on **pwa:3000** and serves **`/api/*`** in Symfony (same origin).

## Prerequisites

- **Node.js 20+** and npm (for local dev and tests).
- **Docker** (optional) for the full stack from the repo root.

## Quick start

1. **`make pwa.install`** (or **`npm ci`** from `pwa/`).
2. Copy [`.env.example`](.env.example) to **`.env.local`** when you need overrides.
3. **Against Docker (full stack on host 80/443)**
   From repo root: **`make docker.up.wait`** (or **`make app.dev`** for down → install → up --wait → fix ownership).
   Use **`NEXT_PUBLIC_API_BASE_URL=https://localhost`** (or **`http://localhost`**) so the browser matches the page origin. **`SYMFONY_INTERNAL_URL=http://php:80`** is set in Compose for the **pwa** container.

## Scripts

Prefer the root **`make`** targets — they are the canonical interface and wrap these npm scripts:

| Make target                 | npm script                                       | Description                                        |
| --------------------------- | ------------------------------------------------ | -------------------------------------------------- |
| `make pwa.dev`              | `npm run dev`                                    | Next dev (Turbopack, :80)                          |
| `make pwa.production.build` | `npm run build`                                  | Production build                                   |
| `make pwa.test.unit`        | `npm run test`                                   | Vitest (single run)                                |
| `make pwa.test.e2e`         | `npm run e2e`                                    | Playwright (see below)                             |
| `make pwa.quality`          | `npm run lint` + `lint:graph` + Prettier + `tsc` | ESLint + dependency-cruiser + Prettier + typecheck |
| `make pwa.lint.graph`       | `npm run lint:graph`                             | dependency-cruiser boundary gate over `src`        |

Pass extra args with **`c='…'`**, e.g. `make pwa.test.unit c='path/to/file.test.ts'`.

## E2E (Playwright)

`baseURL` is resolved in `playwright.config.ts`, never hard-coded in a spec:

```
PLAYWRIGHT_BASE_URL ?? (CI ? "https://localhost" : "http://127.0.0.1:3000")
```

- **Default local run**: `http://127.0.0.1:3000`. Playwright spawns `npm run dev:e2e` itself — `useWebServer` is on when there is no `CI`, the URL is `http://`, and `PLAYWRIGHT_SKIP_WEBSERVER` is not `1`. `reuseExistingServer` skips the spawn when something already answers there. The API stays on the Compose stack regardless.
- **Against the full stack on HTTPS**: `PLAYWRIGHT_BASE_URL=https://localhost`. No web server is spawned — Next dev cannot serve HTTPS — so Compose must already be up. Use this for anything that needs same-origin cookies: `https://localhost` cookies never reach a browser on `http://127.0.0.1:3000`.
- **CI**: sets `CI=true` and `PLAYWRIGHT_BASE_URL=https://localhost` (root [`.github/workflows/ci.yml`](../.github/workflows/ci.yml)).
- `PLAYWRIGHT_SKIP_WEBSERVER=1` never spawns the dev server, even for `http://` base URLs.

## Docs

- [docs/production-deployment.md](docs/production-deployment.md)
- Repo root [README.md](../README.md) and [docs-info/local-fullstack-traffic.md](../docs-info/local-fullstack-traffic.md)
- [Reproducible `erpify.local` prod deploy](../docs/erpify-local-test-deployment.md) and the [production security checklist](../PRODUCTION_SECURITY_CHECKLIST.md)
