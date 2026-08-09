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
 * {@link StoredIdentityIntegrity} over `identity_user` via plain DBAL — three reads, never a mutation and never
 * a hydration. Hydration is what it must avoid: the aggregate filters the orphan out on the way in, so an ORM
 * round trip would report every row as clean no matter what it holds. It cannot hydrate a malformed row at all,
 * which is the second reason: a `roles` column holding a JSON scalar fails the entity's `array` property type
 * before any accessor runs.
 *
 * `roles` is declared `json`, not `jsonb`, so `json_array_elements_text` is the expander that matches the
 * column — its `jsonb` sibling does not apply to a `json` value and the query would fail rather than degrade.
 */
final readonly class DbalStoredIdentityIntegrity implements StoredIdentityIntegrity
{
    /**
     * The text a JSON `null` element is reported as. It collides with an identity that stored the literal
     * string `"null"`, and the collision is harmless: both are findings, neither is a `Role`, and the repair
     * for both is to rewrite the column.
     */
    private const string NULL_ELEMENT = 'null';

    public function __construct(private Connection $connection)
    {
    }

    #[Override]
    public function roleValuesOutsideTheEnum(): array
    {
        // The CASE is what keeps a malformed row from aborting the whole read: `json_array_elements_text`
        // raises on a non-array, and a set-returning function in FROM is evaluated BEFORE any WHERE could
        // exclude the row — so filtering it there would not help. Substituting an empty array leaves those
        // rows to `identitiesWithMalformedRoles()`, which is where they belong: one corrupt cell must not
        // cost the operator every other finding in the table, nor the credential column that is read after.
        //
        // `value IS NULL OR …` is load-bearing too: a JSON `null` element expands to SQL NULL, and
        // `NULL NOT IN (…)` is NULL rather than TRUE, so without it the row would be dropped by the very
        // predicate meant to catch it — the control reporting clean over the corruption it exists to find.
        $sql = <<<'SQL'
            SELECT DISTINCT COALESCE(stored.value, :nullElement) AS orphan
            FROM identity_user
            CROSS JOIN LATERAL json_array_elements_text(
                CASE WHEN json_typeof(identity_user.roles) = 'array' THEN identity_user.roles ELSE '[]'::json END
            ) AS stored(value)
            WHERE stored.value IS NULL OR stored.value NOT IN (:known)
            ORDER BY orphan
            SQL;

        try {
            $values = $this->connection->fetchFirstColumn(
                $sql,
                ['nullElement' => self::NULL_ELEMENT, 'known' => $this->liveRoleValues()],
                ['known' => ArrayParameterType::STRING],
            );
        } catch (Exception $exception) {
            throw StoredIdentityProbeFailed::reading('identity_user.roles', $exception);
        }

        foreach ($values as $value) {
            // Never filtered away: discarding what the read could not explain is how a probe comes to answer
            // "nothing to report" from a result nobody understood — the one thing this control may not do.
            if (!\is_string($value)) {
                throw StoredIdentityProbeFailed::uncountableResultFrom('identity_user.roles');
            }
        }

        return $values;
    }

    #[Override]
    public function identitiesWithMalformedRoles(): int
    {
        return $this->count(
            "SELECT COUNT(*) FROM identity_user WHERE json_typeof(roles) <> 'array'",
            'identity_user.roles',
        );
    }

    #[Override]
    public function identitiesWithAnUnreadableCredential(): int
    {
        // The empty string and not `IS NULL`: null is the credential-less INVITED state the aggregate models
        // on purpose, while '' is the one value HashedPassword refuses. A null hash on an identity that is
        // past INVITED is a different fault with a different repair, counted separately below.
        return $this->count(
            "SELECT COUNT(*) FROM identity_user WHERE password_hash = ''",
            'identity_user.password_hash',
        );
    }

    #[Override]
    public function admittedIdentitiesWithoutACredential(): int
    {
        // `HashedPassword` never sees a null, so nothing raises and nothing is refused loudly: the firewall's
        // credentials check reads a null password as "cannot authenticate" and answers the same uniform 401 a
        // wrong password gets. The owner is locked out with no signal to distinguish it, and every other
        // control stays green — which is exactly the silence this command exists to break.
        return $this->count(
            "SELECT COUNT(*) FROM identity_user WHERE password_hash IS NULL AND status NOT IN ('INVITED', 'REVOKED')",
            'identity_user.password_hash',
        );
    }

    private function count(string $sql, string $column): int
    {
        try {
            $count = $this->connection->fetchOne($sql);
        } catch (Exception $exception) {
            throw StoredIdentityProbeFailed::reading($column, $exception);
        }

        // PostgreSQL's COUNT is a bigint, which the driver hands back as a string on some builds and an int
        // on others; anything else means the read returned something that is not a count at all.
        if (!\is_numeric($count)) {
            throw StoredIdentityProbeFailed::uncountableResultFrom($column);
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
