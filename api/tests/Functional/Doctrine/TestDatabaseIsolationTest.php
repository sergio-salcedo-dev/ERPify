<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Doctrine;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The suite may not run against the database the runtime uses. That is a stronger requirement than "the test
 * config names another database": a dozen functional tests `TRUNCATE` or `DELETE` in `setUp()` and nothing
 * rolls back, so a misresolved connection does not fail — it succeeds, against a developer's data. Measured
 * with the suffix removed: one full `make php.unit` took the dev `identity_user` from 13 rows to 1 and
 * `iam_session` from 6 to 4, which is a signed-out developer and a 401 from the who-am-I route.
 *
 * The isolation is `dbname_suffix` in config/packages/test/doctrine.yaml. What actually STOPS a bad run is
 * {@see \Erpify\Tests\Support\PHPUnit\RefuseRuntimeDatabaseExtension}, which reads the resolved connection
 * parameters on the runner's first event, before any test executes. This test is the falsifiable pin beside
 * it, and it earns its place by asking a different oracle: `current_database()` comes from the server, so it
 * proves the connection that was actually opened, where the extension proves only what was configured.
 *
 * Asserted as a marker rather than a terminal suffix because a lane may append its own token —
 * `api/tests/Behat/bootstrap.php` sets `TEST_TOKEN=_behat` so the two lanes hold one database each.
 *
 * What it does not prove: the name is checked, not the contents. A deployment whose runtime database were
 * itself spelled with `_test` in it would satisfy this while sharing one database with the suite; no
 * environment in this repository is spelled that way.
 *
 * @internal
 */
#[CoversNothing]
final class TestDatabaseIsolationTest extends KernelTestCase
{
    private const string TEST_DATABASE_MARKER = '_test';

    public function testTheOpenConnectionNamesADatabaseTheRuntimeCannotBeUsing(): void
    {
        self::bootKernel();

        $connection = self::getContainer()->get(Connection::class);
        $this->assertInstanceOf(Connection::class, $connection);

        $liveDatabase = $connection->fetchOne('SELECT current_database()');
        $this->assertIsString($liveDatabase);
        $this->assertNotSame('', $liveDatabase);

        $this->assertStringContainsString(
            self::TEST_DATABASE_MARKER,
            $liveDatabase,
            \sprintf(
                'The suite is connected to "%s", which carries no "%s" marker and may therefore be the '
                . 'database the runtime uses. This suite truncates and deletes without rolling back, so the '
                . 'run would consume the dev data. Check `dbname_suffix` under '
                . 'config/packages/test/doctrine.yaml.',
                $liveDatabase,
                self::TEST_DATABASE_MARKER,
            ),
        );
    }
}
