<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Erpify\Shared\Audit\Application\PersonResourceReferences;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * {@link PersonResourceReferences} over `audit_log` via plain DBAL — a `DISTINCT` read, never a mutation.
 *
 * `resource_erased = FALSE` is the load-bearing predicate, not an optimisation: an anonymised reference
 * holds a pseudonym that resolves to no live subject, so including those would report every correct
 * erasure as a divergence. The pair `(resource_type, resource_id)` leads `audit_log_resource_idx`, so the
 * scan stays indexed as the table grows; `resource_erased` is not in that index, so it is filtered rather
 * than sought.
 *
 * `ORDER BY` is not cosmetic: without it Postgres returns the `DISTINCT` hash-aggregate in whatever order
 * it lands, so two consecutive runs over an unchanged trail print the same divergence set differently and
 * any alert that diffs the reconciler's output fires on noise.
 */
#[AsAlias(PersonResourceReferences::class)]
final readonly class DbalPersonResourceReferences implements PersonResourceReferences
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[Override]
    public function unerasedIdsOfType(string $resourceType): array
    {
        $ids = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT resource_id FROM audit_log '
            . 'WHERE resource_type = :resource_type AND resource_id IS NOT NULL AND resource_erased = FALSE '
            . 'ORDER BY resource_id',
            ['resource_type' => $resourceType],
        );

        return \array_values(\array_filter($ids, \is_string(...)));
    }
}
