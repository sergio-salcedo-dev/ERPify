<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Domain\Search;

use Erpify\Shared\Domain\Search\Exception\InvalidPagination;
use Erpify\Shared\Domain\Search\SearchCriteria;
use Erpify\Shared\Domain\Search\SortDirection;
use Erpify\Tests\Unit\Shared\Domain\Search\Mother\FilterMother;
use Erpify\Tests\Unit\Shared\Domain\Search\Mother\FiltersMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('provideRejectsAPageOutsideTheAllowedRangeCases')]
    public function testRejectsAPageOutsideTheAllowedRange(int $page): void
    {
        $this->expectException(InvalidPagination::class);

        new SearchCriteria(page: $page);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideRejectsAPageOutsideTheAllowedRangeCases(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'above max' => [SearchCriteria::MAX_PAGE + 1];
    }

    #[DataProvider('provideRejectsALimitOutsideTheAllowedRangeCases')]
    public function testRejectsALimitOutsideTheAllowedRange(int $limit): void
    {
        $this->expectException(InvalidPagination::class);

        new SearchCriteria(limit: $limit);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideRejectsALimitOutsideTheAllowedRangeCases(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-5];
        yield 'above max' => [SearchCriteria::MAX_LIMIT + 1];
    }

    public function testAcceptsTheBoundaryLimitValues(): void
    {
        $this->assertSame(1, (new SearchCriteria(limit: 1))->limit);
        $this->assertSame(
            SearchCriteria::MAX_LIMIT,
            (new SearchCriteria(limit: SearchCriteria::MAX_LIMIT))->limit,
        );
    }
}
