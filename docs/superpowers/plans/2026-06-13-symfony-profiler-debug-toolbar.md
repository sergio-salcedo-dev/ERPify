# Symfony Profiler & Debug Toolbar Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give developers the Symfony Profiler (web UI at `/_profiler`), a visible floating Web Debug Toolbar (on a dev-only `/_dev` HTML page), and an out-of-band `dump()` server — strictly in `dev` + `test`, never loaded in production.

> **Amendment (2026-06-14):** scope narrowed to **`dev` only** — the profiler in `test` broke Behat's per-scenario Doctrine query-count assertions. Bundles are `['dev' => true]`, the `when@test` blocks in `web_profiler.yaml`/`twig.yaml` are dropped, and `ProfilerEnabledFunctionalTest` is removed. The dev+test steps/verifications below are the original plan, kept for history.

**Architecture:** Install `symfony/profiler-pack` as a dev dependency; register its bundles `['dev'=>true,'test'=>true]` so prod never loads Twig/profiler. Because the API is JSON-only, the toolbar can't inject into `/api/*` responses — so we serve one tiny dev-only HTML page (`/_dev`, via the framework's built-in `TemplateController`, no domain code) whose AJAX panel captures `/api/*` calls. A new `make/profiler.mk` adds `profiler.open` (launch the UI) and `profiler.dump-server`.

**Tech Stack:** PHP 8.5 · Symfony 8 · FrankenPHP (worker mode, dev) · Caddy · Twig (dev/test only) · Make · Docker Compose · PHPUnit (WebTestCase).

**Spec:** `docs/superpowers/specs/2026-06-13-symfony-profiler-debug-toolbar-design.md`

---

## Prerequisites

- Work happens in this worktree on branch `feat/symfony-profiler-and-debug-tool-bar-rwfw`.
- The worktree's Docker stack must be **up** (container-exec `make` targets need it):

```bash
make docker.ps   # if php is not "running", start it:
make app.dev     # down → install → up --wait → fix ownership (this worktree's stack)
```

- All `make` commands are run from the repo root of this worktree.

## File Structure

| File | Action | Responsibility |
|------|--------|----------------|
| `api/composer.json`, `api/composer.lock` | modify (via composer) | add profiler bundles to `require-dev` |
| `api/config/bundles.php` | modify | register the 3 bundles as `['dev'=>true,'test'=>true]` |
| `api/config/packages/twig.yaml` | create/normalize | Twig config **scoped** to `when@dev`/`when@test` |
| `api/config/packages/web_profiler.yaml` | create/verify | profiler + toolbar config (recipe default) |
| `api/config/packages/debug.yaml` | create/verify | `dump()` → var-dumper server (`when@dev`) |
| `api/config/routes/web_profiler.yaml` | create/verify | `/_wdt`, `/_profiler` routes (`when@dev`) |
| `api/config/routes/dev.yaml` | create | `/_dev` page route (`when@dev`, `TemplateController`) |
| `api/templates/dev/home.html.twig` | create | the dev page that renders the toolbar + sample fetch |
| `api/templates/base.html.twig` | delete | unused recipe scaffold |
| `api/.env.dev` | modify | `VAR_DUMPER_SERVER=127.0.0.1:9912` |
| `api/frankenphp/Caddyfile` | modify | exclude `/_dev*` from the `@pwa` proxy |
| `api/tools/composer-require-checker/composer-require-checker.json` | modify | whitelist the 3 bundle FQCNs |
| `make/profiler.mk` | create | `profiler.open` + `profiler.dump-server` targets |
| `api/tests/Functional/Shared/Infrastructure/Profiler/ProfilerEnabledFunctionalTest.php` | create | pin profiler-in-test wiring |
| `docs/claude-code-quickref.md`, `docs/development-guide-api.md` | modify | document the targets + workflow |

---

### Task 1: Install profiler-pack and register bundles (dev/test only)

