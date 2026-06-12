<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset;

use Erpify\Shared\Domain\Search\SortDirection;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset\KeysetPredicateBuilder;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset\OrderByColumns;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset\WirePaginationPolicy;
use InvalidArgumentException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(KeysetPredicateBuilder::class)]
#[CoversClass(OrderByColumns::class)]
#[CoversClass(WirePaginationPolicy::class)]
final class KeysetPredicateBuilderTest extends TestCase
{
    private KeysetPredicateBuilder $builder;

    #[Override]
    protected function setUp(): void
    {
        $this->builder = new KeysetPredicateBuilder();
    }

    #[Test]
    public function itBuildsASingleKeyPredicate(): void
    {
        $result = $this->builder->build(
            OrderByColumns::tieBreakOnly(),
            ['id' => 'u-1'],
            WirePaginationPolicy::wire(),
            'b',
        );

        $this->assertSame(\sprintf('(b.id > :%s)', $this->param('id')), $result['dql']);
        $this->assertSame([$this->param('id') => 'u-1'], $result['parameters']);
    }

    #[Test]
    public function itBuildsATwoKeyPredicateWithExplicitGroupingPerLevel(): void
    {
        $result = $this->builder->build(
            OrderByColumns::fromPrimarySort('name', SortDirection::ASC),
            ['name' => 'BBVA', 'id' => 'u-1'],
            WirePaginationPolicy::wire(),
            'b',
        );

        $this->assertSame(\sprintf(
            '((b.name > :%1$s) OR (b.name = :%1$s AND (b.id > :%2$s)))',
            $this->param('name'),
            $this->param('id'),
        ), $result['dql']);
        $this->assertSame([$this->param('id') => 'u-1', $this->param('name') => 'BBVA'], $result['parameters']);
    }

    #[Test]
    public function itFlipsTheStrictOperatorForADescendingKey(): void
    {
        $result = $this->builder->build(
            OrderByColumns::fromPrimarySort('name', SortDirection::DESC),
            ['name' => 'BBVA', 'id' => 'u-1'],
            WirePaginationPolicy::wire(),
            'b',
        );

        // name DESC ⇒ '<'; the id tie-break stays ASC ⇒ '>'.
        $this->assertSame(\sprintf(
            '((b.name < :%1$s) OR (b.name = :%1$s AND (b.id > :%2$s)))',
            $this->param('name'),
            $this->param('id'),
        ), $result['dql']);
    }

    #[Test]
    public function itBuildsAnNKeyPredicateNestedInsideOut(): void
    {
        $result = $this->builder->build(
            OrderByColumns::fromSorts([
                ['column' => 'name', 'direction' => SortDirection::ASC],
                ['column' => 'code', 'direction' => SortDirection::DESC],
            ]),
            ['name' => 'BBVA', 'code' => 'ES', 'id' => 'u-1'],
            WirePaginationPolicy::wire(),
            'b',
        );

        $this->assertSame(\sprintf(
            '((b.name > :%1$s) OR (b.name = :%1$s AND ((b.code < :%2$s) OR (b.code = :%2$s AND (b.id > :%3$s)))))',
            $this->param('name'),
            $this->param('code'),
            $this->param('id'),
        ), $result['dql']);
        $this->assertSame([
            $this->param('id') => 'u-1',
            $this->param('code') => 'ES',
            $this->param('name') => 'BBVA',
        ], $result['parameters']);
    }

    #[Test]
    public function itUsesInclusiveOperatorsWhenThePolicyBoundaryIsInclusive(): void
    {
        $result = $this->builder->build(
            OrderByColumns::tieBreakOnly(),
            ['id' => 'u-1'],
            new WirePaginationPolicy(exclusiveBoundary: false),
            'b',
        );

        $this->assertSame(\sprintf('(b.id >= :%s)', $this->param('id')), $result['dql']);
    }

    #[Test]
    public function itRejectsACursorMissingABoundaryValueForAColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->builder->build(
            OrderByColumns::fromPrimarySort('name', SortDirection::ASC),
            ['name' => 'BBVA'], // 'id' boundary value missing
            WirePaginationPolicy::wire(),
            'b',
        );
    }

    /**
     * Known-answer (golden) vector for the generated parameter name. The other
     * tests use {@see param}, which re-derives the name from the same expression
     * as production, so they only prove self-consistency. These LITERALS pin the
     * actual emitted bytes: a change to the prefix, the `xxh128` digest, or the
     * 16-hex truncation flips this. Stable names are load-bearing — they key
     * Doctrine's SQL cache (same query ⇒ same params ⇒ cache hit).
     */
    #[Test]
    public function itGeneratesTheKnownGoldenParameterNames(): void
    {
        $result = $this->builder->build(
            OrderByColumns::fromPrimarySort('name', SortDirection::ASC),
            ['name' => 'BBVA', 'id' => 'u-1'],
            WirePaginationPolicy::wire(),
            'b',
        );

        $this->assertSame(
            ['keyset_aa667553259dc3e4' => 'u-1', 'keyset_c96a918390b153ff' => 'BBVA'],
            $result['parameters'],
        );
    }

    private function param(string $column): string
    {
        return 'keyset_' . \substr(\hash('xxh128', $column), 0, 16);
    }
}
