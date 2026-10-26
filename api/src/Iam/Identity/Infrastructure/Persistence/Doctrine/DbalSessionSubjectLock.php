<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Session\Application\SessionSubjectLock;
use Erpify\Iam\Session\Domain\SubjectAdmission;
use LogicException;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * {@see SessionSubjectLock} over plain DBAL, implemented here because `identity_user` is this context's table and
 * the session context is told only what it needs: whether the row is there, and whether it admits a login.
 *
 * `FOR KEY SHARE` is the weakest mode that still conflicts with the erasure: it waits on the erasure's
 * `FOR UPDATE` and its `DELETE`, and Postgres re-reads the row once the erasure commits, finding none. It does not
 * conflict with a plain `UPDATE` that leaves the key alone. That is the right strength for a revocation, which
 * acts on identities that are no longer active.
 *
 * A login takes {@see holdForAdmission()} instead: `FOR SHARE`, which conflicts with every lock a writer of the
 * row takes — the erasure's, a `FOR UPDATE` read, and the `FOR NO KEY UPDATE` a plain `UPDATE` takes on its own —
 * so a write in flight is waited for, and the status the statement returns is the committed one (a locking read
 * under `READ COMMITTED` follows the row to its latest version once the wait ends). Measured with
 * `FOR KEY SHARE` in its place, a suspension committed while the mint was in flight, its revoke-everything ran
 * before the new row existed, and the mint then committed an `ACTIVE` session for a `SUSPENDED` identity.
 *
 * **What the stronger mode costs.** The status writers (`ChangeUserStatus`, the role, password, reset, recovery
 * and unlock flows) and the failed-login counter (`LoginAttemptRegistrar`, through `findByEmailForUpdate`) take
 * the row `FOR UPDATE`, which `FOR KEY SHARE` waits for already. Three writers take only the plain `UPDATE`'s
 * lock: the invitation acceptance (`INVITED → ACTIVE`), the lockout-notice stamp (`NotifyLockedIdentities`) and
 * the password-rehash swap; a login waits for those as well, each a short single-statement write.
 *
 * **Lock order.** Every holder takes `identity_user` before any `iam_session` row, which is the erasure's order
 * (`FOR UPDATE` on the identity, then its sessions), so neither strength can close a cycle with it; none of the
 * writers above takes an `iam_session` row before the identity, so every wait has an end. The login's own
 * earlier writes of the row (clearing the lockout, rehashing the password) commit in their own transactions
 * before the mint begins.
 *
 * **The admission rule lives twice**, here and in `User::ensureActive()`, because the session context may not
 * read the identity aggregate. `DbalSessionSubjectLockAdmissionTest` holds the two in agreement over every
 * status, and reds when a status is added.
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
    public function holdForAdmission(string $userId): SubjectAdmission
    {
        if (!$this->connection->isTransactionActive()) {
            throw new LogicException(
                "A session subject lock is held for its caller's transaction; it was called outside one.",
            );
        }

        $status = $this->connection->fetchOne(
            <<<'SQL'
                SELECT status
                FROM identity_user
                WHERE id = CAST(:userId AS UUID)
                FOR SHARE
                SQL,
            ['userId' => $userId],
        );

        if (!\is_string($status)) {
            return SubjectAdmission::Gone;
        }

        return match (IdentityStatus::from($status)) {
            IdentityStatus::ACTIVE => SubjectAdmission::Admissible,
            IdentityStatus::SUSPENDED => SubjectAdmission::Suspended,
            IdentityStatus::INVITED,
            IdentityStatus::DEACTIVATED,
            IdentityStatus::REVOKED => SubjectAdmission::Deactivated,
        };
    }
}
