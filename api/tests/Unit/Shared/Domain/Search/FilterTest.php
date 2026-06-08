<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Domain\Search;

use Erpify\Shared\Domain\Search\Filter;
use Erpify\Shared\Domain\Search\FilterOperator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(Filter::class)]
final class FilterTest extends TestCase
{
    public function testEqBuildsAnEqualityFilterOverThePublicFieldName(): void
    {
        $filter = Filter::eq('name', 'BBVA');

        $this->assertSame('name', $filter->field);
        $this->assertSame(FilterOperator::Eq, $filter->operator);
        $this->assertSame('BBVA', $filter->value);
    }

    public function testInBuildsAListFilter(): void
    {
        $filter = Filter::in('id', ['7f9154f3-0e6d-4c14-b1a5-7a2d40d51a4f', 'c0a8b0e2-2f64-43d3-9b21-1f2f5f4e8d3c']);

        $this->assertSame('id', $filter->field);
        $this->assertSame(FilterOperator::In, $filter->operator);
        $this->assertSame(['7f9154f3-0e6d-4c14-b1a5-7a2d40d51a4f', 'c0a8b0e2-2f64-43d3-9b21-1f2f5f4e8d3c'], $filter->value);
    }

    public function testContainsBuildsASubstringFilter(): void
    {
        $filter = Filter::contains('name', 'banc');

        $this->assertSame('name', $filter->field);
        $this->assertSame(FilterOperator::Contains, $filter->operator);
        $this->assertSame('banc', $filter->value);
    }

    #[DataProvider('provideRangeConstructorsBuildScalarRangeFiltersCases')]
    public function testRangeConstructorsBuildScalarRangeFilters(Filter $filter, FilterOperator $expectedOperator): void
    {
        $this->assertSame('createdAt', $filter->field);
        $this->assertSame($expectedOperator, $filter->operator);
        $this->assertSame('2026-01-01T00:00:00+00:00', $filter->value);
    }

    /**
     * @return iterable<string, array{Filter, FilterOperator}>
     */
    public static function provideRangeConstructorsBuildScalarRangeFiltersCases(): iterable
    {
        $bound = '2026-01-01T00:00:00+00:00';

        yield 'gt' => [Filter::gt('createdAt', $bound), FilterOperator::Gt];
        yield 'gte' => [Filter::gte('createdAt', $bound), FilterOperator::Gte];
        yield 'lt' => [Filter::lt('createdAt', $bound), FilterOperator::Lt];
        yield 'lte' => [Filter::lte('createdAt', $bound), FilterOperator::Lte];
    }

    public function testIsImmutable(): void
    {
        $this->assertTrue((new ReflectionClass(Filter::class))->isReadOnly());
    }
}
