<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
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
 * need to see — only behind a transaction that took the row `FOR UPDATE`. That is the right strength for a
 * revocation, which acts on identities that are no longer active.
 *
 * A login needs one step more, and {@see holdsAdmissible()} takes it: `FOR SHARE` conflicts with the
 * `FOR NO KEY UPDATE` lock every plain `UPDATE` of the row takes, so a status change in flight is waited for,
 * and the `status` predicate is re-evaluated against the committed row once it ends (a suspension that wins the
 * race leaves nothing to match). Measured with `FOR KEY SHARE` in its place, a suspension committed while the
 * mint was in flight, its revoke-everything ran before the new row existed, and the mint then committed an
 * `ACTIVE` session for a `SUSPENDED` identity.
 *
 * **Lock order.** Every holder takes `identity_user` before any `iam_session` row, which is the erasure's order
 * (`FOR UPDATE` on the identity, then its sessions), so neither strength can close a cycle with it. The
 * stronger one makes a login queue behind ANY open write of the identity's row — the erasure, a status change,
 * the lockout counter a failed login bumps — for as long as that write's transaction stays open; none of them
 * takes an `iam_session` row before the identity, so the wait has an end. The login's own earlier writes of the
 * row (clearing the lockout, rehashing the password) commit in their own transactions before the mint begins.
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

    #[Override]
    public function holdsAdmissible(string $userId): bool
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
                WHERE id = CAST(:userId AS UUID) AND status = :active
                FOR SHARE
                SQL,
            ['userId' => $userId, 'active' => IdentityStatus::ACTIVE->value],
        );
    }
}
