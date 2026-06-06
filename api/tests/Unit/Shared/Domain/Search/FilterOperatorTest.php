<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Domain\Search;

use Erpify\Shared\Domain\Search\FilterOperator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(FilterOperator::class)]
final class FilterOperatorTest extends TestCase
{
    #[DataProvider('provideBackingValuesAreTheLowercaseWireTokensCases')]
    public function testBackingValuesAreTheLowercaseWireTokens(FilterOperator $operator, string $wireToken): void
    {
        $this->assertSame($wireToken, $operator->value);
    }

    /**
     * @return iterable<string, array{FilterOperator, string}>
     */
    public static function provideBackingValuesAreTheLowercaseWireTokensCases(): iterable
    {
        yield 'eq' => [FilterOperator::Eq, 'eq'];
        yield 'in' => [FilterOperator::In, 'in'];
        yield 'contains' => [FilterOperator::Contains, 'contains'];
    }

    public function testEveryOperatorIsPinnedByAWireToken(): void
    {
        $pinnedOperators = [];

        foreach (self::provideBackingValuesAreTheLowercaseWireTokensCases() as $case) {
            $pinnedOperators[] = $case[0];
        }

        $this->assertSame(FilterOperator::cases(), $pinnedOperators);
    }
}