**Files:**
- Modify: `api/composer.json`, `api/composer.lock` (via composer)
- Modify: `api/config/bundles.php`

- [ ] **Step 1: Require the pack (dev), let Flex apply + unpack recipes**

Run:
```bash
make composer c='require --dev symfony/profiler-pack --no-interaction'
```
Expected: composer resolves and installs `symfony/web-profiler-bundle`, `symfony/twig-bundle`, `symfony/debug-bundle` (+ `twig/twig`); `auto-scripts` run `cache:clear` and `assets:install public` without error. The pack is unpacked (no `symfony/profiler-pack` line remains in `composer.json`).

- [ ] **Step 2: Confirm they landed under `require-dev`, not `require`**

Run:
```bash
cd api && python3 -c "import json;d=json.load(open('composer.json'));dev=d['require-dev'];print('web-profiler:',dev.get('symfony/web-profiler-bundle'));print('twig-bundle:',dev.get('symfony/twig-bundle'));print('debug-bundle:',dev.get('symfony/debug-bundle'));print('IN PROD?:',[k for k in d['require'] if k in('symfony/web-profiler-bundle','symfony/twig-bundle','symfony/debug-bundle')])"; cd ..
```
Expected: each prints a version (e.g. `8.0.*`); `IN PROD?: []`. If any bundle is under `require`, move it to `require-dev` (`composer remove <pkg>` then `composer require --dev <pkg>`), or hand-edit + `composer update --lock`.

- [ ] **Step 3: Normalize `config/bundles.php` to dev/test for all three**

Open `api/config/bundles.php`. Flex's twig recipe registers `TwigBundle => ['all' => true]` — change it. Ensure exactly these three entries exist inside the `$bundles` array (web-profiler and debug recipes already use `['dev'=>true,'test'=>true]`; fix Twig and de-duplicate):

```php
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['dev' => true, 'test' => true],
    Symfony\Bundle\WebProfilerBundle\WebProfilerBundle::class => ['dev' => true, 'test' => true],
    Symfony\Bundle\DebugBundle\DebugBundle::class => ['dev' => true, 'test' => true],
```

Place them after the `Sentry\SentryBundle\SentryBundle::class => ['dev' => true, 'prod' => true],` line and before the closing `];`. Leave the trailing `FriendsOfBehat` conditional block untouched.

- [ ] **Step 4: Verify the bundles file parses and gates correctly**

Run:
```bash
make sf c='debug:container --env=dev 2>&1 | grep -ci profiler' && grep -nE 'TwigBundle|WebProfilerBundle|DebugBundle' api/config/bundles.php
```
Expected: the grep prints all three lines, each ending in `['dev' => true, 'test' => true]`. (The `debug:container` count is informational — non-zero in dev.)

- [ ] **Step 5: Confirm generated profiler assets are ignored, not staged**

Run:
```bash
git status --short api/public/ ; git check-ignore api/public/bundles/webprofiler 2>/dev/null && echo "ignored OK"
```
Expected: `ignored OK`; no `api/public/bundles/...` appears as untracked. (`.gitignore` already lists `/public/bundles/`.) If `api/var/` churned, it's ignored too.

- [ ] **Step 6: Commit**

```bash
git add api/composer.json api/composer.lock api/config/bundles.php
git commit -m "feat(api): install symfony/profiler-pack (dev/test only)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Scope Twig/Debug config to dev+test; wire the var-dumper env

Prod must not try to load `twig:`/`debug:` config (the bundles aren't loaded there). We wrap them in `when@dev`/`when@test` and remove the unused base template.

**Files:**
- Create/overwrite: `api/config/packages/twig.yaml`
- Create/verify: `api/config/packages/web_profiler.yaml`
- Create/verify: `api/config/packages/debug.yaml`
- Create/verify: `api/config/routes/web_profiler.yaml`
- Delete: `api/templates/base.html.twig`
- Modify: `api/.env.dev`

- [ ] **Step 1: Write `api/config/packages/twig.yaml` (scoped, with a templates path)**

Overwrite the recipe's global file with:
```yaml
when@dev:
    twig:
        default_path: '%kernel.project_dir%/templates'

