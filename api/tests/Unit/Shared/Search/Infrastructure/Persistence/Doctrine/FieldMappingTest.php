<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Search\Infrastructure\Persistence\Doctrine;

use Erpify\Shared\Search\Domain\FilterOperator;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\FieldMapping;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(FieldMapping::class)]
final class FieldMappingTest extends TestCase
{
    public function testUuidFieldRejectsContainsAmongExplicitOperators(): void
    {
        $this->assertConstructionRejected(static fn (): FieldMapping => new FieldMapping(
            'b.id',
            operators: [FilterOperator::Eq, FilterOperator::Contains],
            requiresUuidValues: true,
        ));
    }

    public function testUuidFieldRejectsDefaultOperatorsBecauseTheyIncludeContains(): void
    {
        $this->assertConstructionRejected(
            static fn (): FieldMapping => new FieldMapping('b.id', requiresUuidValues: true),
        );
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

    public function testDateTimeFieldRejectsContainsAmongExplicitOperators(): void
    {
        $this->assertConstructionRejected(static fn (): FieldMapping => new FieldMapping(
            'b.createdAt',
            operators: [FilterOperator::Gte, FilterOperator::Contains],
            requiresDateTimeValues: true,
        ));
    }

    public function testDateTimeFieldRejectsDefaultOperatorsBecauseTheyIncludeContains(): void
    {
        $this->assertConstructionRejected(
            static fn (): FieldMapping => new FieldMapping('b.createdAt', requiresDateTimeValues: true),
        );
    }

    public function testDateTimeFieldRejectsEqAmongExplicitOperators(): void
    {
        $this->assertConstructionRejected(static fn (): FieldMapping => new FieldMapping(
            'b.createdAt',
            operators: [FilterOperator::Eq, FilterOperator::Gte],
            requiresDateTimeValues: true,
        ));
    }

    public function testDateTimeFieldRejectsInAmongExplicitOperators(): void
    {
        $this->assertConstructionRejected(static fn (): FieldMapping => new FieldMapping(
            'b.createdAt',
            operators: [FilterOperator::In, FilterOperator::Gte],
            requiresDateTimeValues: true,
        ));
    }

    public function testFieldRejectsRequiringBothUuidAndDateTimeValues(): void
    {
        $this->assertConstructionRejected(static fn (): FieldMapping => new FieldMapping(
            'b.createdAt',
            operators: [FilterOperator::Gt],
            requiresUuidValues: true,
            requiresDateTimeValues: true,
        ));
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

    /**
     * Asserts the guarded constructor rejects the given arguments. The construction is deferred
     * inside the closure so the `new` is a returned (used) value, never a bare discarded statement.
     *
     * @param callable(): FieldMapping $construct
     */
    private function assertConstructionRejected(callable $construct): void
    {
        $this->expectException(LogicException::class);

        $construct();
    }
}
