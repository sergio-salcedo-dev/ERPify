<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Search\Domain;

use Erpify\Shared\Search\Domain\Filter;
use Erpify\Shared\Search\Domain\Filters;
use Erpify\Tests\Unit\Shared\Search\Domain\Mother\FilterMother;
use Erpify\Tests\Unit\Shared\Search\Domain\Mother\FiltersMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(Filters::class)]
final class FiltersTest extends TestCase
{
    public function testNoneIsEmpty(): void
    {
        $filters = Filters::none();

        $this->assertTrue($filters->isEmpty());
        $this->assertCount(0, $filters);
        $this->assertSame([], $filters->all());
    }

    public function testKeepsConstructionOrder(): void
    {
        $eq = FilterMother::eq();
        $in = FilterMother::in();

        $filters = new Filters($eq, $in);

        $this->assertFalse($filters->isEmpty());
        $this->assertSame([$eq, $in], $filters->all());
    }

    public function testFromListBuildsTheSameCollectionAsTheConstructor(): void
    {
        $contains = FilterMother::contains();

        $this->assertSame([$contains], Filters::fromList([$contains])->all());
    }

    public function testIteratesItsFiltersInOrder(): void
    {
        $eq = FilterMother::eq();
        $contains = FilterMother::contains();

        $this->assertSame([$eq, $contains], \iterator_to_array(new Filters($eq, $contains)));
    }

    public function testKeepsEveryFilterOverTheSameField(): void
    {
        // Same-field filters compose with AND downstream — the collection must not dedupe them.
        $first = Filter::contains('name', 'ban');
        $second = Filter::contains('name', 'co');

        $this->assertCount(2, new Filters($first, $second));
    }

    public function testCountsItsFilters(): void
    {
        $this->assertCount(3, FiltersMother::typical());
    }

    public function testIsImmutable(): void
    {
        $this->assertTrue((new ReflectionClass(Filters::class))->isReadOnly());
    }
}
