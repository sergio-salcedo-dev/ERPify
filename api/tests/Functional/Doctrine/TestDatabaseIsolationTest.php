<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Doctrine;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The suite may not run against the database the runtime uses. That is a stronger requirement than "the test
 * config names another database": this suite does not merely write rows, it purges the schema, clones it to
 * `<dbname>_behat_backup` and restores over it once per feature, so a connection resolving to the dev
 * database consumes whatever a developer had there — the session they were signed in with included.
 *
 * The isolation is `dbname_suffix` in config/packages/doctrine.yaml, and it is asserted here by its
 * consequence rather than by its spelling. A gate reading that YAML would go green on a suffix the container
 * never applies, which is the shape the previous guarantee failed in: a dedicated DSN sat in .env.test while
 * Dotenv declined to overwrite the DATABASE_URL the container already exported, so the declared isolation and
 * the live connection disagreed with nothing able to report it. `current_database()` is asked of the open
 * connection, so the answer comes from the server rather than from the configuration that hoped to set it.
 *
 * What it does not prove: the name is checked, not the contents. A deployment that named its runtime database
 * `<something>_test` would satisfy this while sharing one database with the suite — nothing here can tell
 * those apart, and no environment in this repository is spelled that way.
 *
 * @internal
 */
#[CoversNothing]
final class TestDatabaseIsolationTest extends KernelTestCase
{
    private const string TEST_DATABASE_SUFFIX = '_test';

    public function testTheSuiteConnectsToADatabaseTheRuntimeCannotBeUsing(): void
    {
        self::bootKernel();

        $connection = self::getContainer()->get(Connection::class);
        $this->assertInstanceOf(Connection::class, $connection);

        $liveDatabase = $connection->fetchOne('SELECT current_database()');
        $this->assertIsString($liveDatabase);
        $this->assertNotSame('', $liveDatabase);

        $this->assertStringEndsWith(
            self::TEST_DATABASE_SUFFIX,
            $liveDatabase,
            \sprintf(
                'The suite is connected to "%s", which carries no "%s" suffix and is therefore a database the '
                . 'runtime can be using. Behat purges and restores over its connection, so this run would '
                . 'consume the dev data. Restore the `dbname_suffix` under `when@test` in '
                . 'config/packages/doctrine.yaml.',
                $liveDatabase,
                self::TEST_DATABASE_SUFFIX,
            ),
        );
    }
}
