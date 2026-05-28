# Plan: make E2E always hit the live Docker stack; remove the `dev:e2e` path

> **Status:** planned, not implemented — to be done in a **separate PR** (not on
> the current `refactor-make-remove-deprecated` branch).
> **Date:** 2026-05-28
> **Branch to create:** `refactor/pwa-e2e-live-stack-only` (off `main`)

## Goal

`make pwa.test.e2e` targets `https://localhost` (the FrankenPHP stack) in every
environment — same-origin + TLS, identical to CI and production. Playwright no
longer auto-spawns its own Next dev server.

### Why

The current local default boots a host-spawned `npm run dev:e2e` (Next on
`http://127.0.0.1:3000`) whose in-page `fetch` still calls the Docker API at
`https://localhost`. That is a **cross-origin, plain-HTTP** topology that
**neither CI nor production uses** (both are same-origin behind FrankenPHP with
TLS). It also creates the recurring "`:3000` not `:80`" footgun the docs warn
about in three places. Contributors running E2E are expected to have the full
stack up (`make docker.up`), so the auto-spawn convenience is not worth the
fidelity gap.

## The one rule that prevents breaking this PR

`:3000` appears throughout the repo with **two unrelated meanings**. Only the
second is in scope.

| Meaning | Examples | Action |
|---|---|---|
| **In-container Next**, reverse-proxied by FrankenPHP (`pwa:3000`) | `compose.yaml` / `compose.dev.yaml` healthchecks, `CLAUDE.md`, `README.md`, `pwa/Dockerfile`, `docs/integration-architecture.md`, `docs/claude-code-quickref.md`, `docs/project-overview.md`, `docs/development-guide-pwa.md` (the `make pwa.dev` row), `docs-info/local-fullstack-traffic.md` | **KEEP — do not touch.** Production topology, unchanged. |
| **Host-spawned `dev:e2e`** Next on `127.0.0.1:3000` | `pwa/package.json` line 7, the `webServer` block, the `npm.dev.e2e` target, `.next-e2e`, the CORS `:3000` entries | **This PR removes these.** |

## Changes by file

### 1. `pwa/playwright.config.ts` (core)

- Default `baseURL` → `process.env.PLAYWRIGHT_BASE_URL ?? "https://localhost"`
  (drop the CI ternary and the `http://127.0.0.1:3000` fallback).
- Delete `localWebServerPort`, the `useWebServer` block, and the entire
  `...(useWebServer ? { webServer: {...} } : {})` spread.
- **Keep** `applyPlaywrightDotenvFiles()` + `parseDotenv` — they still load
  `PW_WORKERS` from `.env` / `.env.local`. (`existsSync` / `readFileSync` /
  `path` stay in use; `devices` stays.)
- Update the now-stale comment that mentions `dev:e2e` / `127.0.0.1:3000`.

### 2. `pwa/package.json`

- Remove line 7 (`"dev:e2e": "next dev --turbo -p 3000"`).
- `"clean": "rm -rf .next .next-e2e"` → `"rm -rf .next"`.

### 3. `pwa/next.config.ts`

- Remove the `NEXT_DIST_DIR` → `distDir` block. It existed only to isolate the
  host-spawned e2e build dir from the container's bind-mounted `.next/`.

### 4. `make/pwa.mk`

- Delete the `npm.dev.e2e` target and its `.PHONY` entry.
- Add a fast preflight to `pwa.test.e2e`, **guarded so it is skipped when an
  override is set** (CI sets `PLAYWRIGHT_BASE_URL`, so CI skips it):

  ```make
  @if [ -z "$(PLAYWRIGHT_BASE_URL)" ] && ! curl -skSf https://localhost/api/v1/health >/dev/null 2>&1; then \
      echo "✗ Stack not reachable at https://localhost — run 'make docker.up' first (or set PLAYWRIGHT_BASE_URL)."; exit 1; \
  fi
  ```

  (Same health endpoint CI uses in `.github/workflows/ci.yml`.)
- Update `pwa.clean.soft` / `pwa.clean.all` / `pwa.clean.sudo` help text and the
  surrounding comment to drop `.next-e2e`.

### 5. `.next-e2e` leftovers (no longer produced)

Remove the `.next-e2e` entries from:

- `pwa/.gitignore`
- `pwa/eslint.config.mjs`
- `pwa/tsconfig.json`

### 6. `pwa/.env.example`

- Drop the `webServer`-override note and the `PLAYWRIGHT_SYMFONY_*` vars (they
  only fed the spawned dev server). Keep `PW_WORKERS`.

### 7. Docs (descriptive text only)

- `docs/project-context.md`: the E2E-tests row, the ports-table "Next container
  (e2e target)" row (delete it), and the two runtime-gotcha bullets that warn
  about `:3000`.
- `docs/contribution-guide.md`: the "Playwright `baseURL: http://localhost:3000`"
  bullet.
- `docs/development-guide-pwa.md`: the E2E row and the `:3000` gotcha bullet
  (the `make pwa.dev` container row stays).
- `pwa/README.md`: the `reuseExistingServer` / `PLAYWRIGHT_SKIP_WEBSERVER`
  bullets under "E2E (Playwright)".

### 8. Test-comment touch-ups (comments only, no logic)

These comments describe the now-removed webServer / `127.0.0.1:3000` behavior:

- `pwa/tests/e2e/backoffice/banks-real-api-flows.spec.ts`
- `pwa/tests/e2e/backoffice/banks-real-api.spec.ts`
- `pwa/tests/e2e/fixtures/banks-real-api.ts`

## Explicit decision point — CORS `:3000` entries (recommend: OUT of scope)

`api/.env`, `api/.env.example`, and `api/config/packages/nelmio_cors.php` list
`http://localhost:3000` / `http://127.0.0.1:3000`. These existed for the
cross-origin `dev:e2e` page→API calls and are now dead — **but**
`ExceptionResponderFunctionalTest` and `ExceptionResponderListenerPriorityTest`
**assert** that `http://localhost:3000` is recognized as an allowed
cross-origin.

**Recommendation:** leave CORS untouched in this PR (harmless, and removing it
forces rewriting those functional tests with a different representative origin).
File a follow-up if the allowlist should be trimmed. Call this out in the PR
description so it reads as a conscious skip, not an oversight.

## Verification

1. `make docker.up` → `make pwa.test.e2e` passes against `https://localhost`.
2. With the stack **down**, `make pwa.test.e2e` fails fast with the friendly
   message (not a raw connection-refused).
3. `PLAYWRIGHT_BASE_URL=https://localhost CI=true make pwa.test.e2e CI_SHARD=1 CI_TOTAL_SHARDS=3`
   still works (CI path; preflight skipped).
4. `make pwa.quality` clean (ESLint does not trip on the removed `.next-e2e`
   ignore).
5. This sweep returns nothing except intended in-container `:3000` refs:

   ```bash
   grep -rn "dev:e2e\|next-e2e\|NEXT_DIST_DIR\|PLAYWRIGHT_SKIP_WEBSERVER" \
     --include='*.ts' --include='*.json' --include='*.mk' --include='*.md' . \
     | grep -v node_modules
   ```
6. Sanity-check that CI's `pwa-e2e` job is unaffected — it already sets `CI` +
   `PLAYWRIGHT_BASE_URL=https://localhost` and never used the webServer path.

## Notes

- No Markdown link targets change in the doc edits, but watch the IDE Markdown
  linter on them anyway.
- Per the repo's docs-sync rule, the `docs/` + `pwa/README.md` edits are
  mandatory parts of this PR, not optional.