when@test:
    twig:
        default_path: '%kernel.project_dir%/templates'
        strict_variables: true
```

- [ ] **Step 2: Ensure `api/config/packages/web_profiler.yaml` matches this**

Create or overwrite with:
```yaml
when@dev:
    web_profiler:
        toolbar: true
        intercept_redirects: false

    framework:
        profiler:
            only_exceptions: false
            collect_serializer_data: true

when@test:
    web_profiler:
        toolbar: false
        intercept_redirects: false

    framework:
        profiler:
            collect: false
```

- [ ] **Step 3: Ensure `api/config/packages/debug.yaml` forwards dumps to the server**

Create or overwrite with:
```yaml
when@dev:
    debug:
        # Dumps are sent to the var-dumper server (make profiler.dump-server);
        # VarDumper falls back to inline output when the server is not running.
        dump_destination: "tcp://%env(VAR_DUMPER_SERVER)%"
```

- [ ] **Step 4: Ensure `api/config/routes/web_profiler.yaml` exposes the UI routes (dev)**

Create or overwrite with:
```yaml
when@dev:
    web_profiler_wdt:
        resource: '@WebProfilerBundle/Resources/config/routing/wdt.xml'
        prefix: /_wdt

    web_profiler_profiler:
        resource: '@WebProfilerBundle/Resources/config/routing/profiler.xml'
        prefix: /_profiler
```

- [ ] **Step 5: Delete the unused recipe template scaffold**

Run:
```bash
rm -f api/templates/base.html.twig
```
(We add our own `templates/dev/home.html.twig` in Task 3; `base.html.twig` is unused — no app templates extend it.)

- [ ] **Step 6: Add the var-dumper server address to `api/.env.dev`**

Append to `api/.env.dev`:
```bash
# var-dumper server target for dump() output (make profiler.dump-server).
# Inline fallback when unset/not running; only read in dev (see config/packages/debug.yaml).
VAR_DUMPER_SERVER=127.0.0.1:9912
```

- [ ] **Step 7: Verify dev container still boots and prod config has no twig/debug extension error**

Run:
```bash
make sf c='cache:clear --env=dev' && make sf c='lint:container --env=dev'
```
Expected: both succeed. `lint:container` compiles the dev container with the new config without "There is no extension able to load the configuration for ..." errors.

- [ ] **Step 8: Commit**

```bash
git add api/config/packages/twig.yaml api/config/packages/web_profiler.yaml api/config/packages/debug.yaml api/config/routes/web_profiler.yaml api/.env.dev
git rm api/templates/base.html.twig 2>/dev/null; git add -A api/templates 2>/dev/null
git commit -m "feat(api): scope profiler/twig/debug config to dev+test, wire var-dumper server

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Serve the `/_dev` toolbar page through Symfony

The Caddy `@pwa` matcher reverse-proxies any `text/html` request to Next.js unless the path is excluded. Add `/_dev*` to the exclusion, add a dev-only route to the framework's `TemplateController`, and write the page.

**Files:**
- Modify: `api/frankenphp/Caddyfile`
- Create: `api/config/routes/dev.yaml`
- Create: `api/templates/dev/home.html.twig`

- [ ] **Step 1: Exclude `/_dev*` from the PWA proxy**

In `api/frankenphp/Caddyfile`, find the line inside the `@pwa expression`:
```
					'/docs*', '/graphql*', '/bundles*', '/contexts*', '/_profiler*', '/_wdt*',
```
Change it to add `/_dev*`:
```
					'/docs*', '/graphql*', '/bundles*', '/contexts*', '/_profiler*', '/_wdt*', '/_dev*',
```

