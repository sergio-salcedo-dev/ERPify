<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Identity\Domain\Repository\ActiveAdministratorDirectory;
use Erpify\Shared\Access\Domain\Role;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Single-context production adapter for {@see ActiveAdministratorDirectory}: answers both administrator
 * questions straight off `identity_user`, with no JOIN to Organization's Membership. Operational roles live on
 * the identity aggregate today, so an active administrator is a row whose `status` is `ACTIVE`, whose `roles`
 * contains `ADMIN`, and whose id differs — no cross-context seam and no phantom membership to discount,
 * because the source IS the user row (an absent/hard-deleted user is simply never counted). When tenancy moves
 * the authoritative role source to `Membership`, this adapter is re-pointed exactly as the read model will be —
 * the port never changes.
 *
 * The read takes a `FOR UPDATE` lock on the active-admin set so concurrent transitions serialize — the
 * invariant is set-based, so two last-two-admin suspends must not both pass a stale read and drain every
 * administrator. `roles` is a Postgres `json` column, so the containment operator runs against an explicit
 * `::jsonb` cast; the query is parameterised end to end.
 */
#[AsAlias(ActiveAdministratorDirectory::class)]
final readonly class DoctrineActiveAdministratorDirectory implements ActiveAdministratorDirectory
{
    public function __construct(private Connection $connection)
    {
    }

    #[Override]
    public function lockActiveAdministrators(): void
    {
        $this->lockedActiveAdminIds();
    }

    #[Override]
    public function keepsAnActiveAdminWithout(string $userId): bool
    {
        return \array_any(
            $this->lockedActiveAdminIds(),
            static fn ($adminId): bool => \is_string($adminId) && 0 !== \strcasecmp($adminId, $userId),
        );
    }

    /**
     * The one statement both locking members share, so the `ORDER BY` that carries the invariant cannot drift
     * between a copy that answers a question and a copy that only takes the lock.
     *
     * Locks the whole active-admin set — not only the rows other than a given id — so two concurrent
     * transitions acquire the same rows in the same order and the second blocks behind the first, then re-reads
     * the committed state under READ COMMITTED. Excluding an id in SQL would make the two lock sets diverge and
     * could deadlock; {@see keepsAnActiveAdminWithout()} applies its exclusion in PHP instead. The explicit
     * `ORDER BY` is what makes that shared order real: Postgres does not promise a stable scan order across
     * plans, so without it two concurrent callers could take the same rows in opposite orders and deadlock. It
     * places `LockRows` above `Sort`, so the rows are locked as they emerge sorted rather than merely returned
     * that way. Must run inside the caller's transaction for the lock to hold until commit.
     *
     * @return list<mixed> the locked ids, discarded by the caller that only wants the lock
     */
    private function lockedActiveAdminIds(): array
    {
        return $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT id
                FROM identity_user
                WHERE status = :active
                  AND roles::jsonb @> CAST(:adminRole AS jsonb)
                ORDER BY id
                FOR UPDATE
                SQL,
            [
                'active' => IdentityStatus::ACTIVE->value,
                'adminRole' => \json_encode([Role::ADMIN->value], JSON_THROW_ON_ERROR),
            ],
        );
    }

    #[Override]
    public function holdsAdministratorRole(string $userId): bool
    {
        // No status predicate and no lock, unlike the set invariant above: a suspended administrator still
        // carries the role, and this asks only about the subject's own row. `id` is a `uuid` column, so
        // Postgres compares the cast value canonically — the case-insensitivity the string comparisons
        // elsewhere in this class have to spell out is free here.
        return (bool) $this->connection->fetchOne(
            <<<'SQL'
                SELECT EXISTS (
                    SELECT 1
                    FROM identity_user
                    WHERE id = CAST(:userId AS UUID)
                      AND roles::jsonb @> CAST(:adminRole AS jsonb)
                )
                SQL,
            [
                'userId' => $userId,
                'adminRole' => \json_encode([Role::ADMIN->value], JSON_THROW_ON_ERROR),
            ],
        );
    }
}
