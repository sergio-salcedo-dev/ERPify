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
 * Single-context production adapter for {@see ActiveAdministratorDirectory}: answers "would an active ADMIN
 * remain if this identity were excluded?" straight off `identity_user`, with no JOIN to Organization's
 * Membership. Operational roles live on the identity aggregate today, so an active administrator is a
 * row whose `status` is `ACTIVE`, whose `roles` contains `ADMIN`, and whose id differs — no cross-context seam
 * and no phantom membership to discount, because the source IS the user row (an absent/hard-deleted user is
 * simply never counted). When tenancy moves the authoritative role source to `Membership`, this adapter is
 * re-pointed exactly as the read model will be — the port never changes.
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
    public function keepsAnActiveAdminWithout(string $userId): bool
    {
        // Lock the whole active-admin set — not only the rows other than $userId — so two concurrent
        // transitions acquire the same rows in the same scan order (no deadlock) and the second blocks behind
        // the first, then re-reads the committed state under READ COMMITTED. Excluding $userId in SQL would
        // make the two lock sets diverge and could deadlock; the exclusion is applied in PHP instead. This must
        // run inside the caller's transaction for the lock to hold until commit.
        $activeAdminIds = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT id
                FROM identity_user
                WHERE status = :active
                  AND roles::jsonb @> CAST(:adminRole AS jsonb)
                FOR UPDATE
                SQL,
            [
                'active' => IdentityStatus::ACTIVE->value,
                'adminRole' => \json_encode([Role::ADMIN->value], JSON_THROW_ON_ERROR),
            ],
        );

        return \array_any(
            $activeAdminIds,
            static fn ($adminId): bool => \is_string($adminId) && 0 !== \strcasecmp($adminId, $userId),
        );
    }
}
