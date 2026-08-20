<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Search\Infrastructure\Persistence\Doctrine;

use Erpify\Shared\Search\Domain\FilterOperator;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\FieldMapping;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(FieldMapping::class)]
final class FieldMappingTest extends TestCase
{
    /**
     * @param callable(): FieldMapping $construct
     */
    #[DataProvider('provideTheConstructorRefusesAnIncoherentMappingCases')]
    public function testTheConstructorRefusesAnIncoherentMapping(callable $construct): void
    {
        $this->expectException(LogicException::class);

        $construct();
    }

    /**
     * Every combination the constructor refuses, in one place. The construction is deferred inside a
     * closure so the `new` is a returned (used) value, never a bare discarded statement.
     *
     * @return iterable<string, array{callable(): FieldMapping}>
     */
    public static function provideTheConstructorRefusesAnIncoherentMappingCases(): iterable
    {
        yield 'a uuid column with CONTAINS among explicit operators' => [
            static fn (): FieldMapping => new FieldMapping(
                'b.id',
                operators: [FilterOperator::Eq, FilterOperator::Contains],
                requiresUuidValues: true,
            ),
        ];

        yield 'a uuid column on the default operators, which include CONTAINS' => [
            static fn (): FieldMapping => new FieldMapping('b.id', requiresUuidValues: true),
        ];

        yield 'a timestamp column with CONTAINS among explicit operators' => [
            static fn (): FieldMapping => new FieldMapping(
                'b.createdAt',
                operators: [FilterOperator::Gte, FilterOperator::Contains],
                requiresDateTimeValues: true,
            ),
        ];

        yield 'a timestamp column on the default operators, which include CONTAINS' => [
            static fn (): FieldMapping => new FieldMapping('b.createdAt', requiresDateTimeValues: true),
        ];

        yield 'a timestamp column with EQ among explicit operators' => [
            static fn (): FieldMapping => new FieldMapping(
                'b.createdAt',
                operators: [FilterOperator::Eq, FilterOperator::Gte],
                requiresDateTimeValues: true,
            ),
        ];

        yield 'a timestamp column with IN among explicit operators' => [
            static fn (): FieldMapping => new FieldMapping(
                'b.createdAt',
                operators: [FilterOperator::In, FilterOperator::Gte],
                requiresDateTimeValues: true,
            ),
        ];

        yield 'a column requiring both uuid and datetime values' => [
            static fn (): FieldMapping => new FieldMapping(
                'b.createdAt',
                operators: [FilterOperator::Gt],
                requiresUuidValues: true,
                requiresDateTimeValues: true,
            ),
        ];

        // A mapped field admitting nothing is not a narrower field: every filter on it then answers
        // `unsupported-search-operator` where the caller would expect `unknown-search-field`, and nothing
        // at construction says so. Reachable as a typo now that naming the list is the norm.
        yield 'an empty operator set' => [
            static fn (): FieldMapping => new FieldMapping('b.name', operators: []),
        ];
    }

    public function testUuidFieldAcceptsEqAndIn(): void
    {
        $mapping = new FieldMapping(
            'b.id',
            operators: [FilterOperator::Eq, FilterOperator::In],
            requiresUuidValues: true,
        );

        $this->assertTrue($mapping->allows(FilterOperator::Eq));
        $this->assertTrue($mapping->allows(FilterOperator::In));
        $this->assertFalse($mapping->allows(FilterOperator::Contains));
    }

    /**
     * The default is the capability every field gets without anybody deciding, so `In` is not in it: it is
     * the one operator whose value is a list, and the one whose wire spelling therefore grows a sub-index.
     * Both directions are asserted together — a field that wants it says so — because the pair IS the rule,
     * and splitting it would let the negative half stand alone and read as "lists are not supported".
     */
    public function testTheListOperatorIsGrantedOnlyWhenAFieldNamesIt(): void
    {
        $inherited = new FieldMapping('b.name');

        $this->assertTrue($inherited->allows(FilterOperator::Eq));
        $this->assertTrue($inherited->allows(FilterOperator::Contains));
        $this->assertFalse($inherited->allows(FilterOperator::In));

        $declared = new FieldMapping('b.status', operators: [FilterOperator::Eq, FilterOperator::In]);

        $this->assertTrue($declared->allows(FilterOperator::In));
    }

    public function testDateTimeFieldAllowsRangeOperators(): void
    {
        $mapping = new FieldMapping(
            'b.createdAt',
            operators: [FilterOperator::Gt, FilterOperator::Gte, FilterOperator::Lt, FilterOperator::Lte],
            requiresDateTimeValues: true,
        );

        $this->assertTrue($mapping->allows(FilterOperator::Gt));
        $this->assertTrue($mapping->allows(FilterOperator::Gte));
        $this->assertTrue($mapping->allows(FilterOperator::Lt));
        $this->assertTrue($mapping->allows(FilterOperator::Lte));
        $this->assertFalse($mapping->allows(FilterOperator::Eq));
    }
}
