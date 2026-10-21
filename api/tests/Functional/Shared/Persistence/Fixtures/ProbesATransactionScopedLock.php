<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Persistence\Fixtures;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * A transaction-scoped advisory lock taken on the application's connection and probed from a second one, so a
 * test asserts that the SERVER released a transaction rather than that DBAL's nesting counter reads zero — a
 * counter can be right while the server still holds the transaction.
 *
 * The using test boots its kernel and calls {@see self::openBothConnections()} from its own `setUp()`.
 *
 * @internal
 */
trait ProbesATransactionScopedLock
{
    private Connection $connection;

    private Connection $outside;

    private function openBothConnections(Connection $connection): void
    {
        $this->connection = $connection;
        $this->outside = DriverManager::getConnection($connection->getParams());
    }

    private function closeBothConnections(): void
    {
        for ($level = $this->connection->getTransactionNestingLevel(); $level > 0; --$level) {
            $this->connection->rollBack();
        }

        $this->outside->close();
    }

    private function takeTheLock(): void
    {
        $this->connection->executeStatement(
            'SELECT pg_advisory_xact_lock(:key)',
            ['key' => LeaksATransaction::LOCK_KEY],
        );
        $this->assertFalse($this->lockIsFreeOutside(), 'the lock is not held: the test would pass vacuously');
    }

    private function lockIsFreeOutside(): bool
    {
        $key = ['key' => LeaksATransaction::LOCK_KEY];
        $free = (bool) $this->outside->fetchOne('SELECT pg_try_advisory_lock(:key)', $key);

        if ($free) {
            $this->outside->executeStatement('SELECT pg_advisory_unlock(:key)', $key);
        }

        return $free;
    }
}
