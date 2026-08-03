<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Erpify\Iam\Identity\Domain\Repository\LiveIdentityDirectory;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * {@link LiveIdentityDirectory} over `identity_user` via plain DBAL — one indexed primary-key probe for the
 * whole batch, never a hydration and never a mutation.
 *
 * `id` is a `uuid` column, so Postgres resolves the untyped list parameters to `uuid` and compares them
 * canonically; the case-insensitivity of RFC 4122 hex is therefore free in SQL. It is not free in PHP, which
 * is why the returned ids are mapped back to the caller's own spelling instead of being handed on as the
 * database wrote them — a caller diffing with `===` would otherwise read a differently-cased but present id
 * as a missing one, and this port's whole output feeds such a difference.
 *
 * The empty batch returns before touching the connection: an expanded `IN ()` is not valid SQL, and "no ids
 * to ask about" has an answer that needs no query.
 */
#[AsAlias(LiveIdentityDirectory::class)]
final readonly class DoctrineLiveIdentityDirectory implements LiveIdentityDirectory
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param string[] $ids
     */
    #[Override]
    public function existingIdsAmong(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $found = $this->connection->fetchFirstColumn(
            'SELECT id FROM identity_user WHERE id IN (:ids)',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );

        $live = \array_map(\strtolower(...), \array_filter($found, \is_string(...)));

        return \array_values(\array_filter(
            $ids,
            static fn (string $id): bool => \in_array(\strtolower($id), $live, true),
        ));
    }
}
