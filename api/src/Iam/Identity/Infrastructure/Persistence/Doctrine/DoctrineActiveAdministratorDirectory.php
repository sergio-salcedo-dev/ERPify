<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Identity\Domain\Repository\ActiveAdministratorDirectory;
use Erpify\Shared\Access\Domain\Role;
use JsonException;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Single-context production adapter for {@see ActiveAdministratorDirectory}: answers both administrator
 * questions straight off `identity_user`, with no JOIN to Organization's Membership. Roles live on the identity
 * aggregate and nowhere else, so an active administrator is a row whose `status` is `ACTIVE` and whose `roles`
 * contains `ADMIN`. The subject's id is not part of that definition: it is one of the two questions asked OVER
 * the set — does another member remain, and is the subject a member at all — and the second is what separates
 * "this removal drains the set" from "some administrator exists somewhere". No cross-context seam and no
 * phantom membership to discount,
 * because the source IS the user row (an absent/hard-deleted user is simply never counted). Moving that
 * authority to `Membership` would take a role column that table does not have, so it is a schema decision
 * before it is an adapter one; the port is what stays unchanged across it.
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

    /**
     * The containment operand every statement here binds, encoded once: three copies of the same
     * `json_encode` is three places a future role rename has to be found.
     *
     * @throws JsonException
     */
    private function adminRoleOperand(): string
    {
        return \json_encode([Role::ADMIN->value], JSON_THROW_ON_ERROR);
    }

    #[Override]
    public function lockActiveAdministrators(): void
    {
        $this->lockedActiveAdminIds();
    }

    #[Override]
    public function keepsAnActiveAdminWithout(string $userId): bool
    {
        $activeAdminIds = $this->lockedActiveAdminIds();

        // Only the SOLE member of the set drains it, which is one comparison and not two questions. With
        // anyone else in the set an administrator remains whatever the subject is; and an identity the set
        // never held is removed from nothing, so an active VIEWER — or anyone at all while the set is empty —
        // leaves the invariant exactly as it was found, and refusing there would answer them with a conflict
        // about an invariant their change does not touch. Both readings come from the one locked acquisition
        // above rather than from two snapshots that can disagree, and the comparison is case-insensitive
        // because `Uuid::ensure()` validates a route id without normalising its casing.
        return 1 !== \count($activeAdminIds) || 0 !== \strcasecmp($activeAdminIds[0], $userId);
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
     * Narrowed to strings HERE rather than guarded at each reader. `id` is a Postgres `uuid`, so the driver
     * yields strings and the filter removes nothing — but a guard written per reader has to decide what a
     * non-string MEANS, and the two readings of this set would decide it in opposite directions: skipping it
     * makes "another administrator remains" false and "the subject is a member" false too, which together
     * PERMIT the drain. Narrowing once is what keeps the last-admin invariant's failure direction closed
     * without asking either reader to think about a row shape that cannot occur.
     *
     * @return list<string> the locked ids, discarded by the caller that only wants the lock
     */
    private function lockedActiveAdminIds(): array
    {
        $ids = $this->connection->fetchFirstColumn(
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
                'adminRole' => $this->adminRoleOperand(),
            ],
        );

        return \array_values(\array_filter($ids, \is_string(...)));
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
                'adminRole' => $this->adminRoleOperand(),
            ],
        );
    }

    /**
     * The predicate is computed in the projection rather than in a `WHERE`, because the row has to be LOCKED
     * whatever the answer is: `WHERE roles @> …` would return no row — and take no lock — for the subject who
     * is not an administrator, which is precisely the subject whose role is about to change underneath. An
     * absent row yields `false`, the same answer the unlocked reading gives, since there is nothing to erase.
     *
     * `FOR UPDATE` cannot sit inside an `EXISTS` subquery, so this returns the boolean as a column instead.
     */
    #[Override]
    public function holdsAdministratorRoleForUpdate(string $userId): bool
    {
        return (bool) $this->connection->fetchOne(
            <<<'SQL'
                SELECT roles::jsonb @> CAST(:adminRole AS jsonb)
                FROM identity_user
                WHERE id = CAST(:userId AS UUID)
                FOR UPDATE
                SQL,
            [
                'userId' => $userId,
                'adminRole' => $this->adminRoleOperand(),
            ],
        );
    }
}
