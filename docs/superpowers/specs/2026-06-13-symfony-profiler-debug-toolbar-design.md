# Symfony Profiler & Debug Toolbar — design

**Date:** 2026-06-13 · **Branch:** `feat/symfony-profiler-and-debug-tool-bar-rwfw` · **Scope:** `api/` (+ root Compose/Caddy, docs)

## Goal

Give developers the Symfony Profiler and a visible Web Debug Toolbar for the ERPify
HTTP API, **strictly in `dev` + `test`** — never loaded in production.

## The JSON reality (why this isn't the stock recipe)

The API returns JSON only and has no Twig in production (the security checklist forbids
server-side HTML). Two consequences drive the design:

- The floating **Web Debug Toolbar injects into HTML responses only**. `/api/*` is JSON,
  so the bar cannot appear on API responses, and the PWA's pages are served by Next.js
  (not Symfony) so Symfony cannot inject into them either. To *see* a toolbar we serve one
  small **dev-only HTML page from Symfony** (`/_dev`); its **AJAX panel** captures `/api/*`
  calls fired from that page, each with timing, query count, and a profiler link.
- The Profiler web UI at **`/_profiler`** inspects every request regardless of content
  type (Doctrine queries, timeline, Messenger, serializer, security, logs). Every response
  also carries `X-Debug-Token` / `X-Debug-Token-Link`.

## Decisions (settled with the user)

1. Install the **full `symfony/profiler-pack`** (web-profiler-bundle + twig-bundle +
   debug-bundle; `stopwatch` + `var-dumper` already present transitively).
2. Enable in **`dev` + `test`** (test collection stays on-demand → ~zero overhead, but
   functional/Behat tests can assert on collected data).
3. Provide a **dev-only `/_dev` HTML page** so the real floating toolbar is visible.
4. Wire the **`var-dumper` server** (`bin/console server:dump`) so `dump()` output is
   collected out-of-band instead of corrupting JSON responses.
5. **Defer** surfacing the toolbar inside the Next.js PWA → follow-up issue (separate PR
   touching `pwa/` + dev-only CSP relaxation).

## Changes

### Dependency
- `composer require --dev symfony/profiler-pack`. Flex unpacks it (`allow-contrib: true`,
  repo carries no `*-pack` entries) into individual `require-dev` constraints.

### Bundle registration — `config/bundles.php`
- `WebProfilerBundle`, `TwigBundle`, `DebugBundle` → `['dev' => true, 'test' => true]`.
  This deliberately overrides the Twig recipe's default `TwigBundle => ['all' => true]`,
  which would pull Twig into prod and break the Twig-free-prod invariant. Same dev/test
  pattern as the existing Alice/fixtures bundles.

### Config — `config/packages/`
- `web_profiler.yaml` — recipe defaults: `when@dev` (toolbar + intercept_redirects),
  `when@test` (`collect: false`, toolbar off).
- `twig.yaml` — **scoped to `when@dev` + `when@test`** with
  `default_path: '%kernel.project_dir%/templates'` (needed for the `/_dev` template); a
  global `twig:` key would error in prod where TwigBundle is not loaded.
- `debug.yaml` — `when@dev: debug: dump_destination: "tcp://%env(VAR_DUMPER_SERVER)%"`.

### Routes — `config/routes/`
- `web_profiler.yaml` — recipe `when@dev` routes (`_wdt`, `_profiler`).
- `dev/dev_home.yaml` — **dev-only** route `/_dev` mapped to the framework's built-in
  `Symfony\Bundle\FrameworkBundle\Controller\TemplateController` (no custom controller
  class → the domain/clean-architecture layers stay untouched), template `dev/home.html.twig`.

### Template — `templates/dev/home.html.twig`
- Minimal HTML page: heading, link to `/_profiler/latest`, and a **"Run sample API call"**
  button that `fetch()`es an existing read endpoint under `/api/v1/...` (same origin → no
  CORS) so the toolbar's AJAX panel populates. Toolbar auto-injects (HTML response in dev).

### var-dumper server
- `api/.env.dev` → `VAR_DUMPER_SERVER=127.0.0.1:9912` (versioned dev default; prevents an
  unresolved-env error when `debug.yaml` is read).
- Make target `php.dump-server` → `bin/console server:dump` inside the `php` container
  (listens on `127.0.0.1:9912`; the web worker connects within the same container).
- Safe when not running: `ServerDumper` falls back to inline dumping, so `dump()` never
  breaks on a missing server. Dumps also appear in the profiler's Debug/Dump collector.

### Caddy — `api/frankenphp/Caddyfile`
- Add `/_dev*` to the `@pwa` exclusion list (next to `/_profiler*`, `/_wdt*`) so Symfony,
  not Next.js, serves it. One line.

### Quality gate — `tools/composer-require-checker/composer-require-checker.json`
- Add the 3 bundle FQCNs to `symbol-whitelist` (referenced in the scanned `bundles.php`
  but dev-only deps), mirroring the existing fixtures/alice entries.

### Docs
- `docs/development-guide-api.md` (+ `docs/claude-code-quickref.md` for the new make
  target): how to reach `/_dev` and `/_profiler`, the `php.dump-server` workflow, the
  dev+test scope, and the JSON-no-inline-toolbar caveat.

## Out of scope

- **Toolbar inside the Next.js PWA** — deferred to a follow-up issue (separate PR:
  PWA HTTP interceptor reading `X-Debug-Token`, loading `/_wdt/{token}`, dev-only CSP
  relaxation, prod-build exclusion).
- Any production profiling.

## Success criteria

- `GET https://localhost/_dev` (browser) renders HTML with the **floating toolbar**; the
  "Run sample API call" button fires `fetch` to `/api/*` and the call appears in the AJAX
  panel with a profiler link (verified via Playwright screenshot).
- `curl -I` an `/api/v1/...` endpoint → `X-Debug-Token` header present.
- `GET /_profiler/latest` (`Accept: text/html`) → 200 HTML (reaches Symfony, not the PWA).
- `dump('x')` in dev with `make php.dump-server` running → appears in the dump server
  terminal and the profiler Dump panel, not in the JSON body.
- A `prod`-env `cache:clear`/container build → Twig, profiler, and debug bundle **not**
  loaded; no missing-env or missing-extension errors.
- `make php.stan` clean on `bundles.php`; `make php.quality` clean (require-checker
  passes); `make php.unit` smoke boots the test env with the new bundles.

## Risks / verification notes

- **FrankenPHP worker mode (dev).** The HTTP service runs long-lived workers with
  `APP_DEBUG=1`. Verify the profiler resets per request (kernel reset writes one profile
  per request to `var/cache/dev/profiler`) and that the `src/**/*.php` / `config/**`
  watch-reload picks up the new config without a stale-container crash (cf. the
  messenger_worker `APP_DEBUG=0` mitigation — that service is separate and unaffected).
- **composer-unused** may flag the dev bundles (referenced only via `bundles.php` string).
  The existing dev bundles set the precedent; mirror whatever handling they rely on, or
  confirm the gate passes as-is during `make php.quality`.
