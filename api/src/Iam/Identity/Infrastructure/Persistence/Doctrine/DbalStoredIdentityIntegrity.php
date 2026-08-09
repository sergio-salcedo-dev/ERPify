<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Erpify\Iam\Identity\Application\StoredIdentityIntegrity;
use Erpify\Iam\Identity\Application\StoredIdentityProbeFailed;
use Erpify\Shared\Access\Domain\Role;
use Override;

/**
 * {@link StoredIdentityIntegrity} over `identity_user` via plain DBAL — two reads, never a mutation and never
 * a hydration. Hydration is what it must avoid: the aggregate filters the orphan value out on the way in, so
 * an ORM round trip would report every row as clean no matter what it holds.
 *
 * `roles` is declared `json`, not `jsonb`, so `json_array_elements_text` is the expander that matches the
 * column — its `jsonb` sibling does not apply to a `json` value and the query would fail rather than degrade.
 */
final readonly class DbalStoredIdentityIntegrity implements StoredIdentityIntegrity
{
    public function __construct(private Connection $connection)
    {
    }

    #[Override]
    public function roleValuesOutsideTheEnum(): array
    {
        $sql = <<<'SQL'
            SELECT DISTINCT stored.value
            FROM identity_user, json_array_elements_text(identity_user.roles) AS stored(value)
            WHERE stored.value NOT IN (:known)
            ORDER BY stored.value
            SQL;

        try {
            $values = $this->connection->fetchFirstColumn(
                $sql,
                ['known' => $this->liveRoleValues()],
                ['known' => ArrayParameterType::STRING],
            );
        } catch (Exception $exception) {
            throw StoredIdentityProbeFailed::reading('identity_user.roles', $exception);
        }

        return \array_values(\array_filter($values, \is_string(...)));
    }

    #[Override]
    public function identitiesWithAnUnreadableCredential(): int
    {
        try {
            // The empty string and not `IS NULL`: null is the credential-less INVITED state the aggregate
            // models on purpose, while '' is the one value HashedPassword refuses.
            $count = $this->connection->fetchOne(
                "SELECT COUNT(*) FROM identity_user WHERE password_hash = ''",
            );
        } catch (Exception $exception) {
            throw StoredIdentityProbeFailed::reading('identity_user.password_hash', $exception);
        }

        // PostgreSQL's COUNT is a bigint, which the driver hands back as a string on some builds and an int
        // on others; anything else means the read returned something that is not a count at all.
        if (!\is_numeric($count)) {
            throw StoredIdentityProbeFailed::uncountableResultFrom('identity_user.password_hash');
        }

        return (int) $count;
    }

    /**
     * @return list<string>
     */
    private function liveRoleValues(): array
    {
        return \array_map(static fn (Role $role): string => $role->value, Role::cases());
    }
}
