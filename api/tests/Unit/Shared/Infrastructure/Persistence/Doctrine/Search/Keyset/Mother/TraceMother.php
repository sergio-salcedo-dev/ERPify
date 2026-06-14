<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset\Mother;

use Erpify\Shared\Domain\Search\SortDirection;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset\AppliedFilters;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset\AppliedLimit;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset\AppliedSort;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset\QueryExecutionTrace;

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
