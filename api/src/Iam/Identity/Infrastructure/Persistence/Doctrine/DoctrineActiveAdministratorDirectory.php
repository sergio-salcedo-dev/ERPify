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
 * `EXISTS` stops at the first surviving administrator — the question is existence, never a count. `roles` is a
 * Postgres `json` column, so the containment operator runs against an explicit `::jsonb` cast; the query is
 * parameterised end to end.
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
        $keeps = $this->connection->fetchOne(
            <<<'SQL'
                SELECT EXISTS(
                    SELECT 1
                    FROM identity_user
                    WHERE status = :active
                      AND id <> CAST(:excluded AS uuid)
                      AND roles::jsonb @> CAST(:adminRole AS jsonb)
                )::int
                SQL,
            [
                'active' => IdentityStatus::ACTIVE->value,
                'excluded' => $userId,
                'adminRole' => \json_encode([Role::ADMIN->value], JSON_THROW_ON_ERROR),
            ],
        );

        // `EXISTS(...)::int` yields 1 when an active admin other than $userId remains, else 0; pdo_pgsql may
        // surface it as the int or its numeric string, so accept both without casting the mixed driver value.
        return 1 === $keeps || '1' === $keeps;
    }
}
