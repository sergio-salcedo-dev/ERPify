<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use LogicException;

/**
 * A `SELECT … FOR UPDATE` releases its locks at the end of the statement that took it, so run with no
 * transaction open it returns the rows, raises nothing and holds nothing by the time the rewrite beside it
 * runs. Refusing loudly is what keeps the lock order the erasure depends on from degrading into a no-op with
 * every test still green.
 */
final class RequiresActiveTransaction
{
    private function __construct()
    {
    }

    /**
     * @throws LogicException when the connection has no transaction open
     */
    public static function ensure(Connection $connection, string $operation): void
    {
        if (!$connection->isTransactionActive()) {
            throw new LogicException(
                \sprintf("%s takes row locks and must run inside the caller's transaction.", $operation),
            );
        }
    }
}
