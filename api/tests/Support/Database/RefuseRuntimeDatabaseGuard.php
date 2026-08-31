<?php

declare(strict_types=1);

namespace Erpify\Tests\Support\Database;

use Doctrine\DBAL\Connection;
use Erpify\Kernel;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Refuses to let a suite start against the database the runtime uses.
 *
 * The suite writes destructively and does not roll back: a dozen functional tests `TRUNCATE` or `DELETE` in
 * `setUp()`, over `identity_user`, `iam_session`, `membership` and `bank` among others. A run whose
 * connection resolves to the runtime database therefore does not fail — it succeeds, against the developer's
 * data. Measured with `dbname_suffix` removed: one `make php.unit` took the dev `identity_user` from 13 rows
 * to 1 and `iam_session` from 6 to 4, which is a signed-out developer and a 401 from the who-am-I route.
 *
 * **It is called from api/tools/phpunit/bootstrap.php, and that placement is the whole point.** Two earlier
 * homes were measured and both failed to stop anything: as a `PHPUnit\Runner\Extension` subscribing to
 * `ExecutionStarted`, the runner catches the throwable ("Exception in third-party event subscriber") and runs
 * the suite anyway; throwing from the extension's own `bootstrap()` is reported as "Bootstrapping of
 * extension … failed" and also continues. Both printed the refusal and then destroyed the data anyway — dev
 * `identity_user` 13 → 1 in each case. The file bootstrap is executed by `require` before the runner exists,
 * so a throwable there is fatal, which is the only behaviour worth having here.
 *
 * **It deliberately does not connect.** `dbname_suffix` is applied by Doctrine's `ConnectionFactory` while
 * assembling the parameters, so the resolved name is readable from `getParams()` without opening a socket.
 * That matters beyond speed: CI fans out 27 `php.lint.*` gates through `bin/phpunit --filter` during
 * `php.quality.dry-run`, which runs before `db.test.prepare` has created anything, and every one of those
 * gates is kernel-free by design. A connecting check would turn each of them into a red on a database nobody
 * had asked for yet. {@see \Erpify\Tests\Functional\Doctrine\TestDatabaseIsolationTest} asks the server
 * instead, and is the pin that this mechanism is live.
 *
 * A container that will not boot, or a connection naming no database, throws rather than passing: a check
 * that quietly stops running is the failure mode the suffix exists to end.
 */
final class RefuseRuntimeDatabaseGuard
{
    /**
     * The segment `dbname_suffix` appends under `APP_ENV=test` (`config/packages/test/doctrine.yaml`).
     * Matched as a substring, not as a terminal suffix: a lane may append its own token after it, as
     * `api/tests/Behat/bootstrap.php` does with `TEST_TOKEN=_behat`.
     */
    private const string TEST_DATABASE_MARKER = '_test';

    public static function refuseUnlessTestDatabase(): void
    {
        $databaseName = self::resolveDatabaseName();

        if (!\str_contains($databaseName, self::TEST_DATABASE_MARKER)) {
            throw new RuntimeException(\sprintf(
                'Refusing to run the suite against "%s": the name carries no "%s" marker, so it may be the '
                . 'database the runtime uses, and this suite truncates and deletes without rolling back. '
                . 'Check `dbname_suffix` under config/packages/test/doctrine.yaml.',
                $databaseName,
                self::TEST_DATABASE_MARKER,
            ));
        }
    }

    private static function resolveDatabaseName(): string
    {
        $kernel = new Kernel('test', false);
        $kernel->boot();

        try {
            // The DBAL connection is a private service; under `framework.test` the kernel publishes
            // `test.service_container` to reach those. Absent it, the check cannot be made, and saying so is
            // the point — a silent fallback is how an isolation check stops running without anyone noticing.
            $container = $kernel->getContainer();

            if (!$container->has('test.service_container')) {
                throw new RuntimeException(
                    'The test service container is unavailable, so the resolved database cannot be read. '
                    . 'Check that `framework.test` is enabled under APP_ENV=test.',
                );
            }

            $testContainer = $container->get('test.service_container');

            if (!$testContainer instanceof ContainerInterface) {
                throw new RuntimeException('`test.service_container` is not a container; cannot verify isolation.');
            }

            $connection = $testContainer->get(Connection::class);

            if (!$connection instanceof Connection) {
                throw new RuntimeException('The test container holds no Doctrine connection to check.');
            }

            $databaseName = $connection->getParams()['dbname'] ?? null;

            if (!\is_string($databaseName) || '' === $databaseName) {
                throw new RuntimeException('The Doctrine connection names no database; cannot verify isolation.');
            }

            return $databaseName;
        } finally {
            $kernel->shutdown();
        }
    }
}
