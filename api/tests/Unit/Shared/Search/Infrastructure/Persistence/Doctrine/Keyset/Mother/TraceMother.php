<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\Mother;

use Erpify\Shared\Search\Domain\SortDirection;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\AppliedFilters;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\AppliedLimit;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\AppliedSort;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\QueryExecutionTrace;

/**
 * Builds {@see QueryExecutionTrace} instances (with their receipts) for the
 * canonicalization and stability suites.
 */
final class TraceMother
{
    public static function create(
        string $entity = 'Bank',
        ?AppliedFilters $filters = null,
        ?AppliedSort $sort = null,
        ?AppliedLimit $limit = null,
    ): QueryExecutionTrace {
        return new QueryExecutionTrace(
            $entity,
            $filters ?? AppliedFilters::none(),
            $sort ?? new AppliedSort('name', SortDirection::ASC),
            $limit ?? new AppliedLimit(25),
        );
    }
}
