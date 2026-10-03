<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use Erpify\Iam\Identity\Domain\Repository\IdentityRowLock;
use LogicException;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * {@see IdentityRowLock} over `identity_user` via plain DBAL: one `SELECT 1 … FOR UPDATE` on the primary key,
 * on the connection the EntityManager wraps, so it joins whatever transaction the caller opened through the
 * transaction manager.
 *
 * Under `READ COMMITTED` a `FOR UPDATE` that waits on a row another transaction then DELETES returns no row
 * once that transaction commits, which is exactly the "erased while we waited" answer the port promises — no
 * re-read is needed to tell the two outcomes apart.
 *
 * One row, never a second: a caller holding it acquires nothing else on this table, so it can block an
 * erasure but cannot be one end of a cycle with it.
 */
#[AsAlias(IdentityRowLock::class)]
final readonly class DbalIdentityRowLock implements IdentityRowLock
{
    public function __construct(private Connection $connection)
    {
    }

    #[Override]
    public function lockIfLive(string $userId): bool
    {
        if (!$this->connection->isTransactionActive()) {
            throw new LogicException('The identity row lock must be taken inside a transaction.');
        }

        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM identity_user WHERE id = CAST(:id AS UUID) FOR UPDATE',
            ['id' => $userId],
        );
    }
}