- [ ] **Step 2: Add the dev-only route (no custom controller class)**

Create `api/config/routes/dev.yaml`:
```yaml
# Dev-only HTML landing page so the Web Debug Toolbar (HTML-only) is visible for an
# otherwise JSON API. Uses the framework's built-in TemplateController — no domain code.
when@dev:
    dev_home:
        path: /_dev
        controller: Symfony\Bundle\FrameworkBundle\Controller\TemplateController
        defaults:
            template: dev/home.html.twig
```

- [ ] **Step 3: Write the page**

Create `api/templates/dev/home.html.twig`:
```twig
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>ERPify — dev tools</title>
</head>
<body>
    <h1>ERPify — dev tools</h1>
    <p>The Symfony Profiler is active in <strong>dev</strong>. The Web Debug Toolbar is
       pinned to the bottom of this page; click any segment to open that request's profile.
       API responses are JSON, so the toolbar can't render on them directly — use the
       button below (its calls appear in the toolbar's AJAX panel) or the Profiler UI.</p>
    <ul>
        <li><a href="/_profiler/latest">Open the Profiler UI (/_profiler/latest)</a></li>
    </ul>
    <button type="button" id="sample-call">Run sample API call</button>
    <pre id="sample-output"></pre>
    <script>
        document.getElementById('sample-call').addEventListener('click', async () => {
            const out = document.getElementById('sample-output');
            out.textContent = 'Loading…';
            const res = await fetch('/api/v1/backoffice/banks', { headers: { Accept: 'application/json' } });
            out.textContent = res.status + ' ' + res.statusText + '\n' + await res.text();
        });
    </script>
</body>
</html>
```

- [ ] **Step 4: Reload Caddy + verify the route exists and reaches Symfony**

Caddy hot-reloads on Caddyfile change in dev; if needed, `make docker.restart`. Then:
```bash
make sf c='debug:router dev_home'
curl -sk -o /dev/null -w '%{http_code}\n' -H 'Accept: text/html' https://localhost/_dev
```
Expected: `debug:router` shows the `dev_home` route at `/_dev`; curl prints `200`. (In a worktree, use the resolved port from `make docker.info` / `make profiler.open` instead of `:443`.)

- [ ] **Step 5: Commit**

```bash
git add api/frankenphp/Caddyfile api/config/routes/dev.yaml api/templates/dev/home.html.twig
git commit -m "feat(api): add dev-only /_dev page so the debug toolbar is visible

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: `make/profiler.mk` — open the UI + run the dump server

**Files:**
- Create: `make/profiler.mk`

- [ ] **Step 1: Write the module**

Create `make/profiler.mk`:
```makefile
# =============================================================================
# Symfony Profiler dev tooling (dev/test only).
# =============================================================================

.PHONY: profiler.open profiler.dump-server

## —— Profiler ——

profiler.open: ## Open the Symfony Profiler UI (/_profiler/latest) in the host browser
	@port=$$($(DOCKER_COMPOSE) port --protocol tcp $(PHP_SERVICE) 443 2>/dev/null | cut -d: -f2); \
	url="https://localhost:$${port:-443}/_profiler/latest"; \
	printf 'Opening %s\n' "$$url"; \
	xdg-open "$$url" >/dev/null 2>&1 || open "$$url" >/dev/null 2>&1 || printf 'Open it manually: %s\n' "$$url"

profiler.dump-server: ## Start the var-dumper server (collects dump() out-of-band; Ctrl-C to stop)
	@$(SYMFONY) server:dump
