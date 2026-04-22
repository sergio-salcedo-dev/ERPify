# Merging the Behat stack into the main Composer project

The Behat stack lives in an isolated Composer project at `api/tools/behat/`
with its own `composer.json`, `composer.lock`, and `vendor/`. The reason is
upstream: Behat 3.x pins `symfony/config`, `symfony/dependency-injection`, and
`symfony/http-kernel` to `^7` (`^5.4 || ^6.4 || ^7.0`), and the app is on
Symfony 8. `friends-of-behat/symfony-extension` (which we use to boot the
kernel and inject services into contexts) has the same constraint on DI.

Until upstream publishes Symfony-8-compatible releases, the two trees must
stay separate. `api/tools/behat/run.php` reconciles them at runtime by loading
the app autoload **before** the tools-vendor autoload, so Sf8 classes win for
any FQCN present in both.

## When can we merge?

Do not start this migration until **all** of the following resolve cleanly
against `symfony/*:8.0.*`:

- `behat/behat` (likely `>= 3.32` or a `4.x` release)
- `friends-of-behat/symfony-extension` (likely a `>= 2.7` release supporting
  both Sf8 DI and the chosen Behat major)

Smoke test from `api/`:

```bash
composer require --dev --dry-run \
  behat/behat \
  friends-of-behat/symfony-extension
```

If Composer resolves without conflicts, proceed.

## Migration checklist

1. **Move the deps into `api/composer.json`**

   ```bash
   cd api
   composer require --dev behat/behat friends-of-behat/symfony-extension
   ```

2. **Drop the autoload duplication.** `tests/Behat/` is already covered by
   the `Erpify\Tests\` → `tests/` mapping in `autoload-dev`, so no extra
   PSR-4 entry is needed. Delete `tools/behat/composer.json` and the
   `Erpify\Tests\Behat\` PSR-4 block it defined.

3. **Move `behat.yml.dist` to `api/`** and update the path prefix from
   `%paths.base%/../../features/...` to `%paths.base%/features/...`. The
   `FriendsOfBehatSymfonyExtension` `bootstrap` key can be removed entirely
   once the app autoload is the only autoload in play.

4. **Retire `tools/behat/run.php`** — with a single vendor tree, the wrapper
   is no longer needed. `vendor/bin/behat -c behat.yml.dist` runs directly.

5. **Unconditional bundle registration.** In `api/config/bundles.php`, drop
   the `class_exists(...)` guard around
   `FriendsOfBehatSymfonyExtensionBundle` and register it normally for the
   `test` environment:

   ```php
   FriendsOfBehat\SymfonyExtension\Bundle\FriendsOfBehatSymfonyExtensionBundle::class => ['test' => true],
   ```

6. **Update the Makefile.** In `make/php-test.mk`:

   - `php.behat` becomes `$(PHP_BEHAT) php vendor/bin/behat -c behat.yml.dist --format=pretty $(c)`.
   - `php.behat.install` can fold into the main `composer install` (or be
     removed if `composer.install` covers dev deps).

7. **Clean up `api/composer.json` scripts.** Delete the
   `behat-tools-install` entry from `scripts` and its description.

8. **Delete the isolated tree.**

   ```bash
   rm -rf api/tools/behat/
   ```

   Then `grep -R tools/behat api/` to confirm nothing else references it —
   CI configs, scripts, docs, etc.

9. **Run the suite.**

   ```bash
   make php.behat
   ```

All existing `.feature` files should pass unchanged — the contexts and
assertions are identical; only the autoload source moves.
