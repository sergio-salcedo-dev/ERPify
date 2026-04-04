# Migrating Behat into the main Composer project

Behat 3 and its Mink ecosystem depend on Symfony 7 components, which conflict
with the Symfony 8 packages used by the application.  To work around this the
Behat stack lives in an **isolated Composer project** at `api/tools/behat/` with
its own `composer.json`, `composer.lock`, and `vendor/` directory.

Once Behat (or a successor such as Behat 4) officially supports Symfony 8, the
isolated project can be removed and Behat can move into `api/composer.json` as a
regular dev dependency.

## Prerequisites

Before starting, confirm compatibility:

1. Check the Behat changelog / release notes for Symfony 8 support.
2. Verify that **all** of these packages resolve cleanly against `symfony/*:8.0.*`:
   - `behat/behat`
   - `behat/mink`
   - `behat/mink-browserkit-driver`
   - `friends-of-behat/mink-extension`

A quick smoke test from `api/`:

```bash
composer require --dry-run --dev \
  behat/behat \
  behat/mink \
  behat/mink-browserkit-driver \
  friends-of-behat/mink-extension
```

If Composer resolves without conflicts, proceed with the migration.

## Step 1 — Add Behat packages to `api/composer.json`

```bash
cd api
composer require --dev \
  behat/behat \
  behat/mink \
  behat/mink-browserkit-driver \
  friends-of-behat/mink-extension
```

## Step 2 — Add the Behat context autoload namespace

Contexts live under `api/tests/Behat/` with namespace `Erpify\Tests\Behat\`.
PHPUnit loads them via `api/composer.json` `autoload-dev` (`Erpify\Tests\` → `tests/`).

Behat uses the **isolated** `api/tools/behat/composer.json` (see `bootstrap.php`), so that file must also map the same classes:

```json
"autoload": {
    "psr-4": {
        "Erpify\\Tests\\Behat\\": "../../tests/Behat/"
    }
}
```

Then regenerate the Behat tools autoloader:

```bash
composer dump-autoload --working-dir=tools/behat
```

## Step 3 — Move `behat.yml.dist` to `api/`

Copy the config to the application root and update the paths (they were
relative to `api/tools/behat/`; now they are relative to `api/`):

```yaml
# api/tools/behat/behat.yml.dist (paths relative to api/tools/behat/)
default:
    suites:
        default:
            paths:
                - '%paths.base%/../../features/backoffice'
                - '%paths.base%/../../features/frontoffice'
            contexts:
                - Erpify\Tests\Behat\Context\FeatureContext
    extensions:
        Behat\MinkExtension\ServiceContainer\MinkExtension:
            base_url: '%env(MINK_BASE_URL)%'
            sessions:
                default:
                    browserkit_http: ~
```

## Step 4 — Simplify `api/bin/behat`

Replace the wrapper so it uses the main vendor binary and the new config
location:

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);

require $root.'/vendor/autoload.php';

// Boot .env so MINK_BASE_URL is available
if (is_file($root.'/.env')) {
    (new \Symfony\Component\Dotenv\Dotenv())->bootEnv($root.'/.env');
}

$_SERVER['argv'] ??= [];
$hasConfig = false;
foreach ($_SERVER['argv'] as $arg) {
    if ($arg === '-c' || $arg === '--config' || str_starts_with($arg, '--config=')) {
        $hasConfig = true;
        break;
    }
}
if (!$hasConfig) {
    array_splice($_SERVER['argv'], 1, 0, ['--config', $root.'/behat.yml.dist']);
}

require $root.'/vendor/behat/behat/bin/behat';
```

## Step 5 — Update the Makefile

In the root `Makefile`, the `php.behat` target no longer needs a separate
install step.  Remove or update these targets:

- **Remove** `php.behat.install` (no separate vendor to install).
- **Simplify** `php.behat` — the `bin/behat` wrapper now uses the main vendor.

## Step 6 — Clean up `api/composer.json` scripts

Remove the `behat-tools-install` script and its description:

```json
"scripts": {
    ...
    // DELETE these two entries:
    // "behat-tools-install": ["@composer install --working-dir=tools/behat --no-interaction"]
},
"scripts-descriptions": {
    // DELETE:
    // "behat-tools-install": "Install Behat and Mink into ..."
}
```

## Step 7 — Delete the isolated Behat project

```bash
rm -rf api/tools/behat/
```

Verify nothing else references `tools/behat` (grep the codebase).

## Step 8 — Run the tests

```bash
make php.behat
```

All existing `.feature` files should pass without changes — the contexts,
features, and Mink configuration are identical; only the autoloading source
changed.

## Step 9  — check if extension is needed
friends-of-behat/symfony-extension -- This extension boots your Symfony kernel inside Behat so you can inject services directly into step definitions. It currently supports Symfony 6/7 only -- Symfony 8 support is in-progress (open PRs from late 2025) but not merged yet. More importantly, your health test doesn't need it: it sends plain HTTP requests via Mink's BrowserKit driver, which is the right approach for endpoint-level acceptance tests.
