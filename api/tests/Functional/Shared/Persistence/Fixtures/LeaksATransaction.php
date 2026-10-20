<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Persistence\Fixtures;

use Doctrine\DBAL\Connection;

/**
 * Opens a transaction and takes a transaction-scoped advisory lock inside it, the way a unit of work that forgets
 * to commit holds its row locks. The lock is what {@see ProbesATransactionScopedLock} probes from a second
 * connection, so it outlives the leak exactly as long as the server keeps the transaction.
 *
 * @internal
 */
final class LeaksATransaction
{
    public const int LOCK_KEY = 815_004_211;

    private function __construct()
    {
    }

    public static function on(Connection $connection): void
    {
        $connection->beginTransaction();
        $connection->executeStatement('SELECT pg_advisory_xact_lock(:key)', ['key' => self::LOCK_KEY]);
    }
}
