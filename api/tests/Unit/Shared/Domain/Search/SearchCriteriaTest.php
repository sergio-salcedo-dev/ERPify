<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Domain\Search;

use Erpify\Shared\Domain\Search\SearchCriteria;
use Erpify\Shared\Domain\Search\SortDirection;
use Erpify\Tests\Unit\Shared\Domain\Search\Mother\FilterMother;
use Erpify\Tests\Unit\Shared\Domain\Search\Mother\FiltersMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SearchCriteria::class)]
final class SearchCriteriaTest extends TestCase
{
    public function testDefaultsToNoFiltering(): void
    {
        $criteria = new SearchCriteria();

        $this->assertTrue($criteria->filters->isEmpty());
    }

    public function testTransportsFiltersAsNamedArgument(): void
    {
        $filters = FiltersMother::with(FilterMother::contains());

        $criteria = new SearchCriteria(filters: $filters);

        $this->assertSame($filters, $criteria->filters);
    }

    public function testDefaultsToNoOrdering(): void
    {
        $criteria = new SearchCriteria();

        $this->assertNull($criteria->sort);
        $this->assertNotInstanceOf(SortDirection::class, $criteria->direction);
    }

    public function testTransportsSortAndDirectionAsNamedArguments(): void
    {
        $criteria = new SearchCriteria(sort: 'name', direction: SortDirection::DESC);

        $this->assertSame('name', $criteria->sort);
        $this->assertSame(SortDirection::DESC, $criteria->direction);
    }
}
