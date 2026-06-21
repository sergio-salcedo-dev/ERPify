<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset;

use Erpify\Shared\Search\Domain\SortDirection;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\OrderByColumns;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(OrderByColumns::class)]
final class OrderByColumnsTest extends TestCase
{
    #[Test]
    public function itAppendsTheIdTieBreakAsTheLastKey(): void
    {
        $columns = OrderByColumns::fromPrimarySort('name', SortDirection::ASC);

        $this->assertSame(['name', 'id'], $columns->columnNames());
        $this->assertSame('id', $columns->tieBreakColumn());
    }

    #[Test]
    public function itKeepsTheResolvedDirectionPerColumnWithAnAscendingTieBreak(): void
    {
        $columns = OrderByColumns::fromPrimarySort('name', SortDirection::DESC);

        $this->assertSame([
            ['column' => 'name', 'direction' => SortDirection::DESC],
            ['column' => 'id', 'direction' => SortDirection::ASC],
        ], $columns->all());
    }

    #[Test]
    public function itDoesNotDuplicateTheTieBreakWhenSortingByIdDirectly(): void
    {
        $columns = OrderByColumns::fromPrimarySort('id', SortDirection::DESC);

        $this->assertSame(['id'], $columns->columnNames());
        $this->assertSame([['column' => 'id', 'direction' => SortDirection::DESC]], $columns->all());
    }

    #[Test]
    public function itBuildsATieBreakOnlyOrdering(): void
    {
        $columns = OrderByColumns::tieBreakOnly();

        $this->assertSame(['id'], $columns->columnNames());
        $this->assertSame('id', $columns->tieBreakColumn());
    }

    #[Test]
    public function itGuaranteesTheTieBreakIsLastForAMultiKeySort(): void
    {
        $columns = OrderByColumns::fromSorts([
            ['column' => 'name', 'direction' => SortDirection::ASC],
            ['column' => 'code', 'direction' => SortDirection::DESC],
        ]);

        $this->assertSame(['name', 'code', 'id'], $columns->columnNames());
        $this->assertSame('id', $columns->tieBreakColumn());
    }

    #[Test]
    public function itDoesNotDuplicateATieBreakAlreadyTerminatingAMultiKeySort(): void
    {
        $columns = OrderByColumns::fromSorts([
            ['column' => 'name', 'direction' => SortDirection::ASC],
            ['column' => 'id', 'direction' => SortDirection::ASC],
        ]);

        $this->assertSame(['name', 'id'], $columns->columnNames());
    }

    #[Test]
    public function itDropsAnIdKeyThatIsNotInTheFinalPositionToAvoidDuplicatingTheTieBreak(): void
    {
        // Deferred review item (story 1.1): a mid-list `id` would duplicate the appended tie-break in
        // the ORDER BY and the keyset predicate. It is dropped; the tie-break is appended exactly once.
        $columns = OrderByColumns::fromSorts([
            ['column' => 'id', 'direction' => SortDirection::DESC],
            ['column' => 'name', 'direction' => SortDirection::ASC],
        ]);

        $this->assertSame(['name', 'id'], $columns->columnNames());
        $this->assertSame('id', $columns->tieBreakColumn());
    }
}
