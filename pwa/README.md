# ERPify PWA (Next.js)

Next.js **App Router** app. In the **default Docker stack**, the browser uses **http(s)://localhost** on the host; **FrankenPHP** in the **`php`** container reverse-proxies HTML to this app on **pwa:3000** and serves **`/api/*`** in Symfony (same origin).

## Prerequisites

- **Node.js 20+** and npm (for local dev and tests).
- **Docker** (optional) for the full stack from the repo root.

## Quick start

1. **`npm ci`**
2. Copy [`.env.example`](.env.example) to **`.env.local`** when you need overrides.
3. **Against Docker (full stack on host 80/443)**  
   From repo root: **`make up-wait`**.  
   Use **`NEXT_PUBLIC_SYMFONY_API_BASE_URL=https://localhost`** (or **`http://localhost`**) so the browser matches the page origin. **`SYMFONY_INTERNAL_URL=http://php:80`** is set in Compose for the **pwa** container.
4. **Local dev with API on :8000**  
   **`make api-up-http`** from repo root, then **`npm run dev`** (port **80** — see `package.json`). In **`.env.local`**: **`NEXT_PUBLIC_SYMFONY_API_BASE_URL=http://localhost:8000`**, **`SYMFONY_INTERNAL_URL=http://localhost:8000`**.

## Scripts

| Script        | Description                |
| ------------- | -------------------------- |
| `npm run dev` | Next dev (Turbopack, :80) |
| `npm run build` / `start` | Production build / start |
| `npm run test` | Vitest                     |
| `npm run e2e`  | Playwright (see below)     |

## E2E (Playwright)

- **CI / Docker stack**: **`CI=true`** and **`PLAYWRIGHT_BASE_URL=https://localhost`** (see root [`.github/workflows/ci.yml`](../.github/workflows/ci.yml)).
- **Local** with the full stack on **https://localhost**: set **`PLAYWRIGHT_BASE_URL=https://localhost`** — Playwright does not start **`npm run dev`** (Next dev only serves HTTP). With **`http://localhost`**, if something already listens on that URL, **`reuseExistingServer`** skips spawning the dev server.
- **Local** with **`npm run dev`** only: omit **`PLAYWRIGHT_BASE_URL`** (default **`http://localhost`**, port 80).
- Optional **`PLAYWRIGHT_SKIP_WEBSERVER=1`**: never spawn **`npm run dev`** even for **`http://`** base URLs.

## Docs

- [docs/production-deployment.md](docs/production-deployment.md)
- Repo root [README.md](../README.md) and [docs/local-fullstack-traffic.md](../docs/local-fullstack-traffic.md)