```
(`include make/*.mk` in the root `Makefile` auto-discovers the file. The `## —— Profiler ——` header + `## description` comments make `make help` group both under "Profiler", per `make/CONVENTIONS.md`.)

- [ ] **Step 2: Verify help grouping and the resolved URL**

Run:
```bash
make help 2>/dev/null | grep -A3 -i 'Profiler'
make -n profiler.open
```
Expected: `make help` lists `profiler.open` and `profiler.dump-server` under a "Profiler" section; `make -n profiler.open` prints the port-resolution + `xdg-open` shell without executing it.

- [ ] **Step 3: Smoke-test opening the UI (optional, needs a desktop session)**

Run:
```bash
make profiler.open
```
Expected: prints `Opening https://localhost:<port>/_profiler/latest` and launches the browser (or prints the manual URL when no opener is available).

- [ ] **Step 4: Commit**

```bash
git add make/profiler.mk
git commit -m "feat: add profiler.open + profiler.dump-server make targets

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Keep the composer-require-checker gate green

`make composer.check.missing-deps` scans `config/bundles.php` and would flag the dev-only bundle FQCNs (no prod `require` for them). Whitelist them, exactly as the existing Alice/fixtures bundles are.

**Files:**
- Modify: `api/tools/composer-require-checker/composer-require-checker.json`

- [ ] **Step 1: Add the three FQCNs to `symbol-whitelist`**

In `api/tools/composer-require-checker/composer-require-checker.json`, inside the `symbol-whitelist` array, after the line:
```json
        "Nelmio\\Alice\\Bridge\\Symfony\\NelmioAliceBundle",
```
add:
```json
        "Symfony\\Bundle\\WebProfilerBundle\\WebProfilerBundle",
        "Symfony\\Bundle\\TwigBundle\\TwigBundle",
        "Symfony\\Bundle\\DebugBundle\\DebugBundle",
```

- [ ] **Step 2: Run the gate**

Run:
```bash
make composer.check.missing-deps
```
Expected: exits 0, no "The following unknown symbols were found" lines for the three bundles.

- [ ] **Step 3: Commit**

```bash
git add api/tools/composer-require-checker/composer-require-checker.json
git commit -m "build(api): whitelist dev profiler bundles for require-checker

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: Pin the profiler-in-test wiring with a functional test

This guards the `test`-env half of the dev+test decision: a regression that drops the profiler from `test` (or unregisters the Doctrine collector) breaks this test.

**Files:**
- Create: `api/tests/Functional/Shared/Infrastructure/Profiler/ProfilerEnabledFunctionalTest.php`

- [ ] **Step 1: Write the test**

Create the file:
```php
<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Infrastructure\Profiler;

use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Pins that the Symfony Profiler is wired in the test environment so functional
 * tests can assert on collected data. The profiler is dev+test only; this guards
 * the test half of that decision (web_profiler.yaml `when@test`).
 *
 * @internal
 */
