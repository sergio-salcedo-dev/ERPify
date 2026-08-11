<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Erpify\Iam\Identity\Application\CorruptIdentityRow;
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
 * The empty batch returns before touching the connection — "no ids to ask about" has an answer that needs no
 * query. It is an optimisation and nothing more: DBAL expands an empty array parameter to the literal `NULL`,
 * so the statement would be valid `… IN (NULL)` and would correctly return nothing.
 *
 * The batch is one statement, so it is bounded by the PostgreSQL wire protocol's 65535 bound parameters —
 * a hard ceiling, not a soft one: past it the reconciliation fails with a driver error instead of degrading.
 * That is roughly the number of distinct people the installation has ever held across all its axes, so it is
 * far from the current scale; chunking is the change to make when a measurement says it is close, not before.
 */
#[AsAlias(LiveIdentityDirectory::class)]
final readonly class DoctrineLiveIdentityDirectory implements LiveIdentityDirectory
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param string[] $ids
     *
     * @throws CorruptIdentityRow when a row holds something an identity id cannot be — this control's output
     *                            is an absence, so a dropped id would be reported as an erased person
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

        // Flipped to a lookup set rather than scanned: `in_array` inside the filter is a linear scan per
        // candidate, so the pair is quadratic in the number of people the installation has ever had — the
        // very size this batch exists to handle in one go. UUIDs always contain hyphens, so none of them can
        // be coerced into an integer array key.
        $live = \array_flip(\array_map(
            static fn (mixed $id): string => \strtolower(self::asIdentityId($id)),
            $found,
        ));

        return \array_values(\array_filter(
            $ids,
            static fn (string $id): bool => isset($live[\strtolower($id)]),
        ));
    }

    private static function asIdentityId(mixed $id): string
    {
        if (!\is_string($id)) {
            throw CorruptIdentityRow::idIsNotAString('identity_user.id');
        }

        return $id;
    }
}
