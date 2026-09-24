<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Erpify\Shared\Audit\Application\PersonResourceReferences;
use Erpify\Shared\Persistence\Infrastructure\KeysetDistinctIds;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * {@link PersonResourceReferences} over `audit_log` via plain DBAL — a `DISTINCT` read in bounded keyset pages
 * ({@see KeysetDistinctIds}), never a mutation.
 *
 * `resource_erased = FALSE` is the load-bearing predicate, not an optimisation: an anonymised reference
 * holds a pseudonym that resolves to no live subject, so including those would report every correct
 * erasure as a divergence. The pair `(resource_type, resource_id)` leads `audit_log_resource_idx`, so with
 * the type pinned by equality each page is a range scan over `resource_id` however large the trail grows;
 * `resource_erased` is not in that index, so it is filtered rather than sought. `LIMIT` therefore bounds what
 * a page returns, not what it walks: a page reads every row between its bounds, every event of each person
 * and every erased row included, so a trail dominated by erased or chatty subjects makes each page longer.
 *
 * `ORDER BY` is not cosmetic: without it Postgres returns the `DISTINCT` hash-aggregate in whatever order
 * it lands, so two consecutive runs over an unchanged trail print the same divergence set differently and
 * any alert that diffs the reconciler's output fires on noise.
 */
#[AsAlias(PersonResourceReferences::class)]
final readonly class DbalPersonResourceReferences implements PersonResourceReferences
{
    private KeysetDistinctIds $ids;

    public function __construct(Connection $connection, int $pageSize = KeysetDistinctIds::DEFAULT_PAGE_SIZE)
    {
        $this->ids = new KeysetDistinctIds($connection, $pageSize);
    }

    #[Override]
    public function unerasedIdsOfType(string $resourceType): array
    {
        return $this->ids->idsOf(
            'audit_log',
            'resource_id',
            'resource_type = :resource_type AND resource_erased = FALSE',
            ['resource_type' => $resourceType],
        );
    }
}
