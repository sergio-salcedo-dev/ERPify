<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Infrastructure\Persistence\Doctrine\Search;

use Erpify\Shared\Domain\Search\FilterOperator;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\FieldMapping;
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
        $this->expectException(LogicException::class);

        new FieldMapping(
            'b.id',
            operators: [FilterOperator::Eq, FilterOperator::Contains],
            requiresUuidValues: true,
        );
    }

    public function testUuidFieldRejectsDefaultOperatorsBecauseTheyIncludeContains(): void
    {
        $this->expectException(LogicException::class);

        new FieldMapping('b.id', requiresUuidValues: true);
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

    public function testNonUuidFieldAllowsAllOperatorsByDefault(): void
    {
        $mapping = new FieldMapping('b.name');

        $this->assertTrue($mapping->allows(FilterOperator::Eq));
        $this->assertTrue($mapping->allows(FilterOperator::In));
        $this->assertTrue($mapping->allows(FilterOperator::Contains));
    }
}