#[CoversNothing]
final class ProfilerEnabledFunctionalTest extends WebTestCase
{
    public function testProfilerIsEnabledAndRegistersCoreCollectors(): void
    {
        $client = static::createClient();
        $client->enableProfiler();

        $client->request('GET', '/api/v1/backoffice/health', [], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();

        $profile = $client->getProfile();
        self::assertNotFalse($profile, 'The profiler must be enabled in the test environment.');
        self::assertTrue($profile->hasCollector('db'), 'The Doctrine (db) collector must be registered.');
        self::assertTrue($profile->hasCollector('time'), 'The time collector must be registered.');
    }
}
```

- [ ] **Step 2: Run it (expect PASS — it pins the wiring just built)**

Run:
```bash
make php.unit c='--filter ProfilerEnabledFunctionalTest'
```
Expected: PASS (1 test, 3 assertions). If `getProfile()` returns `false`, `web_profiler.yaml`'s `when@test` block is missing or the bundle isn't test-enabled — fix Task 1 Step 3 / Task 2 Step 2.

- [ ] **Step 3: Commit**

```bash
git add api/tests/Functional/Shared/Infrastructure/Profiler/ProfilerEnabledFunctionalTest.php
git commit -m "test(api): pin profiler enabled in the test environment

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: Documentation

**Files:**
- Modify: `docs/claude-code-quickref.md`
- Modify: `docs/development-guide-api.md`

- [ ] **Step 1: Add the targets to the quickref command catalog**

In `docs/claude-code-quickref.md`, inside the `### API / PHP` fenced `bash` block, after the `make sf c='about'` line, add:
```bash
make profiler.open                  # Open the Symfony Profiler UI (/_profiler/latest) in the browser (dev).
make profiler.dump-server           # Start the var-dumper server: collects dump() out-of-band (dev).
```

- [ ] **Step 2: Add a Profiler subsection to the API dev guide**

In `docs/development-guide-api.md`, after the `## Run / stop / inspect` section, add:
```markdown
## Profiler & debug toolbar (dev/test only)

The Symfony Profiler is enabled in `dev` + `test` (never prod — the bundles are registered
`['dev'=>true,'test'=>true]`). Because the API returns JSON, the floating toolbar can't
inject into `/api/*` responses; the surfaces are:

- **`/_profiler`** — full Profiler web UI (Doctrine queries, timeline, Messenger,
  serializer, logs). `make profiler.open` opens `/_profiler/latest` in your browser
  (resolves this checkout's HTTPS port, including worktrees). Every response also carries
  an `X-Debug-Token` / `X-Debug-Token-Link` header.
- **`/_dev`** — a dev-only HTML page where the floating toolbar renders. Its
  "Run sample API call" button fetches `/api/*`, and those calls show up in the toolbar's
  AJAX panel with profiler links.
- **`dump()`** — run `make profiler.dump-server` in a spare terminal to collect dumps
  out-of-band (so they never corrupt JSON responses); they also appear in the profiler's
  Debug/Dump panel. Without the server running, dumps fall back to inline output.

Want the toolbar on the real Next.js app instead? That's tracked as a follow-up (PWA
reads `X-Debug-Token` and loads `/_wdt/{token}`); it's intentionally not wired here.
```

- [ ] **Step 3: Commit**

```bash
git add docs/claude-code-quickref.md docs/development-guide-api.md
git commit -m "docs: document profiler UI, /_dev page, and var-dumper targets

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: Full verification sweep

No code changes (unless a gate flags something). Confirm every success criterion from the spec.

- [ ] **Step 1: Static gates**

Run:
```bash
make php.stan
make php.quality
```
Expected: both clean. Notes from repo history: `php.quality` regenerates `api/config/reference.php` (auto-gen) — if it shows as modified, `git checkout -- api/config/reference.php` before any further commit. If PHPMD OOMs (Error 137), re-run `make php.md` alone.

- [ ] **Step 2: Require-checker + full PHP test suite smoke**

Run:
```bash
make composer.check.missing-deps
make php.unit
```
Expected: require-checker exits 0; PHPUnit suite green (includes the new `ProfilerEnabledFunctionalTest`).

- [ ] **Step 3: Runtime — profiler header + UI reachability**

Resolve the HTTPS port (`make docker.info`; primary = 443). With `BASE=https://localhost:<port>`:
```bash
curl -sk -D - -o /dev/null -H 'Accept: application/json' "$BASE/api/v1/backoffice/health" | grep -i 'x-debug-token'
curl -sk -o /dev/null -w '%{http_code}\n' -H 'Accept: text/html' "$BASE/_profiler/latest"
curl -sk -o /dev/null -w '%{http_code}\n' -H 'Accept: text/html' "$BASE/_dev"
```
Expected: an `x-debug-token:` header on the API response; `200` for `/_profiler/latest`; `200` for `/_dev`.

- [ ] **Step 4: Visual — confirm the toolbar renders on `/_dev`**

Using the Playwright browser tools: `browser_navigate` to `https://localhost:<port>/_dev`, then `browser_snapshot` and `browser_take_screenshot`. Confirm a `.sf-toolbar` / `.sf-minitoolbar` element (the floating toolbar) is present at the bottom. Then `browser_click` the "Run sample API call" button and re-`browser_snapshot`: the `<pre>` shows the JSON, and the toolbar's AJAX counter increments. (Self-signed local cert: if navigation is blocked, accept/ignore the cert warning; the curl checks above are the hard gate.)

- [ ] **Step 5: Prod-safety (static guarantee)**

Run:
```bash
grep -nE 'TwigBundle|WebProfilerBundle|DebugBundle' api/config/bundles.php
grep -L 'when@' api/config/packages/twig.yaml api/config/packages/debug.yaml
head -1 api/config/packages/web_profiler.yaml
```
Expected: all three bundles show `['dev' => true, 'test' => true]`; `grep -L 'when@'` prints **nothing** (both files are fully `when@`-wrapped, so prod loads neither `twig:` nor `debug:`); `web_profiler.yaml` starts with `when@dev:`. This proves prod never loads Twig/profiler/debug.

- [ ] **Step 6: No verification changes to commit**

Run `git status` — expect a clean tree (aside from intentionally-ignored `api/var/`, `api/public/bundles/`). If `reference.php` is dirty, `git checkout -- api/config/reference.php`.

---

### Task 9: File the follow-up issue for the PWA toolbar (option B)

Deferred per the spec. **Outward-facing action — confirm with the user before running `gh`.**

- [ ] **Step 1: Create the issue**

Run (after user confirmation):
```bash
gh issue create \
  --title "Surface the Symfony debug toolbar inside the Next.js PWA" \
  --label enhancement \
  --body "$(cat <<'EOF'
Follow-up to the dev profiler/toolbar work (spec: docs/superpowers/specs/2026-06-13-symfony-profiler-debug-toolbar-design.md).

Today the floating toolbar is only visible on Symfony-served HTML (`/_dev`); the Profiler
UI lives at `/_profiler`. This issue tracks showing the toolbar while using the **real**
Next.js app.

Approach (API Platform style):
- PWA HTTP layer reads the `X-Debug-Token` header off each `/api/*` response.
- A dev-only PWA component loads the `/_wdt/{token}` fragment from Symfony and mounts it.
- Relax the PWA CSP **in dev only** to allow the toolbar's inline scripts/styles.
- Exclude the whole thing from the production build.

Cross-deployable (touches `api/` + `pwa/`); ship as its own PR.
EOF
)"
```
Expected: prints the new issue URL.

- [ ] **Step 2: Done** — no commit (issue is remote).

---

## Self-Review

**Spec coverage:**
- profiler-pack install (dev/test) → Task 1 ✔
- bundles dev/test registration (Twig not all-env) → Task 1 Step 3 ✔
- `web_profiler.yaml` / routes / scoped `twig.yaml` / `debug.yaml` → Task 2 ✔
- `/_dev` page + TemplateController + Caddy exclusion → Task 3 ✔
- var-dumper server (`debug.yaml` + `.env.dev` + `profiler.dump-server`) → Task 2 + Task 4 ✔
- `profiler.open` UI launcher → Task 4 ✔
- require-checker whitelist → Task 5 ✔
- docs (quickref + dev-guide) → Task 7 ✔
- success criteria (X-Debug-Token, /_profiler 200, /_dev toolbar, dump server, prod-safe, stan/quality/unit) → Task 8 ✔
- deferred option B issue → Task 9 ✔
- FrankenPHP worker-mode / composer-unused risks → exercised by Task 2 Step 7 (`lint:container`) + Task 8 Steps 1–2 (full quality + suite under the dev worker).

**Placeholder scan:** none — every file has full content; every command has expected output.

**Type/name consistency:** route name `dev_home`, template `dev/home.html.twig`, env `VAR_DUMPER_SERVER=127.0.0.1:9912` (matches `server:dump`'s default `127.0.0.1:9912`), targets `profiler.open` / `profiler.dump-server`, test class `ProfilerEnabledFunctionalTest` — all referenced consistently across tasks and docs.
