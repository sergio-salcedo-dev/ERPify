<?php

declare(strict_types=1);

namespace Erpify\Tests\Support\Database;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Refuses to let a suite start against the database the runtime uses.
 *
 * The suite writes destructively and does not roll back: a dozen functional tests `TRUNCATE` or `DELETE` in
 * `setUp()`, over `identity_user`, `iam_session`, `membership` and `bank` among others. A run whose
 * connection resolves to the runtime database therefore does not fail — it succeeds, against the developer's
 * data. Measured with `dbname_suffix` removed: one `make php.unit` took the dev `identity_user` from 13 rows
 * to 1 and `iam_session` from 6 to 4, which is a signed-out developer and a 401 from the who-am-I route.
 *
 * **Called from api/tools/phpunit/bootstrap.php, and that placement is what makes it a stop rather than a
 * report.** Two plausible placements do not work, and both were measured: a `PHPUnit\Runner\Extension`
 * subscribing to `ExecutionStarted` prints "Exception in third-party event subscriber" and runs the suite
 * anyway, and throwing from such an extension's own `bootstrap()` prints "Bootstrapping of extension … failed"
 * and also continues — each printed the refusal and then took dev `identity_user` from 13 rows to 1. The file
 * bootstrap is different: PHPUnit's `BootstrapLoader` wraps a throwable there in a `BootstrapScriptException`
 * and `Application` exits with `Result::EXCEPTION` before a single test is built.
 *
 * **It resolves the name from the sources rather than from a booted kernel, and that is deliberate.** Booting
 * one costs three separate defects. A non-debug kernel reuses a compiled container that Symfony never
 * freshness-checks (`KernelTrait::initializeContainer()` short-circuits on `!$this->debug`), so the guard
 * reads a stale `dbname_suffix` and passes on the exact mutation it exists to catch — measured: with the
 * suffix deleted and the container warm, the guard raised nothing. A debug kernel fixes that by warming the
 * very container the suite is about to use, which is what `Erpify\Kernel::getCacheDir()` gives PHPUnit a
 * private cache directory to prevent: a warm container means PHPUnit never autoloads the service classes,
 * never sees their file-scope deprecations, and reports green with `failOnDeprecation="true"` set. And either
 * one turns every kernel-free `php.lint.*` gate into a container compile, fanned out under CI's `-j4`.
 *
 * Reading the two sources costs no cache, no container and no socket, so it is also the only shape that
 * leaves the 32 filtered gate invocations exactly as they were.
 *
 * The cost is a second source of truth: this composes the name the way doctrine-bundle's
 * `ConnectionFactory::addDatabaseSuffix()` does, so a change to that mechanism would need a change here.
 * For a guard that is a feature rather than a defect — the two disagreeing is a red, and
 * {@see \Erpify\Tests\Functional\Doctrine\TestDatabaseIsolationTest} asks the server what actually happened.
 */
final class RefuseRuntimeDatabaseGuard
{
    /**
     * The segment the suffix must carry. Matched as a substring, not as a terminal suffix: a lane appends its
     * own token after it, as `api/tests/Behat/bootstrap.php` does with `TEST_TOKEN=_behat`.
     */
    private const string TEST_DATABASE_MARKER = '_test';

    /**
     * @param string|null $databaseUrl        the DSN as the process received it, before any suffix
     * @param string      $testDoctrineConfig path to the env-scoped Doctrine config declaring `dbname_suffix`
     * @param string|null $testToken          the per-process token a lane appends after the suffix
     */
    public static function refuseUnlessTestDatabase(
        ?string $databaseUrl,
        string $testDoctrineConfig,
        ?string $testToken,
    ): void {
        $resolved = self::resolveDatabaseName($databaseUrl, $testDoctrineConfig, $testToken);

        if (!\str_contains($resolved, self::TEST_DATABASE_MARKER)) {
            throw new RuntimeException(\sprintf(
                'Refusing to run the suite against "%s": the name carries no "%s" marker, so it may be the '
                . 'database the runtime uses, and this suite truncates and deletes without rolling back. '
                . 'Check `dbname_suffix` in %s.',
                $resolved,
                self::TEST_DATABASE_MARKER,
                $testDoctrineConfig,
            ));
        }
    }

    private static function resolveDatabaseName(
        ?string $databaseUrl,
        string $testDoctrineConfig,
        ?string $testToken,
    ): string {
        return self::databaseFromDsn($databaseUrl)
            . self::suffixFromConfig($testDoctrineConfig)
            . ($testToken ?? '');
    }

    private static function databaseFromDsn(?string $databaseUrl): string
    {
        if (null === $databaseUrl || '' === $databaseUrl) {
            throw new RuntimeException(
                'DATABASE_URL is unset, so the database this run would connect to cannot be determined.',
            );
        }

        $path = \parse_url($databaseUrl, PHP_URL_PATH);

        if (!\is_string($path) || '' === \ltrim($path, '/')) {
            throw new RuntimeException('DATABASE_URL names no database, so isolation cannot be verified.');
        }

        return \ltrim($path, '/');
    }

    /**
     * The suffix as declared, with any `%env(...)%` placeholder stripped — the token is supplied separately,
     * and a placeholder left in place would make every name match the marker by accident.
     */
    private static function suffixFromConfig(string $testDoctrineConfig): string
    {
        if (!\is_file($testDoctrineConfig)) {
            throw new RuntimeException(\sprintf(
                'No Doctrine test config at %s, so `dbname_suffix` cannot be read.',
                $testDoctrineConfig,
            ));
        }

        $parsed = Yaml::parseFile($testDoctrineConfig);
        $doctrine = \is_array($parsed) ? ($parsed['doctrine'] ?? null) : null;
        $dbal = \is_array($doctrine) ? ($doctrine['dbal'] ?? null) : null;
        $suffix = \is_array($dbal) ? ($dbal['dbname_suffix'] ?? null) : null;

        if (!\is_string($suffix)) {
            throw new RuntimeException(\sprintf(
                'No `doctrine.dbal.dbname_suffix` in %s. Without it every APP_ENV=test process resolves the '
                . 'runtime database, and this suite would truncate it.',
                $testDoctrineConfig,
            ));
        }

        return (string) \preg_replace('/%env\([^)]*\)%/', '', $suffix);
    }
}
