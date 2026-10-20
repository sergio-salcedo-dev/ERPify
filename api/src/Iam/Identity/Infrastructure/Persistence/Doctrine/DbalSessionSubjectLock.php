<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use Erpify\Iam\Session\Application\SessionSubjectLock;
use LogicException;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * {@see SessionSubjectLock} over plain DBAL, implemented here because `identity_user` is this context's table and
 * the session context is told only whether the row is there.
 *
 * `FOR KEY SHARE` is the weakest mode that still conflicts with the erasure: it waits on the erasure's
 * `FOR UPDATE` and its `DELETE`, and Postgres re-reads the row once the erasure commits, finding none. It does not
 * conflict with a plain `UPDATE` that leaves the key alone, so a login never queues behind a write it does not
 * need to see — only behind a transaction that took the row `FOR UPDATE`.
 */
#[AsAlias(SessionSubjectLock::class)]
final readonly class DbalSessionSubjectLock implements SessionSubjectLock
{
    public function __construct(private Connection $connection)
    {
    }

    #[Override]
    public function holdsLive(string $userId): bool
    {
        if (!$this->connection->isTransactionActive()) {
            throw new LogicException(
                "A session subject lock is held for its caller's transaction; it was called outside one.",
            );
        }

        return false !== $this->connection->fetchOne(
            <<<'SQL'
                SELECT 1
                FROM identity_user
                WHERE id = CAST(:userId AS UUID)
                FOR KEY SHARE
                SQL,
            ['userId' => $userId],
        );
    }
}
